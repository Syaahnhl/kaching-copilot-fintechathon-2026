<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use App\Services\Financial\FinancialScenarioService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FinancialScenarioServiceTest
 *
 * Comprehensive feature test suite for deterministic financial scenario simulations.
 * Validates Phase B.4.1 requirements:
 * 1. PRICE_CHANGE, COST_CHANGE, REVENUE_CHANGE, EXPENSE_CHANGE, COMBINED_SCENARIO
 * 2. Edge cases (zero revenue, zero COGS, untracked COGS)
 * 3. Validation gates & fail-closed behavior (extreme values, negative expense, unauthorized outlet)
 * 4. Tenant isolation & timezone boundaries
 * 5. Read-only mutation guarantee
 * 6. Mathematical invariants & decoupling of price vs volume effects
 */
class FinancialScenarioServiceTest extends TestCase
{
    use RefreshDatabase;

    protected FinancialScenarioService $service;
    protected User $user;
    protected Outlet $outlet;
    protected string $startDate = '2026-03-01';
    protected string $endDate = '2026-03-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FinancialScenarioService::class);

        $this->user = User::factory()->create([
            'email' => 'merchant@kaching.test',
        ]);

        $this->outlet = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Main Outlet',
            'device_id' => 'DEV-SCENARIO-01',
        ]);
    }

    /**
     * Helper to seed standard baseline data:
     * - Total Revenue: 50,000,000 IDR
     * - Total COGS: 20,000,000 IDR (Gross Profit: 30,000,000 IDR, Margin: 60.0%)
     * - Operating Expenses: 10,000,000 IDR (Net Profit: 20,000,000 IDR, Margin: 40.0%)
     */
    protected function seedStandardBaseline(
        float $revenue = 50000000.0,
        float $cogs = 20000000.0,
        float $expense = 10000000.0,
        ?int $userId = null,
        ?int $outletId = null,
        bool $trackedCogs = true
    ): void {
        $uId = $userId ?? $this->user->id;
        $oId = $outletId ?? $this->outlet->id;

        // Insert transaction: 1000 items @ price each
        $trxId = DB::table('transactions')->insertGetId([
            'user_id' => $uId,
            'outlet_id' => $oId,
            'app_uuid' => 'tx-uuid-' . uniqid('', true),
            'official_invoice_number' => 'INV-' . uniqid(),
            'total_amount' => $revenue,
            'pay_amount' => $revenue,
            'payment_method' => 'cash',
            'status' => 'Selesai',
            'created_at_device' => '2026-03-15 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($revenue > 0) {
            DB::table('transaction_items')->insert([
                'transaction_id' => $trxId,
                'menu_id' => 101,
                'menu_name' => 'Menu Standard',
                'qty' => 1,
                'price' => $revenue,
                'cost_price' => $trackedCogs ? $cogs : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($expense > 0) {
            DB::table('expenses')->insert([
                'user_id' => $uId,
                'outlet_id' => $oId,
                'name' => 'Biaya Operasional',
                'amount' => $expense,
                'category' => 'Operasional',
                'date' => '2026-03-10',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // ==========================================
    // 1 & 2. PRICE_CHANGE
    // ==========================================

    public function test_price_change_positive_ten_percent(): void
    {
        $this->seedStandardBaseline();

        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'PRICE_CHANGE',
            'price_change_percent' => 10.0,
        ], $this->startDate, $this->endDate);

        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertEquals('PRICE_CHANGE', $res['scenario']['type']);

        // Baseline verification
        $this->assertEquals(50000000.0, $res['baseline']['revenue']);
        $this->assertEquals(20000000.0, $res['baseline']['cogs']);
        $this->assertEquals(30000000.0, $res['baseline']['gross_profit']);
        $this->assertEquals(10000000.0, $res['baseline']['expenses']);
        $this->assertEquals(20000000.0, $res['baseline']['net_profit']);
        $this->assertEquals(60.0, $res['baseline']['gross_margin']);
        $this->assertEquals(40.0, $res['baseline']['net_margin']);

        // Simulation verification: Zero elasticity (COGS & Expenses constant)
        $this->assertEquals(55000000.0, $res['result']['revenue']);
        $this->assertEquals(20000000.0, $res['result']['cogs']);
        $this->assertEquals(35000000.0, $res['result']['gross_profit']);
        $this->assertEquals(10000000.0, $res['result']['expenses']);
        $this->assertEquals(25000000.0, $res['result']['net_profit']);
        $this->assertEquals(63.64, $res['result']['gross_margin']);
        $this->assertEquals(45.45, $res['result']['net_margin']);

        // Delta verification
        $this->assertEquals(5000000.0, $res['delta']['revenue']);
        $this->assertEquals(10.0, $res['delta']['revenue_percent']);
        $this->assertEquals(0.0, $res['delta']['cogs']);
        $this->assertEquals(5000000.0, $res['delta']['gross_profit']);
        $this->assertEquals(16.67, $res['delta']['gross_profit_percent']);
        $this->assertEquals(5000000.0, $res['delta']['net_profit']);
        $this->assertEquals(25.0, $res['delta']['net_profit_percent']);
        $this->assertEquals(3.64, $res['delta']['gross_margin_pp']);
        $this->assertEquals(5.45, $res['delta']['net_margin_pp']);

        $this->assertNotEmpty($res['assumptions']);
        $this->assertStringContainsString('Zero elasticity', $res['assumptions'][0]);
    }

    public function test_price_change_negative_ten_percent(): void
    {
        $this->seedStandardBaseline();

        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'PRICE_CHANGE',
            'price_change_percent' => -10.0,
        ], $this->startDate, $this->endDate);

        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertEquals(45000000.0, $res['result']['revenue']);
        $this->assertEquals(20000000.0, $res['result']['cogs']);
        $this->assertEquals(25000000.0, $res['result']['gross_profit']);
        $this->assertEquals(10000000.0, $res['result']['expenses']);
        $this->assertEquals(15000000.0, $res['result']['net_profit']);
        $this->assertEquals(55.56, $res['result']['gross_margin']);
        $this->assertEquals(33.33, $res['result']['net_margin']);

        $this->assertEquals(-5000000.0, $res['delta']['revenue']);
        $this->assertEquals(-10.0, $res['delta']['revenue_percent']);
        $this->assertEquals(-4.44, $res['delta']['gross_margin_pp']);
        $this->assertEquals(-6.67, $res['delta']['net_margin_pp']);
    }

    // ==========================================
    // 3 & 4. COST_CHANGE
    // ==========================================

    public function test_cost_change_positive_fifteen_percent(): void
    {
        $this->seedStandardBaseline();

        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'COST_CHANGE',
            'cost_change_percent' => 15.0,
        ], $this->startDate, $this->endDate);

        $this->assertEquals('SUCCESS', $res['status']);
        // Revenue and Expenses unchanged
        $this->assertEquals(50000000.0, $res['result']['revenue']);
        $this->assertEquals(23000000.0, $res['result']['cogs']); // 20M * 1.15
        $this->assertEquals(27000000.0, $res['result']['gross_profit']);
        $this->assertEquals(10000000.0, $res['result']['expenses']);
        $this->assertEquals(17000000.0, $res['result']['net_profit']);
        $this->assertEquals(54.0, $res['result']['gross_margin']);
        $this->assertEquals(34.0, $res['result']['net_margin']);

        $this->assertEquals(3000000.0, $res['delta']['cogs']);
        $this->assertEquals(15.0, $res['delta']['cogs_percent']);
        $this->assertEquals(-3000000.0, $res['delta']['gross_profit']);
        $this->assertEquals(-3000000.0, $res['delta']['net_profit']);
        $this->assertEquals(-6.0, $res['delta']['gross_margin_pp']);
        $this->assertEquals(-6.0, $res['delta']['net_margin_pp']);
    }

    public function test_cost_change_negative_ten_percent(): void
    {
        $this->seedStandardBaseline();

        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'COST_CHANGE',
            'cost_change_percent' => -10.0,
        ], $this->startDate, $this->endDate);

        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertEquals(50000000.0, $res['result']['revenue']);
        $this->assertEquals(18000000.0, $res['result']['cogs']); // 20M * 0.9
        $this->assertEquals(32000000.0, $res['result']['gross_profit']);
        $this->assertEquals(22000000.0, $res['result']['net_profit']);
        $this->assertEquals(64.0, $res['result']['gross_margin']);
        $this->assertEquals(44.0, $res['result']['net_margin']);

        $this->assertEquals(-2000000.0, $res['delta']['cogs']);
        $this->assertEquals(4.0, $res['delta']['gross_margin_pp']);
        $this->assertEquals(4.0, $res['delta']['net_margin_pp']);
    }

    // ==========================================
    // 5 & 6. REVENUE_CHANGE
    // ==========================================

    public function test_revenue_change_positive_ten_percent(): void
    {
        $this->seedStandardBaseline();

        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'REVENUE_CHANGE',
            'revenue_change_percent' => 10.0,
        ], $this->startDate, $this->endDate);

        $this->assertEquals('SUCCESS', $res['status']);
        // Variable COGS scales with revenue, operating expenses fixed
        $this->assertEquals(55000000.0, $res['result']['revenue']);
        $this->assertEquals(22000000.0, $res['result']['cogs']);
        $this->assertEquals(33000000.0, $res['result']['gross_profit']);
        $this->assertEquals(10000000.0, $res['result']['expenses']);
        $this->assertEquals(23000000.0, $res['result']['net_profit']);

        // Gross margin remains 60% because both revenue and COGS scaled 10%
        $this->assertEquals(60.0, $res['result']['gross_margin']);
        $this->assertEquals(0.0, $res['delta']['gross_margin_pp']);

        // Net margin increases due to operating leverage (fixed expense)
        $this->assertEquals(41.82, $res['result']['net_margin']);
        $this->assertEquals(1.82, $res['delta']['net_margin_pp']);
    }

    public function test_revenue_change_negative_twenty_percent(): void
    {
        $this->seedStandardBaseline();

        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'REVENUE_CHANGE',
            'revenue_change_percent' => -20.0,
        ], $this->startDate, $this->endDate);

        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertEquals(40000000.0, $res['result']['revenue']);
        $this->assertEquals(16000000.0, $res['result']['cogs']);
        $this->assertEquals(24000000.0, $res['result']['gross_profit']);
        $this->assertEquals(10000000.0, $res['result']['expenses']);
        $this->assertEquals(14000000.0, $res['result']['net_profit']);

        $this->assertEquals(60.0, $res['result']['gross_margin']);
        $this->assertEquals(35.0, $res['result']['net_margin']);
        $this->assertEquals(-5.0, $res['delta']['net_margin_pp']);
    }

    // ==========================================
    // 7 & 8. EXPENSE_CHANGE
    // ==========================================

    public function test_expense_change_nominal_minus_one_million(): void
    {
        $this->seedStandardBaseline();

        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'EXPENSE_CHANGE',
            'expense_change_amount' => -1000000.0,
        ], $this->startDate, $this->endDate);

        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertEquals(50000000.0, $res['result']['revenue']);
        $this->assertEquals(20000000.0, $res['result']['cogs']);
        $this->assertEquals(30000000.0, $res['result']['gross_profit']);
        $this->assertEquals(9000000.0, $res['result']['expenses']);
        $this->assertEquals(21000000.0, $res['result']['net_profit']);

        $this->assertEquals(-1000000.0, $res['delta']['expenses']);
        $this->assertEquals(-10.0, $res['delta']['expenses_percent']);
        $this->assertEquals(1000000.0, $res['delta']['net_profit']);
        $this->assertEquals(5.0, $res['delta']['net_profit_percent']);
        $this->assertEquals(42.0, $res['result']['net_margin']);
        $this->assertEquals(2.0, $res['delta']['net_margin_pp']);
    }

    public function test_expense_change_percent_minus_ten_percent(): void
    {
        $this->seedStandardBaseline();

        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'EXPENSE_CHANGE',
            'expense_change_percent' => -10.0,
        ], $this->startDate, $this->endDate);

        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertEquals(9000000.0, $res['result']['expenses']);
        $this->assertEquals(21000000.0, $res['result']['net_profit']);
        $this->assertEquals(42.0, $res['result']['net_margin']);
    }

    // ==========================================
    // 9. COMBINED_SCENARIO
    // ==========================================

    public function test_combined_scenario_calculation(): void
    {
        $this->seedStandardBaseline();

        // Price +10%, Volume -20%, Cost +15%, Expense -Rp1M
        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'COMBINED_SCENARIO',
            'price_change_percent' => 10.0,
            'revenue_change_percent' => -20.0,
            'cost_change_percent' => 15.0,
            'expense_change_amount' => -1000000.0,
        ], $this->startDate, $this->endDate);

        $this->assertEquals('SUCCESS', $res['status']);

        // Revenue: 50M * 1.1 (price) * 0.8 (volume) = 44,000,000
        $this->assertEquals(44000000.0, $res['result']['revenue']);

        // COGS: 20M * 0.8 (volume) * 1.15 (cost) = 18,400,000
        $this->assertEquals(18400000.0, $res['result']['cogs']);

        // Gross Profit: 44M - 18.4M = 25,600,000
        $this->assertEquals(25600000.0, $res['result']['gross_profit']);

        // Expenses: 10M - 1M = 9,000,000
        $this->assertEquals(9000000.0, $res['result']['expenses']);

        // Net Profit: 25.6M - 9M = 16,600,000
        $this->assertEquals(16600000.0, $res['result']['net_profit']);

        $this->assertEquals(58.18, $res['result']['gross_margin']);
        $this->assertEquals(37.73, $res['result']['net_margin']);
    }

    // ==========================================
    // 10. ZERO REVENUE
    // ==========================================

    public function test_zero_revenue_handling(): void
    {
        // Zero transactions, but 5,000,000 expense
        $this->seedStandardBaseline(0.0, 0.0, 5000000.0);

        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'EXPENSE_CHANGE',
            'expense_change_percent' => -20.0,
        ], $this->startDate, $this->endDate);

        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertEquals(0.0, $res['baseline']['revenue']);
        $this->assertNull($res['baseline']['gross_margin']);
        $this->assertNull($res['baseline']['net_margin']);

        $this->assertEquals(0.0, $res['result']['revenue']);
        $this->assertNull($res['result']['gross_margin']);
        $this->assertNull($res['result']['net_margin']);
        $this->assertNull($res['delta']['revenue_percent']);

        // Should include limitation explaining zero revenue
        $limitationsText = implode(' ', $res['limitations']);
        $this->assertStringContainsString('Baseline revenue bernilai 0', $limitationsText);
    }

    // ==========================================
    // 11. ZERO COGS
    // ==========================================

    public function test_zero_cogs_handling(): void
    {
        // Revenue 50M, COGS 0 (service business)
        $this->seedStandardBaseline(50000000.0, 0.0, 5000000.0);

        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'PRICE_CHANGE',
            'price_change_percent' => 10.0,
        ], $this->startDate, $this->endDate);

        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertEquals(0.0, $res['result']['cogs']);
        $this->assertEquals(55000000.0, $res['result']['gross_profit']);
        $this->assertEquals(100.0, $res['result']['gross_margin']);
        $this->assertNull($res['delta']['cogs_percent']); // Baseline cogs is 0
    }

    // ==========================================
    // 12. MISSING / UNTRACKED COGS
    // ==========================================

    public function test_missing_or_untracked_cogs_handling(): void
    {
        // Seed with trackedCogs = false
        $this->seedStandardBaseline(50000000.0, 0.0, 5000000.0, null, null, false);

        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'PRICE_CHANGE',
            'price_change_percent' => 10.0,
        ], $this->startDate, $this->endDate);

        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertEquals('UNTRACKED_COGS_WARNING', $res['data_quality']);
        $this->assertTrue($res['baseline']['data_quality']['has_untracked_cogs']);

        $limitationsText = implode(' ', $res['limitations']);
        $this->assertStringContainsString('tanpa data HPP', $limitationsText);
    }

    // ==========================================
    // 13. INVALID SCENARIO TYPE
    // ==========================================

    public function test_invalid_scenario_type_rejected(): void
    {
        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'DEMAND_PREDICTION_AI',
        ], $this->startDate, $this->endDate);

        $this->assertEquals('ERROR', $res['status']);
        $this->assertEquals('INVALID_SCENARIO_TYPE', $res['code']);
    }

    // ==========================================
    // 14. INVALID PARAMETER VALUES
    // ==========================================

    public function test_missing_or_invalid_parameter_rejected(): void
    {
        // Missing parameter for PRICE_CHANGE
        $res1 = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'PRICE_CHANGE',
        ], $this->startDate, $this->endDate);

        $this->assertEquals('ERROR', $res1['status']);
        $this->assertEquals('MISSING_REQUIRED_PARAMETER', $res1['code']);

        // Non-numeric parameter
        $res2 = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'PRICE_CHANGE',
            'price_change_percent' => 'naik_banyak',
        ], $this->startDate, $this->endDate);

        $this->assertEquals('ERROR', $res2['status']);
        $this->assertEquals('INVALID_PARAMETER_VALUE', $res2['code']);
    }

    // ==========================================
    // 15. EXTREME PERCENTAGE REJECTED
    // ==========================================

    public function test_extreme_percentage_rejected(): void
    {
        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'PRICE_CHANGE',
            'price_change_percent' => 500.0, // Max allowed is +100%
        ], $this->startDate, $this->endDate);

        $this->assertEquals('ERROR', $res['status']);
        $this->assertEquals('PARAMETER_OUT_OF_BOUNDS', $res['code']);
    }

    // ==========================================
    // 16. NEGATIVE RESULTING EXPENSE REJECTED
    // ==========================================

    public function test_negative_resulting_expense_rejected(): void
    {
        $this->seedStandardBaseline(50000000.0, 20000000.0, 5000000.0);

        // Expense baseline is 5M, trying to reduce by 10M -> -5M
        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'EXPENSE_CHANGE',
            'expense_change_amount' => -10000000.0,
        ], $this->startDate, $this->endDate);

        $this->assertEquals('ERROR', $res['status']);
        $this->assertEquals('INVALID_EXPENSE_RESULT', $res['code']);
    }

    // ==========================================
    // 17. UNAUTHORIZED OUTLET
    // ==========================================

    public function test_unauthorized_outlet_rejected(): void
    {
        $otherUser = User::factory()->create();
        $otherOutlet = Outlet::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Tenant Outlet',
            'device_id' => 'DEV-OTHER-99',
        ]);

        // User attempts to simulate other tenant's outlet
        $res = $this->service->simulate($this->user->id, $otherOutlet->id, [
            'scenario_type' => 'PRICE_CHANGE',
            'price_change_percent' => 10.0,
        ], $this->startDate, $this->endDate);

        $this->assertEquals('ERROR', $res['status']);
        $this->assertEquals('UNAUTHORIZED_OUTLET', $res['code']);
    }

    // ==========================================
    // 18. CROSS-TENANT ISOLATION
    // ==========================================

    public function test_cross_tenant_data_isolation(): void
    {
        // Seed data for User A
        $this->seedStandardBaseline(100000000.0, 40000000.0, 20000000.0, $this->user->id, $this->outlet->id);

        // Seed data for User B
        $userB = User::factory()->create();
        $outletB = Outlet::create([
            'user_id' => $userB->id,
            'name' => 'Outlet B',
            'device_id' => 'DEV-B-01',
        ]);
        $this->seedStandardBaseline(10000000.0, 4000000.0, 2000000.0, $userB->id, $outletB->id);

        // User B runs simulation
        $resB = $this->service->simulate($userB->id, $outletB->id, [
            'scenario_type' => 'PRICE_CHANGE',
            'price_change_percent' => 10.0,
        ], $this->startDate, $this->endDate);

        $this->assertEquals('SUCCESS', $resB['status']);
        // User B's baseline must be 10M, not User A's 100M
        $this->assertEquals(10000000.0, $resB['baseline']['revenue']);
        $this->assertEquals(11000000.0, $resB['result']['revenue']);
    }

    // ==========================================
    // 19. TIMEZONE BOUNDARY RESOLUTION
    // ==========================================

    public function test_timezone_boundary_resolution(): void
    {
        // Insert transaction right at local midnight boundary
        DB::table('transactions')->insert([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'app_uuid' => 'tx-uuid-tz-01',
            'official_invoice_number' => 'INV-TZ-01',
            'total_amount' => 15000000.0,
            'pay_amount' => 15000000.0,
            'payment_method' => 'cash',
            'status' => 'Selesai',
            'created_at_device' => '2026-03-01 00:05:00', // Inside start date boundary
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'PRICE_CHANGE',
            'price_change_percent' => 10.0,
        ], '2026-03-01', '2026-03-01');

        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertEquals(15000000.0, $res['baseline']['revenue']);
        $this->assertEquals(16500000.0, $res['result']['revenue']);
    }

    // ==========================================
    // 20. READ-ONLY MUTATION GUARANTEE
    // ==========================================

    public function test_read_only_mutation_guarantee(): void
    {
        $this->seedStandardBaseline();

        // Capture table counts before simulations
        $trxCountBefore = DB::table('transactions')->count();
        $itemsCountBefore = DB::table('transaction_items')->count();
        $expensesCountBefore = DB::table('expenses')->count();
        $outletsCountBefore = DB::table('outlets')->count();
        $usersCountBefore = DB::table('users')->count();

        // Run all scenario types
        $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'PRICE_CHANGE',
            'price_change_percent' => 10.0,
        ], $this->startDate, $this->endDate);

        $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'COST_CHANGE',
            'cost_change_percent' => 15.0,
        ], $this->startDate, $this->endDate);

        $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'REVENUE_CHANGE',
            'revenue_change_percent' => -20.0,
        ], $this->startDate, $this->endDate);

        $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'EXPENSE_CHANGE',
            'expense_change_amount' => -1000000.0,
        ], $this->startDate, $this->endDate);

        $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'COMBINED_SCENARIO',
            'price_change_percent' => 10.0,
            'cost_change_percent' => 10.0,
        ], $this->startDate, $this->endDate);

        // Verify counts after simulations are 100% identical
        $this->assertEquals($trxCountBefore, DB::table('transactions')->count());
        $this->assertEquals($itemsCountBefore, DB::table('transaction_items')->count());
        $this->assertEquals($expensesCountBefore, DB::table('expenses')->count());
        $this->assertEquals($outletsCountBefore, DB::table('outlets')->count());
        $this->assertEquals($usersCountBefore, DB::table('users')->count());
    }

    // ==========================================
    // 21. FORMULA INVARIANTS & DECOUPLING
    // ==========================================

    public function test_formula_invariants_and_decoupling(): void
    {
        $this->seedStandardBaseline();

        // Invariant 1: PRICE_CHANGE must NOT alter COGS
        $priceRes = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'PRICE_CHANGE',
            'price_change_percent' => 20.0,
        ], $this->startDate, $this->endDate);
        $this->assertEquals($priceRes['baseline']['cogs'], $priceRes['result']['cogs']);
        $this->assertEquals(0.0, $priceRes['delta']['cogs']);

        // Invariant 2: COST_CHANGE must NOT alter Revenue
        $costRes = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'COST_CHANGE',
            'cost_change_percent' => 20.0,
        ], $this->startDate, $this->endDate);
        $this->assertEquals($costRes['baseline']['revenue'], $costRes['result']['revenue']);
        $this->assertEquals(0.0, $costRes['delta']['revenue']);

        // Invariant 3: REVENUE_CHANGE must scale COGS strictly proportionally
        $revRes = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'REVENUE_CHANGE',
            'revenue_change_percent' => 15.0,
        ], $this->startDate, $this->endDate);
        $this->assertEquals(15.0, $revRes['delta']['revenue_percent']);
        $this->assertEquals(15.0, $revRes['delta']['cogs_percent']);

        // Invariant 4: EXPENSE_CHANGE must NOT alter Revenue or COGS
        $expRes = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'EXPENSE_CHANGE',
            'expense_change_amount' => 500000.0,
        ], $this->startDate, $this->endDate);
        $this->assertEquals($expRes['baseline']['revenue'], $expRes['result']['revenue']);
        $this->assertEquals($expRes['baseline']['cogs'], $expRes['result']['cogs']);
        $this->assertEquals($expRes['baseline']['gross_profit'], $expRes['result']['gross_profit']);

        // Invariant 5: COMBINED separates price effect from volume effect without double counting
        // Baseline: Rev = 50M, COGS = 20M
        // If price +10% and volume +10%:
        // Rev = 50M * 1.1 * 1.1 = 60.5M
        // COGS = 20M * 1.1 = 22M (COGS does not scale with price!)
        $combRes = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'COMBINED_SCENARIO',
            'price_change_percent' => 10.0,
            'revenue_change_percent' => 10.0,
        ], $this->startDate, $this->endDate);
        $this->assertEquals(60500000.0, $combRes['result']['revenue']);
        $this->assertEquals(22000000.0, $combRes['result']['cogs']);
    }

    // ==========================================
    // 22. FORBIDDEN PARAMETERS REJECTION
    // ==========================================

    public function test_forbidden_parameters_rejected(): void
    {
        $res = $this->service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type' => 'PRICE_CHANGE',
            'price_change_percent' => 10.0,
            'user_id' => 999, // Injection attempt
        ], $this->startDate, $this->endDate);

        $this->assertEquals('ERROR', $res['status']);
        $this->assertEquals('FORBIDDEN_PARAMETER', $res['code']);
    }
}
