<?php

namespace Tests\Unit;

use App\Models\Menu;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Financial\ContributionMarginService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContributionMarginServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ContributionMarginService $service;
    protected User $tenant1;
    protected User $tenant2;
    protected Outlet $outlet1;
    protected Outlet $outlet2;
    protected Outlet $tenant2Outlet;
    protected Menu $menu1;
    protected Menu $menu2;
    protected Menu $menu3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ContributionMarginService::class);

        $this->tenant1 = User::factory()->create();
        $this->tenant2 = User::factory()->create();

        $this->outlet1 = Outlet::create([
            'user_id'  => $this->tenant1->id,
            'name'     => 'Outlet Utama',
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->outlet2 = Outlet::create([
            'user_id'  => $this->tenant1->id,
            'name'     => 'Outlet Cabang',
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->tenant2Outlet = Outlet::create([
            'user_id'  => $this->tenant2->id,
            'name'     => 'Tenant 2 Outlet',
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->menu1 = Menu::create([
            'user_id'    => $this->tenant1->id,
            'outlet_id'  => $this->outlet1->id,
            'name'       => 'Healthy Burger',
            'category'   => 'Food',
            'price'      => 25000,
            'cost_price' => 10000,
        ]);

        $this->menu2 = Menu::create([
            'user_id'    => $this->tenant1->id,
            'outlet_id'  => $this->outlet1->id,
            'name'       => 'Thin Margin Fries',
            'category'   => 'Food',
            'price'      => 20000,
            'cost_price' => 13000,
        ]);

        $this->menu3 = Menu::create([
            'user_id'    => $this->tenant1->id,
            'outlet_id'  => $this->outlet1->id,
            'name'       => 'Zero Cost Drink',
            'category'   => 'Beverage',
            'price'      => 10000,
            'cost_price' => 0,
        ]);
    }

    protected function insertTxWithItem(
        int $userId,
        int $outletId,
        string $businessDate,
        string $status,
        float $totalAmount,
        ?int $menuId = null,
        ?string $menuName = null,
        float $qty = 1.0,
        float $price = 0.0,
        ?float $tiCostPrice = null,
        ?string $updatedAt = null
    ): int {
        $createdAtUtc = Carbon::createFromFormat('Y-m-d H:i:s', "{$businessDate} 12:00:00", 'Asia/Jakarta')
            ->setTimezone('UTC')
            ->format('Y-m-d H:i:s');

        $updatedAtUtc = $updatedAt ?? $createdAtUtc;

        $txId = DB::table('transactions')->insertGetId([
            'app_uuid'       => (string) Str::uuid(),
            'user_id'        => $userId,
            'outlet_id'      => $outletId,
            'payment_method' => 'Tunai',
            'status'         => $status,
            'total_amount'   => $totalAmount,
            'pay_amount'     => $totalAmount,
            'created_at'     => $createdAtUtc,
            'updated_at'     => $updatedAtUtc,
        ]);

        if ($qty > 0) {
            $itemData = [
                'transaction_id' => $txId,
                'menu_id'        => $menuId,
                'menu_name'      => $menuName ?? ($menuId ? Menu::find($menuId)?->name : 'Unknown Item'),
                'qty'            => $qty,
                'price'          => $price,
                'created_at'     => $createdAtUtc,
                'updated_at'     => $updatedAtUtc,
            ];

            if ($tiCostPrice !== null) {
                $itemData['cost_price'] = $tiCostPrice;
            }

            DB::table('transaction_items')->insert($itemData);
        }

        return $txId;
    }

    public function test_normal_margin_calculation(): void
    {
        // 2 units of menu1 (price 25k, cost 10k)
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-15',
            status: 'Selesai',
            totalAmount: 50000,
            menuId: $this->menu1->id,
            menuName: $this->menu1->name,
            qty: 2.0,
            price: 25000,
            tiCostPrice: 10000
        );

        $result = $this->service->analyzeMargin($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);

        $this->assertEquals(1, $result['summary']['total_eligible_transactions']);
        $this->assertEquals(1, $result['summary']['total_eligible_items']);
        $this->assertEquals(50000.0, $result['summary']['total_gross_revenue']);
        $this->assertEquals(50000.0, $result['summary']['covered_revenue']);
        $this->assertEquals(20000.0, $result['summary']['estimated_cogs']);
        $this->assertEquals(30000.0, $result['summary']['estimated_contribution_margin']);
        $this->assertEquals(60.0, $result['summary']['estimated_margin_pct']);
        $this->assertEquals(100.0, $result['summary']['revenue_coverage_pct']);

        $this->assertArrayHasKey($this->menu1->id, $result['menus']);
        $menuReport = $result['menus'][$this->menu1->id];
        $this->assertEquals(2.0, $menuReport['quantity_sold']);
        $this->assertEquals(50000.0, $menuReport['gross_revenue']);
        $this->assertEquals(20000.0, $menuReport['estimated_cogs']);
        $this->assertEquals(30000.0, $menuReport['estimated_margin']);
        $this->assertEquals(60.0, $menuReport['estimated_margin_pct']);
        $this->assertEquals(ContributionMarginService::HEALTH_HEALTHY_MARGIN, $menuReport['health_category']);
        $this->assertEquals(ContributionMarginService::COST_STATUS_VALID, $menuReport['cost_status']);
    }

    public function test_thin_and_low_margin_classification(): void
    {
        // Menu2 (price 20k, cost 13k -> margin 7k / 35% -> THIN_MARGIN)
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-15',
            status: 'Selesai',
            totalAmount: 20000,
            menuId: $this->menu2->id,
            menuName: $this->menu2->name,
            qty: 1.0,
            price: 20000,
            tiCostPrice: 13000
        );

        $lowMenu = Menu::create([
            'user_id'    => $this->tenant1->id,
            'outlet_id'  => $this->outlet1->id,
            'name'       => 'Low Margin Snack',
            'category'   => 'Food',
            'price'      => 10000,
            'cost_price' => 8500, // margin 1500 / 15% -> LOW_MARGIN
        ]);

        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-16',
            status: 'Selesai',
            totalAmount: 10000,
            menuId: $lowMenu->id,
            menuName: $lowMenu->name,
            qty: 1.0,
            price: 10000,
            tiCostPrice: 8500
        );

        $result = $this->service->analyzeMargin($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);

        $this->assertEquals(ContributionMarginService::HEALTH_THIN_MARGIN, $result['menus'][$this->menu2->id]['health_category']);
        $this->assertEquals(ContributionMarginService::HEALTH_LOW_MARGIN, $result['menus'][$lowMenu->id]['health_category']);
    }

    public function test_zero_cost_categorization_and_insufficient_cost_health(): void
    {
        // Menu3 has cost_price = 0
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-15',
            status: 'Selesai',
            totalAmount: 10000,
            menuId: $this->menu3->id,
            menuName: $this->menu3->name,
            qty: 1.0,
            price: 10000,
            tiCostPrice: 0.0
        );

        $result = $this->service->analyzeMargin($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);

        $this->assertEquals(1, $result['summary']['zero_cost_items_count']);
        $this->assertEquals(0.0, $result['summary']['covered_revenue']);
        $this->assertEquals(0.0, $result['summary']['revenue_coverage_pct']);

        $menuReport = $result['menus'][$this->menu3->id];
        $this->assertEquals(ContributionMarginService::COST_STATUS_ZERO, $menuReport['cost_status']);
        $this->assertEquals(ContributionMarginService::HEALTH_INSUFFICIENT_COST, $menuReport['health_category']);
    }

    public function test_missing_cost_categorization_and_insufficient_cost_health(): void
    {
        $unlinkedMenu = Menu::create([
            'user_id'    => $this->tenant1->id,
            'outlet_id'  => $this->outlet1->id,
            'name'       => 'Unlinked Cost Item',
            'category'   => 'Misc',
            'price'      => 15000,
            'cost_price' => 0,
        ]);

        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-15',
            status: 'Selesai',
            totalAmount: 15000,
            menuId: $unlinkedMenu->id,
            menuName: $unlinkedMenu->name,
            qty: 1.0,
            price: 15000,
            tiCostPrice: null
        );

        $result = $this->service->analyzeMargin($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);

        $this->assertEquals(1, $result['summary']['menus_with_insufficient_cost']);
        $this->assertEquals(ContributionMarginService::HEALTH_INSUFFICIENT_COST, $result['menus'][$unlinkedMenu->id]['health_category']);
    }

    public function test_negative_margin_and_anomalous_cost_detection(): void
    {
        $lossMenu = Menu::create([
            'user_id'    => $this->tenant1->id,
            'outlet_id'  => $this->outlet1->id,
            'name'       => 'Loss Leader Item',
            'category'   => 'Promo',
            'price'      => 10000,
            'cost_price' => 15000, // cost > price
        ]);

        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-15',
            status: 'Selesai',
            totalAmount: 10000,
            menuId: $lossMenu->id,
            menuName: $lossMenu->name,
            qty: 1.0,
            price: 10000,
            tiCostPrice: 15000
        );

        $result = $this->service->analyzeMargin($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);

        $menuReport = $result['menus'][$lossMenu->id];
        $this->assertEquals(-5000.0, $menuReport['estimated_margin']);
        $this->assertEquals(-50.0, $menuReport['estimated_margin_pct']);
        $this->assertEquals(ContributionMarginService::HEALTH_NEGATIVE_MARGIN, $menuReport['health_category']);
        $this->assertEquals(ContributionMarginService::COST_STATUS_ANOMALOUS, $menuReport['cost_status']);
        $this->assertNotEmpty($result['anomalies']);
    }

    public function test_cancelled_and_refunded_transactions_excluded(): void
    {
        // Cancelled transaction
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-15',
            status: 'Batal',
            totalAmount: 25000,
            menuId: $this->menu1->id,
            menuName: $this->menu1->name,
            qty: 1.0,
            price: 25000,
            tiCostPrice: 10000
        );

        $result = $this->service->analyzeMargin($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);

        $this->assertEquals(0, $result['summary']['total_eligible_transactions']);
        $this->assertEquals(0, $result['summary']['total_eligible_items']);
        $this->assertEquals(0.0, $result['summary']['total_gross_revenue']);
    }

    public function test_pit_cutoff_excludes_post_cutoff_transactions(): void
    {
        // Created at 2026-10-02 (after cutoff 2026-09-30)
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-10-02',
            status: 'Selesai',
            totalAmount: 25000,
            menuId: $this->menu1->id,
            menuName: $this->menu1->name,
            qty: 1.0,
            price: 25000,
            tiCostPrice: 10000
        );

        $result = $this->service->analyzeMargin($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);

        $this->assertEquals(0, $result['summary']['total_eligible_transactions']);
        $this->assertEquals(0.0, $result['summary']['total_gross_revenue']);
    }

    public function test_future_cancellation_preserves_historical_valid_status_at_cutoff(): void
    {
        // Created at 2026-09-20 (valid at cutoff 2026-09-30), but updated/cancelled on 2026-10-05 (future)
        $updatedAtUtc = Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 10:00:00', 'Asia/Jakarta')
            ->setTimezone('UTC')
            ->format('Y-m-d H:i:s');

        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-20',
            status: 'Batal',
            totalAmount: 25000,
            menuId: $this->menu1->id,
            menuName: $this->menu1->name,
            qty: 1.0,
            price: 25000,
            tiCostPrice: 10000,
            updatedAt: $updatedAtUtc
        );

        $result = $this->service->analyzeMargin($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);

        // Historical status at cutoff was valid (cancellation happened in future)
        $this->assertEquals(1, $result['summary']['total_eligible_transactions']);
        $this->assertEquals(25000.0, $result['summary']['total_gross_revenue']);
    }

    public function test_strict_tenant_isolation(): void
    {
        // Tenant 2 transaction
        $tenant2Menu = Menu::create([
            'user_id'    => $this->tenant2->id,
            'outlet_id'  => $this->tenant2Outlet->id,
            'name'       => 'Other Dish',
            'category'   => 'Food',
            'price'      => 100000,
            'cost_price' => 40000,
        ]);

        $this->insertTxWithItem(
            userId: $this->tenant2->id,
            outletId: $this->tenant2Outlet->id,
            businessDate: '2026-09-15',
            status: 'Selesai',
            totalAmount: 100000,
            menuId: $tenant2Menu->id,
            menuName: $tenant2Menu->name,
            qty: 1.0,
            price: 100000,
            tiCostPrice: 40000
        );

        // Analysis for Tenant 1 must be 0
        $result = $this->service->analyzeMargin($this->tenant1->id, null, '2026-09-30', 30);

        $this->assertEquals(0, $result['summary']['total_eligible_transactions']);
        $this->assertEquals(0.0, $result['summary']['total_gross_revenue']);
        $this->assertArrayNotHasKey($tenant2Menu->id, $result['menus']);
    }

    public function test_strict_outlet_isolation(): void
    {
        // Outlet 2 transaction
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet2->id,
            businessDate: '2026-09-15',
            status: 'Selesai',
            totalAmount: 25000,
            menuId: $this->menu1->id,
            menuName: $this->menu1->name,
            qty: 1.0,
            price: 25000,
            tiCostPrice: 10000
        );

        // Scoped to Outlet 1 -> must return 0
        $result = $this->service->analyzeMargin($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);
        $this->assertEquals(0, $result['summary']['total_eligible_transactions']);

        // Scoped to Outlet 2 -> must return 1
        $resultOutlet2 = $this->service->analyzeMargin($this->tenant1->id, $this->outlet2->id, '2026-09-30', 30);
        $this->assertEquals(1, $resultOutlet2['summary']['total_eligible_transactions']);
    }

    public function test_decimal_price_and_cost_calculation(): void
    {
        // 2.5 kg sold at 15500.50 with cost 9500.25
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-15',
            status: 'Selesai',
            totalAmount: 38751.25,
            menuId: $this->menu1->id,
            menuName: $this->menu1->name,
            qty: 2.5,
            price: 15500.50,
            tiCostPrice: 9500.25
        );

        $result = $this->service->analyzeMargin($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);

        $this->assertEquals(38751.25, $result['summary']['total_gross_revenue']);
        $this->assertEquals(23750.63, $result['summary']['estimated_cogs']);
        $this->assertEquals(15000.62, $result['summary']['estimated_contribution_margin']);
    }

    public function test_ambiguous_menu_identity_fails_closed(): void
    {
        // Item with menu_id = null and unresolvable name
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-15',
            status: 'Selesai',
            totalAmount: 20000,
            menuId: null,
            menuName: 'Non-Existent Mystery Custom Item',
            qty: 1.0,
            price: 20000,
            tiCostPrice: 5000
        );

        $result = $this->service->analyzeMargin($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);

        // Unresolved items are quarantined
        $this->assertEquals(1, $result['unresolved_items']['count']);
        $this->assertEquals(20000.0, $result['unresolved_items']['gross_revenue']);
        $this->assertEmpty($result['menus']); // No registered menu is polluted
    }
}
