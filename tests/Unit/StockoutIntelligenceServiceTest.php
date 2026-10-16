<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Menu;
use App\Models\Item;
use App\Models\MenuIngredient;
use App\Models\StockMovement;
use App\Services\Financial\MenuDemandForecastService;
use App\Services\Financial\StockoutIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class StockoutIntelligenceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenantA;
    protected User $tenantB;
    protected Outlet $outletA;
    protected StockoutIntelligenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = User::factory()->create([
            'email' => 'tenant_stock_a@kaching.test',
        ]);

        $this->tenantB = User::factory()->create([
            'email' => 'tenant_stock_b@kaching.test',
        ]);

        $this->outletA = Outlet::create([
            'user_id' => $this->tenantA->id,
            'name'    => 'Outlet Stock A',
        ]);

        $demandService = app(MenuDemandForecastService::class);
        $this->service = new StockoutIntelligenceService($demandService);
    }

    public function test_menu_with_valid_bom_and_sufficient_stock_returns_normal(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Kopi Susu Gula Aren',
            'category'  => 'Minuman',
            'price'     => 20000,
            'stock'     => -1, // Untracked menu, tracked via BOM
        ]);

        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Biji Kopi Arabika',
            'type'          => 'raw_material',
            'base_uom'      => 'gram',
            'cost_price'    => 200,
            'reorder_point' => 50,
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $item->id,
            'qty'     => 15.0, // 15 grams per cup
        ]);

        // Incoming stock 10,000 grams
        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 10000.0,
        ]);

        $res = $this->service->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);

        $this->assertSame(StockoutIntelligenceService::STATUS_NORMAL, $res['status']);
        $this->assertSame('BOM_RECIPE_TRACKED', $res['menu']['stock_mode']);
        $this->assertCount(1, $res['ingredients']);
        $this->assertSame(10000.0, $res['ingredients'][0]['current_stock_on_hand']);
    }

    public function test_menu_with_bom_depletion_within_lead_time_returns_stockout_risk(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Roti Bakar Keju',
            'category'  => 'Makanan',
            'price'     => 15000,
            'stock'     => -1,
        ]);

        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Keju Cheddar Block',
            'type'          => 'raw_material',
            'base_uom'      => 'gram',
            'cost_price'    => 100,
            'reorder_point' => 200,
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $item->id,
            'qty'     => 20.0,
        ]);

        // Seed supplier with 3 days lead time
        $supplierId = DB::table('suppliers')->insertGetId([
            'name'       => 'Distributor Keju',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('item_supplier')->insert([
            'item_id'        => $item->id,
            'supplier_id'    => $supplierId,
            'supplier_price' => 50000,
            'lead_time_days' => 3,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Stock movement: only 40 grams left (enough for only 2 portions)
        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 40.0,
        ]);

        // Mock daily transactions to generate demand: 10 portions/day
        $this->seedHistoricalSales($menu, 10, 14);

        $res = $this->service->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);

        $this->assertSame(StockoutIntelligenceService::STATUS_STOCKOUT_RISK, $res['status']);
        $this->assertSame('Keju Cheddar Block', $res['bottleneck_ingredient']);
        $this->assertLessThanOrEqual(3, $res['min_days_until_out']);
    }

    public function test_menu_with_bom_zero_stock_returns_stockout(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Teh Tarik',
            'category'  => 'Minuman',
            'price'     => 12000,
            'stock'     => -1,
        ]);

        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Susu Evaporasi',
            'type'          => 'raw_material',
            'base_uom'      => 'kaleng',
            'cost_price'    => 15000,
            'reorder_point' => 5,
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $item->id,
            'qty'     => 0.2,
        ]);

        // Zero movements inserted -> on hand = 0.0
        $res = $this->service->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);

        $this->assertSame(StockoutIntelligenceService::STATUS_STOCKOUT, $res['status']);
        $this->assertSame(0.0, $res['min_days_until_out']);
        $this->assertSame('Susu Evaporasi', $res['bottleneck_ingredient']);
    }

    public function test_menu_with_anomalous_negative_stock_returns_stockout_with_anomalous_reason(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Espresso Single',
            'category'  => 'Minuman',
            'price'     => 10000,
            'stock'     => -1,
        ]);

        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Biji Robusta',
            'type'          => 'raw_material',
            'base_uom'      => 'gram',
            'cost_price'    => 100,
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $item->id,
            'qty'     => 10.0,
        ]);

        // Negative stock caused by manual outgoing error
        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'outgoing',
            'qty'     => 50.0,
        ]);

        $res = $this->service->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);

        $this->assertSame(StockoutIntelligenceService::STATUS_STOCKOUT, $res['status']);
        $this->assertSame(StockoutIntelligenceService::REASON_ANOMALOUS_STOCK, $res['ingredients'][0]['reason']);
    }

    public function test_menu_without_bom_and_untracked_stock_fails_closed_with_unsupported(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Ayam Geprek Spesial',
            'category'  => 'Makanan',
            'price'     => 25000,
            'stock'     => -1, // Untracked
        ]);

        $res = $this->service->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);

        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $res['status']);
        $this->assertSame(StockoutIntelligenceService::REASON_MISSING_BOM, $res['reason']);
        $this->assertSame(0, $res['ingredients_count']);
    }

    public function test_menu_without_bom_but_tracked_finished_good_evaluates_direct_stock(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Coca Cola Kaleng 330ml',
            'category'  => 'Minuman',
            'price'     => 8000,
            'stock'     => 5, // 5 cans on hand
        ]);

        $this->seedHistoricalSales($menu, 5, 14); // 5 cans/day demand

        $res = $this->service->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);

        // 5 stock / 2.5 daily (median of [0, 0, 5, 5]) = 2.0 days -> STOCKOUT_RISK (depletion imminent)
        $this->assertSame(StockoutIntelligenceService::STATUS_STOCKOUT_RISK, $res['status']);
        $this->assertSame('DIRECT_FINISHED_GOOD', $res['menu']['stock_mode']);
        $this->assertEquals(2.0, $res['days_until_stockout']);
    }

    public function test_tenant_isolation_prevents_unauthorized_access(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Menu Rahasia A',
            'category'  => 'Makanan',
            'price'     => 50000,
            'stock'     => 10,
        ]);

        $res = $this->service->evaluateMenuStockRisk($this->tenantB->id, $menu->id, $this->outletA->id);

        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $res['status']);
        $this->assertSame('MENU_NOT_FOUND', $res['reason']);
    }

    public function test_evaluate_all_menus_stock_risk_summary(): void
    {
        // Menu 1: Tracked finished good, healthy
        Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Air Mineral 600ml',
            'category'  => 'Minuman',
            'price'     => 4000,
            'stock'     => 500,
        ]);

        // Menu 2: Untracked no BOM
        Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Nasi Goreng Kampung',
            'category'  => 'Makanan',
            'price'     => 22000,
            'stock'     => -1,
        ]);

        $batch = $this->service->evaluateAllMenusStockRisk($this->tenantA->id, $this->outletA->id);

        $this->assertSame('SUCCESS', $batch['status']);
        $this->assertSame(2, $batch['summary']['total_menus']);
        $this->assertSame(1, $batch['summary']['normal']);
        $this->assertSame(1, $batch['summary']['unsupported']);
    }

    public function test_safe_table_guard_missing_bom_tables_returns_unsupported_without_fatal_exception(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Ayam Bakar Madu Safe',
            'category'  => 'Makanan',
            'price'     => 35000,
            'stock'     => -1, // Untracked, expects BOM
        ]);

        $this->seedHistoricalSales($menu, 10, 14);

        // Simulate absence of BOM tables (e.g. kaching_real_data snapshot)
        $this->service->setTableAvailability('menu_ingredients', false);

        $res = $this->service->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);

        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $res['status']);
        $this->assertSame(StockoutIntelligenceService::REASON_INVENTORY_SCHEMA_UNAVAILABLE, $res['reason']);
        $this->assertStringContainsString('menu_ingredients', $res['message']);
        $this->assertSame(0, $res['ingredients_count']);
    }

    public function test_safe_table_guard_missing_bom_tables_preserves_direct_finished_goods_mode(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Teh Botol Kotak Direct Safe',
            'category'  => 'Minuman',
            'price'     => 5000,
            'stock'     => 200, // Direct finished-good stock
        ]);

        $this->seedHistoricalSales($menu, 10, 14);

        // Simulate absence of BOM tables
        $this->service->setTableAvailability('menu_ingredients', false);

        $res = $this->service->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);

        // Direct finished-goods evaluation must succeed without requiring BOM tables
        $this->assertSame(StockoutIntelligenceService::STATUS_NORMAL, $res['status']);
        $this->assertSame('STOCK_HEALTHY', $res['reason']);
        $this->assertSame(200.0, $res['menu']['current_stock']);
    }

    public function test_safe_table_guard_missing_stock_movements_table_returns_unsupported(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Espresso Double Safe Stock',
            'category'  => 'Minuman',
            'price'     => 18000,
            'stock'     => -1,
        ]);

        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Biji Kopi Arabika Safe Stock',
            'base_uom'      => 'g',
            'reorder_point' => 500.0,
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $item->id,
            'qty'     => 18.0,
        ]);

        $this->seedHistoricalSales($menu, 20, 14);

        // Simulate missing stock_movements table
        $this->service->setTableAvailability('stock_movements', false);

        $res = $this->service->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);

        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $res['status']);
        $this->assertSame(StockoutIntelligenceService::REASON_INVENTORY_SCHEMA_UNAVAILABLE, $res['reason']);
        $this->assertStringContainsString('stock_movements', $res['message']);
    }

    public function test_safe_table_guard_missing_item_supplier_defaults_lead_time_safely(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Kopi Hitam Safe Supplier',
            'category'  => 'Minuman',
            'price'     => 12000,
            'stock'     => -1,
        ]);

        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Biji Kopi Robusta No Supplier',
            'base_uom'      => 'g',
            'reorder_point' => 100.0,
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $item->id,
            'qty'     => 15.0,
        ]);

        StockMovement::forceCreate([
            'item_id'   => $item->id,
            'outlet_id' => $this->outletA->id,
            'type'      => 'incoming',
            'qty'       => 5000.0,
        ]);

        $this->seedHistoricalSales($menu, 10, 14);

        // Simulate missing item_supplier table
        $this->service->setTableAvailability('item_supplier', false);

        $res = $this->service->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);

        $this->assertSame(StockoutIntelligenceService::STATUS_NORMAL, $res['status']);
        $this->assertSame(0, $res['ingredients'][0]['lead_time_days']);
    }

    public function test_outlet_isolation_strictly_separates_same_item_stock_between_outlets(): void
    {
        $outletB = Outlet::create([
            'user_id' => $this->tenantA->id,
            'name'    => 'Outlet Stock B Isolation',
        ]);

        $menuA = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Menu Outlet A',
            'category'  => 'Minuman',
            'price'     => 15000,
            'stock'     => -1,
        ]);

        $menuB = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $outletB->id,
            'name'      => 'Menu Outlet B',
            'category'  => 'Minuman',
            'price'     => 15000,
            'stock'     => -1,
        ]);

        $itemShared = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Bahan Baku Bersama',
            'base_uom'      => 'g',
            'reorder_point' => 50.0,
        ]);

        MenuIngredient::create([
            'menu_id' => $menuA->id,
            'item_id' => $itemShared->id,
            'qty'     => 10.0,
        ]);

        MenuIngredient::create([
            'menu_id' => $menuB->id,
            'item_id' => $itemShared->id,
            'qty'     => 10.0,
        ]);

        // Outlet A: stock = 100
        StockMovement::forceCreate([
            'item_id'   => $itemShared->id,
            'outlet_id' => $this->outletA->id,
            'type'      => 'incoming',
            'qty'       => 100.0,
        ]);

        // Outlet B: stock = 900
        StockMovement::forceCreate([
            'item_id'   => $itemShared->id,
            'outlet_id' => $outletB->id,
            'type'      => 'incoming',
            'qty'       => 900.0,
        ]);

        $this->seedHistoricalSales($menuA, 5, 14);
        $this->seedHistoricalSalesForOutlet($menuB, $outletB, 5, 14);

        // Evaluation for Outlet A MUST see 100, not 1000
        $resA = $this->service->evaluateMenuStockRisk($this->tenantA->id, $menuA->id, $this->outletA->id);
        $this->assertSame(100.0, $resA['ingredients'][0]['current_stock_on_hand']);

        // Evaluation for Outlet B MUST see 900, not 1000
        $resB = $this->service->evaluateMenuStockRisk($this->tenantA->id, $menuB->id, $outletB->id);
        $this->assertSame(900.0, $resB['ingredients'][0]['current_stock_on_hand']);
    }

    public function test_current_stock_mode_includes_all_movements_when_cutoff_absent(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Latte All Movements',
            'category'  => 'Minuman',
            'price'     => 25000,
            'stock'     => -1,
        ]);

        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Biji Kopi Current',
            'base_uom'      => 'g',
            'reorder_point' => 50.0,
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $item->id,
            'qty'     => 20.0,
        ]);

        // Movement 1
        StockMovement::forceCreate([
            'item_id'    => $item->id,
            'outlet_id'  => $this->outletA->id,
            'type'       => 'incoming',
            'qty'        => 100.0,
            'created_at' => '2026-08-01 10:00:00',
        ]);

        // Movement 2 (later)
        StockMovement::forceCreate([
            'item_id'    => $item->id,
            'outlet_id'  => $this->outletA->id,
            'type'       => 'incoming',
            'qty'        => 50.0,
            'created_at' => '2026-08-10 10:00:00',
        ]);

        $this->seedHistoricalSales($menu, 5, 14);

        // When anchorDate/cutoff is absent: all current movements are included (100 + 50 = 150)
        $res = $this->service->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);
        $this->assertSame(150.0, $res['ingredients'][0]['current_stock_on_hand']);
    }

    public function test_historical_point_in_time_excludes_movements_after_cutoff(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Latte PIT Cutoff',
            'category'  => 'Minuman',
            'price'     => 25000,
            'stock'     => -1,
        ]);

        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Biji Kopi PIT',
            'base_uom'      => 'g',
            'reorder_point' => 50.0,
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $item->id,
            'qty'     => 20.0,
        ]);

        // Movement 1: on 2026-08-01 (UTC 10:00:00 is within 2026-08-01 WIB)
        StockMovement::forceCreate([
            'item_id'    => $item->id,
            'outlet_id'  => $this->outletA->id,
            'type'       => 'incoming',
            'qty'        => 100.0,
            'created_at' => '2026-08-01 10:00:00',
        ]);

        // Movement 2: on 2026-08-05 (after 2026-08-01)
        StockMovement::forceCreate([
            'item_id'    => $item->id,
            'outlet_id'  => $this->outletA->id,
            'type'       => 'incoming',
            'qty'        => 50.0,
            'created_at' => '2026-08-05 10:00:00',
        ]);

        $this->seedHistoricalSales($menu, 5, 14);

        // Historical evaluation as of 2026-08-01 MUST see only 100.0, excluding Movement 2
        $res = $this->service->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id, '2026-08-01');
        $this->assertSame(100.0, $res['ingredients'][0]['current_stock_on_hand']);
    }

    public function test_historical_boundary_includes_movement_at_exact_cutoff(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Cappuccino Exact Cutoff',
            'category'  => 'Minuman',
            'price'     => 24000,
            'stock'     => -1,
        ]);

        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Biji Kopi Boundary',
            'base_uom'      => 'g',
            'reorder_point' => 50.0,
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $item->id,
            'qty'     => 20.0,
        ]);

        // Exactly at cutoff
        StockMovement::forceCreate([
            'item_id'    => $item->id,
            'outlet_id'  => $this->outletA->id,
            'type'       => 'incoming',
            'qty'        => 100.0,
            'created_at' => '2026-08-01 12:00:00',
        ]);

        // 1 second after cutoff
        StockMovement::forceCreate([
            'item_id'    => $item->id,
            'outlet_id'  => $this->outletA->id,
            'type'       => 'incoming',
            'qty'        => 50.0,
            'created_at' => '2026-08-01 12:00:01',
        ]);

        $this->seedHistoricalSales($menu, 5, 14);

        $res = $this->service->evaluateMenuStockRisk(
            $this->tenantA->id,
            $menu->id,
            $this->outletA->id,
            '2026-08-01',
            ['cutoff_utc' => '2026-08-01 12:00:00']
        );

        $this->assertSame(100.0, $res['ingredients'][0]['current_stock_on_hand']);
    }

    public function test_stock_ledger_incoming_and_outgoing_arithmetic(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Americano Arithmetic',
            'category'  => 'Minuman',
            'price'     => 18000,
            'stock'     => -1,
        ]);

        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Biji Kopi Arithmetic',
            'base_uom'      => 'g',
            'reorder_point' => 50.0,
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $item->id,
            'qty'     => 15.0,
        ]);

        // Incoming 100
        StockMovement::forceCreate([
            'item_id'   => $item->id,
            'outlet_id' => $this->outletA->id,
            'type'      => 'incoming',
            'qty'       => 100.0,
        ]);

        // Outgoing 30
        StockMovement::forceCreate([
            'item_id'   => $item->id,
            'outlet_id' => $this->outletA->id,
            'type'      => 'outgoing',
            'qty'       => 30.0,
        ]);

        $this->seedHistoricalSales($menu, 5, 14);

        $res = $this->service->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);

        // 100 - 30 = 70
        $this->assertSame(70.0, $res['ingredients'][0]['current_stock_on_hand']);
    }

    public function test_zero_demand_handles_gracefully_without_division_by_zero(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Menu Zero Demand',
            'category'  => 'Makanan',
            'price'     => 30000,
            'stock'     => -1,
        ]);

        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Bahan Baku Zero Demand',
            'base_uom'      => 'g',
            'reorder_point' => 50.0,
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $item->id,
            'qty'     => 25.0,
        ]);

        StockMovement::forceCreate([
            'item_id'   => $item->id,
            'outlet_id' => $this->outletA->id,
            'type'      => 'incoming',
            'qty'       => 500.0,
        ]);

        // Zero sales seeded -> zero demand
        $res = $this->service->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);

        $this->assertSame(StockoutIntelligenceService::STATUS_NORMAL, $res['status']);
        $this->assertSame(StockoutIntelligenceService::REASON_ZERO_DEMAND, $res['ingredients'][0]['reason']);
        $this->assertNull($res['ingredients'][0]['days_until_stockout']);
    }

    public function test_bom_conversion_same_dimension_converts_and_cross_dimension_fails_closed(): void
    {
        // 1. Same dimension: recipe in g, inventory base_uom in kg (1000g = 1kg)
        $menuA = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Kopi Susu Literan',
            'category'  => 'Minuman',
            'price'     => 45000,
            'stock'     => -1,
        ]);

        $itemA = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Biji Kopi Kg',
            'base_uom'      => 'kg',
            'reorder_point' => 1.0,
        ]);

        MenuIngredient::create([
            'menu_id' => $menuA->id,
            'item_id' => $itemA->id,
            'qty'     => 200.0, // 200 grams
        ]);

        StockMovement::forceCreate([
            'item_id'   => $itemA->id,
            'outlet_id' => $this->outletA->id,
            'type'      => 'incoming',
            'qty'       => 10.0, // 10 kg
        ]);

        $this->seedHistoricalSales($menuA, 5, 14);

        $resA = $this->service->evaluateMenuStockRisk(
            $this->tenantA->id,
            $menuA->id,
            $this->outletA->id,
            null,
            ['recipe_uom' => [$itemA->id => 'g']]
        );

        $this->assertSame(StockoutIntelligenceService::STATUS_NORMAL, $resA['status']);
        // 200g normalized to 0.2kg
        $this->assertSame(0.2, $resA['ingredients'][0]['normalized_recipe_qty']);

        // 2. Cross dimension: recipe in g, inventory base_uom in ml without density -> fails closed
        $menuB = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Minuman Cross Dimension',
            'category'  => 'Minuman',
            'price'     => 20000,
            'stock'     => -1,
        ]);

        $itemB = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Sirup Volume Base',
            'base_uom'      => 'ml',
            'reorder_point' => 100.0,
        ]);

        MenuIngredient::create([
            'menu_id' => $menuB->id,
            'item_id' => $itemB->id,
            'qty'     => 50.0, // 50 grams
        ]);

        StockMovement::forceCreate([
            'item_id'   => $itemB->id,
            'outlet_id' => $this->outletA->id,
            'type'      => 'incoming',
            'qty'       => 5000.0,
        ]);

        $this->seedHistoricalSales($menuB, 5, 14);

        $resB = $this->service->evaluateMenuStockRisk(
            $this->tenantA->id,
            $menuB->id,
            $this->outletA->id,
            null,
            ['recipe_uom' => [$itemB->id => 'g']]
        );

        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $resB['status']);
        $this->assertSame(StockoutIntelligenceService::REASON_UNIT_DIMENSION_MISMATCH, $resB['ingredients'][0]['reason']);
        $this->assertNull($resB['ingredients'][0]['days_until_stockout']);
    }

    protected function seedHistoricalSalesForOutlet(Menu $menu, Outlet $outlet, int $dailyQty, int $days): void
    {
        for ($i = 1; $i <= $days; $i++) {
            $date = now()->subDays($i)->format('Y-m-d 12:00:00');

            $txId = DB::table('transactions')->insertGetId([
                'user_id'           => $menu->user_id,
                'outlet_id'         => $outlet->id,
                'app_uuid'          => (string) \Illuminate\Support\Str::uuid(),
                'total_amount'      => $dailyQty * $menu->price,
                'payment_method'    => 'cash',
                'status'            => 'Selesai',
                'created_at_device' => $date,
                'created_at'        => $date,
                'updated_at'        => $date,
            ]);

            DB::table('transaction_items')->insert([
                'transaction_id' => $txId,
                'menu_id'        => $menu->id,
                'menu_name'      => $menu->name,
                'qty'            => $dailyQty,
                'price'          => $menu->price,
                'created_at'     => $date,
                'updated_at'     => $date,
            ]);
        }
    }

    protected function seedHistoricalSales(Menu $menu, int $dailyQty, int $days): void
    {
        for ($i = 1; $i <= $days; $i++) {
            $date = now()->subDays($i)->format('Y-m-d 12:00:00');

            $txId = DB::table('transactions')->insertGetId([
                'user_id'           => $this->tenantA->id,
                'outlet_id'         => $this->outletA->id,
                'app_uuid'          => (string) \Illuminate\Support\Str::uuid(),
                'total_amount'      => $dailyQty * $menu->price,
                'payment_method'    => 'cash',
                'status'            => 'Selesai',
                'created_at_device' => $date,
                'created_at'        => $date,
                'updated_at'        => $date,
            ]);

            DB::table('transaction_items')->insert([
                'transaction_id' => $txId,
                'menu_id'        => $menu->id,
                'menu_name'      => $menu->name,
                'qty'            => $dailyQty,
                'price'          => $menu->price,
                'created_at'     => $date,
                'updated_at'     => $date,
            ]);
        }
    }
}
