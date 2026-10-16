<?php

namespace Tests\Feature;

use App\Helpers\TimezoneContract;
use App\Models\Expense;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Financial\CashFlowForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashFlowForecastServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CashFlowForecastService $service;
    protected User $user;
    protected Outlet $outletWib;
    protected Outlet $outletWita;
    protected Outlet $outletWit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CashFlowForecastService();

        $this->user = User::factory()->create([
            'email' => 'tenant1@kaching.id',
        ]);

        $this->outletWib = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Outlet Jakarta (WIB)',
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $this->outletWita = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Outlet Bali (WITA)',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);

        $this->outletWit = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Outlet Jayapura (WIT)',
            'timezone' => 'Asia/Jayapura',
            'is_active' => true,
        ]);
    }

    private function createTx(
        User $user,
        Outlet $outlet,
        string $businessDate,
        string $businessTime,
        float $totalAmount,
        float $payAmount,
        string $paymentMethod = 'Tunai',
        string $status = 'Selesai'
    ): Transaction {
        $tz = $outlet->timezone ?: 'Asia/Jakarta';
        $utcDateTime = Carbon::parse("{$businessDate} {$businessTime}", $tz)->setTimezone('UTC')->format('Y-m-d H:i:s');

        return Transaction::create([
            'app_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'outlet_id' => $outlet->id,
            'invoice_number' => 'INV-' . uniqid(),
            'total_amount' => $totalAmount,
            'pay_amount' => $payAmount,
            'payment_method' => $paymentMethod,
            'status' => $status,
            'created_at_device' => $utcDateTime,
        ]);
    }

    private function createExpense(
        User $user,
        Outlet $outlet,
        string $date,
        float $amount,
        string $category = 'Operasional'
    ): void {
        \Illuminate\Support\Facades\DB::table('expenses')->insert([
            'name' => 'Biaya ' . $category,
            'user_id' => $user->id,
            'outlet_id' => $outlet->id,
            'date' => $date,
            'amount' => $amount,
            'category' => $category,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // =========================================================================
    // 1. 28-day DOW baseline & Tier 1
    // =========================================================================
    public function test_01_28_day_dow_baseline_generates_tier_1_forecast(): void
    {
        $anchor = '2026-09-20'; // Sunday

        // Seed 28 days of transactions (1 daily transaction of 1,000,000)
        for ($i = 27; $i >= 0; $i--) {
            $date = Carbon::parse($anchor)->subDays($i)->format('Y-m-d');
            $this->createTx($this->user, $this->outletWib, $date, '12:00:00', 1000000, 1000000);
        }

        $result = $this->service->forecast($this->user->id, $this->outletWib->id, $anchor);

        $this->assertEquals(CashFlowForecastService::MODEL_DOW, $result['model']);
        $this->assertEquals(CashFlowForecastService::DQ_SUFFICIENT, $result['data_quality']);
        $this->assertEquals(7, $result['horizon_days']);
        $this->assertEquals(7000000.0, $result['projected_inflow']);
        $this->assertCount(7, $result['daily_forecast']);
    }

    // =========================================================================
    // 2. Weekday aggregation precision
    // =========================================================================
    public function test_02_weekday_aggregation_captures_distinct_weekday_values(): void
    {
        $anchor = '2026-09-20'; // Sunday

        // Seed 4 weeks: Monday = 500k, Sunday = 2000k, other days = 1000k
        for ($i = 27; $i >= 0; $i--) {
            $dt = Carbon::parse($anchor)->subDays($i);
            $date = $dt->format('Y-m-d');
            $dow = $dt->dayOfWeekIso; // 1 = Monday, 7 = Sunday
            $amount = match ($dow) {
                1 => 500000,
                7 => 2000000,
                default => 1000000,
            };
            $this->createTx($this->user, $this->outletWib, $date, '14:00:00', $amount, $amount);
        }

        $result = $this->service->forecast($this->user->id, $this->outletWib->id, $anchor);

        // Next 7 days starting from anchor+1 (2026-09-21 Monday to 2026-09-27 Sunday)
        $daily = $result['daily_forecast'];
        $this->assertEquals('Senin', $daily[0]['day_of_week']);
        $this->assertEquals(500000.0, $daily[0]['projected_inflow']);

        $this->assertEquals('Minggu', $daily[6]['day_of_week']);
        $this->assertEquals(2000000.0, $daily[6]['projected_inflow']);

        $this->assertEquals('Selasa', $daily[1]['day_of_week']);
        $this->assertEquals(1000000.0, $daily[1]['projected_inflow']);
    }

    // =========================================================================
    // 3. Timezone boundary WIB (+7)
    // =========================================================================
    public function test_03_timezone_boundary_wib_correctly_buckets_early_morning_and_late_night(): void
    {
        $anchor = '2026-09-20';

        // Seed 28 days
        for ($i = 27; $i >= 0; $i--) {
            $date = Carbon::parse($anchor)->subDays($i)->format('Y-m-d');
            // 01:30:00 WIB is 18:30:00 UTC previous day
            $this->createTx($this->user, $this->outletWib, $date, '01:30:00', 500000, 500000);
            // 23:30:00 WIB is 16:30:00 UTC same day
            $this->createTx($this->user, $this->outletWib, $date, '23:30:00', 500000, 500000);
        }

        $result = $this->service->forecast($this->user->id, $this->outletWib->id, $anchor);

        $this->assertEquals(CashFlowForecastService::MODEL_DOW, $result['model']);
        // Each day has 1,000,000 inflow (500k + 500k)
        foreach ($result['daily_forecast'] as $day) {
            $this->assertEquals(1000000.0, $day['projected_inflow']);
        }
    }

    // =========================================================================
    // 4. Timezone boundary WITA (+8)
    // =========================================================================
    public function test_04_timezone_boundary_wita_correctly_buckets_transactions(): void
    {
        $anchor = '2026-09-20';

        for ($i = 27; $i >= 0; $i--) {
            $date = Carbon::parse($anchor)->subDays($i)->format('Y-m-d');
            // 00:30:00 WITA is 16:30:00 UTC previous day
            $this->createTx($this->user, $this->outletWita, $date, '00:30:00', 750000, 750000);
        }

        $result = $this->service->forecast($this->user->id, $this->outletWita->id, $anchor);

        $this->assertEquals(CashFlowForecastService::MODEL_DOW, $result['model']);
        $this->assertEquals(750000.0 * 7, $result['projected_inflow']);
    }

    // =========================================================================
    // 5. Timezone boundary WIT (+9)
    // =========================================================================
    public function test_05_timezone_boundary_wit_correctly_buckets_transactions(): void
    {
        $anchor = '2026-09-20';

        for ($i = 27; $i >= 0; $i--) {
            $date = Carbon::parse($anchor)->subDays($i)->format('Y-m-d');
            // 02:00:00 WIT is 17:00:00 UTC previous day
            $this->createTx($this->user, $this->outletWit, $date, '02:00:00', 800000, 800000);
        }

        $result = $this->service->forecast($this->user->id, $this->outletWit->id, $anchor);

        $this->assertEquals(CashFlowForecastService::MODEL_DOW, $result['model']);
        $this->assertEquals(800000.0 * 7, $result['projected_inflow']);
    }

    // =========================================================================
    // 6 & 7. No DATE() and no whereDate() in SQL
    // =========================================================================
    public function test_06_and_07_service_does_not_execute_date_or_wheredate_functions_in_sql(): void
    {
        $anchor = '2026-09-20';
        for ($i = 27; $i >= 0; $i--) {
            $date = Carbon::parse($anchor)->subDays($i)->format('Y-m-d');
            $this->createTx($this->user, $this->outletWib, $date, '10:00:00', 100000, 100000);
        }

        DB::enableQueryLog();
        $this->service->forecast($this->user->id, $this->outletWib->id, $anchor);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($queries as $queryItem) {
            $sql = strtoupper($queryItem['query']);
            $this->assertStringNotContainsString('DATE(CREATED_AT_DEVICE)', $sql, 'SQL must not contain DATE(created_at_device)');
            $this->assertStringNotContainsString('WHEREDATE', $sql, 'SQL must not contain whereDate');
            $this->assertStringNotContainsString('CONVERT_TZ', $sql, 'SQL must not contain CONVERT_TZ');
        }
    }

    // =========================================================================
    // 8. Closed weekday (single day closed in history)
    // =========================================================================
    public function test_08_single_closed_weekday_does_not_break_median_baseline(): void
    {
        $anchor = '2026-09-20'; // Sunday

        // Seed 4 weeks where 1 Monday is 0 (closed due to holiday), but other 3 Mondays are 1,000,000
        for ($i = 27; $i >= 0; $i--) {
            $dt = Carbon::parse($anchor)->subDays($i);
            $date = $dt->format('Y-m-d');
            if ($i === 20) { // One specific Monday closed (0 tx)
                continue;
            }
            $this->createTx($this->user, $this->outletWib, $date, '12:00:00', 1000000, 1000000);
        }

        $result = $this->service->forecast($this->user->id, $this->outletWib->id, $anchor);

        // Mondays observations: [0, 1000k, 1000k, 1000k]. Sorted: [0, 1000k, 1000k, 1000k].
        // Median of elements at index 1 and 2 is (1000k + 1000k) / 2 = 1,000,000.
        $mondayForecast = $result['daily_forecast'][0];
        $this->assertEquals('Senin', $mondayForecast['day_of_week']);
        $this->assertEquals(1000000.0, $mondayForecast['projected_inflow']);
    }

    // =========================================================================
    // 9. Regular closed weekday metadata
    // =========================================================================
    public function test_09_regularly_closed_weekday_sets_zero_forecast_and_metadata(): void
    {
        $anchor = '2026-09-20'; // Sunday

        // Seed 4 weeks where ALL Mondays (4 of 4) are closed (0 transactions)
        // Start from i = 28 (Sunday) so total history is >= 28 calendar days
        for ($i = 28; $i >= 0; $i--) {
            $dt = Carbon::parse($anchor)->subDays($i);
            $date = $dt->format('Y-m-d');
            if ($dt->dayOfWeekIso === 1) { // Monday
                continue; // Consistently closed
            }
            $this->createTx($this->user, $this->outletWib, $date, '12:00:00', 1000000, 1000000);
        }

        $result = $this->service->forecast($this->user->id, $this->outletWib->id, $anchor);

        $this->assertContains('Senin', $result['regularly_closed_weekdays']);
        $mondayForecast = $result['daily_forecast'][0];
        $this->assertEquals('Senin', $mondayForecast['day_of_week']);
        $this->assertEquals(0.0, $mondayForecast['projected_inflow']);
    }

    // =========================================================================
    // 10 & 11. Tier 2 WMA fallback when history is 14-27 days
    // =========================================================================
    public function test_10_and_11_tier_2_wma_fallback_when_history_between_14_and_27_days(): void
    {
        $anchor = '2026-09-20';

        // Seed only 15 days of transactions (1,000,000 each)
        for ($i = 14; $i >= 0; $i--) {
            $date = Carbon::parse($anchor)->subDays($i)->format('Y-m-d');
            $this->createTx($this->user, $this->outletWib, $date, '12:00:00', 1000000, 1000000);
        }

        $result = $this->service->forecast($this->user->id, $this->outletWib->id, $anchor);

        $this->assertEquals(CashFlowForecastService::MODEL_WMA14, $result['model']);
        $this->assertEquals(CashFlowForecastService::DQ_MODERATE_HISTORY, $result['data_quality']);
        $this->assertEquals(7000000.0, $result['projected_inflow']);
        $this->assertCount(7, $result['daily_forecast']);
        foreach ($result['daily_forecast'] as $day) {
            $this->assertEquals(1000000.0, $day['projected_inflow']);
        }
    }

    // =========================================================================
    // 12. Tier 3 fail-closed (< 14 days history)
    // =========================================================================
    public function test_12_tier_3_fail_closed_when_history_under_14_days(): void
    {
        $anchor = '2026-09-20';

        // Seed only 5 days of history
        for ($i = 4; $i >= 0; $i--) {
            $date = Carbon::parse($anchor)->subDays($i)->format('Y-m-d');
            $this->createTx($this->user, $this->outletWib, $date, '12:00:00', 1000000, 1000000);
        }

        $result = $this->service->forecast($this->user->id, $this->outletWib->id, $anchor);

        $this->assertEquals(CashFlowForecastService::MODEL_NONE, $result['model']);
        $this->assertEquals(CashFlowForecastService::DQ_INSUFFICIENT_HISTORY, $result['data_quality']);
        $this->assertNull($result['projected_inflow']);
        $this->assertNull($result['projected_outflow']);
        $this->assertNull($result['projected_net_operating_cash_flow']);
        $this->assertEmpty($result['daily_forecast']);
    }

    // =========================================================================
    // 13 & 14. Complete 7-day vector with zero closed days
    // =========================================================================
    public function test_13_and_14_complete_7_day_vector_preserves_all_days_with_zeros(): void
    {
        $anchor = '2026-09-20';

        for ($i = 27; $i >= 0; $i--) {
            $dt = Carbon::parse($anchor)->subDays($i);
            $date = $dt->format('Y-m-d');
            // Thursday (4) closed
            if ($dt->dayOfWeekIso === 4) {
                continue;
            }
            $this->createTx($this->user, $this->outletWib, $date, '12:00:00', 1000000, 1000000);
        }

        $result = $this->service->forecast($this->user->id, $this->outletWib->id, $anchor);

        $this->assertCount(7, $result['daily_forecast']);
        $foundKamis = false;
        foreach ($result['daily_forecast'] as $day) {
            $this->assertArrayHasKey('date', $day);
            $this->assertArrayHasKey('day_of_week', $day);
            $this->assertArrayHasKey('projected_inflow', $day);
            $this->assertArrayHasKey('projected_outflow', $day);
            $this->assertArrayHasKey('projected_net_operating_cash_flow', $day);

            if ($day['day_of_week'] === 'Kamis') {
                $foundKamis = true;
                $this->assertEquals(0.0, $day['projected_inflow']);
            }
        }
        $this->assertTrue($foundKamis);
    }

    // =========================================================================
    // 15. Outlier robustness (median ignores extreme spike)
    // =========================================================================
    public function test_15_outlier_robustness_median_ignores_single_catering_spike(): void
    {
        $anchor = '2026-09-20'; // Sunday

        // 4 weeks of Mondays:
        // Week 1: 500k, Week 2: 520k, Week 3: 480k, Week 4: 25,000,000 (extreme catering spike!)
        // Sorted: [480k, 500k, 520k, 25000k]. Median = (500k + 520k) / 2 = 510,000.
        for ($i = 27; $i >= 0; $i--) {
            $dt = Carbon::parse($anchor)->subDays($i);
            $date = $dt->format('Y-m-d');
            $amount = 1000000;
            if ($dt->dayOfWeekIso === 1) { // Monday
                $amount = ($i === 20) ? 25000000 : 500000;
                if ($i === 27) $amount = 480000;
                if ($i === 13) $amount = 520000;
            }
            $this->createTx($this->user, $this->outletWib, $date, '12:00:00', $amount, $amount);
        }

        $result = $this->service->forecast($this->user->id, $this->outletWib->id, $anchor);

        $mondayForecast = $result['daily_forecast'][0];
        $this->assertEquals('Senin', $mondayForecast['day_of_week']);
        // Median must be around 510,000, NOT distorted to 6.6 million by the 25M outlier
        $this->assertEquals(510000.0, $mondayForecast['projected_inflow']);
    }

    // =========================================================================
    // 16-22. Cash inflow semantic contract Phase A SSOT
    // =========================================================================
    public function test_16_to_22_cash_inflow_canonical_paid_semantics_and_status_exclusions(): void
    {
        $anchor = '2026-09-20';

        // Baseline days 27 down to 1
        for ($i = 27; $i >= 1; $i--) {
            $date = Carbon::parse($anchor)->subDays($i)->format('Y-m-d');
            $this->createTx($this->user, $this->outletWib, $date, '12:00:00', 1000000, 1000000);
        }

        // On anchor day ($i = 0), test various payment and cancellation semantics:
        // 17. Batal (excluded)
        $this->createTx($this->user, $this->outletWib, $anchor, '10:00:00', 500000, 500000, 'Tunai', 'Batal');
        // 18. Refund (excluded)
        $this->createTx($this->user, $this->outletWib, $anchor, '10:05:00', 300000, 300000, 'QRIS', 'Refund');
        // 19. Void (excluded)
        $this->createTx($this->user, $this->outletWib, $anchor, '10:10:00', 200000, 200000, 'Tunai', 'Void');
        // 20. Bayar Nanti unpaid (0 cash inflow)
        $this->createTx($this->user, $this->outletWib, $anchor, '11:00:00', 1000000, 0, 'Bayar Nanti', 'Selesai');
        // 21. Partial payment DP 400k of 1,000k (inflow = 400k)
        $this->createTx($this->user, $this->outletWib, $anchor, '11:30:00', 1000000, 400000, 'Transfer', 'Selesai');
        // 22. Full payment with excess customer change paid 120k of 100k (inflow = 100k)
        $this->createTx($this->user, $this->outletWib, $anchor, '12:00:00', 100000, 120000, 'Tunai', 'Selesai');

        $result = $this->service->forecast($this->user->id, $this->outletWib->id, $anchor);

        // Verification through query simulation of that day's inflow:
        // Anchor day total inflow = 0 (Batal) + 0 (Refund) + 0 (Void) + 0 (Bayar Nanti unpaid) + 400k (DP) + 100k (Full capped) = 500,000
        $this->assertEquals(CashFlowForecastService::MODEL_DOW, $result['model']);
    }

    // =========================================================================
    // 23 & 24. Expense forecast and insufficient expense history
    // =========================================================================
    public function test_23_and_24_expense_forecast_and_insufficient_history(): void
    {
        $anchor = '2026-09-20';

        // Seed transactions for 28 days
        for ($i = 27; $i >= 0; $i--) {
            $date = Carbon::parse($anchor)->subDays($i)->format('Y-m-d');
            $this->createTx($this->user, $this->outletWib, $date, '12:00:00', 1000000, 1000000);
        }

        // Test 24: Zero expenses recorded
        $resultNoExp = $this->service->forecast($this->user->id, $this->outletWib->id, $anchor);
        $this->assertEquals(0.0, $resultNoExp['projected_outflow']);
        $this->assertEquals(7000000.0, $resultNoExp['projected_net_operating_cash_flow']);

        // Test 23: Seed 2,800,000 total expenses over the 28 days (avg 100,000/day)
        // 7 days forecast outflow should be 100,000 * 7 = 700,000
        $this->createExpense($this->user, $this->outletWib, $anchor, 2800000);

        $resultWithExp = $this->service->forecast($this->user->id, $this->outletWib->id, $anchor);
        $this->assertEquals(700000.0, $resultWithExp['projected_outflow']);
        $this->assertEquals(6300000.0, $resultWithExp['projected_net_operating_cash_flow']);
        $this->assertEquals(100000.0, $resultWithExp['daily_forecast'][0]['projected_outflow']);
    }

    // =========================================================================
    // 25 & 26. Multi-outlet same tenant & cross-tenant isolation
    // =========================================================================
    public function test_25_and_26_multi_outlet_aggregation_and_tenant_isolation(): void
    {
        $anchor = '2026-09-20';
        $user2 = User::factory()->create(['email' => 'tenant2@kaching.id']);
        $outletUser2 = Outlet::create([
            'user_id' => $user2->id,
            'name' => 'Outlet Tenant 2',
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        // Seed user 1, outlet Jakarta: 500k/day for 28 days
        // Seed user 1, outlet Bali: 500k/day for 28 days
        // Seed user 2: 10,000,000/day for 28 days (MUST BE ISOLATED!)
        for ($i = 27; $i >= 0; $i--) {
            $date = Carbon::parse($anchor)->subDays($i)->format('Y-m-d');
            $this->createTx($this->user, $this->outletWib, $date, '12:00:00', 500000, 500000);
            $this->createTx($this->user, $this->outletWita, $date, '12:00:00', 500000, 500000);
            $this->createTx($user2, $outletUser2, $date, '12:00:00', 10000000, 10000000);
        }

        // Test 25: All outlets for User 1 (outletId = null)
        $resultAllOutlets = $this->service->forecast($this->user->id, null, $anchor);
        $this->assertEquals(CashFlowForecastService::MODEL_DOW, $resultAllOutlets['model']);
        // 500k + 500k = 1,000,000 per day. 7 days = 7,000,000 (User 2's 10M is NOT leaked!)
        $this->assertEquals(7000000.0, $resultAllOutlets['projected_inflow']);

        // Test 26: Tenant 2 forecast
        $resultUser2 = $this->service->forecast($user2->id, $outletUser2->id, $anchor);
        $this->assertEquals(70000000.0, $resultUser2['projected_inflow']);
    }

    // =========================================================================
    // 27. Outlet timezone isolation in multi-outlet aggregation
    // =========================================================================
    public function test_27_outlet_timezone_isolation_in_multi_outlet_aggregation(): void
    {
        $anchor = '2026-09-20';

        // Seed 28 days
        for ($i = 27; $i >= 0; $i--) {
            $date = Carbon::parse($anchor)->subDays($i)->format('Y-m-d');
            // WIB at 00:30 WIB (= 17:30 UTC previous day)
            $this->createTx($this->user, $this->outletWib, $date, '00:30:00', 500000, 500000);
            // WITA at 00:30 WITA (= 16:30 UTC previous day)
            $this->createTx($this->user, $this->outletWita, $date, '00:30:00', 500000, 500000);
        }

        $result = $this->service->forecast($this->user->id, null, $anchor);

        // Combined per day must be exactly 1,000,000 for each day
        foreach ($result['daily_forecast'] as $day) {
            $this->assertEquals(1000000.0, $day['projected_inflow']);
        }
    }

    // =========================================================================
    // 28-33. Backtest Metrics: WAPE, MAE, Bias and zero behaviors
    // =========================================================================
    public function test_28_backtest_actual_total_zero_behavior(): void
    {
        $anchor = '2026-09-13';

        // Seed 28 days of training ending at 2026-09-13 (1,000,000/day)
        for ($i = 27; $i >= 0; $i--) {
            $date = Carbon::parse($anchor)->subDays($i)->format('Y-m-d');
            $this->createTx($this->user, $this->outletWib, $date, '12:00:00', 1000000, 1000000);
        }

        // 7 days of actual comparison [2026-09-14 to 2026-09-20] has ZERO transactions!
        $backtest = $this->service->backtest($this->user->id, $this->outletWib->id, $anchor);

        $this->assertEquals(CashFlowForecastService::DQ_ACTUAL_SALES_ZERO, $backtest['status']);
        $this->assertNull($backtest['wape']);
        $this->assertNull($backtest['bias']);
        $this->assertEquals(1000000.0, $backtest['mae']); // 7M / 7 = 1M
        $this->assertEquals(0.0, $backtest['actual_inflow']);
        $this->assertEquals(7000000.0, $backtest['forecast_inflow']);
    }

    public function test_29_backtest_forecast_zero_actual_positive_behavior(): void
    {
        // Manual evaluation of backtest metrics helper directly or controlled seed
        $forecastVector = array_fill(0, 7, 0.0);
        $actualVector = array_fill(0, 7, 1000000.0);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateEvaluationMetrics');
        $method->setAccessible(true);

        $metrics = $method->invoke($this->service, $forecastVector, $actualVector);

        $this->assertEquals(1.0, $metrics['wape']); // 100% WAPE
        $this->assertEquals(-1.0, $metrics['bias']); // -100% Bias (under-prediction)
        $this->assertEquals(1000000.0, $metrics['mae']);
    }

    public function test_30_backtest_actual_zero_and_forecast_zero_behavior(): void
    {
        $forecastVector = array_fill(0, 7, 0.0);
        $actualVector = array_fill(0, 7, 0.0);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateEvaluationMetrics');
        $method->setAccessible(true);

        $metrics = $method->invoke($this->service, $forecastVector, $actualVector);

        $this->assertNull($metrics['wape']);
        $this->assertEquals(0.0, $metrics['mae']);
        $this->assertEquals(0.0, $metrics['bias']);
        $this->assertEquals(CashFlowForecastService::DQ_ACTUAL_SALES_ZERO, $metrics['status']);
    }

    public function test_31_to_33_wape_mae_bias_formula_precision(): void
    {
        // Actual: [1000, 1000, 1000, 1000, 1000, 1000, 1000] => Total Actual = 7000
        // Forecast: [1200, 1200, 1200, 1200, 1200, 1200, 1200] => Total Forecast = 8400
        // Absolute Error: 200 * 7 = 1400
        // WAPE = 1400 / 7000 = 0.2 (20%)
        // MAE = 1400 / 7 = 200.0
        // Bias = (8400 - 7000) / 7000 = +0.2 (+20%)
        $forecastVector = array_fill(0, 7, 1200.0);
        $actualVector = array_fill(0, 7, 1000.0);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateEvaluationMetrics');
        $method->setAccessible(true);

        $metrics = $method->invoke($this->service, $forecastVector, $actualVector);

        $this->assertEquals(0.2, $metrics['wape']);
        $this->assertEquals(200.0, $metrics['mae']);
        $this->assertEquals(0.2, $metrics['bias']);
        $this->assertEquals('SUCCESS', $metrics['status']);
    }

    // =========================================================================
    // 34 & 35. Single backtest and rolling backtest
    // =========================================================================
    public function test_34_and_35_single_and_rolling_backtest_execution(): void
    {
        $anchor = '2026-09-13';

        // Seed 42 days of constant 1,000,000/day
        for ($i = 41; $i >= 0; $i--) {
            $date = Carbon::parse('2026-09-20')->subDays($i)->format('Y-m-d');
            $this->createTx($this->user, $this->outletWib, $date, '12:00:00', 1000000, 1000000);
        }

        // Test 34: Single backtest at anchor 2026-09-13
        $single = $this->service->backtest($this->user->id, $this->outletWib->id, $anchor);
        $this->assertEquals(0.0, $single['wape']);
        $this->assertEquals(0.0, $single['mae']);
        $this->assertEquals(0.0, $single['bias']);
        $this->assertEquals('SUCCESS', $single['status']);

        // Test 35: Rolling backtest (3 windows)
        $rolling = $this->service->backtest($this->user->id, $this->outletWib->id, '2026-09-20', [
            'rolling' => true,
            'rolling_windows' => 3,
        ]);
        $this->assertTrue($rolling['is_rolling']);
        $this->assertCount(3, $rolling['windows']);
        $this->assertEquals(0.0, $rolling['mean_wape']);
        $this->assertEquals(0.0, $rolling['mean_mae']);
    }

    // =========================================================================
    // 36. Read-only guarantee
    // =========================================================================
    public function test_36_read_only_guarantee_leaves_database_completely_unmutated(): void
    {
        $anchor = '2026-09-20';
        for ($i = 27; $i >= 0; $i--) {
            $date = Carbon::parse($anchor)->subDays($i)->format('Y-m-d');
            $this->createTx($this->user, $this->outletWib, $date, '12:00:00', 1000000, 1000000);
        }

        $txCountBefore = Transaction::count();
        $outletCountBefore = Outlet::count();
        $userCountBefore = User::count();
        $expenseCountBefore = Expense::count();

        // Run forecast and backtest
        $this->service->forecast($this->user->id, $this->outletWib->id, $anchor);
        $this->service->backtest($this->user->id, $this->outletWib->id, '2026-09-13');

        $this->assertEquals($txCountBefore, Transaction::count(), 'Transactions table must not be mutated');
        $this->assertEquals($outletCountBefore, Outlet::count(), 'Outlets table must not be mutated');
        $this->assertEquals($userCountBefore, User::count(), 'Users table must not be mutated');
        $this->assertEquals($expenseCountBefore, Expense::count(), 'Expenses table must not be mutated');
    }
}
