<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Services\Financial\FinancialCalculationService;
use App\Services\Financial\FinancialWarningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Phase B.2 — FinancialWarningService Comprehensive Test Suite
 *
 * Implements isolated, deterministic tests for all Phase B.2 rules:
 * - REVENUE: normal, 20% decline, exactly 25%, >25%, >25% one window only, >25% two consecutive windows, >50%, insufficient baseline, timezone boundary, tenant isolation
 * - EXPENSE: normal, 150%, >150%, <Rp500k, >=Rp500k, >300%, <Rp2m, zero baseline, insufficient baseline
 * - MARGIN: normal, exactly 5pp, >5pp, >15pp, untracked COGS >15%, zero revenue
 * - VOID/REFUND: <5%, exactly 5%, >5%, >15%, 19 transactions, exactly 20, tenant isolation
 * - DRAWER: surplus, deficit below threshold, deficit nominal threshold only, deficit percentage threshold only, both thresholds, critical, missing shift, zero cash sales
 * - GLOBAL: user isolation, outlet isolation, timezone, no division by zero, no INF, no NaN, CASH_DEPLETION = SUSPENDED, cache behavior, deduplication fingerprint
 */
class FinancialWarningServiceTest extends TestCase
{
    use RefreshDatabase;

    protected FinancialCalculationService $calcService;
    protected FinancialWarningService $warningService;
    protected User $user;
    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calcService = new FinancialCalculationService();
        $this->warningService = new FinancialWarningService($this->calcService);

        $this->user = User::factory()->create([
            'role'           => 'owner',
            'account_status' => 'active',
        ]);

        $this->outlet = Outlet::factory()->create([
            'user_id' => $this->user->id,
        ]);
    }

    // ================================================================
    // HELPERS
    // ================================================================

    private function insertTransaction(array $overrides = []): int
    {
        $defaults = [
            'user_id'                 => $this->user->id,
            'outlet_id'               => $this->outlet->id,
            'app_uuid'                => 'tx-' . uniqid('', true),
            'official_invoice_number' => 'INV-' . uniqid(),
            'total_amount'            => 100000,
            'pay_amount'              => 100000,
            'payment_method'          => 'Tunai / Cash',
            'status'                  => 'Selesai',
            'created_at_device'       => '2026-09-24 05:00:00', // 12:00 WIB
            'created_at'              => now(),
            'updated_at'              => now(),
        ];

        if (isset($overrides['total_amount']) && !isset($overrides['pay_amount'])) {
            $overrides['pay_amount'] = $overrides['total_amount'];
        }

        return DB::table('transactions')->insertGetId(array_merge($defaults, $overrides));
    }

    private function insertItem(int $transactionId, array $overrides = []): void
    {
        $defaults = [
            'transaction_id' => $transactionId,
            'menu_id'        => 1,
            'menu_name'      => 'Kopi Susu',
            'qty'            => 1,
            'price'          => 100000,
            'cost_price'     => 40000,
            'status'         => 'completed',
            'created_at'     => now(),
            'updated_at'     => now(),
        ];

        DB::table('transaction_items')->insert(array_merge($defaults, $overrides));
    }

    private function insertExpense(array $overrides = []): void
    {
        $defaults = [
            'user_id'    => $this->user->id,
            'outlet_id'  => $this->outlet->id,
            'name'       => 'Belanja Operasional',
            'amount'     => 100000,
            'category'   => 'Operasional',
            'date'       => '2026-09-24',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('expenses')->insert(array_merge($defaults, $overrides));
    }

    private function insertShift(array $overrides = []): int
    {
        $defaults = [
            'user_id'             => $this->user->id,
            'outlet_id'           => $this->outlet->id,
            'app_uuid'            => 'shift-' . uniqid(),
            'cashier_name'        => 'Kasir Test',
            'start_time'          => '2026-09-24 01:00:00',
            'end_time'            => '2026-09-24 09:00:00',
            'start_cash'          => 200000,
            'total_cash_sales'    => 1000000,
            'total_expense'       => 0,
            'cash_drop'           => 0,
            'total_cash_refunded' => 0,
            'expected_cash'       => 1200000,
            'end_cash'            => 1200000,
            'status'              => 'closed',
            'created_at'          => now(),
            'updated_at'          => now(),
        ];

        return DB::table('shifts')->insertGetId(array_merge($defaults, $overrides));
    }

    // ================================================================
    // A. REVENUE_DECLINE TESTS
    // ================================================================

    /** @test */
    public function revenue_normal_stable()
    {
        // Baseline 7 days (2026-09-11 to 2026-09-17): 7 days x 100k = 700k
        for ($d = 11; $d <= 17; $d++) {
            $this->insertTransaction([
                'total_amount'      => 100000,
                'created_at_device' => "2026-09-{$d} 03:00:00",
            ]);
        }
        // Current 7 days (2026-09-18 to 2026-09-24): 7 days x 100k = 700k
        for ($d = 18; $d <= 24; $d++) {
            $this->insertTransaction([
                'total_amount'      => 100000,
                'created_at_device' => "2026-09-{$d} 03:00:00",
            ]);
        }

        $res = $this->warningService->evaluateRevenueDecline($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
        $this->assertEquals(FinancialWarningService::DQ_SUFFICIENT, $res['data_quality']);
        $this->assertEquals(700000, $res['actual']);
        $this->assertEquals(700000, $res['baseline']);
    }

    /** @test */
    public function revenue_20_percent_decline_triggers_watch()
    {
        // Baseline: 1,000,000 (10 x 100k)
        for ($i = 0; $i < 10; $i++) {
            $this->insertTransaction([
                'total_amount'      => 100000,
                'created_at_device' => '2026-09-15 03:00:00',
            ]);
        }
        // Current: 800,000 (8 x 100k) -> exactly 20.0% decline
        for ($i = 0; $i < 8; $i++) {
            $this->insertTransaction([
                'total_amount'      => 100000,
                'created_at_device' => '2026-09-20 03:00:00',
            ]);
        }

        $res = $this->warningService->evaluateRevenueDecline($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_WATCH, $res['severity']);
        $this->assertEquals(-20.0, $res['evidence']['percentage_change']);
    }

    /** @test */
    public function revenue_exactly_25_percent_decline_triggers_watch()
    {
        // Baseline: 1,000,000
        for ($i = 0; $i < 10; $i++) {
            $this->insertTransaction([
                'total_amount'      => 100000,
                'created_at_device' => '2026-09-15 03:00:00',
            ]);
        }
        // Current: 750,000 (3 x 250k) -> exactly 25.0% decline
        for ($i = 0; $i < 3; $i++) {
            $this->insertTransaction([
                'total_amount'      => 250000,
                'created_at_device' => '2026-09-20 03:00:00',
            ]);
        }

        $res = $this->warningService->evaluateRevenueDecline($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_WATCH, $res['severity']);
        $this->assertEquals(-25.0, $res['evidence']['percentage_change']);
    }

    /** @test */
    public function revenue_greater_than_25_percent_one_window_only_does_not_trigger_warning()
    {
        // Baseline: 1,000,000
        for ($i = 0; $i < 10; $i++) {
            $this->insertTransaction([
                'total_amount'      => 100000,
                'created_at_device' => '2026-09-15 03:00:00',
            ]);
        }
        // Current: 700,000 -> 30.0% decline, but only 1 window (unconfirmed)
        for ($i = 0; $i < 7; $i++) {
            $this->insertTransaction([
                'total_amount'      => 100000,
                'created_at_device' => '2026-09-24 03:00:00',
            ]);
        }

        // Pass options confirmed => false explicitly or test unconfirmed path
        $res = $this->warningService->evaluateRevenueDecline($this->user->id, $this->outlet->id, '2026-09-24', [
            'confirmed' => false,
        ]);

        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status'], 'Single >25% observation must NOT trigger WARNING status');
        $this->assertEquals(FinancialWarningService::SEVERITY_WATCH, $res['severity'], 'Single >25% observation must be categorized as WATCH');
        $this->assertFalse($res['evidence']['confirmed']);
        $this->assertEquals(1, $res['evidence']['windows_observed']);
    }

    /** @test */
    public function revenue_greater_than_25_percent_two_consecutive_windows_triggers_warning()
    {
        // Baseline: 1,000,000
        for ($i = 0; $i < 10; $i++) {
            $this->insertTransaction([
                'total_amount'      => 100000,
                'created_at_device' => '2026-09-15 03:00:00',
            ]);
        }
        // Current: 700,000 -> 30.0% decline
        for ($i = 0; $i < 7; $i++) {
            $this->insertTransaction([
                'total_amount'      => 100000,
                'created_at_device' => '2026-09-24 03:00:00',
            ]);
        }

        $res = $this->warningService->evaluateRevenueDecline($this->user->id, $this->outlet->id, '2026-09-24', [
            'confirmed'            => true,
            'prior_window_decline' => 28.5,
        ]);

        $this->assertEquals(FinancialWarningService::STATUS_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_WARNING, $res['severity']);
        $this->assertTrue($res['evidence']['confirmed']);
        $this->assertEquals(2, $res['evidence']['windows_observed']);
    }

    /** @test */
    public function revenue_greater_than_50_percent_confirmed_triggers_critical()
    {
        // Baseline: 1,000,000
        for ($i = 0; $i < 10; $i++) {
            $this->insertTransaction([
                'total_amount'      => 100000,
                'created_at_device' => '2026-09-15 03:00:00',
            ]);
        }
        // Current: 400,000 -> 60.0% decline
        for ($i = 0; $i < 4; $i++) {
            $this->insertTransaction([
                'total_amount'      => 100000,
                'created_at_device' => '2026-09-24 03:00:00',
            ]);
        }

        $res = $this->warningService->evaluateRevenueDecline($this->user->id, $this->outlet->id, '2026-09-24', [
            'confirmed'            => true,
            'prior_window_decline' => 55.0,
        ]);

        $this->assertEquals(FinancialWarningService::STATUS_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_CRITICAL, $res['severity']);
        $this->assertEquals(-60.0, $res['evidence']['percentage_change']);
    }

    /** @test */
    public function revenue_insufficient_baseline_fails_closed()
    {
        // Baseline: only 300,000 (< 500,000 required baseline floor)
        $this->insertTransaction([
            'total_amount'      => 300000,
            'created_at_device' => '2026-09-15 03:00:00',
        ]);
        // Current: 100,000
        $this->insertTransaction([
            'total_amount'      => 100000,
            'created_at_device' => '2026-09-24 03:00:00',
        ]);

        $res = $this->warningService->evaluateRevenueDecline($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_INSUFFICIENT_DATA, $res['status']);
        $this->assertEquals(FinancialWarningService::DQ_INSUFFICIENT_BASELINE, $res['data_quality']);
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
    }

    /** @test */
    public function revenue_timezone_boundary_preserves_wib_cutoffs()
    {
        // 2026-09-24 16:59:59 UTC = 23:59:59 WIB (Inside current window ending 2026-09-24)
        $this->insertTransaction([
            'total_amount'      => 600000,
            'created_at_device' => '2026-09-24 16:59:59',
        ]);
        // 2026-09-24 17:00:00 UTC = 2026-09-25 00:00:00 WIB (Next day — OUTSIDE window)
        $this->insertTransaction([
            'total_amount'      => 900000,
            'created_at_device' => '2026-09-24 17:00:00',
        ]);
        // Baseline: 600,000 on 2026-09-15
        $this->insertTransaction([
            'total_amount'      => 600000,
            'created_at_device' => '2026-09-15 03:00:00',
        ]);

        $res = $this->warningService->evaluateRevenueDecline($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(600000, $res['actual'], 'The 900k transaction at 17:00:00 UTC belongs to the next day WIB and must be excluded');
    }

    /** @test */
    public function revenue_tenant_isolation_strictly_enforced()
    {
        $otherUser = User::factory()->create();
        $otherOutlet = Outlet::factory()->create(['user_id' => $otherUser->id]);

        // Current user baseline & current
        $this->insertTransaction([
            'user_id'           => $this->user->id,
            'outlet_id'         => $this->outlet->id,
            'total_amount'      => 600000,
            'created_at_device' => '2026-09-15 03:00:00',
        ]);
        $this->insertTransaction([
            'user_id'           => $this->user->id,
            'outlet_id'         => $this->outlet->id,
            'total_amount'      => 600000,
            'created_at_device' => '2026-09-20 03:00:00',
        ]);

        // Other user huge transactions
        $this->insertTransaction([
            'user_id'           => $otherUser->id,
            'outlet_id'         => $otherOutlet->id,
            'total_amount'      => 50000000,
            'created_at_device' => '2026-09-20 03:00:00',
        ]);

        $res = $this->warningService->evaluateRevenueDecline($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(600000, $res['actual']);
        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status']);
    }

    // ================================================================
    // B. EXPENSE_SPIKE TESTS
    // ================================================================

    /** @test */
    public function expense_normal_within_bounds()
    {
        // 30d baseline: 30 days x 100k = 3,000,000. Weekly avg = 700,000.
        for ($i = 1; $i <= 30; $i++) {
            $date = Carbon::parse('2026-09-17')->subDays($i - 1)->format('Y-m-d');
            $this->insertExpense(['amount' => 100000, 'date' => $date]);
        }
        // Current 7d expense: 700,000
        $this->insertExpense(['amount' => 700000, 'date' => '2026-09-20']);

        $res = $this->warningService->evaluateExpenseSpike($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
        $this->assertEquals(1.0, $res['evidence']['ratio']);
    }

    /** @test */
    public function expense_exactly_150_percent_does_not_trigger_warning()
    {
        // 30d baseline total = 3,000,000 (weekly avg = 700,000)
        $this->insertExpense(['amount' => 3000000, 'date' => '2026-09-01']);

        // Current 7d: 1,050,000 (exactly 1.50x of 700,000)
        $this->insertExpense(['amount' => 1050000, 'date' => '2026-09-20']);

        $res = $this->warningService->evaluateExpenseSpike($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
        $this->assertEquals(1.5, $res['evidence']['ratio']);
    }

    /** @test */
    public function expense_greater_than_150_percent_below_rp500k_does_not_trigger_warning()
    {
        // 30d baseline total = 428,571 (weekly avg = 100,000)
        $this->insertExpense(['amount' => 428571, 'date' => '2026-09-01']);

        // Current 7d: 200,000 (2.0x of weekly baseline > 150%, but < Rp500,000 floor!)
        $this->insertExpense(['amount' => 200000, 'date' => '2026-09-20']);

        $res = $this->warningService->evaluateExpenseSpike($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status'], 'Expense spike below Rp500,000 floor must not trigger warning');
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
    }

    /** @test */
    public function expense_greater_than_150_percent_and_above_rp500k_triggers_warning()
    {
        // 30d baseline total = 1,285,714 (weekly avg = 300,000)
        $this->insertExpense(['amount' => 1285714, 'date' => '2026-09-01']);

        // Current 7d: 600,000 (2.0x > 1.5x AND 600k >= Rp500,000)
        $this->insertExpense(['amount' => 600000, 'date' => '2026-09-20']);

        $res = $this->warningService->evaluateExpenseSpike($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_WARNING, $res['severity']);
        $this->assertEquals(2.0, $res['evidence']['ratio']);
    }

    /** @test */
    public function expense_greater_than_300_percent_below_rp2m_triggers_warning_not_critical()
    {
        // 30d baseline weekly avg = 200,000 (total = 857,143)
        $this->insertExpense(['amount' => 857143, 'date' => '2026-09-01']);

        // Current 7d: 800,000 (4.0x > 3.0x BUT 800k < Rp2,000,000)
        $this->insertExpense(['amount' => 800000, 'date' => '2026-09-20']);

        $res = $this->warningService->evaluateExpenseSpike($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_WARNING, $res['severity'], 'Expense >300% but <Rp2,000,000 must be WARNING, not CRITICAL');
    }

    /** @test */
    public function expense_greater_than_300_percent_and_above_rp2m_triggers_critical()
    {
        // 30d baseline weekly avg = 500,000 (total = 2,142,857)
        $this->insertExpense(['amount' => 2142857, 'date' => '2026-09-01']);

        // Current 7d: 2,500,000 (5.0x > 3.0x AND 2.5m >= Rp2,000,000)
        $this->insertExpense(['amount' => 2500000, 'date' => '2026-09-20']);

        $res = $this->warningService->evaluateExpenseSpike($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_CRITICAL, $res['severity']);
    }

    /** @test */
    public function expense_zero_baseline_returns_insufficient_data_safely()
    {
        // No baseline expense, current 7d = 1,000,000
        $this->insertExpense(['amount' => 1000000, 'date' => '2026-09-20']);

        $res = $this->warningService->evaluateExpenseSpike($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_INSUFFICIENT_DATA, $res['status']);
        $this->assertEquals(FinancialWarningService::DQ_INSUFFICIENT_BASELINE, $res['data_quality']);
        $this->assertNull($res['baseline']);
    }

    // ================================================================
    // C. MARGIN_COMPRESSION TESTS
    // ================================================================

    /** @test */
    public function margin_normal_stable()
    {
        // Baseline: Rev 1m, COGS 600k -> 40.0% margin
        $txBase = $this->insertTransaction(['total_amount' => 1000000, 'created_at_device' => '2026-09-15 03:00:00']);
        $this->insertItem($txBase, ['price' => 1000000, 'cost_price' => 600000]);

        // Current: Rev 1m, COGS 620k -> 38.0% margin (drop 2.0 pp <= 5.0 pp)
        $txCur = $this->insertTransaction(['total_amount' => 1000000, 'created_at_device' => '2026-09-20 03:00:00']);
        $this->insertItem($txCur, ['price' => 1000000, 'cost_price' => 620000]);

        $res = $this->warningService->evaluateMarginCompression($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
        $this->assertEquals(2.0, $res['evidence']['drop_percentage_points']);
    }

    /** @test */
    public function margin_exactly_5pp_drop_does_not_trigger_warning()
    {
        // Baseline: 40.0% margin
        $txBase = $this->insertTransaction(['total_amount' => 1000000, 'created_at_device' => '2026-09-15 03:00:00']);
        $this->insertItem($txBase, ['price' => 1000000, 'cost_price' => 600000]);

        // Current: 35.0% margin (drop = 5.0 pp)
        $txCur = $this->insertTransaction(['total_amount' => 1000000, 'created_at_device' => '2026-09-20 03:00:00']);
        $this->insertItem($txCur, ['price' => 1000000, 'cost_price' => 650000]);

        $res = $this->warningService->evaluateMarginCompression($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
        $this->assertEquals(5.0, $res['evidence']['drop_percentage_points']);
    }

    /** @test */
    public function margin_greater_than_5pp_drop_triggers_warning()
    {
        // Baseline: 40.0% margin
        $txBase = $this->insertTransaction(['total_amount' => 1000000, 'created_at_device' => '2026-09-15 03:00:00']);
        $this->insertItem($txBase, ['price' => 1000000, 'cost_price' => 600000]);

        // Current: 32.0% margin (drop = 8.0 pp > 5.0 pp)
        $txCur = $this->insertTransaction(['total_amount' => 1000000, 'created_at_device' => '2026-09-20 03:00:00']);
        $this->insertItem($txCur, ['price' => 1000000, 'cost_price' => 680000]);

        $res = $this->warningService->evaluateMarginCompression($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_WARNING, $res['severity']);
        $this->assertEquals(8.0, $res['evidence']['drop_percentage_points']);
    }

    /** @test */
    public function margin_greater_than_15pp_drop_triggers_critical()
    {
        // Baseline: 50.0% margin
        $txBase = $this->insertTransaction(['total_amount' => 1000000, 'created_at_device' => '2026-09-15 03:00:00']);
        $this->insertItem($txBase, ['price' => 1000000, 'cost_price' => 500000]);

        // Current: 30.0% margin (drop = 20.0 pp > 15.0 pp)
        $txCur = $this->insertTransaction(['total_amount' => 1000000, 'created_at_device' => '2026-09-20 03:00:00']);
        $this->insertItem($txCur, ['price' => 1000000, 'cost_price' => 700000]);

        $res = $this->warningService->evaluateMarginCompression($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_CRITICAL, $res['severity']);
        $this->assertEquals(20.0, $res['evidence']['drop_percentage_points']);
    }

    /** @test */
    public function margin_untracked_cogs_above_15_percent_fails_closed_to_insufficient_data()
    {
        // Baseline: 1m revenue
        $txBase = $this->insertTransaction(['total_amount' => 1000000, 'created_at_device' => '2026-09-15 03:00:00']);
        $this->insertItem($txBase, ['price' => 1000000, 'cost_price' => 500000]);

        // Current: 4 items tracked, 1 item untracked (cost_price NULL) -> 20.0% untracked (>15%)
        $txCur = $this->insertTransaction(['total_amount' => 1000000, 'created_at_device' => '2026-09-20 03:00:00']);
        for ($i = 0; $i < 4; $i++) {
            $this->insertItem($txCur, ['price' => 200000, 'cost_price' => 150000]);
        }
        $this->insertItem($txCur, ['price' => 200000, 'cost_price' => 0]);

        $res = $this->warningService->evaluateMarginCompression($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_INSUFFICIENT_DATA, $res['status']);
        $this->assertEquals(FinancialWarningService::DQ_UNTRACKED_COGS, $res['data_quality']);
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
    }

    /** @test */
    public function margin_zero_revenue_returns_undefined_safely()
    {
        // No current transactions
        $txBase = $this->insertTransaction(['total_amount' => 1000000, 'created_at_device' => '2026-09-15 03:00:00']);
        $this->insertItem($txBase, ['price' => 1000000, 'cost_price' => 500000]);

        $res = $this->warningService->evaluateMarginCompression($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_INSUFFICIENT_DATA, $res['status']);
        $this->assertEquals(FinancialWarningService::DQ_UNDEFINED, $res['data_quality']);
    }

    // ================================================================
    // D. ABNORMAL_VOID_REFUND TESTS
    // ================================================================

    /** @test */
    public function void_refund_less_than_5_percent_normal()
    {
        // 20 total transactions, 0 void (0.0%)
        for ($i = 0; $i < 20; $i++) {
            $this->insertTransaction([
                'status'            => 'Selesai',
                'created_at_device' => '2026-09-24 03:00:00',
            ]);
        }

        $res = $this->warningService->evaluateAbnormalVoidRefund($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
        $this->assertEquals(0.0, $res['actual']);
    }

    /** @test */
    public function void_refund_exactly_5_percent_does_not_trigger_warning()
    {
        // 20 total: 19 Selesai, 1 Batal (1/20 = 5.0%)
        for ($i = 0; $i < 19; $i++) {
            $this->insertTransaction(['status' => 'Selesai', 'created_at_device' => '2026-09-24 03:00:00']);
        }
        $this->insertTransaction(['status' => 'Batal', 'created_at_device' => '2026-09-24 03:00:00']);

        $res = $this->warningService->evaluateAbnormalVoidRefund($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
        $this->assertEquals(5.0, $res['actual']);
    }

    /** @test */
    public function void_refund_greater_than_5_percent_triggers_warning()
    {
        // 20 total: 18 Selesai, 2 Refund (2/20 = 10.0% > 5%)
        for ($i = 0; $i < 18; $i++) {
            $this->insertTransaction(['status' => 'Selesai', 'created_at_device' => '2026-09-24 03:00:00']);
        }
        $this->insertTransaction(['status' => 'Refund', 'created_at_device' => '2026-09-24 03:00:00']);
        $this->insertTransaction(['status' => 'Void', 'created_at_device' => '2026-09-24 03:00:00']);

        $res = $this->warningService->evaluateAbnormalVoidRefund($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_WARNING, $res['severity']);
        $this->assertEquals(10.0, $res['actual']);
    }

    /** @test */
    public function void_refund_greater_than_15_percent_triggers_critical()
    {
        // 20 total: 16 Selesai, 4 Batal (4/20 = 20.0% > 15%)
        for ($i = 0; $i < 16; $i++) {
            $this->insertTransaction(['status' => 'Selesai', 'created_at_device' => '2026-09-24 03:00:00']);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->insertTransaction(['status' => 'Batal', 'created_at_device' => '2026-09-24 03:00:00']);
        }

        $res = $this->warningService->evaluateAbnormalVoidRefund($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_CRITICAL, $res['severity']);
        $this->assertEquals(20.0, $res['actual']);
    }

    /** @test */
    public function void_refund_19_transactions_fails_closed_due_to_volume_gate()
    {
        // 19 total: 17 Selesai, 2 Batal (10.5% > 5%, but total < 20)
        for ($i = 0; $i < 17; $i++) {
            $this->insertTransaction(['status' => 'Selesai', 'created_at_device' => '2026-09-24 03:00:00']);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->insertTransaction(['status' => 'Batal', 'created_at_device' => '2026-09-24 03:00:00']);
        }

        $res = $this->warningService->evaluateAbnormalVoidRefund($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_INSUFFICIENT_DATA, $res['status']);
        $this->assertEquals(FinancialWarningService::DQ_INSUFFICIENT_VOLUME, $res['data_quality']);
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
    }

    /** @test */
    public function void_refund_exactly_20_transactions_evaluates_normally()
    {
        // Exactly 20 transactions
        for ($i = 0; $i < 18; $i++) {
            $this->insertTransaction(['status' => 'Selesai', 'created_at_device' => '2026-09-24 03:00:00']);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->insertTransaction(['status' => 'Batal', 'created_at_device' => '2026-09-24 03:00:00']);
        }

        $res = $this->warningService->evaluateAbnormalVoidRefund($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::DQ_SUFFICIENT, $res['data_quality']);
    }

    /** @test */
    public function void_refund_tenant_isolation_strictly_enforced()
    {
        $otherUser = User::factory()->create();
        $otherOutlet = Outlet::factory()->create(['user_id' => $otherUser->id]);

        // Current user: 20 completed transactions (0% void)
        for ($i = 0; $i < 20; $i++) {
            $this->insertTransaction(['status' => 'Selesai', 'created_at_device' => '2026-09-24 03:00:00']);
        }

        // Other user: 10 cancelled transactions
        for ($i = 0; $i < 10; $i++) {
            $this->insertTransaction([
                'user_id'           => $otherUser->id,
                'outlet_id'         => $otherOutlet->id,
                'status'            => 'Batal',
                'created_at_device' => '2026-09-24 03:00:00',
            ]);
        }

        $res = $this->warningService->evaluateAbnormalVoidRefund($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status']);
        $this->assertEquals(0.0, $res['actual']);
    }

    // ================================================================
    // E. DRAWER_DISCREPANCY TESTS
    // ================================================================

    /** @test */
    public function drawer_surplus_does_not_trigger_warning()
    {
        // Expected cash 500k, actual physical cash 550k (+50k surplus)
        $shiftId = $this->insertShift([
            'expected_cash'    => 500000,
            'end_cash'         => 550000,
            'total_cash_sales' => 400000,
            'status'           => 'closed',
        ]);

        $res = $this->warningService->evaluateDrawerDiscrepancy($this->user->id, $this->outlet->id, $shiftId);

        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
        $this->assertEquals(0.0, $res['actual']);
        $this->assertEquals(50000, $res['evidence']['surplus']);
    }

    /** @test */
    public function drawer_deficit_below_threshold_normal()
    {
        // Expected 500k, actual 480k (deficit 20k <= Rp50k)
        $shiftId = $this->insertShift([
            'expected_cash'    => 500000,
            'end_cash'         => 480000,
            'total_cash_sales' => 1000000,
            'status'           => 'closed',
        ]);

        $res = $this->warningService->evaluateDrawerDiscrepancy($this->user->id, $this->outlet->id, $shiftId);

        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
        $this->assertEquals(20000, $res['actual']);
    }

    /** @test */
    public function drawer_deficit_nominal_threshold_only_does_not_trigger_warning()
    {
        // Expected 5,000,000, end_cash 4,930,000 (deficit 70,000 > 50k, BUT 70k/5m = 1.4% <= 2.0%)
        $shiftId = $this->insertShift([
            'expected_cash'    => 5000000,
            'end_cash'         => 4930000,
            'total_cash_sales' => 5000000,
            'status'           => 'closed',
        ]);

        $res = $this->warningService->evaluateDrawerDiscrepancy($this->user->id, $this->outlet->id, $shiftId);

        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
        $this->assertEquals(1.4, $res['evidence']['deficit_percentage']);
    }

    /** @test */
    public function drawer_deficit_percentage_threshold_only_does_not_trigger_warning()
    {
        // Expected 200,000, end_cash 180,000 (deficit 20,000 <= 50k, but 20k/200k = 10% > 2.0%)
        $shiftId = $this->insertShift([
            'expected_cash'    => 200000,
            'end_cash'         => 180000,
            'total_cash_sales' => 200000,
            'status'           => 'closed',
        ]);

        $res = $this->warningService->evaluateDrawerDiscrepancy($this->user->id, $this->outlet->id, $shiftId);

        $this->assertEquals(FinancialWarningService::STATUS_NO_WARNING, $res['status'], 'Drawer deficit <= Rp50,000 floor must not trigger warning');
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
    }

    /** @test */
    public function drawer_deficit_both_thresholds_triggers_warning()
    {
        // Expected 1,000,000, end_cash 900,000 (deficit 100,000 > 50k AND 100k/1m = 10% > 2.0%)
        $shiftId = $this->insertShift([
            'expected_cash'    => 1000000,
            'end_cash'         => 900000,
            'total_cash_sales' => 1000000,
            'status'           => 'closed',
        ]);

        $res = $this->warningService->evaluateDrawerDiscrepancy($this->user->id, $this->outlet->id, $shiftId);

        $this->assertEquals(FinancialWarningService::STATUS_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_WARNING, $res['severity']);
        $this->assertEquals(100000, $res['actual']);
    }

    /** @test */
    public function drawer_deficit_critical_triggers_critical()
    {
        // Expected 5,000,000, end_cash 4,300,000 (deficit 700,000 > 500k AND 700k/4m = 17.5% > 10%)
        $shiftId = $this->insertShift([
            'expected_cash'    => 5000000,
            'end_cash'         => 4300000,
            'total_cash_sales' => 4000000,
            'status'           => 'closed',
        ]);

        $res = $this->warningService->evaluateDrawerDiscrepancy($this->user->id, $this->outlet->id, $shiftId);

        $this->assertEquals(FinancialWarningService::STATUS_WARNING, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_CRITICAL, $res['severity']);
        $this->assertEquals(700000, $res['actual']);
    }

    /** @test */
    public function drawer_missing_shift_returns_insufficient_data()
    {
        $res = $this->warningService->evaluateDrawerDiscrepancy($this->user->id, $this->outlet->id);

        $this->assertEquals(FinancialWarningService::STATUS_INSUFFICIENT_DATA, $res['status']);
        $this->assertEquals(FinancialWarningService::DQ_INSUFFICIENT_VOLUME, $res['data_quality']);
    }

    /** @test */
    public function drawer_zero_cash_sales_returns_undefined_safely()
    {
        // Deficit exists (500k vs 400k = 100k), but cash sales = 0 (prevents division by zero)
        $shiftId = $this->insertShift([
            'expected_cash'    => 500000,
            'end_cash'         => 400000,
            'total_cash_sales' => 0,
            'status'           => 'closed',
        ]);

        $res = $this->warningService->evaluateDrawerDiscrepancy($this->user->id, $this->outlet->id, $shiftId);

        $this->assertEquals(FinancialWarningService::STATUS_INSUFFICIENT_DATA, $res['status']);
        $this->assertEquals(FinancialWarningService::DQ_UNDEFINED, $res['data_quality']);
    }

    // ================================================================
    // F. GLOBAL & CONTRACT INTEGRATION TESTS
    // ================================================================

    /** @test */
    public function cash_depletion_is_strictly_suspended()
    {
        $res = $this->warningService->evaluateCashDepletion($this->user->id, $this->outlet->id);

        $this->assertEquals(FinancialWarningService::CODE_CASH_DEPLETION, $res['code']);
        $this->assertEquals(FinancialWarningService::STATUS_SUSPENDED, $res['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
        $this->assertEquals(FinancialWarningService::DQ_SUSPENDED, $res['data_quality']);
        $this->assertContains('POS cash drawer is not equivalent to business liquidity.', $res['limitations']);
    }

    /** @test */
    public function evaluate_all_warnings_returns_canonical_keys_without_division_by_zero()
    {
        $all = $this->warningService->evaluateAllWarnings($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertCount(6, $all);
        $this->assertArrayHasKey(FinancialWarningService::CODE_REVENUE_DECLINE, $all);
        $this->assertArrayHasKey(FinancialWarningService::CODE_EXPENSE_SPIKE, $all);
        $this->assertArrayHasKey(FinancialWarningService::CODE_MARGIN_COMPRESSION, $all);
        $this->assertArrayHasKey(FinancialWarningService::CODE_ABNORMAL_VOID_REFUND, $all);
        $this->assertArrayHasKey(FinancialWarningService::CODE_DRAWER_DISCREPANCY, $all);
        $this->assertArrayHasKey(FinancialWarningService::CODE_CASH_DEPLETION, $all);

        foreach ($all as $code => $contract) {
            $this->assertEquals($code, $contract['code']);
            $this->assertContains($contract['status'], [
                FinancialWarningService::STATUS_NO_WARNING,
                FinancialWarningService::STATUS_WARNING,
                FinancialWarningService::STATUS_INSUFFICIENT_DATA,
                FinancialWarningService::STATUS_SUSPENDED,
                FinancialWarningService::STATUS_UNDEFINED,
            ]);
            $this->assertContains($contract['severity'], [
                FinancialWarningService::SEVERITY_NONE,
                FinancialWarningService::SEVERITY_WATCH,
                FinancialWarningService::SEVERITY_WARNING,
                FinancialWarningService::SEVERITY_CRITICAL,
            ]);
            $this->assertFalse(is_nan($contract['actual'] ?? 0.0));
            $this->assertFalse(is_infinite($contract['actual'] ?? 0.0));
        }
    }

    /** @test */
    public function fingerprint_generation_is_deterministic_and_unique()
    {
        $fp1 = $this->warningService->generateFingerprint($this->user->id, $this->outlet->id, 'REVENUE_DECLINE', '2026-09-24', 'WARNING');
        $fp2 = $this->warningService->generateFingerprint($this->user->id, $this->outlet->id, 'REVENUE_DECLINE', '2026-09-24', 'WARNING');
        $fpChanged = $this->warningService->generateFingerprint($this->user->id, $this->outlet->id, 'REVENUE_DECLINE', '2026-09-24', 'CRITICAL');

        $this->assertEquals($fp1, $fp2, 'Fingerprints with identical parameters must match exactly');
        $this->assertNotEquals($fp1, $fpChanged, 'Fingerprints with different severity must not match (prevents hiding escalated critical alarms)');
    }

    /** @test */
    public function get_cached_warnings_memoizes_without_cross_tenant_leakage()
    {
        Cache::flush();

        $res1 = $this->warningService->getCachedWarnings($this->user->id, $this->outlet->id, '2026-09-24', 30);
        $this->assertIsArray($res1);

        $cached = Cache::get("fin_warn_v1_{$this->user->id}_{$this->outlet->id}_2026-09-24");
        $this->assertNotNull($cached, 'Result must be stored in cache under tenant-specific key');

        // Other outlet has no cached key
        $otherOutletKey = Cache::get("fin_warn_v1_{$this->user->id}_99999_2026-09-24");
        $this->assertNull($otherOutletKey, 'No cross-outlet cache leakage');
    }

    /** @test */
    public function expense_insufficient_baseline_without_records_returns_insufficient_data()
    {
        // Zero expense in baseline and zero in current
        $res = $this->warningService->evaluateExpenseSpike($this->user->id, $this->outlet->id, '2026-09-24');

        $this->assertEquals(FinancialWarningService::STATUS_INSUFFICIENT_DATA, $res['status']);
        $this->assertEquals(FinancialWarningService::DQ_INSUFFICIENT_BASELINE, $res['data_quality']);
        $this->assertEquals(FinancialWarningService::SEVERITY_NONE, $res['severity']);
    }

    /** @test */
    public function outlet_isolation_strictly_enforced()
    {
        $outlet2 = Outlet::factory()->create(['user_id' => $this->user->id]);

        // Transaction in outlet 2 only
        $this->insertTransaction([
            'outlet_id'         => $outlet2->id,
            'total_amount'      => 1000000,
            'created_at_device' => '2026-09-24 03:00:00',
        ]);

        // Evaluate outlet 1: should have 0 revenue
        $resOutlet1 = $this->warningService->evaluateRevenueDecline($this->user->id, $this->outlet->id, '2026-09-24');
        $this->assertEquals(FinancialWarningService::STATUS_INSUFFICIENT_DATA, $resOutlet1['status']);

        // Evaluate outlet 2: should see outlet 2's transaction
        $resOutlet2 = $this->warningService->evaluateRevenueDecline($this->user->id, $outlet2->id, '2026-09-24');
        $this->assertEquals(1000000, $resOutlet2['actual']);
    }

    /** @test */
    public function global_no_inf_or_nan_across_all_metrics()
    {
        // Intentionally trigger edge conditions (zeros, empty states)
        $all = $this->warningService->evaluateAllWarnings($this->user->id, $this->outlet->id, '2026-09-24');

        foreach ($all as $code => $contract) {
            if ($contract['actual'] !== null) {
                $this->assertFalse(is_nan($contract['actual']), "{$code} actual is NaN");
                $this->assertFalse(is_infinite($contract['actual']), "{$code} actual is INF");
            }
            if ($contract['baseline'] !== null) {
                $this->assertFalse(is_nan($contract['baseline']), "{$code} baseline is NaN");
                $this->assertFalse(is_infinite($contract['baseline']), "{$code} baseline is INF");
            }
            if ($contract['threshold'] !== null && is_numeric($contract['threshold'])) {
                $this->assertFalse(is_nan($contract['threshold']), "{$code} threshold is NaN");
                $this->assertFalse(is_infinite($contract['threshold']), "{$code} threshold is INF");
            }
            foreach ($contract['evidence'] as $key => $val) {
                if (is_numeric($val)) {
                    $this->assertFalse(is_nan($val), "{$code} evidence.{$key} is NaN");
                    $this->assertFalse(is_infinite($val), "{$code} evidence.{$key} is INF");
                }
            }
        }
    }
}
