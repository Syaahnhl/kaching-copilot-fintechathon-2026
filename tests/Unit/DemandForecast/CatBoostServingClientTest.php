<?php

namespace Tests\Unit\DemandForecast;

use App\Services\DemandForecast\CatBoostServingClient;
use App\Services\DemandForecast\Exceptions\CatBoostConnectionException;
use App\Services\DemandForecast\Exceptions\CatBoostServerException;
use App\Services\DemandForecast\Exceptions\CatBoostServiceUnavailableException;
use App\Services\DemandForecast\Exceptions\CatBoostTimeoutException;
use App\Services\DemandForecast\Exceptions\CatBoostValidationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatBoostServingClientTest extends TestCase
{
    protected CatBoostServingClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.catboost_serving.enabled' => true]);
        $this->client = new CatBoostServingClient('http://127.0.0.1:8000', 2, 5, 0);
    }

    public function test_01_health_check_success(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/health' => Http::response([
                'status' => 'healthy',
                'model_loaded' => true,
                'model_path' => '/models/catboost.cbm',
                'features_count' => 37,
            ], 200),
        ]);

        $result = $this->client->health();
        $this->assertEquals('healthy', $result['status']);
        $this->assertTrue($result['model_loaded']);
        $this->assertTrue($this->client->isReady());
    }

    public function test_02_health_check_service_unavailable_503(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/health' => Http::response([
                'status' => 'unhealthy',
                'model_loaded' => false,
                'error' => 'Model not loaded',
            ], 503),
        ]);

        $this->assertFalse($this->client->isReady());

        $this->expectException(CatBoostServiceUnavailableException::class);
        $this->client->health();
    }

    public function test_03_predict_success_and_response_validation(): void
    {
        $items = [
            [
                'menu_id' => '101',
                'menu_name' => 'Kopi Susu',
                'category' => 'Minuman',
                'uom' => 'cup',
                'history_days_available' => 28,
                'features' => ['f01_user_id' => '1'],
            ],
        ];

        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'success' => true,
                'data' => [
                    'outlet_id' => '1',
                    'target_date' => '2026-10-03',
                    'forecasts' => [
                        [
                            'menu_id' => '101',
                            'menu_name' => 'Kopi Susu',
                            'category' => 'Minuman',
                            'uom' => 'cup',
                            'predicted_quantity' => 15.5,
                            'raw_model_output' => 15.5,
                            'clipped' => false,
                            'confidence_level' => 'HIGH',
                            'strategy_used' => 'NATIVE_CATBOOST_REGRESSOR',
                            'fallback_applied' => false,
                        ],
                    ],
                ],
                'metadata' => [
                    'model_id' => 'native-catboost-v1',
                    'features_count' => 37,
                ],
            ], 200),
        ]);

        $response = $this->client->predict(1, '2026-10-03', $items);
        $this->assertTrue($response['success']);
        $this->assertCount(1, $response['data']['forecasts']);
        $this->assertEquals(15.5, $response['data']['forecasts'][0]['predicted_quantity']);
    }

    public function test_04_predict_empty_batch_rejected(): void
    {
        $this->expectException(CatBoostValidationException::class);
        $this->expectExceptionMessage('Prediction items array cannot be empty.');
        $this->client->predict(1, '2026-10-03', []);
    }

    public function test_05_predict_batch_exceeding_100_rejected(): void
    {
        $items = array_fill(0, 101, [
            'menu_id' => '1',
            'features' => [],
        ]);

        $this->expectException(CatBoostValidationException::class);
        $this->expectExceptionMessage('exceeds maximum allowed limit of 100 items');
        $this->client->predict(1, '2026-10-03', $items);
    }

    public function test_06_predict_http_422_validation_error(): void
    {
        $items = [
            ['menu_id' => '101', 'features' => []],
        ];

        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'detail' => [
                    ['loc' => ['body', 'items', 0, 'features', 'f05_lag_1'], 'msg' => 'Field required'],
                ],
            ], 422),
        ]);

        $this->expectException(CatBoostValidationException::class);
        $this->expectExceptionCode(422);
        $this->client->predict(1, '2026-10-03', $items);
    }

    public function test_07_predict_http_500_server_error(): void
    {
        $items = [
            ['menu_id' => '101', 'features' => []],
        ];

        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'error' => 'Internal server error',
            ], 500),
        ]);

        $this->expectException(CatBoostServerException::class);
        $this->expectExceptionCode(500);
        $this->client->predict(1, '2026-10-03', $items);
    }

    public function test_08_predict_http_503_service_unavailable(): void
    {
        $items = [
            ['menu_id' => '101', 'features' => []],
        ];

        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'error' => 'Model not loaded',
            ], 503),
        ]);

        $this->expectException(CatBoostServiceUnavailableException::class);
        $this->expectExceptionCode(503);
        $this->client->predict(1, '2026-10-03', $items);
    }

    public function test_09_predict_connection_timeout(): void
    {
        $items = [
            ['menu_id' => '101', 'features' => []],
        ];

        Http::fake([
            'http://127.0.0.1:8000/predict' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 5000 milliseconds');
            },
        ]);

        $this->expectException(CatBoostTimeoutException::class);
        $this->client->predict(1, '2026-10-03', $items);
    }

    public function test_10_predict_connection_refusal(): void
    {
        $items = [
            ['menu_id' => '101', 'features' => []],
        ];

        Http::fake([
            'http://127.0.0.1:8000/predict' => function () {
                throw new ConnectionException('cURL error 7: Failed to connect to 127.0.0.1 port 8000');
            },
        ]);

        $this->expectException(CatBoostConnectionException::class);
        $this->client->predict(1, '2026-10-03', $items);
    }

    public function test_11_predict_malformed_response_missing_menu(): void
    {
        $items = [
            ['menu_id' => '101', 'features' => []],
            ['menu_id' => '102', 'features' => []],
        ];

        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'success' => true,
                'data' => [
                    'outlet_id' => 1,
                    'target_date' => '2026-10-03',
                    'forecasts' => [
                        // Returns only 101, missing 102
                        [
                            'menu_id' => 101,
                            'predicted_quantity' => 10.0,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->expectException(CatBoostValidationException::class);
        $this->expectExceptionMessage("requested menu_id '102' missing from forecasts");
        $this->client->predict(1, '2026-10-03', $items);
    }

    public function test_12_format_batch_payload_strips_internal_metadata_and_retains_required_fields(): void
    {
        $rawItems = [
            [
                'menu_id' => '101',
                'menu_name' => 'Kopi Susu Gula Aren',
                'category' => 'Coffee',
                'uom' => 'cup',
                'cutoff_date' => '2026-09-28',
                'target_date' => '2026-09-29',
                'history_status' => 'MATURE',
                'history_days_available' => 28,
                'extra_debug_field' => 'should_be_stripped',
                'features' => [
                    'f01_user_id' => '19',
                    'f02_outlet_id' => '3',
                    'f05_lag_1' => 10.0,
                ],
            ],
            [
                'menu_id' => 102,
                'menu_name' => '',
                'category' => '',
                'uom' => '',
                'features' => [],
            ],
        ];

        $payload = $this->client->formatBatchPayload(3, '2026-09-29', $rawItems);

        $this->assertSame(3, $payload['outlet_id']);
        $this->assertSame('2026-09-29', $payload['target_date']);
        $this->assertCount(2, $payload['items']);

        // Item 1 verification
        $item1 = $payload['items'][0];
        $this->assertSame(101, $item1['menu_id']);
        $this->assertSame('Kopi Susu Gula Aren', $item1['menu_name']);
        $this->assertSame('Coffee', $item1['category']);
        $this->assertSame('cup', $item1['uom']);
        $this->assertSame(28, $item1['history_days_available']);
        $this->assertSame(['f01_user_id' => '19', 'f02_outlet_id' => '3', 'f05_lag_1' => 10.0], $item1['features']);

        // Assert strictly forbidden metadata fields are stripped
        $this->assertArrayNotHasKey('cutoff_date', $item1);
        $this->assertArrayNotHasKey('target_date', $item1);
        $this->assertArrayNotHasKey('history_status', $item1);
        $this->assertArrayNotHasKey('extra_debug_field', $item1);

        // Item 2 defaults verification
        $item2 = $payload['items'][1];
        $this->assertSame(102, $item2['menu_id']);
        $this->assertSame('Menu-102', $item2['menu_name']);
        $this->assertSame('Default', $item2['category']);
        $this->assertSame('porsi', $item2['uom']);
        $this->assertSame(28, $item2['history_days_available']);
        $this->assertSame([], $item2['features']);
    }

    public function test_13_predict_sends_clean_payload_via_http(): void
    {
        $rawItems = [
            [
                'menu_id' => '101',
                'menu_name' => 'Kopi Susu',
                'category' => 'Coffee',
                'uom' => 'cup',
                'cutoff_date' => '2026-09-28',
                'target_date' => '2026-09-29',
                'history_status' => 'MATURE',
                'history_days_available' => 28,
                'features' => ['f01_user_id' => '19'],
            ],
        ];

        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'success' => true,
                'data' => [
                    'outlet_id' => 3,
                    'target_date' => '2026-09-29',
                    'forecasts' => [
                        [
                            'menu_id' => 101,
                            'menu_name' => 'Kopi Susu',
                            'category' => 'Coffee',
                            'uom' => 'cup',
                            'predicted_quantity' => 12.0,
                            'raw_model_output' => 12.0,
                            'clipped' => false,
                            'confidence_level' => 'HIGH',
                            'strategy_used' => 'NATIVE_CATBOOST_REGRESSOR',
                            'fallback_applied' => false,
                        ],
                    ],
                ],
                'metadata' => [
                    'model_id' => 'native-catboost-v1',
                    'features_count' => 37,
                ],
            ], 200),
        ]);

        $response = $this->client->predict(3, '2026-09-29', $rawItems);

        $this->assertTrue($response['success']);
        $this->assertEquals(12.0, $response['data']['forecasts'][0]['predicted_quantity']);

        // Verify that Http received clean payload without internal metadata
        Http::assertSent(function ($request) {
            $data = $request->data();
            $item = $data['items'][0];
            return $data['outlet_id'] === 3
                && $data['target_date'] === '2026-09-29'
                && !isset($item['cutoff_date'])
                && !isset($item['target_date'])
                && !isset($item['history_status'])
                && $item['menu_id'] === 101
                && $item['menu_name'] === 'Kopi Susu';
        });
    }

    public function test_14_predict_handles_reordered_daemon_response_correctly(): void
    {
        $items = [
            ['menu_id' => '101', 'features' => []],
            ['menu_id' => '102', 'features' => []],
        ];

        // Daemon returns 102 first, then 101
        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'success' => true,
                'data' => [
                    'outlet_id' => 1,
                    'target_date' => '2026-10-03',
                    'forecasts' => [
                        [
                            'menu_id' => 102,
                            'predicted_quantity' => 25.0,
                        ],
                        [
                            'menu_id' => 101,
                            'predicted_quantity' => 14.0,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->client->predict(1, '2026-10-03', $items);
        $this->assertTrue($response['success']);
        $this->assertCount(2, $response['data']['forecasts']);
    }

    public function test_15_predict_invalid_target_date_or_outlet_id_throws_validation_exception(): void
    {
        $items = [['menu_id' => '101', 'features' => []]];

        // Invalid outlet_id <= 0
        try {
            $this->client->predict(0, '2026-10-03', $items);
            $this->fail('Expected CatBoostValidationException for outlet_id 0');
        } catch (CatBoostValidationException $e) {
            $this->assertStringContainsString('Invalid outlet_id', $e->getMessage());
        }

        // Invalid date format
        try {
            $this->client->predict(1, '2026/10/03', $items);
            $this->fail('Expected CatBoostValidationException for invalid date format');
        } catch (CatBoostValidationException $e) {
            $this->assertStringContainsString('Invalid target_date format', $e->getMessage());
        }
    }

    public function test_16_predict_rejects_non_numeric_prediction(): void
    {
        $items = [['menu_id' => '101', 'features' => []]];

        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'success' => true,
                'data' => [
                    'outlet_id' => 1,
                    'target_date' => '2026-10-03',
                    'forecasts' => [
                        [
                            'menu_id' => 101,
                            'predicted_quantity' => 'INVALID_NUMBER',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->expectException(CatBoostValidationException::class);
        $this->expectExceptionMessage("Non-finite or non-numeric predicted_quantity");
        $this->client->predict(1, '2026-10-03', $items);
    }

    public function test_17_disabled_client_throws_service_unavailable_exception(): void
    {
        config(['services.catboost_serving.enabled' => false]);

        $this->assertFalse($this->client->isEnabled());

        try {
            $this->client->health();
            $this->fail('Expected CatBoostServiceUnavailableException when health() called while disabled');
        } catch (CatBoostServiceUnavailableException $e) {
            $this->assertEquals(503, $e->getCode());
        }

        $items = [['menu_id' => 101, 'features' => ['f01_sales_qty_lag_1' => 5.0]]];
        try {
            $this->client->predict(1, '2026-10-03', $items);
            $this->fail('Expected CatBoostServiceUnavailableException when predict() called while disabled');
        } catch (CatBoostServiceUnavailableException $e) {
            $this->assertEquals(503, $e->getCode());
        }
    }
}
