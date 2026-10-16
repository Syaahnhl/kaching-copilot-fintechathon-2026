<?php

namespace Tests\Unit\DemandForecast;

use App\Models\DailyMenuSalesSummary;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\OutletWeatherForecast;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DemandForecast\DemandForecastBacktestingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DemandForecastBacktestingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DemandForecastBacktestingService $service;
    protected User $user;
    protected Outlet $outlet;
    protected Menu $menu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DemandForecastBacktestingService();

        $this->user = User::factory()->create(['name' => 'Owner Test', 'email' => 'owner.test@example.com']);
        $this->outlet = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Outlet Bukit Padangan',
            'latitude' => -7.150975,
            'longitude' => 110.140259,
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);
        $this->menu = Menu::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'name' => 'Ayam Bakar Madu',
            'category' => 'Makanan Utama',
            'price' => 30000,
            'cost_price' => 18000,
            'unit' => 'porsi',
            'is_available' => true,
        ]);
    }

    public function test_01_wape_mae_and_bias_metrics_calculation(): void
    {
        $actuals = [10.0, 20.0, 30.0, 40.0];
        $preds = [12.0, 18.0, 35.0, 35.0];

        // Sum actual = 100.0, Abs errors: 2 + 2 + 5 + 5 = 14.0
        // WAPE = 14.0 / 100.0 = 0.14 (14.0%)
        $wape = $this->service->calculateWape($actuals, $preds);
        $this->assertEquals(0.14, $wape);

        // MAE = 14.0 / 4 = 3.5
        $mae = $this->service->calculateMae($actuals, $preds);
        $this->assertEquals(3.5, $mae);

        // Error = (12-10) + (18-20) + (35-30) + (35-40) = 2 - 2 + 5 - 5 = 0.0
        // Bias = 0.0 / 100.0 = 0.00
        $bias = $this->service->calculateBias($actuals, $preds);
        $this->assertEquals(0.0, $bias);
    }

    public function test_02_metrics_handle_zero_sum_actuals_safely(): void
    {
        $actuals = [0.0, 0.0, 0.0];
        $predsExact = [0.0, 0.0, 0.0];
        $predsNonZero = [1.0, 2.0, 0.0];

        $this->assertEquals(0.0, $this->service->calculateWape($actuals, $predsExact));
        $this->assertNull($this->service->calculateWape($actuals, $predsNonZero));
        $this->assertEquals(0.0, $this->service->calculateBias($actuals, $predsExact));
        $this->assertNull($this->service->calculateBias($actuals, $predsNonZero));
    }

    public function test_03_relative_wape_improvement_calculation(): void
    {
        $baseWape = 0.20; // 20%
        $candWape = 0.18; // 18% -> 10% relative improvement
        $worseWape = 0.22; // 22% -> -10% relative deterioration

        $relImprovement = $this->service->calculateRelativeWapeImprovement($baseWape, $candWape);
        $this->assertEquals(0.10, $relImprovement);

        $relWorse = $this->service->calculateRelativeWapeImprovement($baseWape, $worseWape);
        $this->assertEquals(-0.10, $relWorse);
    }

    public function test_04_dow_median_and_wma14_baseline_algorithms(): void
    {
        // Build 21-day synthetic history
        $historySeries = [];
        $startDate = Carbon::parse('2026-09-01', 'Asia/Jakarta');

        for ($i = 0; $i < 21; $i++) {
            $date = $startDate->copy()->addDays($i);
            // Every Friday has value 50.0, others 20.0
            $dow = (int) $date->dayOfWeekIso;
            $val = ($dow === 5) ? 50.0 : 20.0;

            $historySeries[] = [
                'date' => $date->toDateString(),
                'value' => $val,
                'day_of_week' => $dow,
            ];
        }

        // Target: next Friday (2026-09-25 is Friday)
        $targetFriday = '2026-09-25';
        $targetMonday = '2026-09-28';

        $predFriday = $this->service->predictDowMedian($historySeries, $targetFriday);
        $this->assertEquals(50.0, $predFriday);

        $predMonday = $this->service->predictDowMedian($historySeries, $targetMonday);
        $this->assertEquals(20.0, $predMonday);

        // WMA-14 calculation
        $wma = $this->service->predictWma($historySeries, 14);
        $this->assertGreaterThan(0.0, $wma);
    }

    public function test_05_feature_extraction_prevents_target_leakage(): void
    {
        $startDate = Carbon::parse('2026-08-01', 'Asia/Jakarta');
        $historySeries = [];

        for ($i = 0; $i < 30; $i++) {
            $date = $startDate->copy()->addDays($i);
            $historySeries[] = [
                'date' => $date->toDateString(),
                'value' => 10.0 + $i,
                'day_of_week' => (int) $date->dayOfWeekIso,
            ];
        }

        $cutoffDate = '2026-08-30';
        $targetDate = '2026-08-31';

        $extracted = $this->service->extractFeaturesForBacktest($historySeries, $targetDate, $this->outlet->id, false);
        $features = $extracted['features'];

        // Lag 1 must be history[29] (value = 10 + 29 = 39.0)
        $this->assertEquals(39.0, $features['f_lag_1']);
        $this->assertEquals(38.0, $features['f_lag_2']);
        $this->assertArrayHasKey('f_roll_mean_7', $features);
        $this->assertArrayHasKey('f_dow_median', $features);
        $this->assertArrayHasKey('f_target_dow', $features);
    }

    public function test_06_weather_feature_status_flags_reanalysis_vs_forecast(): void
    {
        $targetDate = '2026-10-04';

        // 1. Reanalysis / Actual record (is_forecast = false)
        OutletWeatherForecast::create([
            'outlet_id' => $this->outlet->id,
            'source' => 'OPEN_METEO_HISTORICAL_ARCHIVE',
            'forecast_published_at' => Carbon::parse('2026-10-04 00:00:00'),
            'retrieved_at' => Carbon::parse('2026-10-04 01:00:00'),
            'forecast_for_timestamp' => Carbon::parse('2026-10-04 12:00:00'),
            'is_forecast' => false,
            'latitude' => -7.150975,
            'longitude' => 110.140259,
            'temperature_c' => 29.5,
            'precipitation_mm' => 5.2,
            'precipitation_probability_pct' => 80.0,
            'weather_code' => 61,
        ]);

        $historySeries = [
            ['date' => '2026-10-03', 'value' => 25.0, 'day_of_week' => 6],
        ];

        $extracted = $this->service->extractFeaturesForBacktest($historySeries, $targetDate, $this->outlet->id, true);
        $this->assertEquals('REANALYSIS_ARCHIVE_PROXY_LEAKAGE_RISK', $extracted['weather_status']);
        $this->assertEquals(29.5, $extracted['features']['w_temp_mean_c']);
        $this->assertEquals(5.2, $extracted['features']['w_precipitation_mm']);
        $this->assertEquals(1.0, $extracted['features']['w_is_rainy']);
    }

    public function test_07_rolling_origin_backtest_execution_and_insufficient_history_gate(): void
    {
        // 1. Insufficient days -> must return BLOCKED_BY_DATA_GAPS
        $shortSeries = [
            ['date' => '2026-09-01', 'value' => 10.0, 'day_of_week' => 2],
            ['date' => '2026-09-02', 'value' => 12.0, 'day_of_week' => 3],
        ];

        $blockedResult = $this->service->executeRollingOriginBacktest($shortSeries, 28, [1, 3, 7], $this->outlet->id);
        $this->assertEquals('BLOCKED_BY_DATA_GAPS', $blockedResult['status']);

        // 2. Full 50-day series -> must complete expanding-window validation
        $fullSeries = [];
        $startDate = Carbon::parse('2026-08-01', 'Asia/Jakarta');

        for ($i = 0; $i < 50; $i++) {
            $date = $startDate->copy()->addDays($i);
            $dow = (int) $date->dayOfWeekIso;
            $baseVal = 20.0 + ($dow * 2.0); // weekend higher demand
            $fullSeries[] = [
                'date' => $date->toDateString(),
                'value' => $baseVal,
                'day_of_week' => $dow,
            ];
        }

        $completedResult = $this->service->executeRollingOriginBacktest($fullSeries, 28, [1, 3, 7], $this->outlet->id);
        $this->assertEquals('COMPLETED', $completedResult['status']);
        $this->assertArrayHasKey(1, $completedResult['horizons_summary']);
        $this->assertArrayHasKey(3, $completedResult['horizons_summary']);
        $this->assertArrayHasKey(7, $completedResult['horizons_summary']);

        $h1Summary = $completedResult['horizons_summary'][1];
        $this->assertNotNull($h1Summary['models']['dow_median']['wape']);
        $this->assertNotNull($h1Summary['models']['wma14']['wape']);
        $this->assertNotNull($h1Summary['models']['ml_no_weather']['wape']);
        $this->assertArrayHasKey('relative_improvements', $h1Summary);
    }

    public function test_08_vintage_available_before_origin_is_eligible_for_pit_retrieval(): void
    {
        $targetDate = '2026-10-10';
        $forecastOrigin = '2026-10-08'; // Origin T-2

        // Vintage retrieved at 2026-10-07 06:00:00 (before origin)
        OutletWeatherForecast::create([
            'outlet_id' => $this->outlet->id,
            'source' => 'open_meteo',
            'retrieved_at' => Carbon::parse('2026-10-07 06:00:00', 'UTC'),
            'forecast_for_timestamp' => Carbon::parse('2026-10-10 12:00:00', 'Asia/Jakarta')->setTimezone('UTC'),
            'is_forecast' => true,
            'latitude' => -7.150975,
            'longitude' => 110.140259,
            'temperature_c' => 30.0,
            'precipitation_mm' => 10.0,
            'precipitation_probability_pct' => 90.0,
            'weather_code' => 61,
        ]);

        $weather = $this->service->fetchTargetWeather($this->outlet->id, $targetDate, $forecastOrigin);

        $this->assertEquals('POINT_IN_TIME_FORECAST_VALID', $weather['status']);
        $this->assertEquals(30.0, $weather['temp_mean']);
        $this->assertEquals(10.0, $weather['precip_sum']);
        $this->assertEquals(1.0, $weather['is_rainy']);
    }

    public function test_09_vintage_retrieved_after_origin_is_strictly_rejected(): void
    {
        $targetDate = '2026-10-10';
        $forecastOrigin = '2026-10-08'; // Cutoff at 2026-10-08 23:59:59 WIB

        // Vintage retrieved on 2026-10-09 06:00:00 UTC (after origin!)
        OutletWeatherForecast::create([
            'outlet_id' => $this->outlet->id,
            'source' => 'open_meteo',
            'retrieved_at' => Carbon::parse('2026-10-09 06:00:00', 'UTC'),
            'forecast_for_timestamp' => Carbon::parse('2026-10-10 12:00:00', 'Asia/Jakarta')->setTimezone('UTC'),
            'is_forecast' => true,
            'latitude' => -7.150975,
            'longitude' => 110.140259,
            'temperature_c' => 32.0,
            'precipitation_mm' => 0.0,
            'precipitation_probability_pct' => 10.0,
            'weather_code' => 0,
        ]);

        $weather = $this->service->fetchTargetWeather($this->outlet->id, $targetDate, $forecastOrigin);

        // Must reject future vintage and return missing status
        $this->assertEquals('WEATHER_DATA_MISSING_VINTAGE_UNAVAILABLE', $weather['status']);
        $this->assertEquals(28.0, $weather['temp_mean']); // default fallback
        $this->assertEquals(0.0, $weather['precip_sum']);
    }

    public function test_10_newer_vintage_does_not_replace_older_vintage_in_point_in_time_query(): void
    {
        $targetDate = '2026-10-10';
        $targetTimestampUtc = Carbon::parse('2026-10-10 12:00:00', 'Asia/Jakarta')->setTimezone('UTC');

        // Vintage 1 (retrieved 2026-10-06 at origin T-4): Rain predicted
        OutletWeatherForecast::create([
            'outlet_id' => $this->outlet->id,
            'source' => 'open_meteo',
            'retrieved_at' => Carbon::parse('2026-10-06 06:00:00', 'UTC'),
            'forecast_for_timestamp' => $targetTimestampUtc,
            'is_forecast' => true,
            'latitude' => -7.150975,
            'longitude' => 110.140259,
            'temperature_c' => 27.0,
            'precipitation_mm' => 15.0,
            'precipitation_probability_pct' => 95.0,
            'weather_code' => 65,
        ]);

        // Vintage 2 (retrieved 2026-10-09 at origin T-1): Clear weather revised
        OutletWeatherForecast::create([
            'outlet_id' => $this->outlet->id,
            'source' => 'open_meteo',
            'retrieved_at' => Carbon::parse('2026-10-09 06:00:00', 'UTC'),
            'forecast_for_timestamp' => $targetTimestampUtc,
            'is_forecast' => true,
            'latitude' => -7.150975,
            'longitude' => 110.140259,
            'temperature_c' => 34.0,
            'precipitation_mm' => 0.0,
            'precipitation_probability_pct' => 5.0,
            'weather_code' => 0,
        ]);

        // When evaluating as of 2026-10-07 (T-3), only Vintage 1 must be picked!
        $weatherAtT3 = $this->service->fetchTargetWeather($this->outlet->id, $targetDate, '2026-10-07');
        $this->assertEquals('POINT_IN_TIME_FORECAST_VALID', $weatherAtT3['status']);
        $this->assertEquals(27.0, $weatherAtT3['temp_mean']);
        $this->assertEquals(15.0, $weatherAtT3['precip_sum']);

        // When evaluating as of 2026-10-09 (T-1), Vintage 2 (the latest available as of T-1) must be picked!
        $weatherAtT1 = $this->service->fetchTargetWeather($this->outlet->id, $targetDate, '2026-10-09');
        $this->assertEquals('POINT_IN_TIME_FORECAST_VALID', $weatherAtT1['status']);
        $this->assertEquals(34.0, $weatherAtT1['temp_mean']);
        $this->assertEquals(0.0, $weatherAtT1['precip_sum']);
    }

    public function test_11_forecast_targeting_wrong_interval_is_rejected(): void
    {
        $targetDate = '2026-10-10';
        $forecastOrigin = '2026-10-08';

        // Forecast targeting 2026-10-12 (wrong date)
        OutletWeatherForecast::create([
            'outlet_id' => $this->outlet->id,
            'source' => 'open_meteo',
            'retrieved_at' => Carbon::parse('2026-10-07 06:00:00', 'UTC'),
            'forecast_for_timestamp' => Carbon::parse('2026-10-12 12:00:00', 'Asia/Jakarta')->setTimezone('UTC'),
            'is_forecast' => true,
            'latitude' => -7.150975,
            'longitude' => 110.140259,
            'temperature_c' => 25.0,
            'precipitation_mm' => 20.0,
            'precipitation_probability_pct' => 90.0,
            'weather_code' => 65,
        ]);

        $weather = $this->service->fetchTargetWeather($this->outlet->id, $targetDate, $forecastOrigin);
        $this->assertEquals('WEATHER_DATA_MISSING_VINTAGE_UNAVAILABLE', $weather['status']);
    }

    public function test_12_missing_eligible_forecast_remains_missing_without_reanalysis_substitution(): void
    {
        $targetDate = '2026-10-10';
        $forecastOrigin = '2026-10-08';

        // Ground truth observation (is_forecast = false) is present in DB
        OutletWeatherForecast::create([
            'outlet_id' => $this->outlet->id,
            'source' => 'open_meteo_archive',
            'retrieved_at' => Carbon::parse('2026-10-07 06:00:00', 'UTC'),
            'forecast_for_timestamp' => Carbon::parse('2026-10-10 12:00:00', 'Asia/Jakarta')->setTimezone('UTC'),
            'is_forecast' => false, // REALIZED WEATHER / REANALYSIS
            'latitude' => -7.150975,
            'longitude' => 110.140259,
            'temperature_c' => 22.0,
            'precipitation_mm' => 50.0,
            'precipitation_probability_pct' => 100.0,
            'weather_code' => 65,
        ]);

        $weather = $this->service->fetchTargetWeather($this->outlet->id, $targetDate, $forecastOrigin);

        // Strict PIT must NOT silently substitute reanalysis when forecasting
        $this->assertEquals('WEATHER_DATA_MISSING_VINTAGE_UNAVAILABLE', $weather['status']);
        $this->assertEquals(28.0, $weather['temp_mean']); // Default safe fallback
    }

    public function test_13_outlet_and_tenant_isolation_strictly_enforced_in_weather_retrieval(): void
    {
        $targetDate = '2026-10-10';
        $forecastOrigin = '2026-10-08';

        // Create second outlet belonging to another tenant/outlet
        $otherOutlet = \App\Models\Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Outlet Cabang Lain',
            'address' => 'Jl. Lain',
            'phone' => '081234567899',
            'is_active' => true,
            'latitude' => -6.200000,
            'longitude' => 106.816666,
        ]);

        // Weather saved ONLY for other outlet
        OutletWeatherForecast::create([
            'outlet_id' => $otherOutlet->id,
            'source' => 'open_meteo',
            'retrieved_at' => Carbon::parse('2026-10-07 06:00:00', 'UTC'),
            'forecast_for_timestamp' => Carbon::parse('2026-10-10 12:00:00', 'Asia/Jakarta')->setTimezone('UTC'),
            'is_forecast' => true,
            'latitude' => -6.200000,
            'longitude' => 106.816666,
            'temperature_c' => 35.0,
            'precipitation_mm' => 0.0,
            'precipitation_probability_pct' => 0.0,
            'weather_code' => 0,
        ]);

        // Querying for $this->outlet must find NOTHING
        $weatherThisOutlet = $this->service->fetchTargetWeather($this->outlet->id, $targetDate, $forecastOrigin);
        $this->assertEquals('WEATHER_DATA_MISSING_VINTAGE_UNAVAILABLE', $weatherThisOutlet['status']);

        // Querying for $otherOutlet must find its own data
        $weatherOtherOutlet = $this->service->fetchTargetWeather($otherOutlet->id, $targetDate, $forecastOrigin);
        $this->assertEquals('POINT_IN_TIME_FORECAST_VALID', $weatherOtherOutlet['status']);
        $this->assertEquals(35.0, $weatherOtherOutlet['temp_mean']);
    }

    public function test_14_utc_and_wib_boundary_cases_behave_correctly(): void
    {
        // Target date 2026-10-10 in WIB covers:
        // WIB: 2026-10-10 00:00:00 to 2026-10-10 23:59:59
        // UTC: 2026-10-09 17:00:00 to 2026-10-10 16:59:59
        $targetDate = '2026-10-10';
        $forecastOrigin = '2026-10-08';

        // 1. Slice at 2026-10-10 01:00:00 WIB (= 2026-10-09 18:00:00 UTC) -> INCLUDED in target day
        OutletWeatherForecast::create([
            'outlet_id' => $this->outlet->id,
            'source' => 'open_meteo',
            'retrieved_at' => Carbon::parse('2026-10-07 06:00:00', 'UTC'),
            'forecast_for_timestamp' => Carbon::parse('2026-10-09 18:00:00', 'UTC'),
            'is_forecast' => true,
            'latitude' => -7.150975,
            'longitude' => 110.140259,
            'temperature_c' => 26.0,
            'precipitation_mm' => 4.0,
            'precipitation_probability_pct' => 70.0,
            'weather_code' => 61,
        ]);

        // 2. Slice at 2026-10-10 23:00:00 WIB (= 2026-10-10 16:00:00 UTC) -> INCLUDED in target day
        OutletWeatherForecast::create([
            'outlet_id' => $this->outlet->id,
            'source' => 'open_meteo',
            'retrieved_at' => Carbon::parse('2026-10-07 06:00:00', 'UTC'),
            'forecast_for_timestamp' => Carbon::parse('2026-10-10 16:00:00', 'UTC'),
            'is_forecast' => true,
            'latitude' => -7.150975,
            'longitude' => 110.140259,
            'temperature_c' => 30.0,
            'precipitation_mm' => 6.0,
            'precipitation_probability_pct' => 80.0,
            'weather_code' => 61,
        ]);

        // 3. Slice at 2026-10-11 00:30:00 WIB (= 2026-10-10 17:30:00 UTC) -> EXCLUDED (belongs to next day)
        OutletWeatherForecast::create([
            'outlet_id' => $this->outlet->id,
            'source' => 'open_meteo',
            'retrieved_at' => Carbon::parse('2026-10-07 06:00:00', 'UTC'),
            'forecast_for_timestamp' => Carbon::parse('2026-10-10 17:30:00', 'UTC'),
            'is_forecast' => true,
            'latitude' => -7.150975,
            'longitude' => 110.140259,
            'temperature_c' => 20.0,
            'precipitation_mm' => 100.0, // High outlier, must NOT leak into 2026-10-10
            'precipitation_probability_pct' => 100.0,
            'weather_code' => 65,
        ]);

        $weather = $this->service->fetchTargetWeather($this->outlet->id, $targetDate, $forecastOrigin);

        $this->assertEquals('POINT_IN_TIME_FORECAST_VALID', $weather['status']);
        // Mean temp = (26.0 + 30.0) / 2 = 28.0
        $this->assertEquals(28.0, $weather['temp_mean']);
        // Precip sum = 4.0 + 6.0 = 10.0 (excludes the 100.0 from next day)
        $this->assertEquals(10.0, $weather['precip_sum']);
    }
}
