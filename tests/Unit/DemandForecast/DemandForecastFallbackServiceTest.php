<?php

namespace Tests\Unit\DemandForecast;

use App\Services\DemandForecast\DemandForecastFallbackService;
use Tests\TestCase;

class DemandForecastFallbackServiceTest extends TestCase
{
    protected DemandForecastFallbackService $fallbackService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fallbackService = new DemandForecastFallbackService();
    }

    public function test_01_fallback_sufficient_history_seasonal_naive(): void
    {
        $item = [
            'menu_id' => '101',
            'menu_name' => 'Ayam Bakar',
            'category' => 'Makanan',
            'uom' => 'porsi',
            'history_status' => 'SUFFICIENT',
            'features' => [
                'f10_rolling_mean_7' => 12.0,
                'f13_rolling_std_7' => 2.5,
                'f16_same_dow_lag_7' => 15.0,
                'f20_same_dow_mean_4w' => 14.0,
            ],
        ];

        $res = $this->fallbackService->computeFallback($item);

        $this->assertEquals(15.0, $res['predicted_quantity']);
        $this->assertEquals('SEASONAL_NAIVE_DOW7', $res['strategy_used']);
        $this->assertEquals('MEDIUM', $res['confidence_level']);
        $this->assertEquals('FALLBACK_LOCAL', $res['serving_origin']);
        $this->assertEquals('statistical-baseline-v1', $res['model_version']);
        $this->assertTrue($res['fallback_applied']);
        $this->assertGreaterThan(0.0, $res['lower_bound_p10']);
        $this->assertGreaterThan($res['predicted_quantity'], $res['upper_bound_p90']);
    }

    public function test_02_fallback_sufficient_history_rolling_mean(): void
    {
        $item = [
            'menu_id' => '102',
            'menu_name' => 'Sop Iga',
            'category' => 'Makanan',
            'uom' => 'porsi',
            'history_status' => 'SUFFICIENT',
            'features' => [
                'f10_rolling_mean_7' => 8.5,
                'f13_rolling_std_7' => 1.5,
                'f16_same_dow_lag_7' => 0.0,
                'f20_same_dow_mean_4w' => 0.0,
            ],
        ];

        $res = $this->fallbackService->computeFallback($item);

        $this->assertEquals(8.5, $res['predicted_quantity']);
        $this->assertEquals('ROLLING_MEAN_7', $res['strategy_used']);
        $this->assertEquals('MEDIUM', $res['confidence_level']);
    }

    public function test_03_fallback_insufficient_history(): void
    {
        $item = [
            'menu_id' => '103',
            'menu_name' => 'Menu Baru',
            'category' => 'Makanan',
            'uom' => 'porsi',
            'history_status' => 'INSUFFICIENT_HISTORY',
            'features' => [
                'f10_rolling_mean_7' => 3.0,
                'f13_rolling_std_7' => 0.5,
                'f16_same_dow_lag_7' => 0.0,
                'f20_same_dow_mean_4w' => 0.0,
            ],
        ];

        $res = $this->fallbackService->computeFallback($item);

        $this->assertEquals(3.0, $res['predicted_quantity']);
        $this->assertEquals('INSUFFICIENT_HISTORY', $res['strategy_used']);
        $this->assertEquals('LOW', $res['confidence_level']);
        $this->assertEquals('INSUFFICIENT_HISTORY', $res['history_status']);
    }

    public function test_04_fallback_zero_demand(): void
    {
        $item = [
            'menu_id' => '104',
            'menu_name' => 'Menu Tidak Laku',
            'category' => 'Makanan',
            'uom' => 'porsi',
            'history_status' => 'ZERO_DEMAND',
            'features' => [
                'f10_rolling_mean_7' => 0.0,
                'f13_rolling_std_7' => 0.0,
                'f16_same_dow_lag_7' => 0.0,
                'f20_same_dow_mean_4w' => 0.0,
            ],
        ];

        $res = $this->fallbackService->computeFallback($item);

        $this->assertEquals(0.0, $res['predicted_quantity']);
        $this->assertEquals('ZERO_DEMAND', $res['strategy_used']);
        $this->assertEquals('MEDIUM', $res['confidence_level']);
        $this->assertEquals(0.0, $res['lower_bound_p10']);
        $this->assertEquals(0.0, $res['upper_bound_p90']);
    }

    public function test_05_batch_fallback_computation(): void
    {
        $items = [
            [
                'menu_id' => '101',
                'history_status' => 'SUFFICIENT',
                'features' => ['f16_same_dow_lag_7' => 10.0, 'f13_rolling_std_7' => 2.0],
            ],
            [
                'menu_id' => '102',
                'history_status' => 'ZERO_DEMAND',
                'features' => [],
            ],
        ];

        $batchRes = $this->fallbackService->computeBatchFallback($items);

        $this->assertCount(2, $batchRes);
        $this->assertEquals('101', $batchRes[0]['menu_id']);
        $this->assertEquals(10.0, $batchRes[0]['predicted_quantity']);
        $this->assertEquals('102', $batchRes[1]['menu_id']);
        $this->assertEquals(0.0, $batchRes[1]['predicted_quantity']);
    }
}
