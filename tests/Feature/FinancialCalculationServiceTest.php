<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Shift;
use App\Services\Financial\FinancialCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Phase A — FinancialCalculationService Comprehensive Test Suite
 *
 * Test groups:
 *   A. Revenue (normal, Batal, Refund, Void, Cancel, kasbon, partial payment)
 *   B. COGS (normal cost_price, NULL cost_price, excluded statuses)
 *   C. Timezone boundary (00:00 WIB, 06:59 WIB, 07:00 WIB, day boundary)
 *   D. Tenant isolation (different user_id, different outlet_id)
 *   E. Cash vs Revenue (digital revenue ≠ cash drawer)
 *   F. Cash Runway (burn > 0, burn = 0, burn < 0, insufficient history)
 *   G. Gross/Net Profit formulas
 *
 * All tests run on in-memory SQLite via RefreshDatabase.
 */
class FinancialCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected FinancialCalculationService $service;
    protected User $user;
    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new FinancialCalculationService();

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

    /**
     * Insert a transaction row directly into the DB.
     * created_at_device should be UTC timestamp string.
     */
    private function insertTransaction(array $overrides = []): int
    {
        $defaults = [
            'user_id'                 => $this->user->id,
            'outlet_id'               => $this->outlet->id,
            'app_uuid'                => 'tx-uuid-' . uniqid('', true),
            'official_invoice_number' => 'INV-' . uniqid(),
            'total_amount'            => 100000,
            'pay_amount'              => 100000,
            'payment_method'          => 'Tunai / Cash',
            'status'                  => 'Selesai',
            'created_at_device'       => '2026-09-24 10:00:00', // 17:00 WIB
            'created_at'              => now(),
            'updated_at'              => now(),
        ];

        $data = array_merge($defaults, $overrides);
        return DB::table('transactions')->insertGetId($data);
    }

    /**
     * Insert a transaction_item row.
     */
    private function insertItem(int $transactionId, array $overrides = []): void
    {
        $defaults = [
            'transaction_id' => $transactionId,
            'menu_id'        => 1,
            'menu_name'      => 'Nasi Goreng',
            'qty'            => 1,
            'price'          => 100000,
            'cost_price'     => 30000,
            'status'         => 'completed',
            'created_at'     => now(),
            'updated_at'     => now(),
        ];

        DB::table('transaction_items')->insert(array_merge($defaults, $overrides));
    }

    /**
     * Insert an expense row.
     */
    private function insertExpense(array $overrides = []): void
    {
        $defaults = [
            'user_id'    => $this->user->id,
            'outlet_id'  => $this->outlet->id,
            'name'       => 'Gas LPG',
            'amount'     => 50000,
            'category'   => 'Operasional',
            'date'       => '2026-09-24',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('expenses')->insert(array_merge($defaults, $overrides));
    }

    /**
     * Insert a shift row. user_id is NOT in Shift's $fillable, so we use raw DB.
     */
    private function insertShift(array $overrides = []): int
    {
        $defaults = [
            'user_id'              => $this->user->id,
            'outlet_id'            => $this->outlet->id,
            'app_uuid'             => 'shift-' . uniqid(),
            'cashier_name'         => 'Kasir Test',
            'start_time'           => '2026-09-24 01:00:00', // 08:00 WIB
            'start_cash'           => 200000,
            'total_cash_sales'     => 500000,
            'total_expense'        => 0,
            'cash_drop'            => 0,
            'total_cash_refunded'  => 0,
            'expected_cash'        => 700000,
            'status'               => 'open',
            'created_at'           => now(),
            'updated_at'           => now(),
        ];

        return DB::table('shifts')->insertGetId(array_merge($defaults, $overrides));
    }

    // ================================================================
    // A. REVENUE TESTS
    // ================================================================

    /** @test */
    public function revenue_normal_completed_transaction()
    {
        $this->insertTransaction([
            'total_amount'      => 150000,
            'pay_amount'        => 150000,
            'status'            => 'Selesai',
            'created_at_device' => '2026-09-24 10:00:00',
        ]);

        $result = $this->service->calculateRevenue(
            $this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );

        $this->assertEquals(150000.0, $result['value']);
        $this->assertEquals(1, $result['records_count']);
    }

    /** @test */
    public function revenue_excludes_batal()
    {
        $this->insertTransaction(['status' => 'Selesai', 'total_amount' => 100000, 'pay_amount' => 100000]);
        $this->insertTransaction(['status' => 'Batal',   'total_amount' =>  50000, 'pay_amount' =>  50000]);

        $result = $this->service->calculateRevenue(
            $this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );

        $this->assertEquals(100000.0, $result['value']);
        $this->assertEquals(1, $result['records_count']);
    }

    /** @test */
    public function revenue_excludes_refund()
    {
        $this->insertTransaction(['status' => 'Selesai', 'total_amount' => 100000, 'pay_amount' => 100000]);
        $this->insertTransaction(['status' => 'Refund',  'total_amount' =>  30000, 'pay_amount' =>  30000]);

        $result = $this->service->calculateRevenue(
            $this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );

        $this->assertEquals(100000.0, $result['value']);
        $this->assertEquals(1, $result['records_count']);
    }

    /** @test */
    public function revenue_excludes_void()
    {
        $this->insertTransaction(['status' => 'Selesai', 'total_amount' => 200000, 'pay_amount' => 200000]);
        $this->insertTransaction(['status' => 'Void',    'total_amount' =>  80000, 'pay_amount' =>  80000]);

        $result = $this->service->calculateRevenue(
            $this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );

        $this->assertEquals(200000.0, $result['value']);
        $this->assertEquals(1, $result['records_count']);
    }

    /** @test */
    public function revenue_excludes_cancel_and_canceled()
    {
        $this->insertTransaction(['status' => 'Selesai',   'total_amount' => 100000, 'pay_amount' => 100000]);
        $this->insertTransaction(['status' => 'Cancel',    'total_amount' =>  40000, 'pay_amount' =>  40000]);
        $this->insertTransaction(['status' => 'Canceled',  'total_amount' =>  60000, 'pay_amount' =>  60000]);

        $result = $this->service->calculateRevenue(
            $this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );

        $this->assertEquals(100000.0, $result['value']);
        $this->assertEquals(1, $result['records_count']);
    }

    /** @test */
    public function revenue_kasbon_bayar_nanti_only_counts_paid_portion()
    {
        // Kasbon: total 200k, paid 0 (belum bayar nanti)
        $this->insertTransaction([
            'total_amount'   => 200000,
            'pay_amount'     => 0,
            'payment_method' => 'Bayar Nanti',
            'status'         => 'Selesai',
        ]);

        $result = $this->service->calculateRevenue(
            $this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );

        // pay_amount=0 + payment_method='Bayar Nanti' → actual_paid = pay_amount = 0
        $this->assertEquals(0.0, $result['value']);
    }

    /** @test */
    public function revenue_partial_payment_uses_pay_amount()
    {
        // Partial: total 200k, paid 120k
        $this->insertTransaction([
            'total_amount'   => 200000,
            'pay_amount'     => 120000,
            'payment_method' => 'Tunai / Cash',
            'status'         => 'Selesai',
        ]);

        $result = $this->service->calculateRevenue(
            $this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );

        // pay_amount < total_amount → actual_paid = pay_amount = 120000
        $this->assertEquals(120000.0, $result['value']);
    }

    /** @test */
    public function revenue_legacy_transaction_zero_pay_no_kasbon()
    {
        // Legacy/old transaction: pay_amount=0, status=Selesai, payment_method not kasbon
        // This is a historical data pattern where pay_amount was 0 but transaction completed
        $this->insertTransaction([
            'total_amount'   => 75000,
            'pay_amount'     => 0,
            'payment_method' => 'Tunai / Cash',
            'status'         => 'Selesai',
        ]);

        $result = $this->service->calculateRevenue(
            $this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );

        // Legacy pattern: pay_amount=0 + Selesai + no bayar nanti → actual_paid = total_amount
        $this->assertEquals(75000.0, $result['value']);
    }

    // ================================================================
    // B. COGS TESTS
    // ================================================================

    /** @test */
    public function cogs_normal_cost_price()
    {
        $trxId = $this->insertTransaction(['status' => 'Selesai']);
        $this->insertItem($trxId, ['qty' => 2, 'cost_price' => 15000]);
        $this->insertItem($trxId, ['qty' => 3, 'cost_price' => 10000]);

        $result = $this->service->calculateCogs(
            $this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );

        // (2 * 15000) + (3 * 10000) = 30000 + 30000 = 60000
        $this->assertEquals(60000.0, $result['value']);
        $this->assertFalse($result['has_untracked_cogs']);
        $this->assertEquals(0, $result['untracked_items_count']);
    }

    /** @test */
    public function cogs_null_or_missing_cost_price_flagged()
    {
        $trxId = $this->insertTransaction(['status' => 'Selesai']);
        $this->insertItem($trxId, ['qty' => 2, 'cost_price' => 15000]);
        // Omitted / 0 cost_price (database default in SQLite/MySQL is 0 when untracked)
        $this->insertItem($trxId, ['qty' => 1, 'cost_price' => 0]);

        $result = $this->service->calculateCogs(
            $this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );

        // (2 * 15000) + (1 * 0) = 30000 + 0 = 30000
        $this->assertEquals(30000.0, $result['value']);
        $this->assertTrue($result['has_untracked_cogs']);
        $this->assertEquals(1, $result['untracked_items_count']);
        $this->assertEquals(2, $result['total_items_count']);
        $this->assertEquals(50.0, $result['untracked_items_percentage']);
    }

    /** @test */
    public function cogs_zero_cost_price_flagged()
    {
        $trxId = $this->insertTransaction(['status' => 'Selesai']);
        $this->insertItem($trxId, ['qty' => 1, 'cost_price' => 0]);

        $result = $this->service->calculateCogs(
            $this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );

        $this->assertEquals(0.0, $result['value']);
        $this->assertTrue($result['has_untracked_cogs']);
        $this->assertEquals(1, $result['untracked_items_count']);
    }

    /** @test */
    public function cogs_excludes_refund_and_void_transactions()
    {
        // Selesai transaction → should count
        $trxGood = $this->insertTransaction(['status' => 'Selesai']);
        $this->insertItem($trxGood, ['qty' => 1, 'cost_price' => 20000]);

        // Refund transaction → should NOT count
        $trxRefund = $this->insertTransaction(['status' => 'Refund']);
        $this->insertItem($trxRefund, ['qty' => 1, 'cost_price' => 15000]);

        // Void transaction → should NOT count
        $trxVoid = $this->insertTransaction(['status' => 'Void']);
        $this->insertItem($trxVoid, ['qty' => 1, 'cost_price' => 10000]);

        $result = $this->service->calculateCogs(
            $this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );

        // Only Selesai: 1 * 20000 = 20000
        $this->assertEquals(20000.0, $result['value']);
        $this->assertEquals(1, $result['total_items_count']);
    }

    /** @test */
    public function cogs_uses_same_cancel_statuses_as_revenue()
    {
        // Verify that COGS and Revenue use the exact same CANCEL_STATUSES
        // by inserting a Batal transaction and checking both exclude it.
        $trxOk = $this->insertTransaction(['status' => 'Selesai', 'total_amount' => 100000, 'pay_amount' => 100000]);
        $this->insertItem($trxOk, ['qty' => 1, 'cost_price' => 30000]);

        $trxBatal = $this->insertTransaction(['status' => 'Batal', 'total_amount' => 50000, 'pay_amount' => 50000]);
        $this->insertItem($trxBatal, ['qty' => 1, 'cost_price' => 20000]);

        $revenue = $this->service->calculateRevenue($this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta');
        $cogs = $this->service->calculateCogs($this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta');

        $this->assertEquals(100000.0, $revenue['value']);
        $this->assertEquals(30000.0, $cogs['value']);
        // Both should have exactly 1 record (the Batal one excluded from both)
        $this->assertEquals(1, $revenue['records_count']);
        $this->assertEquals(1, $cogs['total_items_count']);
    }

    // ================================================================
    // C. TIMEZONE BOUNDARY TESTS
    // ================================================================

    /** @test */
    public function timezone_transaction_at_0000_wib_included()
    {
        // 00:00 WIB on 2026-09-25 = 17:00 UTC on 2026-09-24
        $this->insertTransaction([
            'created_at_device' => '2026-09-24 17:00:00', // exactly 00:00 WIB Sep 25
            'status' => 'Selesai', 'total_amount' => 100000, 'pay_amount' => 100000,
        ]);

        $result = $this->service->calculateRevenue(
            $this->user->id, $this->outlet->id, '2026-09-25', '2026-09-25', 'Asia/Jakarta'
        );

        $this->assertEquals(100000.0, $result['value']);
        $this->assertEquals(1, $result['records_count']);
    }

    /** @test */
    public function timezone_transaction_at_0659_wib_included()
    {
        // 06:59 WIB on 2026-09-25 = 23:59 UTC on 2026-09-24
        $this->insertTransaction([
            'created_at_device' => '2026-09-24 23:59:00', // 06:59 WIB Sep 25
            'status' => 'Selesai', 'total_amount' => 80000, 'pay_amount' => 80000,
        ]);

        $result = $this->service->calculateRevenue(
            $this->user->id, $this->outlet->id, '2026-09-25', '2026-09-25', 'Asia/Jakarta'
        );

        $this->assertEquals(80000.0, $result['value']);
        $this->assertEquals(1, $result['records_count']);
    }

    /** @test */
    public function timezone_transaction_at_0700_wib_included()
    {
        // 07:00 WIB on 2026-09-25 = 00:00 UTC on 2026-09-25
        $this->insertTransaction([
            'created_at_device' => '2026-09-25 00:00:00', // 07:00 WIB Sep 25
            'status' => 'Selesai', 'total_amount' => 60000, 'pay_amount' => 60000,
        ]);

        $result = $this->service->calculateRevenue(
            $this->user->id, $this->outlet->id, '2026-09-25', '2026-09-25', 'Asia/Jakarta'
        );

        $this->assertEquals(60000.0, $result['value']);
    }

    /** @test */
    public function timezone_transaction_before_midnight_wib_excluded()
    {
        // 23:59 WIB on 2026-09-24 = 16:59 UTC on 2026-09-24
        // When querying for 2026-09-25, this should be EXCLUDED (it belongs to Sep 24 WIB)
        $this->insertTransaction([
            'created_at_device' => '2026-09-24 16:59:00', // 23:59 WIB Sep 24
            'status' => 'Selesai', 'total_amount' => 120000, 'pay_amount' => 120000,
        ]);

        $result = $this->service->calculateRevenue(
            $this->user->id, $this->outlet->id, '2026-09-25', '2026-09-25', 'Asia/Jakarta'
        );

        $this->assertEquals(0.0, $result['value']);
        $this->assertEquals(0, $result['records_count']);
    }

    /** @test */
    public function timezone_day_boundary_no_overlap()
    {
        // Transaction at exactly the boundary of Sep 24 → Sep 25 WIB
        // 00:00 WIB Sep 25 = 17:00 UTC Sep 24

        // This should be in Sep 25
        $this->insertTransaction([
            'created_at_device' => '2026-09-24 17:00:00',
            'status' => 'Selesai', 'total_amount' => 50000, 'pay_amount' => 50000,
        ]);

        // This should be in Sep 24 (last second of Sep 24 WIB = 16:59:59 UTC)
        $this->insertTransaction([
            'created_at_device' => '2026-09-24 16:59:59',
            'status' => 'Selesai', 'total_amount' => 30000, 'pay_amount' => 30000,
        ]);

        $resultSep24 = $this->service->calculateRevenue(
            $this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );
        $resultSep25 = $this->service->calculateRevenue(
            $this->user->id, $this->outlet->id, '2026-09-25', '2026-09-25', 'Asia/Jakarta'
        );

        // Sep 24 WIB range: UTC [Sep 23 17:00, Sep 24 17:00)
        // The 16:59:59 transaction is included, the 17:00:00 transaction is excluded
        $this->assertEquals(30000.0, $resultSep24['value']);
        $this->assertEquals(50000.0, $resultSep25['value']);
    }

    // ================================================================
    // D. TENANT ISOLATION TESTS
    // ================================================================

    /** @test */
    public function tenant_isolation_different_user()
    {
        $user2 = User::factory()->create(['role' => 'owner', 'account_status' => 'active']);
        $outlet2 = Outlet::factory()->create(['user_id' => $user2->id]);

        // User 1 transaction
        $this->insertTransaction(['total_amount' => 100000, 'pay_amount' => 100000]);

        // User 2 transaction (should NOT appear in user 1's revenue)
        $this->insertTransaction([
            'user_id'    => $user2->id,
            'outlet_id'  => $outlet2->id,
            'total_amount' => 200000,
            'pay_amount' => 200000,
        ]);

        $result1 = $this->service->calculateRevenue(
            $this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );
        $result2 = $this->service->calculateRevenue(
            $user2->id, $outlet2->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );

        $this->assertEquals(100000.0, $result1['value']);
        $this->assertEquals(200000.0, $result2['value']);
    }

    /** @test */
    public function tenant_isolation_different_outlet()
    {
        $outlet2 = Outlet::factory()->create(['user_id' => $this->user->id]);

        // Outlet 1 transaction
        $this->insertTransaction([
            'outlet_id'    => $this->outlet->id,
            'total_amount' => 100000,
            'pay_amount'   => 100000,
        ]);

        // Outlet 2 transaction (should NOT appear in outlet 1's revenue)
        $this->insertTransaction([
            'outlet_id'    => $outlet2->id,
            'total_amount' => 300000,
            'pay_amount'   => 300000,
        ]);

        $resultOutlet1 = $this->service->calculateRevenue(
            $this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );
        $resultOutlet2 = $this->service->calculateRevenue(
            $this->user->id, $outlet2->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );

        $this->assertEquals(100000.0, $resultOutlet1['value']);
        $this->assertEquals(300000.0, $resultOutlet2['value']);
    }

    /** @test */
    public function tenant_isolation_all_outlets()
    {
        $outlet2 = Outlet::factory()->create(['user_id' => $this->user->id]);

        $this->insertTransaction([
            'outlet_id' => $this->outlet->id,
            'total_amount' => 100000, 'pay_amount' => 100000,
        ]);
        $this->insertTransaction([
            'outlet_id' => $outlet2->id,
            'total_amount' => 200000, 'pay_amount' => 200000,
        ]);

        // null outlet = all outlets for this user
        $resultAll = $this->service->calculateRevenue(
            $this->user->id, null, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );

        $this->assertEquals(300000.0, $resultAll['value']);
        $this->assertEquals(2, $resultAll['records_count']);
    }

    /** @test */
    public function tenant_isolation_expenses_not_leaking()
    {
        $user2 = User::factory()->create(['role' => 'owner', 'account_status' => 'active']);
        $outlet2 = Outlet::factory()->create(['user_id' => $user2->id]);

        $this->insertExpense(['amount' => 50000]);
        $this->insertExpense([
            'user_id'   => $user2->id,
            'outlet_id' => $outlet2->id,
            'amount'    => 80000,
        ]);

        $result1 = $this->service->calculateExpenses($this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24');
        $result2 = $this->service->calculateExpenses($user2->id, $outlet2->id, '2026-09-24', '2026-09-24');

        $this->assertEquals(50000.0, $result1['value']);
        $this->assertEquals(80000.0, $result2['value']);
    }

    // ================================================================
    // E. CASH VS REVENUE TESTS
    // ================================================================

    /** @test */
    public function cash_position_does_not_include_digital_revenue()
    {
        // Digital transaction (QRIS) — Rp 5.000.000
        $this->insertTransaction([
            'total_amount'   => 5000000,
            'pay_amount'     => 5000000,
            'payment_method' => 'QRIS',
            'status'         => 'Selesai',
        ]);

        // Cash drawer (shift) — Rp 200.000
        $this->insertShift([
            'start_cash'       => 200000,
            'total_cash_sales' => 0,
            'status'           => 'open',
        ]);

        $cashPos = $this->service->getCashPosition($this->user->id, $this->outlet->id);

        // Cash position MUST be 200000, NOT 5200000
        $this->assertEquals(200000.0, $cashPos['value']);
        $this->assertNotEquals(5200000.0, $cashPos['value']);
        $this->assertEquals('ACTIVE_SHIFT_COMPUTED', $cashPos['source']);
    }

    /** @test */
    public function cash_position_active_shift_includes_cash_sales()
    {
        $this->insertShift([
            'start_cash'           => 200000,
            'total_cash_sales'     => 500000,
            'cash_drop'            => 100000,
            'total_expense'        => 50000,
            'total_cash_refunded'  => 25000,
            'status'               => 'open',
        ]);

        $cashPos = $this->service->getCashPosition($this->user->id, $this->outlet->id);

        // 200000 + 500000 - 100000 - 50000 - 25000 = 525000
        $this->assertEquals(525000.0, $cashPos['value']);
        $this->assertEquals('ACTIVE_SHIFT_COMPUTED', $cashPos['source']);
    }

    /** @test */
    public function cash_position_closed_shift_uses_end_cash()
    {
        $this->insertShift([
            'start_cash'       => 200000,
            'total_cash_sales' => 500000,
            'end_cash'         => 680000,
            'end_time'         => '2026-09-24 10:00:00',
            'status'           => 'closed',
        ]);

        $cashPos = $this->service->getCashPosition($this->user->id, $this->outlet->id);

        // Closed: use actual physical count (end_cash)
        $this->assertEquals(680000.0, $cashPos['value']);
        $this->assertEquals('CLOSED_SHIFT_END_CASH', $cashPos['source']);
    }

    /** @test */
    public function cash_position_no_shift_returns_null()
    {
        $cashPos = $this->service->getCashPosition($this->user->id, $this->outlet->id);

        $this->assertNull($cashPos['value']);
        $this->assertEquals('NO_SHIFT_DATA', $cashPos['source']);
    }

    // ================================================================
    // F. CASH RUNWAY TESTS
    // ================================================================

    /** @test */
    public function cash_runway_normal_burn_rate()
    {
        // Expenses for 14 days: Rp 1.400.000 total → 100k/day
        for ($i = 0; $i < 14; $i++) {
            $this->insertExpense([
                'amount' => 100000,
                'date'   => Carbon::parse('2026-09-24')->subDays($i)->format('Y-m-d'),
            ]);
        }

        // Need a transaction so days_active is valid
        $this->insertTransaction([
            'created_at_device' => Carbon::parse('2026-09-10')->format('Y-m-d H:i:s'),
        ]);

        $burnRate = $this->service->calculateDailyCashBurnRate(
            $this->user->id, $this->outlet->id, '2026-09-24'
        );

        $this->assertEquals('OK', $burnRate['status']);
        $this->assertEquals(100000.0, $burnRate['value']);
        $this->assertEquals(14, $burnRate['days_used']);

        // Now test runway with cash position = 500k
        $runway = $this->service->calculateCashRunway(500000.0, $burnRate['value']);

        $this->assertEquals(5.0, $runway['value']); // 500k / 100k = 5 days
        $this->assertEquals('OK', $runway['status']);
    }

    /** @test */
    public function cash_runway_burn_rate_zero_returns_null()
    {
        // No expenses at all
        $this->insertTransaction(); // Need at least 1 transaction for days_active

        $burnRate = $this->service->calculateDailyCashBurnRate(
            $this->user->id, $this->outlet->id, '2026-09-24'
        );

        $this->assertNull($burnRate['value']);
        $this->assertEquals('NO_EXPENSE_RECORDED', $burnRate['status']);

        // Runway with null burn rate
        $runway = $this->service->calculateCashRunway(500000.0, $burnRate['value']);

        $this->assertNull($runway['value']);
        $this->assertEquals('BURN_RATE_UNDEFINED', $runway['status']);
        // MUST NOT be INF or NaN
        $this->assertNotEquals(INF, $runway['value']);
    }

    /** @test */
    public function cash_runway_no_cash_position()
    {
        $runway = $this->service->calculateCashRunway(null, 100000.0);

        $this->assertNull($runway['value']);
        $this->assertEquals('NO_CASH_POSITION', $runway['status']);
    }

    /** @test */
    public function burn_rate_insufficient_history_less_than_14_days()
    {
        // Store just opened 5 days ago
        $firstTrxDate = Carbon::parse('2026-09-20 03:00:00');

        $this->insertTransaction([
            'created_at_device' => $firstTrxDate->format('Y-m-d H:i:s'),
        ]);

        // 5 days of expenses: Rp 50k each = 250k total
        for ($i = 0; $i < 5; $i++) {
            $this->insertExpense([
                'amount' => 50000,
                'date'   => Carbon::parse('2026-09-24')->subDays($i)->format('Y-m-d'),
            ]);
        }

        $burnRate = $this->service->calculateDailyCashBurnRate(
            $this->user->id, $this->outlet->id, '2026-09-24'
        );

        $this->assertEquals('OK', $burnRate['status']);
        // days_active = 5 (Sep 20 to Sep 24 inclusive), min(5, 14) = 5
        $this->assertEquals(5, $burnRate['days_used']);
        // 250000 / 5 = 50000
        $this->assertEquals(50000.0, $burnRate['value']);
    }

    /** @test */
    public function burn_rate_no_transaction_history()
    {
        // No transactions at all → can't determine days_active
        $this->insertExpense(['amount' => 100000]);

        $burnRate = $this->service->calculateDailyCashBurnRate(
            $this->user->id, $this->outlet->id, '2026-09-24'
        );

        $this->assertNull($burnRate['value']);
        $this->assertEquals('NO_TRANSACTION_HISTORY', $burnRate['status']);
    }

    // ================================================================
    // G. GROSS / NET PROFIT TESTS
    // ================================================================

    /** @test */
    public function gross_profit_equals_revenue_minus_cogs()
    {
        $revenue = 500000.0;
        $cogs    = 150000.0;

        $grossProfit = $this->service->calculateGrossProfit($revenue, $cogs);

        $this->assertEquals(350000.0, $grossProfit);
    }

    /** @test */
    public function net_profit_equals_gross_profit_minus_expenses()
    {
        $grossProfit = 350000.0;
        $expenses    = 100000.0;

        $netProfit = $this->service->calculateNetProfit($grossProfit, $expenses);

        $this->assertEquals(250000.0, $netProfit);
    }

    /** @test */
    public function full_profit_chain_integration()
    {
        // Set up: 2 transactions, each with items and expenses
        $trx1 = $this->insertTransaction([
            'total_amount' => 200000, 'pay_amount' => 200000, 'status' => 'Selesai',
        ]);
        $this->insertItem($trx1, ['qty' => 2, 'cost_price' => 30000]);

        $trx2 = $this->insertTransaction([
            'total_amount' => 150000, 'pay_amount' => 150000, 'status' => 'Selesai',
        ]);
        $this->insertItem($trx2, ['qty' => 1, 'cost_price' => 50000]);

        $this->insertExpense(['amount' => 40000]);

        $revenue  = $this->service->calculateRevenue($this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta');
        $cogs     = $this->service->calculateCogs($this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta');
        $expenses = $this->service->calculateExpenses($this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24');

        $grossProfit = $this->service->calculateGrossProfit($revenue['value'], $cogs['value']);
        $netProfit   = $this->service->calculateNetProfit($grossProfit, $expenses['value']);

        // Revenue: 200000 + 150000 = 350000
        $this->assertEquals(350000.0, $revenue['value']);

        // COGS: (2*30000) + (1*50000) = 60000 + 50000 = 110000
        $this->assertEquals(110000.0, $cogs['value']);

        // Gross: 350000 - 110000 = 240000
        $this->assertEquals(240000.0, $grossProfit);

        // Net: 240000 - 40000 = 200000
        $this->assertEquals(200000.0, $netProfit);
    }

    /** @test */
    public function negative_profit_when_cogs_exceeds_revenue()
    {
        $grossProfit = $this->service->calculateGrossProfit(100000.0, 150000.0);
        $this->assertEquals(-50000.0, $grossProfit);

        $netProfit = $this->service->calculateNetProfit($grossProfit, 20000.0);
        $this->assertEquals(-70000.0, $netProfit);
    }

    // ================================================================
    // H. SNAPSHOT INTEGRATION TEST
    // ================================================================

    /** @test */
    public function compute_snapshot_returns_complete_structure()
    {
        $trx = $this->insertTransaction([
            'total_amount' => 200000, 'pay_amount' => 200000, 'status' => 'Selesai',
        ]);
        $this->insertItem($trx, ['qty' => 2, 'cost_price' => 25000]);
        $this->insertExpense(['amount' => 30000]);
        $this->insertShift(['start_cash' => 100000, 'total_cash_sales' => 150000]);

        $snapshot = $this->service->computeSnapshot(
            $this->user->id, $this->outlet->id, '2026-09-24', '2026-09-24', 'Asia/Jakarta'
        );

        // Structure assertions
        $this->assertArrayHasKey('revenue', $snapshot);
        $this->assertArrayHasKey('cogs', $snapshot);
        $this->assertArrayHasKey('gross_profit', $snapshot);
        $this->assertArrayHasKey('expenses', $snapshot);
        $this->assertArrayHasKey('net_profit', $snapshot);
        $this->assertArrayHasKey('cash_position', $snapshot);
        $this->assertArrayHasKey('burn_rate', $snapshot);
        $this->assertArrayHasKey('cash_runway', $snapshot);
        $this->assertArrayHasKey('data_quality', $snapshot);
        $this->assertArrayHasKey('evidence', $snapshot);

        // Value assertions
        $this->assertEquals(200000.0, $snapshot['revenue']['value']);
        $this->assertEquals(50000.0, $snapshot['cogs']['value']); // 2 * 25000
        $this->assertEquals(150000.0, $snapshot['gross_profit']['value']); // 200k - 50k
        $this->assertEquals(30000.0, $snapshot['expenses']['value']);
        $this->assertEquals(120000.0, $snapshot['net_profit']['value']); // 150k - 30k

        // Cash position: start_cash + total_cash_sales = 100k + 150k = 250k
        $this->assertEquals(250000.0, $snapshot['cash_position']['value']);

        // Evidence
        $this->assertEquals($this->user->id, $snapshot['evidence']['user_id']);
        $this->assertEquals($this->outlet->id, $snapshot['evidence']['outlet_id']);
        $this->assertEquals('Asia/Jakarta', $snapshot['evidence']['timezone']);

        // Data quality
        $this->assertFalse($snapshot['data_quality']['has_untracked_cogs']);
    }
}
