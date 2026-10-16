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
use App\Services\Financial\UnitOfMeasureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class UnitOfMeasureServiceTest extends TestCase
{
    use RefreshDatabase;

    protected UnitOfMeasureService $uomService;
    protected StockoutIntelligenceService $stockoutService;
    protected User $tenantA;
    protected User $tenantB;
    protected Outlet $outletA;
    protected Outlet $outletB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uomService = new UnitOfMeasureService();
        $demandService = app(MenuDemandForecastService::class);
        $this->stockoutService = new StockoutIntelligenceService($demandService, $this->uomService);

        $this->tenantA = User::factory()->create([
            'email' => 'uom_tenant_a@kaching.test',
        ]);
        $this->tenantB = User::factory()->create([
            'email' => 'uom_tenant_b@kaching.test',
        ]);

        $this->outletA = Outlet::create([
            'user_id' => $this->tenantA->id,
            'name'    => 'Outlet UOM A',
        ]);
        $this->outletB = Outlet::create([
            'user_id' => $this->tenantB->id,
            'name'    => 'Outlet UOM B',
        ]);
    }

    /** 1. Existing base unit — no conversion */
    public function test_01_existing_base_unit_requires_no_conversion(): void
    {
        $res = $this->uomService->convert(18.0, 'gr', 'gr');

        $this->assertTrue($res['success']);
        $this->assertSame(18.0, $res['converted_qty']);
        $this->assertSame(1.0, $res['factor']);
        $this->assertSame('SUCCESS', $res['status']);
        $this->assertNull($res['reason']);
    }

    /** 2. Gram vs gr normalization */
    public function test_02_gram_vs_gr_normalization(): void
    {
        $res1 = $this->uomService->convert(25.0, 'Gram', 'gr');
        $this->assertTrue($res1['success']);
        $this->assertSame(25.0, $res1['converted_qty']);
        $this->assertSame(1.0, $res1['factor']);
        $this->assertSame('g', $res1['canonical_from']);
        $this->assertSame('g', $res1['canonical_to']);

        $res2 = $this->uomService->convert(100.0, ' grams ', 'g');
        $this->assertTrue($res2['success']);
        $this->assertSame(100.0, $res2['converted_qty']);
    }

    /** 3. Pcs vs pcs normalization */
    public function test_03_pcs_vs_pcs_normalization(): void
    {
        $res = $this->uomService->convert(2.0, 'Pcs', 'pcs');

        $this->assertTrue($res['success']);
        $this->assertSame(2.0, $res['converted_qty']);
        $this->assertSame(1.0, $res['factor']);
        $this->assertSame('pcs', $res['canonical_from']);
        $this->assertSame('pcs', $res['canonical_to']);

        $resAlias = $this->uomService->convert(5.0, ' piece ', 'PIECES');
        $this->assertTrue($resAlias['success']);
        $this->assertSame(5.0, $resAlias['converted_qty']);
    }

    /** 4. kg → g conversion */
    public function test_04_kg_to_g_conversion(): void
    {
        $res = $this->uomService->convert(0.02, 'kg', 'g');

        $this->assertTrue($res['success']);
        $this->assertSame(20.0, $res['converted_qty']);
        $this->assertSame(1000.0, $res['factor']);
        $this->assertNull($res['reason']);
    }

    /** 5. g → kg where mathematically appropriate */
    public function test_05_g_to_kg_where_mathematically_appropriate(): void
    {
        $res = $this->uomService->convert(500.0, 'g', 'kg');

        $this->assertTrue($res['success']);
        $this->assertSame(0.5, $res['converted_qty']);
        $this->assertSame(0.001, $res['factor']);
    }

    /** 6. liter → ml conversion */
    public function test_06_liter_to_ml_conversion(): void
    {
        $res = $this->uomService->convert(0.25, 'liter', 'ml');

        $this->assertTrue($res['success']);
        $this->assertSame(250.0, $res['converted_qty']);
        $this->assertSame(1000.0, $res['factor']);

        $resL = $this->uomService->convert(1.5, 'L', 'ml');
        $this->assertTrue($resL['success']);
        $this->assertSame(1500.0, $resL['converted_qty']);
    }

    /** 7. ml → liter where mathematically appropriate */
    public function test_07_ml_to_liter_where_mathematically_appropriate(): void
    {
        $res = $this->uomService->convert(750.0, 'ml', 'liter');

        $this->assertTrue($res['success']);
        $this->assertSame(0.75, $res['converted_qty']);
        $this->assertSame(0.001, $res['factor']);
    }

    /** 8. item-specific package conversion */
    public function test_08_item_specific_package_conversion(): void
    {
        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Sirup Karamel',
            'type'          => 'raw_material',
            'base_uom'      => 'ml',
            'cost_price'    => 100,
            'reorder_point' => 500,
        ]);

        DB::table('item_uoms')->insert([
            'item_id'           => $item->id,
            'uom_name'          => 'Botol 750ml',
            'conversion_factor' => 750.0,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $res = $this->uomService->convert(2.0, 'Botol 750ml', 'ml', $item->id);

        $this->assertTrue($res['success']);
        $this->assertSame(1500.0, $res['converted_qty']);
        $this->assertSame(750.0, $res['factor']);
    }

    /** 9. package conversion scoped to correct item */
    public function test_09_package_conversion_scoped_to_correct_item(): void
    {
        $item1 = Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Item 1 Dengan Kemasan',
            'type'       => 'raw_material',
            'base_uom'   => 'ml',
            'cost_price' => 10,
        ]);

        $item2 = Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Item 2 Tanpa Kemasan',
            'type'       => 'raw_material',
            'base_uom'   => 'ml',
            'cost_price' => 10,
        ]);

        DB::table('item_uoms')->insert([
            'item_id'           => $item1->id,
            'uom_name'          => 'Botol 750ml',
            'conversion_factor' => 750.0,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // Attempting to use item1's packaging UOM on item2 must fail closed
        $res = $this->uomService->convert(1.0, 'Botol 750ml', 'ml', $item2->id);

        $this->assertFalse($res['success']);
        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $res['status']);
        $this->assertSame(UnitOfMeasureService::REASON_MISSING_ITEM_CONVERSION, $res['reason']);
    }

    /** 10. missing item conversion */
    public function test_10_missing_item_conversion(): void
    {
        $item = Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Bahan Baku Biasa',
            'type'       => 'raw_material',
            'base_uom'   => 'pcs',
            'cost_price' => 100,
        ]);

        $res = $this->uomService->convert(1.0, 'Kardus Besar', 'pcs', $item->id);

        $this->assertFalse($res['success']);
        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $res['status']);
        $this->assertSame(UnitOfMeasureService::REASON_MISSING_ITEM_CONVERSION, $res['reason']);
    }

    /** 11. unknown unit */
    public function test_11_unknown_unit_rejected(): void
    {
        $res = $this->uomService->convert(10.0, 'xyz_random_unit', 'g');

        $this->assertFalse($res['success']);
        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $res['status']);
        $this->assertSame(UnitOfMeasureService::REASON_UNIT_CONVERSION_UNAVAILABLE, $res['reason']);
    }

    /** 12. custom "lingkaran" */
    public function test_12_custom_lingkaran_rejected_without_explicit_conversion(): void
    {
        // lingkaran -> pcs without item_uoms mapping must fail closed
        $res = $this->uomService->convert(1.0, 'lingkaran', 'pcs');

        $this->assertFalse($res['success']);
        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $res['status']);
        $this->assertSame(UnitOfMeasureService::REASON_UNIT_CONVERSION_UNAVAILABLE, $res['reason']);
    }

    /** 13. g → ml rejected (mass to volume cross-dimension) */
    public function test_13_cross_dimension_g_to_ml_rejected(): void
    {
        $res = $this->uomService->convert(100.0, 'g', 'ml');

        $this->assertFalse($res['success']);
        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $res['status']);
        $this->assertSame(UnitOfMeasureService::REASON_UNIT_DIMENSION_MISMATCH, $res['reason']);
        $this->assertNull($res['converted_qty']);
    }

    /** 14. g → pcs rejected (mass to count cross-dimension) */
    public function test_14_cross_dimension_g_to_pcs_rejected(): void
    {
        $res = $this->uomService->convert(50.0, 'g', 'pcs');

        $this->assertFalse($res['success']);
        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $res['status']);
        $this->assertSame(UnitOfMeasureService::REASON_UNIT_DIMENSION_MISMATCH, $res['reason']);
    }

    /** 15. ml → pcs rejected (volume to count cross-dimension) */
    public function test_15_cross_dimension_ml_to_pcs_rejected(): void
    {
        $res = $this->uomService->convert(250.0, 'ml', 'pcs');

        $this->assertFalse($res['success']);
        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $res['status']);
        $this->assertSame(UnitOfMeasureService::REASON_UNIT_DIMENSION_MISMATCH, $res['reason']);
    }

    /** 16. invalid conversion_factor */
    public function test_16_invalid_conversion_factor_rejected(): void
    {
        $item = Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Item Faktor Rusak',
            'type'       => 'raw_material',
            'base_uom'   => 'pcs',
            'cost_price' => 10,
        ]);

        DB::table('item_uoms')->insert([
            'item_id'           => $item->id,
            'uom_name'          => 'Karton Kosong',
            'conversion_factor' => 0.0, // Invalid factor <= 0
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $res = $this->uomService->convert(1.0, 'Karton Kosong', 'pcs', $item->id);

        $this->assertFalse($res['success']);
        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $res['status']);
        $this->assertSame(UnitOfMeasureService::REASON_INVALID_CONVERSION_FACTOR, $res['reason']);
    }

    /** 17. zero stock */
    public function test_17_stockout_service_handles_zero_stock_with_uom(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Americano Dingin',
            'category'  => 'Minuman',
            'price'     => 18000,
            'stock'     => -1,
        ]);

        $item = Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Biji Kopi House Blend',
            'type'       => 'raw_material',
            'base_uom'   => 'gr',
            'cost_price' => 150,
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $item->id,
            'qty'     => 18.0,
        ]);

        // No stock movements -> stock is exactly 0.0
        $res = $this->stockoutService->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);

        $this->assertSame(StockoutIntelligenceService::STATUS_STOCKOUT, $res['status']);
        $this->assertSame('STOCK_ZERO', $res['ingredients'][0]['reason']);
        $this->assertSame(0.0, $res['ingredients'][0]['days_until_stockout']);
    }

    /** 18. zero demand */
    public function test_18_stockout_service_handles_zero_demand_with_uom(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Menu Musiman',
            'category'  => 'Minuman',
            'price'     => 30000,
            'stock'     => -1,
        ]);

        $item = Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Sirup Khusus',
            'type'       => 'raw_material',
            'base_uom'   => 'ml',
            'cost_price' => 200,
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $item->id,
            'qty'     => 20.0,
        ]);

        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 1000.0,
        ]);

        // Zero demand -> NORMAL with ZERO_DEMAND
        $res = $this->stockoutService->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);

        $this->assertSame(StockoutIntelligenceService::STATUS_NORMAL, $res['status']);
        $this->assertSame(StockoutIntelligenceService::REASON_ZERO_DEMAND, $res['ingredients'][0]['reason']);
        $this->assertNull($res['ingredients'][0]['days_until_stockout']);
    }

    /** 19. missing BOM */
    public function test_19_stockout_service_handles_missing_bom(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Menu Tanpa BOM',
            'category'  => 'Makanan',
            'price'     => 25000,
            'stock'     => -1, // untracked
        ]);

        $res = $this->stockoutService->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);

        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $res['status']);
        $this->assertSame(StockoutIntelligenceService::REASON_MISSING_BOM, $res['reason']);
    }

    /** 20. existing production-compatible BOM produces identical result */
    public function test_20_existing_production_compatible_bom_produces_identical_result(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Kopi Susu Signature',
            'category'  => 'Minuman',
            'price'     => 22000,
            'stock'     => -1,
        ]);

        $itemCoffee = Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Espresso Roast',
            'type'       => 'raw_material',
            'base_uom'   => 'gr',
            'cost_price' => 180,
        ]);

        $itemMilk = Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Fresh Milk',
            'type'       => 'raw_material',
            'base_uom'   => 'ml',
            'cost_price' => 20,
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $itemCoffee->id,
            'qty'     => 18.0,
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $itemMilk->id,
            'qty'     => 200.0,
        ]);

        StockMovement::forceCreate([
            'item_id' => $itemCoffee->id,
            'type'    => 'incoming',
            'qty'     => 1800.0,
        ]);

        StockMovement::forceCreate([
            'item_id' => $itemMilk->id,
            'type'    => 'incoming',
            'qty'     => 20000.0,
        ]);

        $res = $this->stockoutService->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id);

        $this->assertSame('BOM_RECIPE_TRACKED', $res['menu']['stock_mode']);
        $this->assertCount(2, $res['ingredients']);
        $this->assertSame(18.0, $res['ingredients'][0]['recipe_qty_per_portion']);
        $this->assertSame(18.0, $res['ingredients'][0]['normalized_recipe_qty']);
        $this->assertSame(200.0, $res['ingredients'][1]['recipe_qty_per_portion']);
        $this->assertSame(200.0, $res['ingredients'][1]['normalized_recipe_qty']);
    }

    /** 21. tenant isolation */
    public function test_21_tenant_isolation_enforced(): void
    {
        $itemA = Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Item Milik Tenant A',
            'type'       => 'raw_material',
            'base_uom'   => 'g',
            'cost_price' => 50,
        ]);

        $menuB = Menu::create([
            'user_id'   => $this->tenantB->id,
            'outlet_id' => $this->outletB->id,
            'name'      => 'Menu Tenant B',
            'category'  => 'Makanan',
            'price'     => 15000,
            'stock'     => -1,
        ]);

        // Tenant A cannot evaluate Tenant B menu
        $res = $this->stockoutService->evaluateMenuStockRisk($this->tenantA->id, $menuB->id, $this->outletA->id);

        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $res['status']);
        $this->assertSame('MENU_NOT_FOUND', $res['reason']);
    }

    /** 22. outlet isolation */
    public function test_22_outlet_isolation_enforced(): void
    {
        $item = Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Item Outlet A',
            'type'       => 'raw_material',
            'base_uom'   => 'ml',
            'cost_price' => 50,
        ]);

        DB::table('item_uoms')->insert([
            'item_id'           => $item->id,
            'uom_name'          => 'Galon 5L',
            'conversion_factor' => 5000.0,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // Outlet isolation check: query with outletB id against outletA's item
        $res = $this->uomService->convert(1.0, 'Galon 5L', 'ml', $item->id, $this->outletB->id);

        $this->assertFalse($res['success']);
        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $res['status']);
        $this->assertSame(UnitOfMeasureService::REASON_MISSING_ITEM_CONVERSION, $res['reason']);
    }

    /** 23. repeated execution deterministic */
    public function test_23_repeated_execution_deterministic(): void
    {
        $initial = $this->uomService->convert(0.045, 'kg', 'g');

        for ($i = 0; $i < 50; $i++) {
            $current = $this->uomService->convert(0.045, 'kg', 'g');
            $this->assertSame($initial, $current);
        }
    }

    /** 24. no database mutation */
    public function test_24_no_database_mutation(): void
    {
        $initialItemUomsCount = DB::table('item_uoms')->count();
        $initialItemsCount = DB::table('items')->count();
        $initialBomCount = DB::table('menu_ingredients')->count();
        $initialMovementsCount = DB::table('stock_movements')->count();

        // Run conversion operations
        $this->uomService->convert(10.0, 'kg', 'g');
        $this->uomService->convert(500.0, 'ml', 'liter');
        $this->uomService->convert(1.0, 'lingkaran', 'pcs');

        $this->assertSame($initialItemUomsCount, DB::table('item_uoms')->count());
        $this->assertSame($initialItemsCount, DB::table('items')->count());
        $this->assertSame($initialBomCount, DB::table('menu_ingredients')->count());
        $this->assertSame($initialMovementsCount, DB::table('stock_movements')->count());
    }
}
