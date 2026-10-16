<?php

namespace Tests\Feature\DemandForecast;

use App\Models\DailyMenuSalesSummary;
use App\Models\Menu;
use App\Models\MenuDemandForecast;
use App\Models\Outlet;
use App\Models\User;
use App\Services\DemandForecast\CatBoostServingClient;
use App\Services\DemandForecast\DemandForecastFallbackService;
use App\Services\DemandForecast\DemandForecastOrchestrationService;
use App\Services\DemandForecast\NativeDemandFeatureExtractor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class DemandForecastOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Outlet $outlet;
    protected Menu $menu1;
    protected Menu $menu2;
    protected DemandForecastOrchestrationService $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.demand_forecast.enabled' => true,
            'services.catboost_serving.enabled' => true,
        ]);

        $this->user = User::factory()->create(['name' => 'Owner Utama', 'email' => 'owner.utama@example.com']);
        $this->outlet = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Outlet Pusat Jakarta',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);

        $this->menu1 = Menu::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'name' => 'Nasi Goreng Kambing',
            'category' => 'Makanan',
            'price' => 35000,
            'cost_price' => 20000,
            'unit' => 'porsi',
            'is_available' => true,
        ]);

        $this->menu2 = Menu::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'name' => 'Es Teh Manis',
            'category' => 'Minuman',
            'price' => 5000,
            'cost_price' => 1500,
            'unit' => 'gelas',
            'is_available' => true,
        ]);

        $client = new CatBoostServingClient('http://127.0.0.1:8000', 2, 5, 0);
        $extractor = new NativeDemandFeatureExtractor();
        $fallback = new DemandForecastFallbackService();

        $this->orchestrator = new DemandForecastOrchestrationService($extractor, $client, $fallback);
    }

    public function test_01_successful_fastapi_forecast_and_cache_upsert(): void
    {
        $targetDate = '2026-10-03';
        $cutoffDate = '2026-10-02';

        // Seed 28 days of history for menu1
        for ($i = 0; $i < 28; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'menu_id' => $this->menu1->id,
                'business_date' => $d,
                'uom' => 'porsi',
                'total_qty' => 10.0,
                'total_revenue' => 350000,
                'total_cost' => 200000,
                'transaction_count' => 10,
            ]);
        }

        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'success' => true,
                'data' => [
                    'outlet_id' => (string) $this->outlet->id,
                    'target_date' => $targetDate,
                    'forecasts' => [
                        [
                            'menu_id' => (string) $this->menu1->id,
                            'menu_name' => 'Nasi Goreng Kambing',
                            'category' => 'Makanan',
                            'uom' => 'porsi',
                            'predicted_quantity' => 14.25,
                            'raw_model_output' => 14.25,
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

        $res = $this->orchestrator->generateAndCacheForecasts($this->user, $this->outlet, $targetDate, [$this->menu1->id]);

        $this->assertEquals(1, $res['forecasts_generated']);
        $this->assertEquals(1, $res['catboost_count']);
        $this->assertEquals(0, $res['fallback_count']);

        $this->assertDatabaseHas('menu_demand_forecasts', [
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'menu_id' => $this->menu1->id,
            'target_date' => '2026-10-03',
            'cutoff_date' => '2026-10-02',
            'uom' => 'porsi',
            'predicted_quantity' => 14.25,
            'model_version' => 'native-catboost-v1',
            'serving_origin' => 'FASTAPI_CATBOOST',
            'history_status' => 'SUFFICIENT',
        ]);
    }

    public function test_02_fastapi_failure_executes_fallback_gracefully(): void
    {
        $targetDate = '2026-10-03';
        $cutoffDate = '2026-10-02';

        // Seed 28 days of history for menu1
        for ($i = 0; $i < 28; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'menu_id' => $this->menu1->id,
                'business_date' => $d,
                'uom' => 'porsi',
                'total_qty' => 12.0,
                'total_revenue' => 420000,
                'total_cost' => 240000,
                'transaction_count' => 12,
            ]);
        }

        // Simulate FastAPI 503 Daemon Down
        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'error' => 'Model not loaded',
            ], 503),
        ]);

        $res = $this->orchestrator->generateAndCacheForecasts($this->user, $this->outlet, $targetDate, [$this->menu1->id]);

        $this->assertEquals(1, $res['forecasts_generated']);
        $this->assertEquals(0, $res['catboost_count']);
        $this->assertEquals(1, $res['fallback_count']);

        $this->assertDatabaseHas('menu_demand_forecasts', [
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'menu_id' => $this->menu1->id,
            'target_date' => '2026-10-03',
            'uom' => 'porsi',
            'predicted_quantity' => 12.0,
            'model_version' => 'statistical-baseline-v1',
            'serving_origin' => 'FALLBACK_LOCAL',
            'history_status' => 'SUFFICIENT',
        ]);
    }

    public function test_03_mixed_batch_routes_insufficient_history_directly_to_fallback(): void
    {
        $targetDate = '2026-10-03';
        $cutoffDate = '2026-10-02';

        // Menu 1: 28 days -> SUFFICIENT
        for ($i = 0; $i < 28; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'menu_id' => $this->menu1->id,
                'business_date' => $d,
                'uom' => 'porsi',
                'total_qty' => 20.0,
                'total_revenue' => 700000,
                'total_cost' => 400000,
                'transaction_count' => 20,
            ]);
        }

        // Menu 2: Only 2 days -> INSUFFICIENT_HISTORY
        for ($i = 0; $i < 2; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'menu_id' => $this->menu2->id,
                'business_date' => $d,
                'uom' => 'gelas',
                'total_qty' => 50.0,
                'total_revenue' => 250000,
                'total_cost' => 75000,
                'transaction_count' => 30,
            ]);
        }

        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'success' => true,
                'data' => [
                    'outlet_id' => (string) $this->outlet->id,
                    'target_date' => $targetDate,
                    'forecasts' => [
                        [
                            'menu_id' => (string) $this->menu1->id,
                            'menu_name' => 'Nasi Goreng Kambing',
                            'category' => 'Makanan',
                            'uom' => 'porsi',
                            'predicted_quantity' => 22.0,
                            'raw_model_output' => 22.0,
                            'clipped' => false,
                            'confidence_level' => 'HIGH',
                            'strategy_used' => 'NATIVE_CATBOOST_REGRESSOR',
                            'fallback_applied' => false,
                        ],
                    ],
                ],
                'metadata' => ['features_count' => 37],
            ], 200),
        ]);

        $res = $this->orchestrator->generateAndCacheForecasts($this->user, $this->outlet, $targetDate);

        $this->assertEquals(2, $res['forecasts_generated']);
        $this->assertEquals(1, $res['catboost_count']);
        $this->assertEquals(1, $res['fallback_count']);

        // Check Menu 1 (CatBoost)
        $this->assertDatabaseHas('menu_demand_forecasts', [
            'menu_id' => $this->menu1->id,
            'serving_origin' => 'FASTAPI_CATBOOST',
            'history_status' => 'SUFFICIENT',
        ]);

        // Check Menu 2 (Fallback)
        $this->assertDatabaseHas('menu_demand_forecasts', [
            'menu_id' => $this->menu2->id,
            'serving_origin' => 'FALLBACK_LOCAL',
            'history_status' => 'INSUFFICIENT_HISTORY',
        ]);
    }

    public function test_04_upsert_idempotency_does_not_duplicate_records(): void
    {
        $targetDate = '2026-10-03';
        $cutoffDate = '2026-10-02';

        for ($i = 0; $i < 28; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'menu_id' => $this->menu1->id,
                'business_date' => $d,
                'uom' => 'porsi',
                'total_qty' => 10.0,
            ]);
        }

        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'success' => true,
                'data' => [
                    'outlet_id' => (string) $this->outlet->id,
                    'target_date' => $targetDate,
                    'forecasts' => [
                        [
                            'menu_id' => (string) $this->menu1->id,
                            'predicted_quantity' => 15.0,
                            'raw_model_output' => 15.0,
                            'clipped' => false,
                            'confidence_level' => 'HIGH',
                            'strategy_used' => 'NATIVE_CATBOOST_REGRESSOR',
                        ],
                    ],
                ],
                'metadata' => ['features_count' => 37],
            ], 200),
        ]);

        // Run 1st time
        $this->orchestrator->generateAndCacheForecasts($this->user, $this->outlet, $targetDate, [$this->menu1->id]);
        $this->assertEquals(1, MenuDemandForecast::count());

        // Run 2nd time (idempotent update)
        $this->orchestrator->generateAndCacheForecasts($this->user, $this->outlet, $targetDate, [$this->menu1->id]);
        $this->assertEquals(1, MenuDemandForecast::count());
    }

    public function test_05_tenant_isolation_violation_throws_exception(): void
    {
        $otherUser = User::factory()->create(['name' => 'Other User', 'email' => 'other@example.com']);
        $targetDate = '2026-10-03';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tenant isolation violation');

        $this->orchestrator->generateAndCacheForecasts($otherUser, $this->outlet, $targetDate);
    }

    public function test_06_negative_prediction_clipping_in_upsert(): void
    {
        $targetDate = '2026-10-03';
        $cutoffDate = '2026-10-02';

        for ($i = 0; $i < 28; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'menu_id' => $this->menu1->id,
                'business_date' => $d,
                'uom' => 'porsi',
                'total_qty' => 5.0,
            ]);
        }

        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'success' => true,
                'data' => [
                    'outlet_id' => (string) $this->outlet->id,
                    'target_date' => $targetDate,
                    'forecasts' => [
                        [
                            'menu_id' => (string) $this->menu1->id,
                            // Raw output was negative, server clips to 0.0
                            'predicted_quantity' => 0.0,
                            'raw_model_output' => -2.5,
                            'clipped' => true,
                            'confidence_level' => 'LOW',
                            'strategy_used' => 'NATIVE_CATBOOST_REGRESSOR',
                        ],
                    ],
                ],
                'metadata' => ['features_count' => 37],
            ], 200),
        ]);

        $res = $this->orchestrator->generateAndCacheForecasts($this->user, $this->outlet, $targetDate, [$this->menu1->id]);

        $this->assertEquals(1, $res['forecasts_generated']);
        $this->assertDatabaseHas('menu_demand_forecasts', [
            'menu_id' => $this->menu1->id,
            'predicted_quantity' => 0.0,
        ]);
    }

    public function test_07_partial_batch_invalid_item_isolated(): void
    {
        $targetDate = '2026-10-03';
        $cutoffDate = '2026-10-02';

        for ($i = 0; $i < 28; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'menu_id' => $this->menu1->id,
                'business_date' => $d,
                'uom' => 'porsi',
                'total_qty' => 10.0,
            ]);
            DailyMenuSalesSummary::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'menu_id' => $this->menu2->id,
                'business_date' => $d,
                'uom' => 'gelas',
                'total_qty' => 10.0,
            ]);
        }

        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'success' => true,
                'data' => [
                    'outlet_id' => (string) $this->outlet->id,
                    'target_date' => $targetDate,
                    'forecasts' => [
                        [
                            'menu_id' => (string) $this->menu1->id,
                            'predicted_quantity' => 12.0,
                            'raw_model_output' => 12.0,
                            'clipped' => false,
                            'confidence_level' => 'HIGH',
                            'strategy_used' => 'NATIVE_CATBOOST_REGRESSOR',
                        ],
                        [
                            'menu_id' => (string) $this->menu2->id,
                            // Invalid non-finite quantity
                            'predicted_quantity' => -999.0, // Or will be clipped or skipped
                            'raw_model_output' => -999.0,
                            'clipped' => false,
                            'confidence_level' => 'HIGH',
                            'strategy_used' => 'NATIVE_CATBOOST_REGRESSOR',
                        ],
                    ],
                ],
                'metadata' => ['features_count' => 37],
            ], 200),
        ]);

        $res = $this->orchestrator->generateAndCacheForecasts($this->user, $this->outlet, $targetDate);

        $this->assertEquals(2, $res['forecasts_generated']);
        $this->assertDatabaseHas('menu_demand_forecasts', [
            'menu_id' => $this->menu1->id,
            'predicted_quantity' => 12.0,
        ]);
    }

    public function test_08_batch_chunking_for_more_than_100_items(): void
    {
        $targetDate = '2026-10-03';
        $cutoffDate = '2026-10-02';

        // Create 103 additional menus
        $createdMenuIds = [];
        for ($k = 3; $k <= 105; $k++) {
            $m = Menu::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'name' => "Menu Item {$k}",
                'category' => 'Makanan',
                'price' => 10000,
                'cost_price' => 5000,
                'unit' => 'porsi',
                'is_available' => true,
            ]);
            $createdMenuIds[] = $m->id;

            // Seed 28 days of history
            for ($i = 0; $i < 28; $i++) {
                $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
                DailyMenuSalesSummary::create([
                    'user_id' => $this->user->id,
                    'outlet_id' => $this->outlet->id,
                    'menu_id' => $m->id,
                    'business_date' => $d,
                    'uom' => 'porsi',
                    'total_qty' => 5.0,
                ]);
            }
        }

        $requestCallCount = 0;
        Http::fake([
            'http://127.0.0.1:8000/predict' => function ($request) use (&$requestCallCount) {
                $requestCallCount++;
                $data = json_decode($request->body(), true);
                $items = $data['items'] ?? [];
                $forecasts = [];
                foreach ($items as $item) {
                    $forecasts[] = [
                        'menu_id' => (string) $item['menu_id'],
                        'predicted_quantity' => 5.5,
                        'raw_model_output' => 5.5,
                        'clipped' => false,
                        'confidence_level' => 'HIGH',
                        'strategy_used' => 'NATIVE_CATBOOST_REGRESSOR',
                    ];
                }
                return Http::response([
                    'success' => true,
                    'data' => [
                        'outlet_id' => $data['outlet_id'],
                        'target_date' => $data['target_date'],
                        'forecasts' => $forecasts,
                    ],
                    'metadata' => ['features_count' => 37],
                ], 200);
            },
        ]);

        $res = $this->orchestrator->generateAndCacheForecasts($this->user, $this->outlet, $targetDate, $createdMenuIds);

        // 103 items -> 2 HTTP calls (batch of 100, batch of 3)
        $this->assertEquals(2, $requestCallCount);
        $this->assertEquals(103, $res['forecasts_generated']);
        $this->assertEquals(103, $res['catboost_count']);
    }

    /**
     * Test 14: CatBoost serving disabled via CATBOOST_SERVING_ENABLED=false routes all eligible items to local fallback without HTTP call (TASK-08.4).
     */
    public function test_14_catboost_disabled_via_config_routes_eligible_items_to_local_fallback(): void
    {
        config([
            'services.demand_forecast.enabled' => true,
            'services.catboost_serving.enabled' => false,
        ]);

        $targetDate = '2026-10-03';
        $cutoffDate = '2026-10-02';

        // Seed 28 days of history for menu1 so it would otherwise be eligible for CatBoost
        for ($i = 0; $i < 28; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'menu_id' => $this->menu1->id,
                'business_date' => $d,
                'uom' => 'porsi',
                'total_qty' => 12.0,
                'total_revenue' => 420000,
                'total_cost' => 240000,
                'transaction_count' => 12,
            ]);
        }

        Http::fake();

        $res = $this->orchestrator->generateAndCacheForecasts($this->user, $this->outlet, $targetDate, [$this->menu1->id]);

        // Verifikasi tidak ada panggilan HTTP ke FastAPI
        Http::assertNothingSent();

        // Verifikasi output di-route ke fallback
        $this->assertEquals(1, $res['forecasts_generated']);
        $this->assertEquals(0, $res['catboost_count']);
        $this->assertEquals(1, $res['fallback_count']);

        $saved = MenuDemandForecast::where('menu_id', $this->menu1->id)->where('target_date', $targetDate)->first();
        $this->assertNotNull($saved);
        $this->assertEquals('FALLBACK_LOCAL', $saved->serving_origin);
        $this->assertTrue((bool) ($saved->metadata['fallback_applied'] ?? false));
    }
}
