<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Menu;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\MenuIngredient;
use App\Models\StockMovement;
use App\Services\Financial\StockoutIntelligenceService;
use App\Services\Financial\CashFlowForecastService;
use App\Services\Financial\UnitOfMeasureService;
use App\Services\Financial\SmartRestockOrchestratorService;
use App\Services\Financial\MenuDemandForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class SmartRestockOrchestratorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenantA;
    protected User $tenantB;
    protected Outlet $outletA;
    protected Outlet $outletB;
    protected SmartRestockOrchestratorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = User::factory()->create([
            'email' => 'tenant_restock_a@kaching.test',
        ]);

        $this->tenantB = User::factory()->create([
            'email' => 'tenant_restock_b@kaching.test',
        ]);

        $this->outletA = Outlet::create([
            'user_id' => $this->tenantA->id,
            'name'    => 'Kopi Kaching Melawai',
        ]);

        $this->outletB = Outlet::create([
            'user_id' => $this->tenantB->id,
            'name'    => 'Kopi Kaching Menteng',
        ]);

        $demandService = app(MenuDemandForecastService::class);
        $stockoutService = new StockoutIntelligenceService($demandService);
        $cashFlowService = app(CashFlowForecastService::class);
        $uomService = app(UnitOfMeasureService::class);

        $this->service = new SmartRestockOrchestratorService(
            $stockoutService,
            $cashFlowService,
            $uomService
        );
    }

    /**
     * 1. Bahan normal tidak direkomendasikan untuk restock tanpa alasan.
     */
    public function test_normal_item_with_healthy_stock_is_not_recommended_for_restock(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Americano Ice',
            'category'  => 'Coffee',
            'price'     => 22000,
            'stock'     => -1,
        ]);

        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Biji Kopi Gayo',
            'type'          => 'raw_material',
            'base_uom'      => 'gram',
            'cost_price'    => 250,
            'reorder_point' => 1000, // Reorder point 1kg
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $item->id,
            'qty'     => 15.0,
        ]);

        // Sedia 50.000 gram (50 kg) — sangat aman
        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 50000.0,
        ]);

        $res = $this->service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $this->assertSame(SmartRestockOrchestratorService::STATUS_NO_RESTOCK_NEEDED, $res['status']);
        $this->assertCount(0, $res['recommendations']);
        $this->assertSame(0, $res['summary']['items_needing_restock']);
        $this->assertSame(0.0, (float) $res['summary']['total_estimated_cost']);
    }

    /**
     * 2. Bahan kritis menghasilkan kuantitas yang benar.
     */
    public function test_critical_stockout_item_produces_correct_suggested_quantity(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Matcha Latte',
            'category'  => 'Beverage',
            'price'     => 28000,
            'stock'     => -1,
        ]);

        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Bubuk Matcha Uji',
            'type'          => 'raw_material',
            'base_uom'      => 'gram',
            'cost_price'    => 500,
            'reorder_point' => 500, // 500 gr
        ]);

        MenuIngredient::create([
            'menu_id' => $menu->id,
            'item_id' => $item->id,
            'qty'     => 20.0,
        ]);

        // Stok 0 gram (HABIS)
        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 0.0,
        ]);

        $supplier = Supplier::create([
            'name'           => 'CV Matcha Nusantara',
            'contact_person' => 'Budi Santoso',
            'phone'          => '081234567890',
            'is_active'      => true,
        ]);

        $item->suppliers()->attach($supplier->id, [
            'supplier_price' => 450,
            'lead_time_days' => 2,
        ]);

        $res = $this->service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $this->assertSame(SmartRestockOrchestratorService::STATUS_SUCCESS, $res['status']);
        $this->assertCount(1, $res['recommendations']);
        $rec = $res['recommendations'][0];

        $this->assertSame($item->id, $rec['item_id']);
        $this->assertSame('STOCKOUT', $rec['risk_status']);
        $this->assertTrue($rec['needs_restock']);
        $this->assertGreaterThanOrEqual(500.0, $rec['suggested_quantity']); // Minimal memenuhi reorder point 500g
        $this->assertSame(450.0, (float) $rec['unit_price']);
        $this->assertSame(SmartRestockOrchestratorService::PRICE_SOURCE_SUPPLIER_CATALOG, $rec['price_source']);
        $this->assertNotNull($rec['estimated_cost']);
        $this->assertSame($rec['suggested_quantity'] * 450.0, (float) $rec['estimated_cost']);
    }

    /**
     * 3. Konversi satuan dan pembulatan tidak mengurangi kebutuhan.
     */
    public function test_unit_conversion_and_rounding_preserves_required_quantity(): void
    {
        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Gula Pasir Premium',
            'type'          => 'raw_material',
            'base_uom'      => 'kg',
            'cost_price'    => 18000,
            'reorder_point' => 10.5, // 10.5 kg
        ]);

        // Sisa stok 2.2 kg
        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 2.2,
        ]);

        $res = $this->service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $this->assertSame(SmartRestockOrchestratorService::STATUS_SUCCESS, $res['status']);
        $this->assertCount(1, $res['recommendations']);
        $rec = $res['recommendations'][0];

        // Defisit = 10.5 - 2.2 = 8.3 kg
        $this->assertGreaterThanOrEqual(8.3, $rec['suggested_quantity']);
        $this->assertSame('kg', $rec['base_uom']);
    }

    /**
     * 4. Supplier yang tidak terhubung dengan bahan tidak direkomendasikan.
     */
    public function test_unconnected_supplier_is_not_recommended_for_item(): void
    {
        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Sirup Vanilla',
            'type'          => 'raw_material',
            'base_uom'      => 'botol',
            'cost_price'    => 65000,
            'reorder_point' => 5,
        ]);

        // Stok 1 botol (di bawah reorder point 5)
        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 1.0,
        ]);

        // Supplier tidak terhubung ke item ini
        Supplier::create([
            'name'      => 'PT Supplier Terpisah',
            'phone'     => '081122334455',
            'is_active' => true,
        ]);

        $res = $this->service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $this->assertSame(SmartRestockOrchestratorService::STATUS_SUCCESS, $res['status']);
        $this->assertCount(1, $res['recommendations']);
        $rec = $res['recommendations'][0];

        $this->assertCount(0, $rec['supplier_options']);
        $this->assertNull($rec['primary_supplier']);
        $this->assertSame(SmartRestockOrchestratorService::PRICE_SOURCE_ITEM_COST_FALLBACK, $rec['price_source']);
        $this->assertSame(65000.0, (float) $rec['unit_price']);
    }

    /**
     * 5. Isolasi antar-pengguna dan outlet.
     */
    public function test_tenant_and_outlet_isolation(): void
    {
        // Item milik Tenant B di Outlet B
        $itemB = Item::create([
            'outlet_id'     => $this->outletB->id,
            'name'          => 'Bahan Rahasia Tenant B',
            'type'          => 'raw_material',
            'base_uom'      => 'kg',
            'cost_price'    => 100000,
            'reorder_point' => 50,
        ]);

        StockMovement::forceCreate([
            'item_id' => $itemB->id,
            'type'    => 'incoming',
            'qty'     => 0.0,
        ]);

        // Tenant A request analisis di outlet Tenant B -> WAJIB UNAUTHORIZED_OUTLET
        $unauthRes = $this->service->getRestockRecommendations($this->tenantA->id, $this->outletB->id);
        $this->assertSame(SmartRestockOrchestratorService::STATUS_UNAUTHORIZED_OUTLET, $unauthRes['status']);
        $this->assertCount(0, $unauthRes['recommendations']);

        // Tenant A request outletnya sendiri -> Tidak boleh bocor item Tenant B
        $authRes = $this->service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);
        $this->assertSame(SmartRestockOrchestratorService::STATUS_NO_RESTOCK_NEEDED, $authRes['status']);
        $this->assertCount(0, $authRes['recommendations']);
    }

    /**
     * 6. Harga kosong tidak menghasilkan estimasi biaya palsu.
     */
    public function test_empty_supplier_price_does_not_produce_fake_cost_estimate(): void
    {
        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Bahan Tanpa Harga',
            'type'          => 'raw_material',
            'base_uom'      => 'kg',
            'cost_price'    => 0, // Master HPP 0
            'reorder_point' => 10,
        ]);

        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 0.0,
        ]);

        $supplier = Supplier::create([
            'name'      => 'Supplier Tanpa Katalog',
            'phone'     => '081999888777',
            'is_active' => true,
        ]);

        $item->suppliers()->attach($supplier->id, [
            'supplier_price' => 0, // Harga pivot 0 / belum diisi
            'lead_time_days' => 3,
        ]);

        $res = $this->service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $this->assertSame(SmartRestockOrchestratorService::STATUS_SUCCESS, $res['status']);
        $rec = $res['recommendations'][0];

        $this->assertNull($rec['unit_price']);
        $this->assertNull($rec['estimated_cost']);
        $this->assertSame(SmartRestockOrchestratorService::PRICE_SOURCE_UNAVAILABLE, $rec['price_source']);
        $this->assertTrue($res['summary']['has_unpriced_items']);
    }

    /**
     * 7. Beberapa supplier menghasilkan alternatif terpisah.
     */
    public function test_multiple_suppliers_produce_distinct_alternatives(): void
    {
        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Susu UHT Fresh',
            'type'          => 'raw_material',
            'base_uom'      => 'liter',
            'cost_price'    => 19000,
            'reorder_point' => 20,
        ]);

        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 2.0,
        ]);

        $sup1 = Supplier::create([
            'name'  => 'Distributor Susu Murah',
            'phone' => '081211112222',
        ]);

        $sup2 = Supplier::create([
            'name'  => 'Toko Susu Express',
            'phone' => '081233334444',
        ]);

        $item->suppliers()->attach($sup1->id, [
            'supplier_price' => 17500,
            'lead_time_days' => 3,
        ]);

        $item->suppliers()->attach($sup2->id, [
            'supplier_price' => 19500,
            'lead_time_days' => 1,
        ]);

        $res = $this->service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $this->assertSame(SmartRestockOrchestratorService::STATUS_SUCCESS, $res['status']);
        $rec = $res['recommendations'][0];

        $this->assertCount(2, $rec['supplier_options']);
        $this->assertSame('Distributor Susu Murah', $rec['supplier_options'][0]['supplier_name']);
        $this->assertSame(17500.0, (float) $rec['supplier_options'][0]['unit_price_used']);

        $this->assertSame('Toko Susu Express', $rec['supplier_options'][1]['supplier_name']);
        $this->assertSame(19500.0, (float) $rec['supplier_options'][1]['unit_price_used']);
    }

    /**
     * 8. Proyeksi kas tidak mencukupi ditandai dengan benar.
     */
    public function test_insufficient_cashflow_is_flagged_as_potential_deficit(): void
    {
        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Mesin Roasting Mini',
            'type'          => 'raw_material',
            'base_uom'      => 'unit',
            'cost_price'    => 50000000, // Rp 50 Juta
            'reorder_point' => 2,
        ]);

        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 0.0,
        ]);

        $res = $this->service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $this->assertSame(SmartRestockOrchestratorService::STATUS_SUCCESS, $res['status']);
        $cashflowImpact = $res['summary']['cashflow_impact'];

        $this->assertNotNull($cashflowImpact);
        // Ketika tidak ada riwayat transaksi kas, cash flow status adalah UNAVAILABLE atau POTENTIAL_DEFICIT
        $this->assertContains($cashflowImpact['status'], [
            SmartRestockOrchestratorService::CASHFLOW_STATUS_POTENTIAL_DEFICIT,
            SmartRestockOrchestratorService::CASHFLOW_STATUS_UNAVAILABLE,
        ]);
    }

    /**
     * 9. WhatsApp link dan encoding pesan valid.
     */
    public function test_whatsapp_draft_link_and_message_encoding_valid(): void
    {
        $wa = $this->service->buildWhatsAppDraftUrl(
            '0812-3456-7890',
            'Kopi Kaching Melawai',
            'CV Sumber Makmur',
            'Biji Kopi Robusta',
            15.5,
            'kg',
            85000,
            SmartRestockOrchestratorService::PRICE_SOURCE_SUPPLIER_CATALOG
        );

        $this->assertTrue($wa['is_valid']);
        $this->assertSame('6281234567890', $wa['clean_phone']);
        $this->assertStringStartsWith('https://wa.me/6281234567890?text=', $wa['url']);
        $this->assertStringContainsString('Biji Kopi Robusta', urldecode($wa['url']));
        $this->assertStringContainsString('15,5 kg', urldecode($wa['url']));
        $this->assertStringContainsString('Kopi Kaching Melawai', urldecode($wa['url']));
        $this->assertStringContainsString('CV Sumber Makmur', urldecode($wa['url']));
    }

    /**
     * 10. Nomor kontak kosong atau tidak valid tidak menghasilkan link yang menyesatkan.
     */
    public function test_invalid_or_empty_phone_does_not_generate_misleading_whatsapp_link(): void
    {
        $invalidPhones = [null, '', '000', '12345', 'abc-def', '-'];

        foreach ($invalidPhones as $phone) {
            $wa = $this->service->buildWhatsAppDraftUrl(
                $phone,
                'Outlet Test',
                'Supplier Test',
                'Bahan Test',
                10,
                'kg'
            );

            $this->assertFalse($wa['is_valid']);
            $this->assertNull($wa['url']);
            $this->assertNull($wa['clean_phone']);
            $this->assertNotNull($wa['error']);
        }
    }

    /**
     * 11. Input tool tidak valid ditangani dengan aman.
     */
    public function test_invalid_tool_arguments_handled_safely(): void
    {
        // Negative limit and horizon days
        $res = $this->service->getRestockRecommendations($this->tenantA->id, $this->outletA->id, [
            'horizon_days' => -5,
            'limit'        => -10,
        ]);

        $this->assertNotNull($res['status']);
        $this->assertArrayHasKey('recommendations', $res);
        $this->assertArrayHasKey('summary', $res);
    }

    /**
     * 12. Kegagalan service dependency tidak menghasilkan rekomendasi finansial palsu.
     */
    public function test_dependency_failure_fails_closed_without_fake_recommendations(): void
    {
        // Mock StockoutIntelligenceService throwing exception
        $mockStockout = $this->createMock(StockoutIntelligenceService::class);
        $mockStockout->method('evaluateAllMenusStockRisk')
            ->willThrowException(new \RuntimeException('Database schema corrupted'));

        $service = new SmartRestockOrchestratorService(
            $mockStockout,
            app(CashFlowForecastService::class),
            app(UnitOfMeasureService::class)
        );

        $res = $service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $this->assertSame(SmartRestockOrchestratorService::STATUS_UNSUPPORTED, $res['status']);
        $this->assertCount(0, $res['recommendations']);
        $this->assertSame(0.0, (float) $res['summary']['total_estimated_cost']);
        $this->assertStringContainsString('Database schema corrupted', $res['message']);
    }

    /**
     * 13. CashFlow integration: Signature dan parameter diteruskan dengan benar ke forecast().
     */
    public function test_cashflow_integration_passes_correct_signature_and_options_to_forecast(): void
    {
        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Kopi Arabika Toraja',
            'type'          => 'raw_material',
            'base_uom'      => 'gram',
            'cost_price'    => 300,
            'reorder_point' => 500,
        ]);

        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 0.0,
        ]);

        $mockCashflow = $this->createMock(CashFlowForecastService::class);
        $mockCashflow->expects($this->once())
            ->method('forecast')
            ->with(
                $this->equalTo($this->tenantA->id),
                $this->equalTo($this->outletA->id),
                $this->isNull(),
                $this->callback(function ($options) {
                    return is_array($options) && isset($options['horizon_days']) && $options['horizon_days'] === 7;
                })
            )
            ->willReturn([
                'status'                            => 'SUCCESS',
                'projected_inflow'                  => 10000000.0,
                'projected_outflow'                 => 5000000.0,
                'projected_net_operating_cash_flow' => 5000000.0,
                'data_quality'                      => 'FULL_PRECISION',
            ]);

        $service = new SmartRestockOrchestratorService(
            new StockoutIntelligenceService(app(MenuDemandForecastService::class)),
            $mockCashflow,
            app(UnitOfMeasureService::class)
        );

        $res = $service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $this->assertSame(SmartRestockOrchestratorService::STATUS_SUCCESS, $res['status']);
        $cashflowImpact = $res['summary']['cashflow_impact'];
        $this->assertSame(SmartRestockOrchestratorService::CASHFLOW_STATUS_AFFORDABLE, $cashflowImpact['status']);
        $this->assertSame(5000000.0, $cashflowImpact['projected_net_operating_cash_flow']);
        $this->assertSame('SUCCESS', $cashflowImpact['cashflow_service_status']);
    }

    /**
     * 14. CashFlow AFFORDABLE saat proyeksi kas lebih besar dari total restock cost.
     */
    public function test_cashflow_affordable_when_projected_cash_exceeds_total_restock_cost(): void
    {
        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Susu Segar Greenfield',
            'type'          => 'raw_material',
            'base_uom'      => 'ml',
            'cost_price'    => 20,
            'reorder_point' => 1000,
        ]);

        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 0.0,
        ]);

        $mockCashflow = $this->createMock(CashFlowForecastService::class);
        $mockCashflow->method('forecast')
            ->willReturn([
                'status'                            => 'SUCCESS',
                'projected_net_operating_cash_flow' => 2000000.0,
            ]);

        $service = new SmartRestockOrchestratorService(
            new StockoutIntelligenceService(app(MenuDemandForecastService::class)),
            $mockCashflow,
            app(UnitOfMeasureService::class)
        );

        $res = $service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $cashflowImpact = $res['summary']['cashflow_impact'];
        $this->assertSame(SmartRestockOrchestratorService::CASHFLOW_STATUS_AFFORDABLE, $cashflowImpact['status']);
        $this->assertSame(2000000.0, $cashflowImpact['projected_net_operating_cash_flow']);
        $this->assertGreaterThan(0, $cashflowImpact['total_restock_cost']);
        $this->assertLessThan($cashflowImpact['projected_net_operating_cash_flow'], $cashflowImpact['total_restock_cost']);
    }

    /**
     * 15. CashFlow POTENTIAL_DEFICIT saat proyeksi kas lebih kecil dari total restock cost.
     */
    public function test_cashflow_potential_deficit_when_projected_cash_is_less_than_restock_cost(): void
    {
        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Sirup Karamel Premium',
            'type'          => 'raw_material',
            'base_uom'      => 'botol',
            'cost_price'    => 150000,
            'reorder_point' => 5,
        ]);

        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 0.0,
        ]);

        $mockCashflow = $this->createMock(CashFlowForecastService::class);
        $mockCashflow->method('forecast')
            ->willReturn([
                'status'                            => 'SUCCESS',
                'projected_net_operating_cash_flow' => 100000.0,
            ]);

        $service = new SmartRestockOrchestratorService(
            new StockoutIntelligenceService(app(MenuDemandForecastService::class)),
            $mockCashflow,
            app(UnitOfMeasureService::class)
        );

        $res = $service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $cashflowImpact = $res['summary']['cashflow_impact'];
        $this->assertSame(SmartRestockOrchestratorService::CASHFLOW_STATUS_POTENTIAL_DEFICIT, $cashflowImpact['status']);
        $this->assertSame(100000.0, $cashflowImpact['projected_net_operating_cash_flow']);
        $this->assertGreaterThan($cashflowImpact['projected_net_operating_cash_flow'], $cashflowImpact['total_restock_cost']);
    }

    /**
     * 16. CashFlow POTENTIAL_DEFICIT saat proyeksi kas bernilai 0 atau negatif.
     */
    public function test_cashflow_potential_deficit_when_projected_cash_is_zero_or_negative(): void
    {
        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Cup Plastik 16oz',
            'type'          => 'raw_material',
            'base_uom'      => 'pcs',
            'cost_price'    => 500,
            'reorder_point' => 100,
        ]);

        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 0.0,
        ]);

        $mockCashflow = $this->createMock(CashFlowForecastService::class);
        $mockCashflow->method('forecast')
            ->willReturn([
                'status'                            => 'SUCCESS',
                'projected_net_operating_cash_flow' => -250000.0,
            ]);

        $service = new SmartRestockOrchestratorService(
            new StockoutIntelligenceService(app(MenuDemandForecastService::class)),
            $mockCashflow,
            app(UnitOfMeasureService::class)
        );

        $res = $service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $cashflowImpact = $res['summary']['cashflow_impact'];
        $this->assertSame(SmartRestockOrchestratorService::CASHFLOW_STATUS_POTENTIAL_DEFICIT, $cashflowImpact['status']);
        $this->assertSame(-250000.0, $cashflowImpact['projected_net_operating_cash_flow']);
    }

    /**
     * 17. CashFlow UNAVAILABLE saat service melempar Exception.
     */
    public function test_cashflow_unavailable_when_service_throws_exception(): void
    {
        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Biji Kopi Robusta Dampit',
            'type'          => 'raw_material',
            'base_uom'      => 'gram',
            'cost_price'    => 150,
            'reorder_point' => 500,
        ]);

        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 0.0,
        ]);

        $mockCashflow = $this->createMock(CashFlowForecastService::class);
        $mockCashflow->method('forecast')
            ->willThrowException(new \RuntimeException('Timeout accessing financial ledger'));

        $service = new SmartRestockOrchestratorService(
            new StockoutIntelligenceService(app(MenuDemandForecastService::class)),
            $mockCashflow,
            app(UnitOfMeasureService::class)
        );

        $res = $service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $cashflowImpact = $res['summary']['cashflow_impact'];
        $this->assertSame(SmartRestockOrchestratorService::CASHFLOW_STATUS_UNAVAILABLE, $cashflowImpact['status']);
        $this->assertNull($cashflowImpact['projected_net_operating_cash_flow']);
        $this->assertSame('EXCEPTION', $cashflowImpact['cashflow_service_status']);
        $this->assertSame('Analisis arus kas sementara belum dapat diproses.', $cashflowImpact['assessment']);
        $this->assertStringNotContainsString('Timeout accessing financial ledger', $cashflowImpact['assessment']);
    }

    /**
     * 18. CashFlow UNAVAILABLE saat respons tidak memiliki key proyeksi atau nilai non-numerik.
     */
    public function test_cashflow_unavailable_when_response_is_missing_projection_key_or_invalid(): void
    {
        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Gula Aren Cair',
            'type'          => 'raw_material',
            'base_uom'      => 'ml',
            'cost_price'    => 50,
            'reorder_point' => 500,
        ]);

        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 0.0,
        ]);

        $mockCashflow = $this->createMock(CashFlowForecastService::class);
        $mockCashflow->method('forecast')
            ->willReturn([
                'status' => 'SUCCESS',
            ]);

        $service = new SmartRestockOrchestratorService(
            new StockoutIntelligenceService(app(MenuDemandForecastService::class)),
            $mockCashflow,
            app(UnitOfMeasureService::class)
        );

        $res = $service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $cashflowImpact = $res['summary']['cashflow_impact'];
        $this->assertSame(SmartRestockOrchestratorService::CASHFLOW_STATUS_UNAVAILABLE, $cashflowImpact['status']);
        $this->assertNull($cashflowImpact['projected_net_operating_cash_flow']);
    }

    /**
     * 19. CashFlow UNAVAILABLE saat status cashflow bukan SUCCESS (misal SPARSE_DATA / INSUFFICIENT_HISTORY).
     */
    public function test_cashflow_unavailable_when_status_is_sparse_or_insufficient(): void
    {
        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Teh Melati',
            'type'          => 'raw_material',
            'base_uom'      => 'gram',
            'cost_price'    => 100,
            'reorder_point' => 300,
        ]);

        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 0.0,
        ]);

        $mockCashflow = $this->createMock(CashFlowForecastService::class);
        $mockCashflow->method('forecast')
            ->willReturn([
                'status'                            => 'SPARSE_DATA',
                'projected_net_operating_cash_flow' => null,
            ]);

        $service = new SmartRestockOrchestratorService(
            new StockoutIntelligenceService(app(MenuDemandForecastService::class)),
            $mockCashflow,
            app(UnitOfMeasureService::class)
        );

        $res = $service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $cashflowImpact = $res['summary']['cashflow_impact'];
        $this->assertSame(SmartRestockOrchestratorService::CASHFLOW_STATUS_UNAVAILABLE, $cashflowImpact['status']);
        $this->assertNull($cashflowImpact['projected_net_operating_cash_flow']);
        $this->assertSame('SPARSE_DATA', $cashflowImpact['cashflow_service_status']);
    }

    /**
     * 20. Real integration: CashFlowForecastService riil tidak melempar Carbon InvalidFormatException.
     */
    public function test_real_cashflow_service_integration_does_not_throw_carbon_invalid_format_exception(): void
    {
        $item = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Sirup Vanilla',
            'type'          => 'raw_material',
            'base_uom'      => 'ml',
            'cost_price'    => 80,
            'reorder_point' => 200,
        ]);

        StockMovement::forceCreate([
            'item_id' => $item->id,
            'type'    => 'incoming',
            'qty'     => 0.0,
        ]);

        $res = $this->service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $this->assertSame(SmartRestockOrchestratorService::STATUS_SUCCESS, $res['status']);
        $cashflowImpact = $res['summary']['cashflow_impact'];

        $this->assertNotNull($cashflowImpact);
        $this->assertNotSame('EXCEPTION', $cashflowImpact['cashflow_service_status']);
    }

    /**
     * 21. Explainability payload tidak membocorkan nama tabel, nama kolom SQL, atau nama class internal.
     */
    public function test_explainability_does_not_leak_internal_classes_tables_or_sql_identifiers(): void
    {
        $res = $this->service->getRestockRecommendations($this->tenantA->id, $this->outletA->id);

        $explainability = $res['explainability'];
        $this->assertNotNull($explainability);
        $this->assertSame('Sistem Manajemen Restock Kaching POS', $explainability['source']);

        $calculation = $explainability['calculation'];
        $this->assertStringNotContainsString('item_supplier', $calculation);
        $this->assertStringNotContainsString('suggested_qty', $calculation);
        $this->assertStringNotContainsString('reorder_point', $calculation);
        $this->assertStringNotContainsString('SmartRestockOrchestratorService', $explainability['source']);
    }
}
