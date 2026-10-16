<?php

namespace Tests\Unit;

use App\Models\Menu;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Financial\MenuSalesVelocityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MenuSalesVelocityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected MenuSalesVelocityService $service;
    protected User $tenantA;
    protected User $tenantB;
    protected Outlet $outletA1;
    protected Outlet $outletA2;
    protected Outlet $outletB1;
    protected Menu $menuA1;
    protected Menu $menuA2;
    protected Menu $menuA3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MenuSalesVelocityService::class);

        $this->tenantA = User::factory()->create([
            'name'  => 'Tenant A Owner',
            'email' => 'tenanta@kaching.id',
        ]);

        $this->tenantB = User::factory()->create([
            'name'  => 'Tenant B Owner',
            'email' => 'tenantb@kaching.id',
        ]);

        $this->outletA1 = Outlet::create([
            'user_id'   => $this->tenantA->id,
            'name'      => 'Outlet A-1 Menteng',
            'timezone'  => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $this->outletA2 = Outlet::create([
            'user_id'   => $this->tenantA->id,
            'name'      => 'Outlet A-2 Kemang',
            'timezone'  => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $this->outletB1 = Outlet::create([
            'user_id'   => $this->tenantB->id,
            'name'      => 'Outlet B-1 Kuta',
            'timezone'  => 'Asia/Makassar',
            'is_active' => true,
        ]);

        $this->menuA1 = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA1->id,
            'name'       => 'Es Teh Manis Segar',
            'category'   => 'Minuman',
            'price'      => 5000,
            'cost_price' => 1500,
            'stock'      => -1,
        ]);

        $this->menuA2 = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA1->id,
            'name'       => 'Nasi Goreng Spesial',
            'category'   => 'Makanan',
            'price'      => 25000,
            'cost_price' => 12000,
            'stock'      => 50,
        ]);

        $this->menuA3 = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA1->id,
            'name'       => 'Bebek Bakar Madu',
            'category'   => 'Makanan',
            'price'      => 35000,
            'cost_price' => 20000,
            'stock'      => 10,
        ]);
    }

    /**
     * Helper to create a transaction with an item row
     */
    protected function createTxWithItem(
        User $user,
        Outlet $outlet,
        string $businessDate,
        string $businessTime,
        ?int $menuId,
        string $menuName,
        float $qty,
        float $price,
        string $txStatus = 'Selesai',
        ?string $txUpdatedAt = null,
        string $itemStatus = 'Selesai',
        ?string $itemUpdatedAt = null
    ): array {
        $tz = $outlet->timezone ?: 'Asia/Jakarta';
        $utcCreated = Carbon::parse("{$businessDate} {$businessTime}", $tz)->setTimezone('UTC')->format('Y-m-d H:i:s');
        $txUpdated = $txUpdatedAt ?? $utcCreated;
        $itemUpdated = $itemUpdatedAt ?? $txUpdated;

        $txId = DB::table('transactions')->insertGetId([
            'app_uuid'                => (string) \Illuminate\Support\Str::uuid(),
            'user_id'                 => $user->id,
            'outlet_id'               => $outlet->id,
            'official_invoice_number' => 'INV-' . uniqid(),
            'total_amount'            => $qty * $price,
            'pay_amount'              => $qty * $price,
            'payment_method'          => 'Tunai',
            'status'                  => $txStatus,
            'created_at_device'       => $utcCreated,
            'created_at'              => $utcCreated,
            'updated_at'              => $txUpdated,
        ]);

        $itemId = DB::table('transaction_items')->insertGetId([
            'transaction_id' => $txId,
            'menu_id'        => $menuId,
            'menu_name'      => $menuName,
            'qty'            => $qty,
            'price'          => $price,
            'status'         => $itemStatus,
            'created_at'     => $utcCreated,
            'updated_at'     => $itemUpdated,
        ]);

        return ['transaction_id' => $txId, 'item_id' => $itemId, 'created_at_device' => $utcCreated];
    }

    // =========================================================================
    // 1. MENU TANPA TRANSAKSI (NO_SALES / DEAD STOCK)
    // =========================================================================
    public function test_menu_without_transactions_is_classified_as_no_sales(): void
    {
        // No transactions created
        $result = $this->service->analyzeVelocity($this->tenantA->id, $this->outletA1->id, '2026-09-30', 30);

        $this->assertEquals(3, $result['metadata']['total_menus_evaluated']);
        $this->assertEquals(0, $result['metadata']['total_transactions_evaluated']);

        $evaluated = $result['menus'][$this->menuA1->id];
        $this->assertEquals(MenuSalesVelocityService::CAT_NO_SALES, $evaluated['category']);
        $this->assertEquals(0.0, $evaluated['total_quantity_sold']);
        $this->assertEquals(0, $evaluated['active_selling_days']);
        $this->assertEquals(30, $evaluated['zero_sales_days']);
        $this->assertEquals(30, $evaluated['consecutive_zero_sales_days']);
        $this->assertEquals(0.0, $evaluated['sales_frequency']);
        $this->assertEquals(0.0, $evaluated['average_daily_qty']);
        $this->assertEquals(0.0, $evaluated['revenue_contribution_pct']);
        $this->assertCount(3, $result['dead_stock_candidates']);
    }

    // =========================================================================
    // 2. MENU DENGAN SATU TRANSAKSI (LOW_VELOCITY)
    // =========================================================================
    public function test_menu_with_single_transaction_is_classified_as_low_velocity(): void
    {
        // 1 transaction of 2 units on 2026-09-10
        $this->createTxWithItem(
            $this->tenantA,
            $this->outletA1,
            '2026-09-10',
            '12:00:00',
            $this->menuA1->id,
            $this->menuA1->name,
            2.0,
            5000
        );

        $result = $this->service->analyzeVelocity($this->tenantA->id, $this->outletA1->id, '2026-09-30', 30);
        $evaluated = $result['menus'][$this->menuA1->id];

        $this->assertEquals(MenuSalesVelocityService::CAT_LOW_VELOCITY, $evaluated['category']);
        $this->assertEquals(2.0, $evaluated['total_quantity_sold']);
        $this->assertEquals(10000.0, $evaluated['total_revenue']);
        $this->assertEquals(1, $evaluated['active_selling_days']);
        $this->assertEquals(29, $evaluated['zero_sales_days']);
        $this->assertEquals(20, $evaluated['consecutive_zero_sales_days']); // From 2026-09-11 to 2026-09-30
        $this->assertEquals(round(1 / 30, 4), $evaluated['sales_frequency']);
        $this->assertEquals(round(2 / 30, 4), $evaluated['average_daily_qty']);
        $this->assertEquals(2.0, $evaluated['active_day_avg_qty']);
    }

    // =========================================================================
    // 3. ZERO-SALES DAYS PRESERVATION
    // =========================================================================
    public function test_zero_sales_days_are_preserved_and_reflected_in_daily_average(): void
    {
        // Sold on 3 distinct days: 10 units each day = 30 units total across 30 calendar days
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-05', '12:00:00', $this->menuA1->id, $this->menuA1->name, 10.0, 5000);
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-15', '12:00:00', $this->menuA1->id, $this->menuA1->name, 10.0, 5000);
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-25', '12:00:00', $this->menuA1->id, $this->menuA1->name, 10.0, 5000);

        $result = $this->service->analyzeVelocity($this->tenantA->id, $this->outletA1->id, '2026-09-30', 30);
        $evaluated = $result['menus'][$this->menuA1->id];

        $this->assertEquals(30.0, $evaluated['total_quantity_sold']);
        $this->assertEquals(3, $evaluated['active_selling_days']);
        $this->assertEquals(27, $evaluated['zero_sales_days']);
        // True velocity maintaining zero-sales days: 30 units / 30 calendar days = 1.0 unit/day
        $this->assertEquals(1.0, $evaluated['average_daily_qty']);
        // Active days velocity: 30 units / 3 active days = 10.0 units/day
        $this->assertEquals(10.0, $evaluated['active_day_avg_qty']);
    }

    // =========================================================================
    // 4. TREND NAIK (UPWARD TREND)
    // =========================================================================
    public function test_menu_with_upward_trend_is_classified_properly(): void
    {
        // Lookback 28 days (2026-09-03 to 2026-09-30)
        // Prior 14 days (2026-09-03 to 2026-09-16): 1 unit every day = 14 units (1.0/day)
        // Recent 14 days (2026-09-17 to 2026-09-30): 5 units every day = 70 units (5.0/day)
        for ($d = 3; $d <= 16; $d++) {
            $dateStr = sprintf('2026-09-%02d', $d);
            $this->createTxWithItem($this->tenantA, $this->outletA1, $dateStr, '12:00:00', $this->menuA1->id, $this->menuA1->name, 1.0, 5000);
        }
        for ($d = 17; $d <= 30; $d++) {
            $dateStr = sprintf('2026-09-%02d', $d);
            $this->createTxWithItem($this->tenantA, $this->outletA1, $dateStr, '12:00:00', $this->menuA1->id, $this->menuA1->name, 5.0, 5000);
        }

        $result = $this->service->analyzeVelocity($this->tenantA->id, $this->outletA1->id, '2026-09-30', 28);
        $evaluated = $result['menus'][$this->menuA1->id];

        $this->assertEquals(MenuSalesVelocityService::TREND_UPWARD, $evaluated['trend_direction']);
        $this->assertEquals(1.0, $evaluated['prior_avg_qty']);
        $this->assertEquals(5.0, $evaluated['recent_avg_qty']);
        $this->assertEquals(5.0, $evaluated['velocity_trend_ratio']);
        $this->assertEquals(4.0, $evaluated['velocity_trend_delta']);
    }

    // =========================================================================
    // 5. TREND TURUN (DECLINING_VELOCITY)
    // =========================================================================
    public function test_menu_with_declining_velocity_is_classified_as_declining_velocity(): void
    {
        // Lookback 28 days (2026-09-03 to 2026-09-30)
        // Prior 14 days: 5 units/day = 70 units (avg 5.0/day)
        // Recent 14 days: 1 unit on day 18, 1 unit on day 22 = 2 units (avg 0.14/day)
        for ($d = 3; $d <= 16; $d++) {
            $dateStr = sprintf('2026-09-%02d', $d);
            $this->createTxWithItem($this->tenantA, $this->outletA1, $dateStr, '12:00:00', $this->menuA2->id, $this->menuA2->name, 5.0, 25000);
        }
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-18', '12:00:00', $this->menuA2->id, $this->menuA2->name, 1.0, 25000);
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-22', '12:00:00', $this->menuA2->id, $this->menuA2->name, 1.0, 25000);

        $result = $this->service->analyzeVelocity($this->tenantA->id, $this->outletA1->id, '2026-09-30', 28);
        $evaluated = $result['menus'][$this->menuA2->id];

        $this->assertEquals(MenuSalesVelocityService::CAT_DECLINING_VELOCITY, $evaluated['category']);
        $this->assertEquals(MenuSalesVelocityService::TREND_DOWNWARD, $evaluated['trend_direction']);
        $this->assertEquals(5.0, $evaluated['prior_avg_qty']);
        $this->assertEquals(round(2 / 14, 4), $evaluated['recent_avg_qty']);
        $this->assertNotEmpty($result['declining_velocity_menus']);
    }

    // =========================================================================
    // 6. DUPLICATE TRANSACTION ITEMS IN SAME TRANSACTION
    // =========================================================================
    public function test_duplicate_transaction_items_accumulate_without_double_counting_transactions(): void
    {
        $tz = 'Asia/Jakarta';
        $utcCreated = Carbon::parse('2026-09-20 12:00:00', $tz)->setTimezone('UTC')->format('Y-m-d H:i:s');

        // Create 1 transaction with 2 separate item rows for the same menu
        $txId = DB::table('transactions')->insertGetId([
            'app_uuid'                => (string) \Illuminate\Support\Str::uuid(),
            'user_id'                 => $this->tenantA->id,
            'outlet_id'               => $this->outletA1->id,
            'official_invoice_number' => 'INV-DUP-1',
            'total_amount'            => 25000,
            'pay_amount'              => 25000,
            'payment_method'          => 'Tunai',
            'status'                  => 'Selesai',
            'created_at_device'       => $utcCreated,
            'created_at'              => $utcCreated,
            'updated_at'              => $utcCreated,
        ]);

        DB::table('transaction_items')->insert([
            [
                'transaction_id' => $txId,
                'menu_id'        => $this->menuA1->id,
                'menu_name'      => $this->menuA1->name,
                'qty'            => 2.0,
                'price'          => 5000,
                'status'         => 'Selesai',
                'created_at'     => $utcCreated,
                'updated_at'     => $utcCreated,
            ],
            [
                'transaction_id' => $txId,
                'menu_id'        => $this->menuA1->id,
                'menu_name'      => $this->menuA1->name,
                'qty'            => 3.0,
                'price'          => 5000,
                'status'         => 'Selesai',
                'created_at'     => $utcCreated,
                'updated_at'     => $utcCreated,
            ],
        ]);

        $result = $this->service->analyzeVelocity($this->tenantA->id, $this->outletA1->id, '2026-09-30', 30);

        // Transaction counted once
        $this->assertEquals(1, $result['metadata']['total_transactions_evaluated']);
        // Qty correctly summed to 5.0
        $evaluated = $result['menus'][$this->menuA1->id];
        $this->assertEquals(5.0, $evaluated['total_quantity_sold']);
        $this->assertEquals(25000.0, $evaluated['total_revenue']);
        $this->assertEquals(1, $evaluated['active_selling_days']);
    }

    // =========================================================================
    // 7. CANCELLED AND REFUNDED TRANSACTIONS EXCLUDED
    // =========================================================================
    public function test_cancelled_and_refunded_transactions_are_excluded(): void
    {
        // Valid sale
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-15', '10:00:00', $this->menuA1->id, $this->menuA1->name, 4.0, 5000, 'Selesai');
        // Batal
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-16', '11:00:00', $this->menuA1->id, $this->menuA1->name, 10.0, 5000, 'Batal');
        // Refund
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-17', '12:00:00', $this->menuA1->id, $this->menuA1->name, 20.0, 5000, 'Refund');

        $result = $this->service->analyzeVelocity($this->tenantA->id, $this->outletA1->id, '2026-09-30', 30);
        $evaluated = $result['menus'][$this->menuA1->id];

        // Only valid 4.0 units counted
        $this->assertEquals(4.0, $evaluated['total_quantity_sold']);
        $this->assertEquals(20000.0, $evaluated['total_revenue']);
        $this->assertEquals(1, $result['metadata']['total_transactions_evaluated']);
    }

    // =========================================================================
    // 8. STRICT TENANT ISOLATION
    // =========================================================================
    public function test_strict_tenant_isolation(): void
    {
        // Tenant A transaction
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-20', '12:00:00', $this->menuA1->id, $this->menuA1->name, 5.0, 5000);

        // Tenant B transaction on Tenant B outlet
        $menuB = Menu::create([
            'user_id'    => $this->tenantB->id,
            'outlet_id'  => $this->outletB1->id,
            'name'       => 'Menu Tenant B',
            'category'   => 'Lainnya',
            'price'      => 10000,
            'cost_price' => 5000,
            'stock'      => 100,
        ]);
        $this->createTxWithItem($this->tenantB, $this->outletB1, '2026-09-20', '12:00:00', $menuB->id, $menuB->name, 20.0, 10000);

        // Analyze Tenant A
        $resultA = $this->service->analyzeVelocity($this->tenantA->id, null, '2026-09-30', 30);
        $this->assertEquals(1, $resultA['metadata']['total_transactions_evaluated']);
        $this->assertEquals(25000.0, $resultA['metadata']['outlet_total_revenue']);
        $this->assertArrayNotHasKey($menuB->id, $resultA['menus']);

        // Analyze Tenant B
        $resultB = $this->service->analyzeVelocity($this->tenantB->id, null, '2026-09-30', 30);
        $this->assertEquals(1, $resultB['metadata']['total_transactions_evaluated']);
        $this->assertEquals(200000.0, $resultB['metadata']['outlet_total_revenue']);
        $this->assertArrayNotHasKey($this->menuA1->id, $resultB['menus']);
    }

    // =========================================================================
    // 9. STRICT OUTLET ISOLATION
    // =========================================================================
    public function test_strict_outlet_isolation(): void
    {
        // Create menu in Outlet A2
        $menuA2_Kemang = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA2->id,
            'name'       => 'Menu Kemang Khusus',
            'category'   => 'Khusus',
            'price'      => 15000,
            'cost_price' => 8000,
            'stock'      => 50,
        ]);

        // Sale in Outlet A1
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-20', '12:00:00', $this->menuA1->id, $this->menuA1->name, 5.0, 5000);
        // Sale in Outlet A2
        $this->createTxWithItem($this->tenantA, $this->outletA2, '2026-09-20', '12:00:00', $menuA2_Kemang->id, $menuA2_Kemang->name, 8.0, 15000);

        // Analyze strictly Outlet A1
        $resultA1 = $this->service->analyzeVelocity($this->tenantA->id, $this->outletA1->id, '2026-09-30', 30);
        $this->assertEquals(1, $resultA1['metadata']['total_transactions_evaluated']);
        $this->assertEquals(25000.0, $resultA1['metadata']['outlet_total_revenue']);
        $this->assertArrayNotHasKey($menuA2_Kemang->id, $resultA1['menus']);

        // Analyze strictly Outlet A2
        $resultA2 = $this->service->analyzeVelocity($this->tenantA->id, $this->outletA2->id, '2026-09-30', 30);
        $this->assertEquals(1, $resultA2['metadata']['total_transactions_evaluated']);
        $this->assertEquals(120000.0, $resultA2['metadata']['outlet_total_revenue']);
        $this->assertArrayNotHasKey($this->menuA1->id, $resultA2['menus']);
    }

    // =========================================================================
    // 10. POINT-IN-TIME (PIT) CUTOFF & FUTURE CANCELLATION SEMANTICS
    // =========================================================================
    public function test_pit_cutoff_and_future_cancellation_semantics(): void
    {
        $tz = 'Asia/Jakarta';
        // Tx 1: Created on 2026-09-10 (Before Cutoff) -> MUST be included
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-10', '12:00:00', $this->menuA1->id, $this->menuA1->name, 3.0, 5000);

        // Tx 2: Created on 2026-09-25 (After Cutoff of 2026-09-20 23:59:59) -> MUST be excluded
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-25', '12:00:00', $this->menuA1->id, $this->menuA1->name, 10.0, 5000);

        // Tx 3: Created on 2026-09-12 (Before Cutoff), but cancelled in the future on 2026-09-28
        // At the time of cutoff, it was STILL valid -> MUST be included in historical PIT analysis!
        $utcCreatedTx3 = Carbon::parse('2026-09-12 12:00:00', $tz)->setTimezone('UTC')->format('Y-m-d H:i:s');
        $utcCancelledTx3 = Carbon::parse('2026-09-28 15:00:00', $tz)->setTimezone('UTC')->format('Y-m-d H:i:s');
        $this->createTxWithItem(
            $this->tenantA,
            $this->outletA1,
            '2026-09-12',
            '12:00:00',
            $this->menuA1->id,
            $this->menuA1->name,
            2.0,
            5000,
            'Batal',
            $utcCancelledTx3,
            'Batal',
            $utcCancelledTx3
        );

        $cutoffUtc = Carbon::parse('2026-09-20 23:59:59', $tz)->setTimezone('UTC')->format('Y-m-d H:i:s');

        $result = $this->service->analyzeVelocity($this->tenantA->id, $this->outletA1->id, '2026-09-20', 30, [
            'cutoff_utc' => $cutoffUtc,
        ]);

        $evaluated = $result['menus'][$this->menuA1->id];
        // Tx 1 (3.0) + Tx 3 (2.0, active as of cutoff) = 5.0 units. Tx 2 is excluded.
        $this->assertEquals(5.0, $evaluated['total_quantity_sold']);
        $this->assertEquals(2, $result['metadata']['total_transactions_evaluated']);
    }

    // =========================================================================
    // 11. AMBIGUOUS MENU IDENTITY FAILS CLOSED
    // =========================================================================
    public function test_ambiguous_menu_identity_fails_closed(): void
    {
        // Create duplicate active menu with the same exact name in Tenant A
        Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA1->id,
            'name'       => 'Es Teh Manis Segar', // EXACT duplicate name as menuA1
            'category'   => 'Minuman Duplikat',
            'price'      => 6000,
            'cost_price' => 1500,
            'stock'      => -1,
        ]);

        // Transaction item without menu_id (legacy POS) matching the ambiguous name
        $this->createTxWithItem(
            $this->tenantA,
            $this->outletA1,
            '2026-09-20',
            '12:00:00',
            null, // null menu_id!
            'es teh manis segar',
            5.0,
            5000
        );

        $result = $this->service->analyzeVelocity($this->tenantA->id, $this->outletA1->id, '2026-09-30', 30);

        // Fail-closed: Ambiguous name rejected, not attributed to menuA1
        $this->assertEquals(1, $result['metadata']['unresolved_items_count']);
        $evaluated = $result['menus'][$this->menuA1->id];
        $this->assertEquals(0.0, $evaluated['total_quantity_sold']);
        $this->assertEquals(MenuSalesVelocityService::CAT_NO_SALES, $evaluated['category']);
    }

    // =========================================================================
    // 12. HIGH_VELOCITY CLASSIFICATION FOR REGULAR HIGH-VOLUME SELLERS
    // =========================================================================
    public function test_high_velocity_classification_for_fast_selling_staples(): void
    {
        // 25 active selling days out of 30 days (frequency = 25/30 = 83.3% >= 70%)
        // 5 units every day = 125 units total (avg = 125/30 = 4.17 units/day >= 2.0)
        for ($d = 1; $d <= 25; $d++) {
            $dateStr = sprintf('2026-09-%02d', $d);
            $this->createTxWithItem($this->tenantA, $this->outletA1, $dateStr, '12:00:00', $this->menuA1->id, $this->menuA1->name, 5.0, 5000);
        }

        $result = $this->service->analyzeVelocity($this->tenantA->id, $this->outletA1->id, '2026-09-30', 30);
        $evaluated = $result['menus'][$this->menuA1->id];

        $this->assertEquals(MenuSalesVelocityService::CAT_HIGH_VELOCITY, $evaluated['category']);
        $this->assertEquals(125.0, $evaluated['total_quantity_sold']);
        $this->assertEquals(25, $evaluated['active_selling_days']);
        $this->assertEquals(5, $evaluated['zero_sales_days']);
        $this->assertEquals(0.8333, $evaluated['sales_frequency']);
        $this->assertEquals(4.1667, $evaluated['average_daily_qty']);
        $this->assertNotEmpty($result['top_velocity_menus']);
    }
}
