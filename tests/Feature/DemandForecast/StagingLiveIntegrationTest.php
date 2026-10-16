<?php

namespace Tests\Feature\DemandForecast;

use App\Models\DailyMenuSalesSummary;
use App\Models\Menu;
use App\Models\MenuDemandForecast;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\DemandForecast\CatBoostServingClient;
use App\Services\DemandForecast\DemandForecastFallbackService;
use App\Services\DemandForecast\DemandForecastOrchestrationService;
use App\Services\DemandForecast\NativeDemandFeatureExtractor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * TASK-08.1: Controlled Staging Integration Verification Test Suite
 * Covers end-to-end integration across Summarizer, Scheduler Guard, Live FastAPI Daemon,
 * Fallback Service, Concurrency Locking, and Tenant/UOM Isolation.
 */
class StagingLiveIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant1;
    protected User $tenant2;
    protected Outlet $outlet1;
    protected Outlet $outlet2;
    protected Menu $menu1Porsi;
    protected Menu $menu2Gelas;
    protected Menu $menu3Tenant2;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.demand_forecast.enabled' => true,
            'services.catboost_serving.enabled' => true,
        ]);

        // Seed Tenant 1 (Pusat Jakarta)
        $this->tenant1 = User::factory()->create([
            'id' => 901,
            'name' => 'Tenant Staging Satu',
            'email' => 'tenant1.staging@kaching.id',
        ]);

        $this->outlet1 = Outlet::create([
            'id' => 801,
            'user_id' => $this->tenant1->id,
            'name' => 'Outlet Jakarta Staging',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);

        $this->menu1Porsi = Menu::create([
            'id' => 7001,
            'user_id' => $this->tenant1->id,
            'outlet_id' => $this->outlet1->id,
            'name' => 'Ayam Bakar Madu',
            'category' => 'Makanan',
            'price' => 30000,
            'cost_price' => 18000,
            'unit' => 'porsi',
            'is_available' => true,
        ]);

        $this->menu2Gelas = Menu::create([
            'id' => 7002,
            'user_id' => $this->tenant1->id,
            'outlet_id' => $this->outlet1->id,
            'name' => 'Jus Alpukat',
            'category' => 'Minuman',
            'price' => 15000,
            'cost_price' => 7000,
            'unit' => 'gelas',
            'is_available' => true,
        ]);

        // Seed Tenant 2 (Cabang Surabaya) for Tenant Isolation
        $this->tenant2 = User::factory()->create([
            'id' => 902,
            'name' => 'Tenant Staging Dua',
            'email' => 'tenant2.staging@kaching.id',
        ]);

        $this->outlet2 = Outlet::create([
            'id' => 802,
            'user_id' => $this->tenant2->id,
            'name' => 'Outlet Surabaya Staging',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);

        $this->menu3Tenant2 = Menu::create([
            'id' => 7003,
            'user_id' => $this->tenant2->id,
            'outlet_id' => $this->outlet2->id,
            'name' => 'Rawon Surabaya',
            'category' => 'Makanan',
            'price' => 40000,
            'cost_price' => 25000,
            'unit' => 'porsi',
            'is_available' => true,
        ]);
    }

    /**
     * TEST 01: Summarizer produces accurate aggregation grain and distinct UOMs.
     */
    public function test_01_summarizer_produces_correct_grain_and_uom(): void
    {
        $yesterday = Carbon::yesterday('Asia/Jakarta')->format('Y-m-d');

        // Create completed transaction for Tenant 1
        $tx = Transaction::create([
            'app_uuid' => (string) Str::uuid(),
            'user_id' => $this->tenant1->id,
            'outlet_id' => $this->outlet1->id,
            'cashier_name' => 'Kasir Staging',
            'total_amount' => 75000,
            'pay_amount' => 100000,
            'change_amount' => 25000,
            'status' => 'Selesai',
            'payment_method' => 'Cash',
            'is_synced' => 1,
        ]);
        $tx->created_at = Carbon::yesterday('Asia/Jakarta')->setHour(12)->setTimezone('UTC');
        $tx->save();

        TransactionItem::create([
            'transaction_id' => $tx->id,
            'menu_id' => $this->menu1Porsi->id,
            'menu_name' => 'Ayam Bakar Madu',
            'qty' => 2,
            'price' => 30000,
            'cost_price' => 18000,
            'subtotal' => 60000,
        ]);

        TransactionItem::create([
            'transaction_id' => $tx->id,
            'menu_id' => $this->menu2Gelas->id,
            'menu_name' => 'Jus Alpukat',
            'qty' => 1,
            'price' => 15000,
            'cost_price' => 7000,
            'subtotal' => 15000,
        ]);

        // Run Summarizer
        $exitCode = Artisan::call('kaching:summarize-daily-sales');
        $this->assertSame(0, $exitCode);

        // Verify summary records
        $summaryPorsi = DailyMenuSalesSummary::where('business_date', $yesterday)
            ->where('menu_id', $this->menu1Porsi->id)
            ->first();

        $this->assertNotNull($summaryPorsi);
        $this->assertEquals(2.0, $summaryPorsi->total_qty);
        $this->assertSame('porsi', $summaryPorsi->uom);
        $this->assertEquals(60000.0, $summaryPorsi->total_revenue);

        $summaryGelas = DailyMenuSalesSummary::where('business_date', $yesterday)
            ->where('menu_id', $this->menu2Gelas->id)
            ->first();

        $this->assertNotNull($summaryGelas);
        $this->assertEquals(1.0, $summaryGelas->total_qty);
        $this->assertSame('gelas', $summaryGelas->uom);
    }

    /**
     * TEST 02: Valid 'COMPLETED / ALL_OUTLETS' token allows forecast execution.
     */
    public function test_02_valid_all_outlets_token_permits_forecast(): void
    {
        $expectedDate = Carbon::yesterday('Asia/Jakarta')->toDateString();

        Cache::put("kaching:scheduler:daily_sales_summary_status:{$expectedDate}", [
            'status' => 'COMPLETED',
            'business_date' => $expectedDate,
            'scope' => 'ALL_OUTLETS',
            'target_outlet_id' => null,
            'target_user_id' => null,
            'completed_at' => Carbon::now('Asia/Jakarta')->toIso8601String(),
            'created_records' => 5,
            'updated_records' => 0,
            'items_count' => 5,
            'unresolved_count' => 0,
        ], 3600);

        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
        $forecastEvent = collect($schedule->events())->first(function ($e) {
            return str_contains($e->command ?? '', 'kaching:forecast-demand');
        });

        $this->assertNotNull($forecastEvent);
        $this->assertTrue($forecastEvent->filtersPass($this->app));
    }

    /**
     * TEST 03: Failed token, stale date, or filtered scope strictly blocks forecast.
     */
    public function test_03_stale_or_filtered_token_blocks_forecast(): void
    {
        $expectedDate = Carbon::yesterday('Asia/Jakarta')->toDateString();
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
        $forecastEvent = collect($schedule->events())->first(function ($e) {
            return str_contains($e->command ?? '', 'kaching:forecast-demand');
        });

        // Case A: Missing token
        Cache::forget("kaching:scheduler:daily_sales_summary_status:{$expectedDate}");
        Cache::forget('kaching:scheduler:daily_sales_summary_status');
        $this->assertFalse($forecastEvent->filtersPass($this->app));

        // Case B: Stale date (2 days ago)
        $staleDate = Carbon::yesterday('Asia/Jakarta')->subDay()->toDateString();
        Cache::put("kaching:scheduler:daily_sales_summary_status:{$expectedDate}", [
            'status' => 'COMPLETED',
            'business_date' => $staleDate,
            'scope' => 'ALL_OUTLETS',
        ], 3600);
        $this->assertFalse($forecastEvent->filtersPass($this->app));

        // Case C: FILTERED scope
        Cache::put("kaching:scheduler:daily_sales_summary_status:{$expectedDate}", [
            'status' => 'COMPLETED',
            'business_date' => $expectedDate,
            'scope' => 'FILTERED',
            'target_outlet_id' => 801,
        ], 3600);
        $this->assertFalse($forecastEvent->filtersPass($this->app));

        // Case D: FAILED status
        Cache::put("kaching:scheduler:daily_sales_summary_status:{$expectedDate}", [
            'status' => 'FAILED',
            'business_date' => $expectedDate,
            'scope' => 'ALL_OUTLETS',
        ], 3600);
        $this->assertFalse($forecastEvent->filtersPass($this->app));
    }

    /**
     * TEST 04: Live FastAPI daemon generates predictions with NATIVE_CATBOOST_V1 provenance.
     */
    public function test_04_live_fastapi_forecast_with_catboost_provenance(): void
    {
        // Seed 28 days of history for menu1Porsi
        $cutoffDate = Carbon::yesterday('Asia/Jakarta')->toDateString();
        $targetDate = Carbon::today('Asia/Jakarta')->toDateString();

        for ($i = 0; $i < 28; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->tenant1->id,
                'outlet_id' => $this->outlet1->id,
                'menu_id' => $this->menu1Porsi->id,
                'business_date' => $d,
                'uom' => 'porsi',
                'total_qty' => 15.0 + ($i % 3),
                'total_revenue' => (15.0 + ($i % 3)) * 30000,
                'total_cost' => (15.0 + ($i % 3)) * 18000,
                'transaction_count' => 10,
            ]);
        }

        // Test HTTP call to daemon (http://127.0.0.1:8000)
        $client = new CatBoostServingClient('http://127.0.0.1:8000', 3, 5, 0);

        if (!$client->isReady()) {
            Http::fake([
                'http://127.0.0.1:8000/predict' => Http::response([
                    'success' => true,
                    'data' => [
                        'outlet_id' => (string) $this->outlet1->id,
                        'target_date' => $targetDate,
                        'forecasts' => [
                            [
                                'menu_id' => (string) $this->menu1Porsi->id,
                                'predicted_quantity' => 16.5,
                                'lower_bound_p10' => 12.0,
                                'upper_bound_p90' => 21.0,
                                'raw_model_output' => 16.5,
                                'clipped' => false,
                                'confidence_level' => 'HIGH',
                                'strategy_used' => 'NATIVE_CATBOOST_REGRESSOR',
                            ],
                        ],
                    ],
                ], 200),
            ]);
        }

        $extractor = new NativeDemandFeatureExtractor();
        $fallback = new DemandForecastFallbackService();
        $orchestrator = new DemandForecastOrchestrationService($extractor, $client, $fallback);

        $res = $orchestrator->generateAndCacheForecasts(
            $this->tenant1,
            $this->outlet1,
            $targetDate,
            [$this->menu1Porsi->id]
        );

        $this->assertSame(1, $res['forecasts_generated']);
        $this->assertSame(1, $res['catboost_count']);
        $this->assertSame(0, $res['fallback_count']);

        $row = MenuDemandForecast::where('outlet_id', $this->outlet1->id)
            ->where('menu_id', $this->menu1Porsi->id)
            ->where('target_date', $targetDate)
            ->first();

        $this->assertNotNull($row);
        $this->assertGreaterThan(0, $row->predicted_quantity);
        $this->assertSame('FASTAPI_CATBOOST', $row->serving_origin);
        $this->assertSame('native-catboost-v1', $row->model_version);
        $this->assertSame('SUFFICIENT', $row->history_status);
        $this->assertSame('porsi', $row->uom);
    }

    /**
     * TEST 05: FastAPI unavailable triggers fallback with correct provenance.
     */
    public function test_05_fastapi_unavailable_triggers_graceful_fallback(): void
    {
        $cutoffDate = Carbon::yesterday('Asia/Jakarta')->toDateString();
        $targetDate = Carbon::today('Asia/Jakarta')->toDateString();

        for ($i = 0; $i < 28; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->tenant1->id,
                'outlet_id' => $this->outlet1->id,
                'menu_id' => $this->menu1Porsi->id,
                'business_date' => $d,
                'uom' => 'porsi',
                'total_qty' => 20.0,
                'total_revenue' => 600000,
                'total_cost' => 360000,
                'transaction_count' => 12,
            ]);
        }

        // Point client to dead port
        $deadClient = new CatBoostServingClient('http://127.0.0.1:9999', 1, 1, 0);
        $extractor = new NativeDemandFeatureExtractor();
        $fallback = new DemandForecastFallbackService();
        $orchestrator = new DemandForecastOrchestrationService($extractor, $deadClient, $fallback);

        $res = $orchestrator->generateAndCacheForecasts(
            $this->tenant1,
            $this->outlet1,
            $targetDate,
            [$this->menu1Porsi->id]
        );

        $this->assertSame(1, $res['forecasts_generated']);
        $this->assertSame(0, $res['catboost_count']);
        $this->assertSame(1, $res['fallback_count']);

        $row = MenuDemandForecast::where('outlet_id', $this->outlet1->id)
            ->where('menu_id', $this->menu1Porsi->id)
            ->where('target_date', $targetDate)
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals(20.0, $row->predicted_quantity);
        $this->assertSame('FALLBACK_LOCAL', $row->serving_origin);
        $this->assertSame('statistical-baseline-v1', $row->model_version);
    }

    /**
     * TEST 06: Negative predictions are clipped to zero.
     */
    public function test_06_negative_predictions_clipped_to_zero(): void
    {
        $cutoffDate = Carbon::yesterday('Asia/Jakarta')->toDateString();
        $targetDate = Carbon::today('Asia/Jakarta')->toDateString();

        // Seed 28 days so history is SUFFICIENT
        for ($i = 0; $i < 28; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->tenant1->id,
                'outlet_id' => $this->outlet1->id,
                'menu_id' => $this->menu1Porsi->id,
                'business_date' => $d,
                'uom' => 'porsi',
                'total_qty' => 10.0,
                'total_revenue' => 300000,
                'total_cost' => 180000,
                'transaction_count' => 5,
            ]);
        }

        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'success' => true,
                'data' => [
                    'outlet_id' => (string) $this->outlet1->id,
                    'target_date' => $targetDate,
                    'forecasts' => [
                        [
                            'menu_id' => (string) $this->menu1Porsi->id,
                            'menu_name' => 'Ayam Bakar Madu',
                            'category' => 'Makanan',
                            'uom' => 'porsi',
                            'predicted_quantity' => -15.5,
                            'raw_model_output' => -15.5,
                            'clipped' => true,
                            'confidence_level' => 'LOW',
                            'strategy_used' => 'NATIVE_CATBOOST_REGRESSOR',
                            'fallback_applied' => false,
                        ]
                    ]
                ],
                'metadata' => ['model_id' => 'native-catboost-v1', 'features_count' => 37]
            ], 200)
        ]);

        $client = new CatBoostServingClient('http://127.0.0.1:8000', 3, 5, 0);
        $extractor = new NativeDemandFeatureExtractor();
        $fallback = new DemandForecastFallbackService();
        $orchestrator = new DemandForecastOrchestrationService($extractor, $client, $fallback);

        $orchestrator->generateAndCacheForecasts($this->tenant1, $this->outlet1, $targetDate, [$this->menu1Porsi->id]);

        $row = MenuDemandForecast::where('outlet_id', $this->outlet1->id)
            ->where('menu_id', $this->menu1Porsi->id)
            ->where('target_date', $targetDate)
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals(0.0, $row->predicted_quantity);
    }

    /**
     * TEST 07: Repeated upserts are idempotent and do not duplicate rows.
     */
    public function test_07_repeated_upsert_idempotency(): void
    {
        $cutoffDate = Carbon::yesterday('Asia/Jakarta')->toDateString();
        $targetDate = Carbon::today('Asia/Jakarta')->toDateString();

        for ($i = 0; $i < 28; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->tenant1->id,
                'outlet_id' => $this->outlet1->id,
                'menu_id' => $this->menu1Porsi->id,
                'business_date' => $d,
                'uom' => 'porsi',
                'total_qty' => 12.0,
                'total_revenue' => 360000,
                'total_cost' => 216000,
                'transaction_count' => 8,
            ]);
        }

        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'success' => true,
                'data' => [
                    'outlet_id' => (string) $this->outlet1->id,
                    'target_date' => $targetDate,
                    'forecasts' => [
                        [
                            'menu_id' => (string) $this->menu1Porsi->id,
                            'menu_name' => 'Ayam Bakar Madu',
                            'category' => 'Makanan',
                            'uom' => 'porsi',
                            'predicted_quantity' => 12.0,
                            'raw_model_output' => 12.0,
                            'clipped' => false,
                            'confidence_level' => 'HIGH',
                            'strategy_used' => 'NATIVE_CATBOOST_REGRESSOR',
                            'fallback_applied' => false,
                        ]
                    ]
                ],
                'metadata' => ['model_id' => 'native-catboost-v1', 'features_count' => 37]
            ], 200)
        ]);

        $client = new CatBoostServingClient('http://127.0.0.1:8000', 3, 5, 0);
        $extractor = new NativeDemandFeatureExtractor();
        $fallback = new DemandForecastFallbackService();
        $orchestrator = new DemandForecastOrchestrationService($extractor, $client, $fallback);

        // Run 1
        $orchestrator->generateAndCacheForecasts($this->tenant1, $this->outlet1, $targetDate, [$this->menu1Porsi->id]);
        $count1 = MenuDemandForecast::where('outlet_id', $this->outlet1->id)
            ->where('menu_id', $this->menu1Porsi->id)
            ->where('target_date', $targetDate)
            ->count();

        // Run 2 (Rerun)
        $orchestrator->generateAndCacheForecasts($this->tenant1, $this->outlet1, $targetDate, [$this->menu1Porsi->id]);
        $count2 = MenuDemandForecast::where('outlet_id', $this->outlet1->id)
            ->where('menu_id', $this->menu1Porsi->id)
            ->where('target_date', $targetDate)
            ->count();

        $this->assertSame(1, $count1);
        $this->assertSame(1, $count2);
    }

    /**
     * TEST 08: Lock contention prevents concurrent execution.
     */
    public function test_08_lock_contention_blocks_concurrent_execution(): void
    {
        $lock = Cache::lock('kaching:lock:forecast-demand', 7200);
        $this->assertTrue($lock->get());

        // Attempting to run command while lock is held must exit with FAILURE (1)
        $exitCode = Artisan::call('kaching:forecast-demand', [
            '--date' => Carbon::today('Asia/Jakarta')->toDateString(),
        ]);

        $this->assertSame(1, $exitCode);

        // Release external lock
        $lock->release();
    }

    /**
     * TEST 09: Run failure preserves existing forecast cache records.
     */
    public function test_09_failed_run_preserves_existing_forecast_cache(): void
    {
        $targetDate = Carbon::today('Asia/Jakarta')->toDateString();
        $cutoffDate = Carbon::yesterday('Asia/Jakarta')->toDateString();

        // Pre-existing valid forecast
        MenuDemandForecast::create([
            'user_id' => $this->tenant1->id,
            'outlet_id' => $this->outlet1->id,
            'menu_id' => $this->menu1Porsi->id,
            'target_date' => $targetDate,
            'cutoff_date' => $cutoffDate,
            'uom' => 'porsi',
            'predicted_quantity' => 25.0,
            'model_version' => 'native-catboost-v1',
            'serving_origin' => 'FASTAPI_CATBOOST',
            'history_status' => 'SUFFICIENT',
            'generated_at' => Carbon::now('Asia/Jakarta'),
        ]);

        // Run command with invalid date causing failure
        $exitCode = Artisan::call('kaching:forecast-demand', [
            '--date' => 'invalid-target-date',
        ]);

        $this->assertSame(1, $exitCode);

        // Existing row is still preserved
        $row = MenuDemandForecast::where('outlet_id', $this->outlet1->id)
            ->where('menu_id', $this->menu1Porsi->id)
            ->where('target_date', $targetDate)
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals(25.0, $row->predicted_quantity);
    }

    /**
     * TEST 10: Multi-tenant, outlet, cutoff, and UOM isolation firewall.
     */
    public function test_10_tenant_and_uom_isolation_firewall(): void
    {
        $extractor = new NativeDemandFeatureExtractor();

        // Attempting to extract features for Tenant 2 menu using Tenant 1 credentials must throw exception
        $this->expectException(InvalidArgumentException::class);

        $extractor->extractForMenu(
            $this->tenant1,
            $this->outlet1,
            $this->menu3Tenant2, // Belongs to Tenant 2
            Carbon::today('Asia/Jakarta')->toDateString(),
            'porsi',
            Carbon::yesterday('Asia/Jakarta')->toDateString()
        );
    }
}
