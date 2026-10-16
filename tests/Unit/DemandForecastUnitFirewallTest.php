<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\Menu;
use App\Models\MenuIngredient;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Financial\MenuDemandForecastService;
use App\Services\Financial\StockoutIntelligenceService;
use App\Services\Financial\UnitOfMeasureService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemandForecastUnitFirewallTest extends TestCase
{
    use RefreshDatabase;

    protected UnitOfMeasureService $uomService;
    protected MenuDemandForecastService $demandService;
    protected StockoutIntelligenceService $stockoutService;
    protected User $tenantA;
    protected User $tenantB;
    protected Outlet $outletA;
    protected Outlet $outletB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uomService = new UnitOfMeasureService();
        $this->demandService = new MenuDemandForecastService($this->uomService);
        $this->stockoutService = new StockoutIntelligenceService($this->demandService, $this->uomService);

        $this->tenantA = User::factory()->create([
            'name'  => 'Tenant A',
            'email' => 'tenant.a@kaching.id',
        ]);

        $this->tenantB = User::factory()->create([
            'name'  => 'Tenant B',
            'email' => 'tenant.b@kaching.id',
        ]);

        $this->outletA = Outlet::create([
            'user_id' => $this->tenantA->id,
            'name'    => 'Outlet A',
        ]);

        $this->outletB = Outlet::create([
            'user_id' => $this->tenantB->id,
            'name'    => 'Outlet B',
        ]);
    }

    /**
     * Helper to create a completed transaction on a given date.
     */
    protected function createTx(User $user, Outlet $outlet, Menu $menu, float $qty, string $dateStr, float $price = 10000.0): void
    {
        $utcCreated = Carbon::parse($dateStr . ' 12:00:00', 'Asia/Jakarta')->setTimezone('UTC')->toDateTimeString();

        $txId = DB::table('transactions')->insertGetId([
            'app_uuid'                => (string) \Illuminate\Support\Str::uuid(),
            'user_id'                 => $user->id,
            'outlet_id'               => $outlet->id,
            'official_invoice_number' => 'INV-' . uniqid(),
            'total_amount'            => $qty * $price,
            'pay_amount'              => $qty * $price,
            'payment_method'          => 'Tunai',
            'status'                  => 'COMPLETED',
            'created_at_device'       => $utcCreated,
            'created_at'              => $utcCreated,
            'updated_at'              => $utcCreated,
        ]);

        DB::table('transaction_items')->insert([
            'transaction_id' => $txId,
            'menu_id'        => $menu->id,
            'menu_name'      => $menu->name,
            'qty'            => $qty,
            'price'          => $price,
            'status'         => 'COMPLETED',
            'created_at'     => $utcCreated,
            'updated_at'     => $utcCreated,
        ]);
    }

    /**
     * Requirement 1: Porsi -> SUPPORTED
     */
    public function test_1_porsi_returns_supported(): void
    {
        $res = $this->uomService->resolveForecastUnit(1, 'Porsi');

        $this->assertSame(UnitOfMeasureService::STATUS_SUPPORTED, $res['status']);
        $this->assertSame('porsi', $res['canonical_unit']);
        $this->assertSame(UnitOfMeasureService::DIMENSION_PORTION, $res['dimension']);
        $this->assertTrue($res['is_discrete']);
        $this->assertNull($res['failure_reason']);
        $this->assertEquals(1.0, $res['conversion_factor']);
    }

    /**
     * Requirement 2: porsi alias -> SUPPORTED
     */
    public function test_2_porsi_aliases_return_supported(): void
    {
        $aliases = ['porsi', 'portion', 'portions', 'serving', 'servings', 'cup', 'porsii'];

        foreach ($aliases as $alias) {
            $res = $this->uomService->resolveForecastUnit(1, $alias);
            $this->assertSame(UnitOfMeasureService::STATUS_SUPPORTED, $res['status'], "Failed on alias: {$alias}");
            $this->assertSame('porsi', $res['canonical_unit'], "Canonical mismatch for: {$alias}");
            $this->assertSame(UnitOfMeasureService::DIMENSION_PORTION, $res['dimension']);
            $this->assertTrue($res['is_discrete']);
        }
    }

    /**
     * Requirement 3: Pcs -> SUPPORTED
     */
    public function test_3_pcs_returns_supported(): void
    {
        $res = $this->uomService->resolveForecastUnit(1, 'Pcs');

        $this->assertSame(UnitOfMeasureService::STATUS_SUPPORTED, $res['status']);
        $this->assertSame('pcs', $res['canonical_unit']);
        $this->assertSame(UnitOfMeasureService::DIMENSION_COUNT, $res['dimension']);
        $this->assertTrue($res['is_discrete']);
        $this->assertNull($res['failure_reason']);
    }

    /**
     * Requirement 4: pc alias -> SUPPORTED
     */
    public function test_4_pc_aliases_return_supported(): void
    {
        $aliases = ['pc', 'pcs', 'piece', 'pieces', 'biji', 'butir', 'unit', 'lembar'];

        foreach ($aliases as $alias) {
            $res = $this->uomService->resolveForecastUnit(1, $alias);
            $this->assertSame(UnitOfMeasureService::STATUS_SUPPORTED, $res['status'], "Failed on alias: {$alias}");
            $this->assertSame(UnitOfMeasureService::DIMENSION_COUNT, $res['dimension']);
            $this->assertTrue($res['is_discrete']);
        }
    }

    /**
     * Requirement 5: Gram -> rejected from discrete engine
     */
    public function test_5_gram_rejected_from_discrete_engine(): void
    {
        $res = $this->uomService->resolveForecastUnit(1, 'Gram');
        $this->assertSame(UnitOfMeasureService::STATUS_UNIT_DIMENSION_MISMATCH, $res['status']);
        $this->assertSame(UnitOfMeasureService::DIMENSION_MASS, $res['dimension']);
        $this->assertFalse($res['is_discrete']);
        $this->assertSame(UnitOfMeasureService::REASON_UNIT_DIMENSION_MISMATCH, $res['failure_reason']);

        // Check forecastMenu integration
        $menu = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Alpukat Mentega',
            'category'   => 'Buah',
            'unit'       => 'Gram',
            'price'      => 35.0,
            'cost_price' => 20.0,
        ]);

        $forecast = $this->demandService->forecastMenu($this->tenantA->id, $menu->id, $this->outletA->id, '2026-06-01');

        $this->assertSame(UnitOfMeasureService::STATUS_UNIT_DIMENSION_MISMATCH, $forecast['status']);
        $this->assertNull($forecast['forecast']['forecast_qty']);
        $this->assertFalse($forecast['forecast']['is_discrete']);
        $this->assertSame('UNSUPPORTED_DATA', $forecast['history_classification']['tier']);
        $this->assertNull($forecast['financial_impact']['potential_forecast_revenue']);
    }

    /**
     * Requirement 6: kg -> rejected from discrete engine
     */
    public function test_6_kg_rejected_from_discrete_engine(): void
    {
        $res = $this->uomService->resolveForecastUnit(1, 'kg');
        $this->assertSame(UnitOfMeasureService::STATUS_UNIT_DIMENSION_MISMATCH, $res['status']);
        $this->assertSame(UnitOfMeasureService::DIMENSION_MASS, $res['dimension']);
        $this->assertFalse($res['is_discrete']);

        $menu = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Daging Sapi Segar',
            'category'   => 'Bahan Baku',
            'unit'       => 'kg',
            'price'      => 120000.0,
            'cost_price' => 95000.0,
        ]);

        $forecast = $this->demandService->forecastMenu($this->tenantA->id, $menu->id, $this->outletA->id, '2026-06-01');
        $this->assertSame(UnitOfMeasureService::STATUS_UNIT_DIMENSION_MISMATCH, $forecast['status']);
        $this->assertNull($forecast['forecast']['forecast_qty']);
    }

    /**
     * Requirement 7: ml -> rejected from discrete engine
     */
    public function test_7_ml_rejected_from_discrete_engine(): void
    {
        $res = $this->uomService->resolveForecastUnit(1, 'ml');
        $this->assertSame(UnitOfMeasureService::STATUS_UNIT_DIMENSION_MISMATCH, $res['status']);
        $this->assertSame(UnitOfMeasureService::DIMENSION_VOLUME, $res['dimension']);
        $this->assertFalse($res['is_discrete']);

        $menu = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Susu Segar Literan',
            'category'   => 'Minuman',
            'unit'       => 'ml',
            'price'      => 50.0,
        ]);

        $forecast = $this->demandService->forecastMenu($this->tenantA->id, $menu->id, $this->outletA->id, '2026-06-01');
        $this->assertSame(UnitOfMeasureService::STATUS_UNIT_DIMENSION_MISMATCH, $forecast['status']);
        $this->assertNull($forecast['forecast']['forecast_qty']);
    }

    /**
     * Requirement 8: l -> rejected from discrete engine
     */
    public function test_8_l_rejected_from_discrete_engine(): void
    {
        $res = $this->uomService->resolveForecastUnit(1, 'l');
        $this->assertSame(UnitOfMeasureService::STATUS_UNIT_DIMENSION_MISMATCH, $res['status']);
        $this->assertSame(UnitOfMeasureService::DIMENSION_VOLUME, $res['dimension']);
        $this->assertFalse($res['is_discrete']);

        $menu = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Minyak Goreng 2L',
            'category'   => 'Sembako',
            'unit'       => 'liter',
            'price'      => 36000.0,
        ]);

        $forecast = $this->demandService->forecastMenu($this->tenantA->id, $menu->id, $this->outletA->id, '2026-06-01');
        $this->assertSame(UnitOfMeasureService::STATUS_UNIT_DIMENSION_MISMATCH, $forecast['status']);
    }

    /**
     * Requirement 9: Pack without item_uoms -> rejected
     */
    public function test_9_pack_without_item_uoms_rejected(): void
    {
        $res = $this->uomService->resolveForecastUnit(1, 'Pack');

        $this->assertSame(UnitOfMeasureService::STATUS_UNSUPPORTED_DATA, $res['status']);
        $this->assertSame(UnitOfMeasureService::REASON_UNIT_CONVERSION_UNAVAILABLE, $res['failure_reason']);
        $this->assertFalse($res['is_discrete']);

        $menu = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Paket Berbagi Berkah',
            'category'   => 'Paket',
            'unit'       => 'Pack',
            'price'      => 75000.0,
        ]);

        $forecast = $this->demandService->forecastMenu($this->tenantA->id, $menu->id, $this->outletA->id, '2026-06-01');
        $this->assertSame(UnitOfMeasureService::STATUS_UNSUPPORTED_DATA, $forecast['status']);
        $this->assertNull($forecast['forecast']['forecast_qty']);
    }

    /**
     * Requirement 10: Box without item_uoms -> rejected
     */
    public function test_10_box_without_item_uoms_rejected(): void
    {
        $res = $this->uomService->resolveForecastUnit(1, 'Box');

        $this->assertSame(UnitOfMeasureService::STATUS_UNSUPPORTED_DATA, $res['status']);
        $this->assertSame(UnitOfMeasureService::REASON_UNIT_CONVERSION_UNAVAILABLE, $res['failure_reason']);
        $this->assertFalse($res['is_discrete']);
    }

    /**
     * Requirement 11: Box with explicit item_uoms mapping -> accepted only according to mapping
     */
    public function test_11_box_with_explicit_item_uoms_mapping_accepted_only_according_to_mapping(): void
    {
        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Donat Mini Item',
            'type'          => 'raw_material',
            'base_uom'      => 'pcs',
            'cost_price'    => 2000.0,
            'reorder_point' => 10,
        ]);

        DB::table('item_uoms')->insert([
            'item_id'           => $item->id,
            'uom_name'          => 'Box',
            'conversion_factor' => 12.0,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $menu = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Donat 1 Box Isi 12',
            'category'   => 'Makanan',
            'unit'       => 'Box',
            'price'      => 50000.0,
        ]);

        // Link menu to item via menu_ingredients
        DB::table('menu_ingredients')->insert([
            'menu_id'    => $menu->id,
            'item_id'    => $item->id,
            'qty'        => 1.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->uomService->resolveForecastUnit($menu->id, 'Box', 50000.0, $this->outletA->id);

        $this->assertSame(UnitOfMeasureService::STATUS_SUPPORTED, $res['status']);
        $this->assertSame('pcs', $res['canonical_unit']);
        $this->assertSame(UnitOfMeasureService::DIMENSION_COUNT, $res['dimension']);
        $this->assertTrue($res['is_discrete']);
        $this->assertEquals(12.0, $res['conversion_factor']);
        $this->assertEquals(round(1.0 / 12.0, 6), $res['price_multiplier']);
    }

    /**
     * Requirement 12: lingkaran without mapping -> rejected
     */
    public function test_12_lingkaran_without_mapping_rejected(): void
    {
        $res = $this->uomService->resolveForecastUnit(1, 'lingkaran');

        $this->assertSame(UnitOfMeasureService::STATUS_UNSUPPORTED_DATA, $res['status']);
        $this->assertSame(UnitOfMeasureService::REASON_UNIT_CONVERSION_UNAVAILABLE, $res['failure_reason']);
        $this->assertFalse($res['is_discrete']);
    }

    /**
     * Requirement 13: null unit -> MISSING_UNIT_METADATA
     */
    public function test_13_null_unit_returns_missing_unit_metadata(): void
    {
        $resNull = $this->uomService->resolveForecastUnit(1, null);
        $this->assertSame(UnitOfMeasureService::STATUS_MISSING_METADATA, $resNull['status']);
        $this->assertSame(UnitOfMeasureService::REASON_MISSING_UNIT_METADATA, $resNull['failure_reason']);
        $this->assertFalse($resNull['is_discrete']);

        $resEmpty = $this->uomService->resolveForecastUnit(1, '   ');
        $this->assertSame(UnitOfMeasureService::STATUS_MISSING_METADATA, $resEmpty['status']);
        $this->assertSame(UnitOfMeasureService::REASON_MISSING_UNIT_METADATA, $resEmpty['failure_reason']);
    }

    /**
     * Requirement 14: unknown unit -> fail-closed
     */
    public function test_14_unknown_unit_fails_closed(): void
    {
        $unknowns = ['random_str_xyz', 'ember', 'truck', 'unknown_dim'];

        foreach ($unknowns as $unk) {
            $res = $this->uomService->resolveForecastUnit(1, $unk);
            $this->assertSame(UnitOfMeasureService::STATUS_UNSUPPORTED_DATA, $res['status']);
            $this->assertSame(UnitOfMeasureService::REASON_UNIT_CONVERSION_UNAVAILABLE, $res['failure_reason']);
            $this->assertFalse($res['is_discrete']);
        }
    }

    /**
     * Requirement 15: quantity 2500 does NOT infer Gram
     */
    public function test_15_quantity_2500_does_not_infer_gram(): void
    {
        $menu = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Tusuk Sate Ayam',
            'category'   => 'Makanan',
            'unit'       => 'Pcs',
            'price'      => 1000.0,
        ]);

        // Create transaction with large quantity (2500.0 pcs)
        $this->createTx($this->tenantA, $this->outletA, $menu, 2500.0, '2026-05-15', 1000.0);

        $res = $this->uomService->resolveForecastUnit($menu->id, $menu->unit);
        $this->assertSame(UnitOfMeasureService::STATUS_SUPPORTED, $res['status']);
        $this->assertSame('pcs', $res['canonical_unit']);
        $this->assertSame(UnitOfMeasureService::DIMENSION_COUNT, $res['dimension']);
        $this->assertTrue($res['is_discrete']);
        $this->assertNotSame('g', $res['canonical_unit']);
        $this->assertNotSame(UnitOfMeasureService::DIMENSION_MASS, $res['dimension']);
    }

    /**
     * Requirement 16: menu name containing "1 kg" does NOT infer kg
     */
    public function test_16_menu_name_containing_1kg_does_not_infer_kg(): void
    {
        $menu = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Beras Premium 1 kg Kemasan',
            'category'   => 'Sembako',
            'unit'       => 'Pcs',
            'price'      => 18000.0,
        ]);

        $res = $this->uomService->resolveForecastUnit($menu->id, $menu->unit);
        $this->assertSame(UnitOfMeasureService::STATUS_SUPPORTED, $res['status']);
        $this->assertSame('pcs', $res['canonical_unit']);
        $this->assertSame(UnitOfMeasureService::DIMENSION_COUNT, $res['dimension']);
        $this->assertTrue($res['is_discrete']);
        $this->assertNotSame(UnitOfMeasureService::DIMENSION_MASS, $res['dimension']);
    }

    /**
     * Requirement 17: financial impact does not mix normalized quantity with original price basis
     */
    public function test_17_financial_impact_does_not_mix_normalized_quantity_with_original_price_basis(): void
    {
        $menu = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Alpukat Perkebunan',
            'category'   => 'Buah',
            'unit'       => 'Gram',
            'price'      => 35.0, // Rp 35 per gram
        ]);

        // Add 14 days of transactions
        for ($i = 1; $i <= 14; $i++) {
            $date = Carbon::parse('2026-05-01')->addDays($i)->format('Y-m-d');
            $this->createTx($this->tenantA, $this->outletA, $menu, 500.0, $date, 35.0);
        }

        $forecast = $this->demandService->forecastMenu($this->tenantA->id, $menu->id, $this->outletA->id, '2026-05-20');

        $this->assertSame(UnitOfMeasureService::STATUS_UNIT_DIMENSION_MISMATCH, $forecast['status']);
        $this->assertNull($forecast['forecast']['forecast_qty']);
        $this->assertSame('INSUFFICIENT_DATA', $forecast['financial_impact']['status']);
        $this->assertNull($forecast['financial_impact']['potential_forecast_revenue']);
    }

    /**
     * Requirement 18: stockout dimension mismatch fails closed
     */
    public function test_18_stockout_dimension_mismatch_fails_closed(): void
    {
        $menu = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Menu Gram Timbangan',
            'category'   => 'Buah',
            'unit'       => 'Gram',
            'price'      => 1000.0,
            'stock'      => -1.0,
        ]);

        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Bahan Porsi',
            'type'          => 'raw_material',
            'base_uom'      => 'pcs',
            'cost_price'    => 500.0,
            'reorder_point' => 5,
        ]);

        DB::table('menu_ingredients')->insert([
            'menu_id'    => $menu->id,
            'item_id'    => $item->id,
            'qty'        => 1.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stockRisk = $this->stockoutService->evaluateMenuStockRisk($this->tenantA->id, $menu->id, $this->outletA->id, '2026-05-20');

        $this->assertSame(StockoutIntelligenceService::STATUS_UNSUPPORTED, $stockRisk['status']);
        $this->assertSame(UnitOfMeasureService::STATUS_UNIT_DIMENSION_MISMATCH, $stockRisk['reason']);
        $this->assertNull($stockRisk['forecasted_demand']['daily_qty']);
        $this->assertSame(0, $stockRisk['ingredients_count']);
    }

    /**
     * Requirement 19: supported Portion forecast preserves existing forecast result
     */
    public function test_19_supported_portion_forecast_preserves_existing_forecast_result(): void
    {
        $menu = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Nasi Goreng Spesial',
            'category'   => 'Makanan',
            'unit'       => 'Porsi',
            'price'      => 25000.0,
            'cost_price' => 15000.0,
            'stock'      => -1.0,
        ]);

        // Add 28 days of constant sales (10 porsi / day)
        for ($i = 1; $i <= 28; $i++) {
            $date = Carbon::parse('2026-05-01')->addDays($i)->format('Y-m-d');
            $this->createTx($this->tenantA, $this->outletA, $menu, 10.0, $date, 25000.0);
        }

        $forecast = $this->demandService->forecastMenu($this->tenantA->id, $menu->id, $this->outletA->id, '2026-05-30');

        $this->assertSame('SUCCESS', $forecast['status']);
        $this->assertSame('porsi', $forecast['forecast']['uom']);
        $this->assertSame(UnitOfMeasureService::DIMENSION_PORTION, $forecast['forecast']['dimension']);
        $this->assertTrue($forecast['forecast']['is_discrete']);
        $this->assertEquals(10.0, (float) $forecast['forecast']['forecast_qty']);
        $this->assertSame('CALCULATED', $forecast['financial_impact']['status']);
        $this->assertEquals(250000.0, (float) $forecast['financial_impact']['potential_forecast_revenue']);
    }

    /**
     * Requirement 20: tenant/outlet isolation remains intact
     */
    public function test_20_tenant_outlet_isolation_remains_intact(): void
    {
        // Tenant B has an item with Box mapping
        $itemB = Item::create([
            'outlet_id'     => $this->outletB->id,
            'name'          => 'Item Tenant B',
            'type'          => 'raw_material',
            'base_uom'      => 'pcs',
            'cost_price'    => 1000.0,
            'reorder_point' => 5,
        ]);

        DB::table('item_uoms')->insert([
            'item_id'           => $itemB->id,
            'uom_name'          => 'Box',
            'conversion_factor' => 20.0,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // Tenant A creates a Box menu
        $menuA = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Menu Tenant A Box',
            'category'   => 'Makanan',
            'unit'       => 'Box',
            'price'      => 40000.0,
        ]);

        // Tenant A forecast unit resolution cannot use Tenant B item
        $resA = $this->uomService->resolveForecastUnit($menuA->id, 'Box', 40000.0, $this->outletA->id, $itemB->id);
        // Tenant A outlet filter prevents resolving Tenant B item
        $this->assertSame(UnitOfMeasureService::STATUS_UNSUPPORTED_DATA, $resA['status']);

        // Cross-tenant forecastMenu access is blocked
        $forecastCross = $this->demandService->forecastMenu($this->tenantB->id, $menuA->id, $this->outletB->id, '2026-06-01');
        $this->assertSame('MENU_NOT_FOUND', $forecastCross['status']);
    }

    /**
     * Requirement 21: PIT semantics remain intact
     */
    public function test_21_pit_semantics_remain_intact(): void
    {
        $menu = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Ayam Geprek PIT',
            'category'   => 'Makanan',
            'unit'       => 'Porsi',
            'price'      => 20000.0,
        ]);

        // 10 days of past sales before anchor date
        for ($i = 1; $i <= 10; $i++) {
            $date = Carbon::parse('2026-05-01')->addDays($i)->format('Y-m-d');
            $this->createTx($this->tenantA, $this->outletA, $menu, 5.0, $date, 20000.0);
        }

        // Future transaction AFTER anchor date
        $futureDate = '2026-05-25';
        $this->createTx($this->tenantA, $this->outletA, $menu, 999.0, $futureDate, 20000.0);

        // Run forecast with anchor date 2026-05-15
        $forecast = $this->demandService->forecastMenu($this->tenantA->id, $menu->id, $this->outletA->id, '2026-05-15');

        // Verify future transaction is not leaked into active sales days
        $this->assertSame(10, $forecast['history_classification']['active_sales_days']);
        $this->assertLessThan(100.0, (float) $forecast['forecast']['forecast_qty']);
    }

    /**
     * Requirement 22: zero-sales preservation remains intact
     */
    public function test_22_zero_sales_preservation_remains_intact(): void
    {
        $menu = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Es Teh Manis Zero Days',
            'category'   => 'Minuman',
            'unit'       => 'Porsi',
            'price'      => 5000.0,
        ]);

        // Sales on only 5 out of 28 days
        for ($i = 1; $i <= 5; $i++) {
            $date = Carbon::parse('2026-05-01')->addDays($i)->format('Y-m-d');
            $this->createTx($this->tenantA, $this->outletA, $menu, 3.0, $date, 5000.0);
        }

        $forecast = $this->demandService->forecastMenu($this->tenantA->id, $menu->id, $this->outletA->id, '2026-05-30');

        $this->assertSame(5, $forecast['history_classification']['active_sales_days']);
        $this->assertSame(23, $forecast['history_classification']['zero_sales_days']);
        $this->assertSame(28, $forecast['history_classification']['total_history_days']);
    }
}
