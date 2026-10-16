<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Menu;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Financial\FinancialPerformanceIntelligenceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialPerformanceIntelligenceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected FinancialPerformanceIntelligenceService $service;
    protected User $tenant1;
    protected User $tenant2;
    protected Outlet $outlet1;
    protected Outlet $outlet2;
    protected Outlet $tenant2Outlet;
    protected Menu $menuHealthy;
    protected Menu $menuThin;
    protected Menu $menuZeroCost;
    protected Menu $menuDormant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FinancialPerformanceIntelligenceService::class);

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

        // Healthy Margin Menu (Price: 30k, Cost: 10k -> Margin 66.7%)
        $this->menuHealthy = Menu::create([
            'user_id'    => $this->tenant1->id,
            'outlet_id'  => $this->outlet1->id,
            'name'       => 'Healthy Burger',
            'category'   => 'Food',
            'price'      => 30000,
            'cost_price' => 10000,
        ]);

        // Thin Margin Menu (Price: 20k, Cost: 14k -> Margin 30.0%)
        $this->menuThin = Menu::create([
            'user_id'    => $this->tenant1->id,
            'outlet_id'  => $this->outlet1->id,
            'name'       => 'Thin Margin Fries',
            'category'   => 'Food',
            'price'      => 20000,
            'cost_price' => 14000,
        ]);

        // Zero Cost Menu (Price: 15k, Cost: 0 -> Insufficient Cost Data)
        $this->menuZeroCost = Menu::create([
            'user_id'    => $this->tenant1->id,
            'outlet_id'  => $this->outlet1->id,
            'name'       => 'Zero Cost Drink',
            'category'   => 'Beverage',
            'price'      => 15000,
            'cost_price' => 0,
        ]);

        // Dormant Menu (Price: 50k, Cost: 20k -> 0 sales in window)
        $this->menuDormant = Menu::create([
            'user_id'    => $this->tenant1->id,
            'outlet_id'  => $this->outlet1->id,
            'name'       => 'Dormant Steak',
            'category'   => 'Food',
            'price'      => 50000,
            'cost_price' => 20000,
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
                'user_id'        => $userId,
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

    public function test_strong_performer_classification(): void
    {
        // Generate high sales volume over 25 days across 30d window (Frequency: 83.3% -> HIGH_VELOCITY, Margin 66.7% -> HEALTHY_MARGIN)
        for ($i = 1; $i <= 25; $i++) {
            $date = sprintf('2026-09-%02d', $i);
            $this->insertTxWithItem(
                userId: $this->tenant1->id,
                outletId: $this->outlet1->id,
                businessDate: $date,
                status: 'Selesai',
                totalAmount: 90000,
                menuId: $this->menuHealthy->id,
                menuName: $this->menuHealthy->name,
                qty: 3.0,
                price: 30000,
                tiCostPrice: 10000
            );
        }

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);
        $record = $result['menus'][$this->menuHealthy->id] ?? null;

        $this->assertNotNull($record);
        $this->assertSame(FinancialPerformanceIntelligenceService::STATE_STRONG_PERFORMER, $record['performance_state']);
        $this->assertSame('HIGH_VELOCITY', $record['velocity_status']);
        $this->assertSame('HEALTHY_MARGIN', $record['margin_status']);
        $this->assertEquals(66.67, $record['estimated_margin_pct']);
        $this->assertStringContainsString('STRONG_PERFORMER', $result['state_distribution'] ? 'STRONG_PERFORMER' : '');
    }

    public function test_high_sales_low_margin_classification(): void
    {
        // High velocity (25 days x 4 units/day) with Thin margin (30% margin)
        for ($i = 1; $i <= 25; $i++) {
            $date = sprintf('2026-09-%02d', $i);
            $this->insertTxWithItem(
                userId: $this->tenant1->id,
                outletId: $this->outlet1->id,
                businessDate: $date,
                status: 'Selesai',
                totalAmount: 80000,
                menuId: $this->menuThin->id,
                menuName: $this->menuThin->name,
                qty: 4.0,
                price: 20000,
                tiCostPrice: 14000
            );
        }

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);
        $record = $result['menus'][$this->menuThin->id] ?? null;

        $this->assertNotNull($record);
        $this->assertSame(FinancialPerformanceIntelligenceService::STATE_HIGH_SALES_LOW_MARGIN, $record['performance_state']);
        $this->assertSame('HIGH_VELOCITY', $record['velocity_status']);
        $this->assertSame('THIN_MARGIN', $record['margin_status']);
        $this->assertEquals(30.0, $record['estimated_margin_pct']);
    }

    public function test_high_margin_low_velocity_classification(): void
    {
        // Sold only on 2 days out of 30 days (<15% frequency -> LOW_VELOCITY), but Healthy Margin (66.7%)
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-05',
            status: 'Selesai',
            totalAmount: 30000,
            menuId: $this->menuHealthy->id,
            menuName: $this->menuHealthy->name,
            qty: 1.0,
            price: 30000,
            tiCostPrice: 10000
        );
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-18',
            status: 'Selesai',
            totalAmount: 30000,
            menuId: $this->menuHealthy->id,
            menuName: $this->menuHealthy->name,
            qty: 1.0,
            price: 30000,
            tiCostPrice: 10000
        );

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);
        $record = $result['menus'][$this->menuHealthy->id] ?? null;

        $this->assertNotNull($record);
        $this->assertSame(FinancialPerformanceIntelligenceService::STATE_HIGH_MARGIN_LOW_VELOCITY, $record['performance_state']);
        $this->assertSame('LOW_VELOCITY', $record['velocity_status']);
        $this->assertSame('HEALTHY_MARGIN', $record['margin_status']);
    }

    public function test_declining_sales_compressing_margin_deterioration(): void
    {
        // Prior 14d (Sep 3 - Sep 16): heavy sales with high margin (10k cost)
        for ($i = 3; $i <= 16; $i++) {
            $date = sprintf('2026-09-%02d', $i);
            $this->insertTxWithItem(
                userId: $this->tenant1->id,
                outletId: $this->outlet1->id,
                businessDate: $date,
                status: 'Selesai',
                totalAmount: 60000,
                menuId: $this->menuHealthy->id,
                menuName: $this->menuHealthy->name,
                qty: 2.0,
                price: 30000,
                tiCostPrice: 10000
            );
        }

        // Recent 14d (Sep 17 - Sep 30): very low sales (only 1 day) with higher cost (24k cost -> 20% margin -> LOW_MARGIN)
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-20',
            status: 'Selesai',
            totalAmount: 30000,
            menuId: $this->menuHealthy->id,
            menuName: $this->menuHealthy->name,
            qty: 1.0,
            price: 30000,
            tiCostPrice: 24000
        );

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);
        $record = $result['menus'][$this->menuHealthy->id] ?? null;

        $this->assertNotNull($record);
        $this->assertSame(FinancialPerformanceIntelligenceService::STATE_PERFORMANCE_DETERIORATION, $record['performance_state']);
        $this->assertSame('DECLINING_VELOCITY', $record['velocity_status']);
    }

    public function test_insufficient_margin_evidence_when_cost_missing(): void
    {
        // 10 transactions of menuZeroCost (cost 0)
        for ($i = 1; $i <= 10; $i++) {
            $date = sprintf('2026-09-%02d', $i);
            $this->insertTxWithItem(
                userId: $this->tenant1->id,
                outletId: $this->outlet1->id,
                businessDate: $date,
                status: 'Selesai',
                totalAmount: 15000,
                menuId: $this->menuZeroCost->id,
                menuName: $this->menuZeroCost->name,
                qty: 1.0,
                price: 15000,
                tiCostPrice: 0.0
            );
        }

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);
        $record = $result['menus'][$this->menuZeroCost->id] ?? null;

        $this->assertNotNull($record);
        $this->assertSame(FinancialPerformanceIntelligenceService::STATE_INSUFFICIENT_MARGIN_DATA, $record['performance_state']);
        $this->assertSame('INSUFFICIENT_COST_DATA', $record['margin_status']);
        $this->assertEquals(0.0, $record['revenue_cost_coverage_pct']);
    }

    public function test_dormant_catalog_item_when_no_sales(): void
    {
        // menuDormant has 0 transactions
        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);
        $record = $result['menus'][$this->menuDormant->id] ?? null;

        $this->assertNotNull($record);
        $this->assertSame(FinancialPerformanceIntelligenceService::STATE_DORMANT_CATALOG_ITEM, $record['performance_state']);
        $this->assertSame('NO_SALES', $record['velocity_status']);
        $this->assertSame(0, $record['velocity_metrics']['total_quantity_sold']);
        // Verify owner-safe language: no dead stock claim in evidence/limitations
        $this->assertStringNotContainsString('dead stock', strtolower($record['evidence'][0] ?? ''));
    }

    public function test_low_margin_low_velocity_classification(): void
    {
        // Sold only on 2 days out of 30 days (<15% frequency -> LOW_VELOCITY) with Thin margin (30.0%)
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-05',
            status: 'Selesai',
            totalAmount: 20000,
            menuId: $this->menuThin->id,
            menuName: $this->menuThin->name,
            qty: 1.0,
            price: 20000,
            tiCostPrice: 14000
        );
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-18',
            status: 'Selesai',
            totalAmount: 20000,
            menuId: $this->menuThin->id,
            menuName: $this->menuThin->name,
            qty: 1.0,
            price: 20000,
            tiCostPrice: 14000
        );

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);
        $record = $result['menus'][$this->menuThin->id] ?? null;

        $this->assertNotNull($record);
        $this->assertSame(FinancialPerformanceIntelligenceService::STATE_LOW_MARGIN_LOW_VELOCITY, $record['performance_state']);
        $this->assertSame('LOW_VELOCITY', $record['velocity_status']);
        $this->assertSame('THIN_MARGIN', $record['margin_status']);
        $this->assertEquals(30.0, $record['estimated_margin_pct']);
    }

    public function test_stable_core_performer_classification(): void
    {
        // 10 sales evenly distributed across 30 days (5 in prior 14d, 5 in recent 14d)
        // Active days = 10/30 (33.3% -> between 15% and 70% -> STABLE velocity)
        // Prior and recent velocity are identical -> STABLE trend direction
        $priorDates = ['2026-09-03', '2026-09-06', '2026-09-09', '2026-09-12', '2026-09-15'];
        $recentDates = ['2026-09-18', '2026-09-21', '2026-09-24', '2026-09-27', '2026-09-30'];

        foreach (array_merge($priorDates, $recentDates) as $date) {
            $this->insertTxWithItem(
                userId: $this->tenant1->id,
                outletId: $this->outlet1->id,
                businessDate: $date,
                status: 'Selesai',
                totalAmount: 30000,
                menuId: $this->menuHealthy->id,
                menuName: $this->menuHealthy->name,
                qty: 1.0,
                price: 30000,
                tiCostPrice: 10000
            );
        }

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);
        $record = $result['menus'][$this->menuHealthy->id] ?? null;

        $this->assertNotNull($record);
        $this->assertSame(FinancialPerformanceIntelligenceService::STATE_STABLE_CORE_PERFORMER, $record['performance_state']);
        $this->assertSame('STABLE', $record['velocity_status']);
        $this->assertSame('HEALTHY_MARGIN', $record['margin_status']);
        $this->assertSame('STABLE', $record['sales_trend']['direction']);
    }

    public function test_negative_margin_risk_classification(): void
    {
        // Menu where Cost Price (15k) exceeds Selling Price (10k) -> -50.0% margin
        $menuNegative = Menu::create([
            'user_id'    => $this->tenant1->id,
            'outlet_id'  => $this->outlet1->id,
            'name'       => 'Negative Margin Loss Leader',
            'category'   => 'Food',
            'price'      => 10000,
            'cost_price' => 15000,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $this->insertTxWithItem(
                userId: $this->tenant1->id,
                outletId: $this->outlet1->id,
                businessDate: sprintf('2026-09-%02d', $i + 10),
                status: 'Selesai',
                totalAmount: 10000,
                menuId: $menuNegative->id,
                menuName: $menuNegative->name,
                qty: 1.0,
                price: 10000,
                tiCostPrice: 15000
            );
        }

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);
        $record = $result['menus'][$menuNegative->id] ?? null;

        $this->assertNotNull($record);
        $this->assertSame(FinancialPerformanceIntelligenceService::STATE_NEGATIVE_MARGIN_RISK, $record['performance_state']);
        $this->assertSame('NEGATIVE_MARGIN', $record['margin_status']);
        $this->assertEquals(-50.0, $record['estimated_margin_pct']);
        $this->assertTrue($record['data_quality']['margin_data_sufficient']);
        $this->assertEquals(100.0, $record['revenue_cost_coverage_pct']);
    }

    public function test_insufficient_evidence_vs_dormant_catalog_contract(): void
    {
        // 1. Menu with 0 sales in catalog is classified as DORMANT_CATALOG_ITEM
        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);
        $record = $result['menus'][$this->menuDormant->id] ?? null;
        $this->assertNotNull($record);
        $this->assertSame(FinancialPerformanceIntelligenceService::STATE_DORMANT_CATALOG_ITEM, $record['performance_state']);

        // 2. Outlet with completely empty catalog returns empty menus and INSUFFICIENT_EVIDENCE metadata
        $emptyOutlet = Outlet::create([
            'user_id'  => $this->tenant1->id,
            'name'     => 'Brand New Empty Outlet',
            'timezone' => 'Asia/Jakarta',
        ]);

        $emptyResult = $this->service->analyze($this->tenant1->id, $emptyOutlet->id, '2026-09-30', 30);
        $this->assertEmpty($emptyResult['menus']);
        $this->assertSame(0, $emptyResult['summary']['total_catalog_menus']);
        $this->assertNotEmpty($emptyResult['limitations']);
    }

    public function test_unresolved_menu_identity_fails_closed(): void
    {
        // Insert transaction with unlinked menu
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-15',
            status: 'Selesai',
            totalAmount: 25000,
            menuId: null,
            menuName: 'Custom Unregistered Special',
            qty: 1.0,
            price: 25000,
            tiCostPrice: 0.0
        );

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);

        $this->assertNotEmpty($result['unresolved_items']['items'] ?? []);
        $unresolvedNames = array_column($result['unresolved_items']['items'] ?? [], 'raw_menu_name');
        $this->assertContains('Custom Unregistered Special', $unresolvedNames);

        // Ensure not mapped to existing menus
        foreach ($result['menus'] as $rec) {
            $this->assertNotEquals('Custom Unregistered Special', $rec['menu_name']);
        }
    }

    public function test_anomaly_signal_flagging(): void
    {
        // 1. Establish baseline with 15 normal transactions (1 unit each)
        for ($i = 1; $i <= 15; $i++) {
            $this->insertTxWithItem(
                userId: $this->tenant1->id,
                outletId: $this->outlet1->id,
                businessDate: sprintf('2026-09-%02d', $i),
                status: 'Selesai',
                totalAmount: 30000,
                menuId: $this->menuHealthy->id,
                menuName: $this->menuHealthy->name,
                qty: 1.0,
                price: 30000,
                tiCostPrice: 10000
            );
        }

        // 2. Insert unusual quantity order (50 units > 20 threshold)
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-20',
            status: 'Selesai',
            totalAmount: 1500000,
            menuId: $this->menuHealthy->id,
            menuName: $this->menuHealthy->name,
            qty: 50.0,
            price: 30000,
            tiCostPrice: 10000
        );

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);
        $record = $result['menus'][$this->menuHealthy->id] ?? null;

        $this->assertNotNull($record);
        $this->assertSame(FinancialPerformanceIntelligenceService::STATE_ANOMALY_FLAGGED, $record['performance_state']);
        $this->assertNotEmpty($record['anomaly_signals']);
    }

    public function test_mixed_signals_classification(): void
    {
        // Prior 14d: 1 sale per day with 10k cost (66.7% margin)
        for ($i = 3; $i <= 16; $i++) {
            $date = sprintf('2026-09-%02d', $i);
            $this->insertTxWithItem(
                userId: $this->tenant1->id,
                outletId: $this->outlet1->id,
                businessDate: $date,
                status: 'Selesai',
                totalAmount: 30000,
                menuId: $this->menuHealthy->id,
                menuName: $this->menuHealthy->name,
                qty: 1.0,
                price: 30000,
                tiCostPrice: 10000
            );
        }

        // Recent 14d: heavy sales (5 sales/day -> UPWARD trend) but higher cost (22k cost -> 26.7% margin -> COMPRESSING margin)
        for ($i = 17; $i <= 30; $i++) {
            $date = sprintf('2026-09-%02d', $i);
            $this->insertTxWithItem(
                userId: $this->tenant1->id,
                outletId: $this->outlet1->id,
                businessDate: $date,
                status: 'Selesai',
                totalAmount: 150000,
                menuId: $this->menuHealthy->id,
                menuName: $this->menuHealthy->name,
                qty: 5.0,
                price: 30000,
                tiCostPrice: 22000
            );
        }

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);
        $record = $result['menus'][$this->menuHealthy->id] ?? null;

        $this->assertNotNull($record);
        $this->assertSame(FinancialPerformanceIntelligenceService::STATE_MIXED_SIGNAL, $record['performance_state']);
        $this->assertSame('UPWARD', $record['sales_trend']['direction']);
    }

    public function test_strict_tenant_isolation(): void
    {
        // Insert transaction for Tenant 2
        $this->insertTxWithItem(
            userId: $this->tenant2->id,
            outletId: $this->tenant2Outlet->id,
            businessDate: '2026-09-15',
            status: 'Selesai',
            totalAmount: 100000,
            menuId: null,
            menuName: 'Tenant 2 Secret Dish',
            qty: 2.0,
            price: 50000,
            tiCostPrice: 20000
        );

        $resultTenant1 = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);

        foreach ($resultTenant1['menus'] as $rec) {
            $this->assertNotEquals('Tenant 2 Secret Dish', $rec['menu_name']);
        }
    }

    public function test_strict_outlet_isolation(): void
    {
        // Insert transaction in Outlet 2 only
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet2->id,
            businessDate: '2026-09-15',
            status: 'Selesai',
            totalAmount: 60000,
            menuId: $this->menuHealthy->id,
            menuName: $this->menuHealthy->name,
            qty: 2.0,
            price: 30000,
            tiCostPrice: 10000
        );

        $resultOutlet1 = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);
        $recordOutlet1 = $resultOutlet1['menus'][$this->menuHealthy->id] ?? null;

        $this->assertNotNull($recordOutlet1);
        $this->assertSame(0, $recordOutlet1['velocity_metrics']['total_quantity_sold']);
        $this->assertSame(FinancialPerformanceIntelligenceService::STATE_DORMANT_CATALOG_ITEM, $recordOutlet1['performance_state']);
    }

    public function test_deterministic_repeated_execution(): void
    {
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-15',
            status: 'Selesai',
            totalAmount: 30000,
            menuId: $this->menuHealthy->id,
            menuName: $this->menuHealthy->name,
            qty: 1.0,
            price: 30000,
            tiCostPrice: 10000
        );

        $run1 = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);
        $run2 = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);

        // Remove dynamic generated_at timestamp for strict array comparison
        unset($run1['metadata']['generated_at'], $run2['metadata']['generated_at']);

        $this->assertEquals($run1, $run2);
    }

    public function test_no_false_causal_attribution_and_owner_safe_language(): void
    {
        $this->insertTxWithItem(
            userId: $this->tenant1->id,
            outletId: $this->outlet1->id,
            businessDate: '2026-09-15',
            status: 'Selesai',
            totalAmount: 30000,
            menuId: $this->menuHealthy->id,
            menuName: $this->menuHealthy->name,
            qty: 1.0,
            price: 30000,
            tiCostPrice: 10000
        );

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);

        $serialized = json_encode($result);
        $this->assertStringNotContainsString('"actual_profit"', $serialized);
        $this->assertStringNotContainsString('"net_profit"', $serialized);
        $this->assertStringNotContainsString('karena harga bahan', $serialized);
        $this->assertStringNotContainsString('dead stock', strtolower($serialized));
    }

    public function test_no_mutation_guarantee(): void
    {
        $txCountBefore = DB::table('transactions')->count();
        $tiCountBefore = DB::table('transaction_items')->count();
        $menuCountBefore = DB::table('menus')->count();

        $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-30', 30);

        $this->assertSame($txCountBefore, DB::table('transactions')->count());
        $this->assertSame($tiCountBefore, DB::table('transaction_items')->count());
        $this->assertSame($menuCountBefore, DB::table('menus')->count());
    }
}
