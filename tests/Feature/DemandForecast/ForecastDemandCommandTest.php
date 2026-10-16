<?php

namespace Tests\Feature\DemandForecast;

use App\Models\DailyMenuSalesSummary;
use App\Models\Menu;
use App\Models\MenuDemandForecast;
use App\Models\Outlet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ForecastDemandCommandTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Outlet $outlet;
    protected Menu $menu1;
    protected Menu $menu2;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.demand_forecast.enabled' => true,
            'services.catboost_serving.enabled' => true,
        ]);

        $this->user = User::factory()->create([
            'id' => 10,
            'name' => 'Owner Utama',
            'email' => 'owner.utama@example.com'
        ]);

        $this->outlet = Outlet::create([
            'id' => 101,
            'user_id' => $this->user->id,
            'name' => 'Kaching Cafe Staging',
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->menu1 = Menu::create([
            'id' => 1001,
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'name' => 'Nasi Goreng Spesial',
            'category' => 'Makanan',
            'price' => 35000,
            'cost_price' => 20000,
            'unit' => 'porsi',
            'is_available' => true,
        ]);

        $this->menu2 = Menu::create([
            'id' => 1002,
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'name' => 'Es Teh Manis',
            'category' => 'Minuman',
            'price' => 5000,
            'cost_price' => 1500,
            'unit' => 'gelas',
            'is_available' => true,
        ]);
    }

    /**
     * Test 01: Command Registration & Argument Validation
     */
    public function test_01_argument_validation_and_invalid_date_formats(): void
    {
        // Invalid date format
        $this->artisan('kaching:forecast-demand', ['--date' => 'invalid-date'])
            ->assertExitCode(1);

        // Invalid start date format
        $this->artisan('kaching:forecast-demand', [
            '--start-date' => '2026/10/05',
            '--end-date' => '2026-10-06'
        ])
            ->assertExitCode(1);

        // Start date greater than end date
        $this->artisan('kaching:forecast-demand', [
            '--start-date' => '2026-10-10',
            '--end-date' => '2026-10-05'
        ])
            ->assertExitCode(1);
    }

    /**
     * Test 02: Correct WIB Cutoff and Target-Date Handling
     */
    public function test_02_wib_cutoff_and_target_date_handling(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'success' => true,
                'data' => [
                    'outlet_id' => (string) $this->outlet->id,
                    'target_date' => '2026-10-05',
                    'forecasts' => [
                        [
                            'menu_id' => (string) $this->menu1->id,
                            'predicted_quantity' => 18.5,
                            'lower_bound_p10' => 14.0,
                            'upper_bound_p90' => 23.0,
                        ],
                        [
                            'menu_id' => (string) $this->menu2->id,
                            'predicted_quantity' => 45.0,
                            'lower_bound_p10' => 38.0,
                            'upper_bound_p90' => 52.0,
                        ],
                    ]
                ]
            ], 200),
        ]);

        // Default cutoff should be target_date - 1 day (2026-10-04)
        $this->artisan('kaching:forecast-demand', [
            '--date' => '2026-10-05',
            '--outlet' => $this->outlet->id,
        ])
            ->assertExitCode(0);

        $forecast = MenuDemandForecast::where('menu_id', $this->menu1->id)
            ->where('target_date', '2026-10-05')
            ->first();

        $this->assertNotNull($forecast);
        $this->assertEquals('2026-10-04', Carbon::parse($forecast->cutoff_date)->format('Y-m-d'));

        // Reject temporal leakage when cutoff >= target
        $this->artisan('kaching:forecast-demand', [
            '--date' => '2026-10-05',
            '--cutoff-date' => '2026-10-05',
            '--outlet' => $this->outlet->id,
        ])
            ->assertExitCode(1);
    }

    /**
     * Test 03: Seven-Day Maximum Horizon
     */
    public function test_03_seven_day_maximum_horizon(): void
    {
        // Horizon = 8 days should fail
        $this->artisan('kaching:forecast-demand', [
            '--date' => '2026-10-05',
            '--horizon' => 8,
            '--outlet' => $this->outlet->id,
        ])
            ->assertExitCode(1);

        // Date range of 8 days should fail
        $this->artisan('kaching:forecast-demand', [
            '--start-date' => '2026-10-01',
            '--end-date' => '2026-10-08',
            '--outlet' => $this->outlet->id,
        ])
            ->assertExitCode(1);
    }

    /**
     * Test 04: Dry-Run Performs No Cache Writes
     */
    public function test_04_dry_run_performs_no_cache_writes(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'success' => true,
                'data' => [
                    'outlet_id' => (string) $this->outlet->id,
                    'target_date' => '2026-10-05',
                    'forecasts' => [
                        [
                            'menu_id' => (string) $this->menu1->id,
                            'predicted_quantity' => 18.5,
                            'lower_bound_p10' => 14.0,
                            'upper_bound_p90' => 23.0,
                        ],
                        [
                            'menu_id' => (string) $this->menu2->id,
                            'predicted_quantity' => 45.0,
                            'lower_bound_p10' => 38.0,
                            'upper_bound_p90' => 52.0,
                        ],
                    ]
                ]
            ], 200),
        ]);

        $this->artisan('kaching:forecast-demand', [
            '--date' => '2026-10-05',
            '--outlet' => $this->outlet->id,
            '--dry-run' => true,
        ])
            ->assertExitCode(0);

        // Verify zero rows in database
        $this->assertEquals(0, MenuDemandForecast::count());
    }

    /**
     * Test 05: Eligible Menu/Outlet/UOM Selection and Tenant Isolation
     */
    public function test_05_eligible_selection_and_tenant_isolation(): void
    {
        $otherUser = User::factory()->create(['id' => 99]);
        $otherOutlet = Outlet::create([
            'id' => 999,
            'user_id' => $otherUser->id,
            'name' => 'Competitor Resto',
            'timezone' => 'Asia/Jakarta',
        ]);

        // Attempting to run with mismatched user and outlet
        $this->artisan('kaching:forecast-demand', [
            '--date' => '2026-10-05',
            '--outlet' => $otherOutlet->id,
            '--user' => $this->user->id,
        ])
            ->assertExitCode(1);
    }

    /**
     * Test 06: Successful CatBoost Batch
     */
    public function test_06_successful_catboost_batch(): void
    {
        $targetDate = '2026-10-05';
        $cutoffDate = '2026-10-04';

        // Seed 14 days of history for menu 1
        for ($i = 0; $i < 14; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'menu_id' => $this->menu1->id,
                'business_date' => $d,
                'uom' => 'porsi',
                'total_qty' => 15.0,
                'total_revenue' => 15.0 * 35000,
                'total_cost' => 15.0 * 20000,
                'transaction_count' => 5,
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
                            'predicted_quantity' => 20.0,
                            'lower_bound_p10' => 16.0,
                            'upper_bound_p90' => 25.0,
                            'confidence_level' => 'HIGH',
                            'strategy_used' => 'NATIVE_CATBOOST_REGRESSOR',
                            'fallback_applied' => false,
                        ],
                    ]
                ]
            ], 200),
        ]);

        $this->artisan('kaching:forecast-demand', [
            '--date' => $targetDate,
            '--outlet' => $this->outlet->id,
            '--menu' => (string)$this->menu1->id,
        ])
            ->assertExitCode(0);

        $forecast = MenuDemandForecast::where('menu_id', $this->menu1->id)
            ->where('target_date', $targetDate)
            ->first();

        $this->assertNotNull($forecast);
        $this->assertEquals('native-catboost-v1', $forecast->model_version);
        $this->assertEquals(20.0, (float)$forecast->predicted_quantity);
        $this->assertFalse((bool)$forecast->is_fallback);
    }

    /**
     * Test 07: Fallback and Insufficient-History Accounting
     */
    public function test_07_fallback_and_insufficient_history_accounting(): void
    {
        // Menu 2 has 0 history -> INSUFFICIENT_HISTORY / ZERO_DEMAND
        $this->artisan('kaching:forecast-demand', [
            '--date' => '2026-10-05',
            '--outlet' => $this->outlet->id,
            '--menu' => (string)$this->menu2->id,
        ])
            ->assertExitCode(0);

        $forecast = MenuDemandForecast::where('menu_id', $this->menu2->id)
            ->where('target_date', '2026-10-05')
            ->first();

        $this->assertNotNull($forecast);
        $this->assertEquals('FALLBACK_LOCAL', $forecast->serving_origin);
        $this->assertEquals('statistical-baseline-v1', $forecast->model_version);
    }

    /**
     * Test 08: Failed Inference Executes Fallback and Reports Correctly
     */
    public function test_08_failed_inference_executes_fallback_and_reports_correctly(): void
    {
        $targetDate = '2026-10-05';
        $cutoffDate = '2026-10-04';

        // Seed 14 days history for menu 1
        for ($i = 0; $i < 14; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'menu_id' => $this->menu1->id,
                'business_date' => $d,
                'uom' => 'porsi',
                'total_qty' => 15.0,
                'total_revenue' => 15.0 * 35000,
                'total_cost' => 15.0 * 20000,
                'transaction_count' => 5,
            ]);
        }

        // FastAPI server returns 500 Internal Error
        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response(['detail' => 'Internal server error'], 500),
        ]);

        $this->artisan('kaching:forecast-demand', [
            '--date' => $targetDate,
            '--outlet' => $this->outlet->id,
            '--menu' => (string)$this->menu1->id,
        ])
            ->assertExitCode(0); // Gracefully completed via fallback

        $forecast = MenuDemandForecast::where('menu_id', $this->menu1->id)
            ->where('target_date', $targetDate)
            ->first();

        $this->assertNotNull($forecast);
        $this->assertEquals('FALLBACK_LOCAL', $forecast->serving_origin);
        $this->assertEquals('statistical-baseline-v1', $forecast->model_version);
    }

    /**
     * Test 09: Rerun Idempotency
     */
    public function test_09_rerun_idempotency(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'success' => true,
                'data' => [
                    'outlet_id' => (string) $this->outlet->id,
                    'target_date' => '2026-10-05',
                    'forecasts' => [
                        [
                            'menu_id' => (string) $this->menu1->id,
                            'predicted_quantity' => 18.5,
                            'lower_bound_p10' => 14.0,
                            'upper_bound_p90' => 23.0,
                        ],
                        [
                            'menu_id' => (string) $this->menu2->id,
                            'predicted_quantity' => 45.0,
                            'lower_bound_p10' => 38.0,
                            'upper_bound_p90' => 52.0,
                        ],
                    ]
                ]
            ], 200),
        ]);

        // Run 1
        $this->artisan('kaching:forecast-demand', [
            '--date' => '2026-10-05',
            '--outlet' => $this->outlet->id,
        ])->assertExitCode(0);

        $countAfterRun1 = MenuDemandForecast::count();
        $this->assertEquals(2, $countAfterRun1);

        // Run 2 (Rerun identical parameters)
        $this->artisan('kaching:forecast-demand', [
            '--date' => '2026-10-05',
            '--outlet' => $this->outlet->id,
        ])->assertExitCode(0);

        $countAfterRun2 = MenuDemandForecast::count();
        $this->assertEquals(2, $countAfterRun2);
    }

    /**
     * Test 10: Non-Zero Exit Status for Incomplete/Unrecoverable Runs
     */
    public function test_10_non_zero_exit_for_unrecoverable_runs(): void
    {
        // Non-existent outlet filter should exit with failure (1)
        $this->artisan('kaching:forecast-demand', [
            '--date' => '2026-10-05',
            '--outlet' => 99999,
        ])
            ->assertExitCode(1);
    }

    /**
     * Test 11: Limited-Run Labeling
     */
    public function test_11_limited_run_labeling(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/predict' => Http::response([
                'success' => true,
                'data' => [
                    'outlet_id' => (string) $this->outlet->id,
                    'target_date' => '2026-10-05',
                    'forecasts' => [
                        [
                            'menu_id' => (string) $this->menu1->id,
                            'predicted_quantity' => 18.5,
                            'lower_bound_p10' => 14.0,
                            'upper_bound_p90' => 23.0,
                        ],
                    ]
                ]
            ], 200),
        ]);

        $this->artisan('kaching:forecast-demand', [
            '--date' => '2026-10-05',
            '--outlet' => $this->outlet->id,
            '--limit' => 1,
        ])
            ->expectsOutputToContain('LIMITED RUN — TEST MODE')
            ->assertExitCode(0);

        // Only 1 record should be generated due to limit
        $this->assertEquals(1, MenuDemandForecast::count());
    }

    /**
     * Test 12: No Deletion of Previously Valid Cache Rows After Failed Run
     */
    public function test_12_no_deletion_of_previously_valid_cache_rows_after_failed_run(): void
    {
        // Create pre-existing valid forecast
        MenuDemandForecast::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'menu_id' => $this->menu1->id,
            'target_date' => '2026-10-04',
            'cutoff_date' => '2026-10-03',
            'uom' => 'porsi',
            'predicted_quantity' => 25.0,
            'lower_bound_p10' => 20.0,
            'upper_bound_p90' => 30.0,
            'model_version' => 'native-catboost-v1',
            'feature_version' => '37-features-v1',
            'serving_origin' => 'FASTAPI_CATBOOST',
            'generation_strategy' => 'catboost_serving',
            'confidence_level' => 'HIGH',
        ]);

        $this->assertEquals(1, MenuDemandForecast::count());

        // Run an invalid run (e.g., horizon > 7)
        $this->artisan('kaching:forecast-demand', [
            '--date' => '2026-10-05',
            '--horizon' => 10,
            '--outlet' => $this->outlet->id,
        ])
            ->assertExitCode(1);

        // Pre-existing row must remain untouched
        $this->assertEquals(1, MenuDemandForecast::count());
        $existing = MenuDemandForecast::where('target_date', '2026-10-04')->first();
        $this->assertNotNull($existing);
        $this->assertEquals(25.0, (float)$existing->predicted_quantity);
    }

    /**
     * Test 13: Kill-switch DEMAND_FORECASTING_ENABLED=false aborts command without extracting features or writing forecasts (TASK-08.4).
     */
    public function test_13_kill_switch_disabled_aborts_command_without_feature_extraction_or_db_writes(): void
    {
        config(['services.demand_forecast.enabled' => false]);

        $this->artisan('kaching:forecast-demand', [
            '--date' => '2026-10-05',
            '--outlet' => $this->outlet->id,
        ])
            ->expectsOutputToContain('[DISABLED] Demand forecasting is disabled via configuration')
            ->assertExitCode(0);

        // Tidak boleh ada data yang ditulis ke database
        $this->assertEquals(0, MenuDemandForecast::count(), 'Zero records must be written to MenuDemandForecast when kill switch is disabled.');
    }

    /**
     * Test 14: CATBOOST_SERVING_ENABLED=false uses local fallback without sending HTTP requests to FastAPI (TASK-08.4).
     */
    public function test_14_catboost_disabled_uses_local_fallback_without_calling_fastapi(): void
    {
        config([
            'services.demand_forecast.enabled' => true,
            'services.catboost_serving.enabled' => false,
        ]);

        Http::fake();

        $this->artisan('kaching:forecast-demand', [
            '--date' => '2026-10-05',
            '--outlet' => $this->outlet->id,
        ])
            ->assertExitCode(0);

        // Verifikasi tidak ada HTTP request yang dikirim ke daemon FastAPI
        Http::assertNothingSent();

        // Verifikasi data tetap ditulis via local fallback
        $forecasts = MenuDemandForecast::all();
        $this->assertGreaterThan(0, $forecasts->count(), 'Forecasts must be written using local fallback.');

        foreach ($forecasts as $forecast) {
            $this->assertEquals('FALLBACK_LOCAL', $forecast->serving_origin);
            $this->assertTrue((bool) ($forecast->metadata['fallback_applied'] ?? false));
        }
    }
}
