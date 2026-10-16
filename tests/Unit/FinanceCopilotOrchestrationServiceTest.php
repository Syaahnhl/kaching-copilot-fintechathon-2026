<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Menu;
use App\Services\Financial\FinanceCopilotOrchestrationService;
use App\Services\Policy\ActionPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class FinanceCopilotOrchestrationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenantA;
    protected User $tenantB;
    protected Outlet $outletA;
    protected Outlet $outletB;
    protected FinanceCopilotOrchestrationService $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = User::factory()->create([
            'email' => 'tenant_orch_a@kaching.test',
        ]);

        $this->tenantB = User::factory()->create([
            'email' => 'tenant_orch_b@kaching.test',
        ]);

        $this->outletA = Outlet::create([
            'user_id' => $this->tenantA->id,
            'name'    => 'Outlet Orch A',
        ]);

        $this->outletB = Outlet::create([
            'user_id' => $this->tenantB->id,
            'name'    => 'Outlet Orch B',
        ]);

        $this->orchestrator = app(FinanceCopilotOrchestrationService::class);
    }

    public function test_all_19_intelligence_tools_are_registered(): void
    {
        $tools = $this->orchestrator->getRegisteredTools();

        $this->assertCount(20, $tools);
        $this->assertArrayHasKey('get_today_sales', $tools);
        $this->assertArrayHasKey('get_sales_summary', $tools);
        $this->assertArrayHasKey('get_top_selling_products', $tools);
        $this->assertArrayHasKey('get_suppliers', $tools);
        $this->assertArrayHasKey('get_financial_summary', $tools);
        $this->assertArrayHasKey('get_risk_radar', $tools);
        $this->assertArrayHasKey('get_cash_flow_forecast', $tools);
        $this->assertArrayHasKey('get_demand_forecast', $tools);
        $this->assertArrayHasKey('get_stock_risk', $tools);
        $this->assertArrayHasKey('get_material_price_forecast', $tools);
        $this->assertArrayHasKey('get_financial_scenario', $tools);
        $this->assertArrayHasKey('get_financial_action_recommendations', $tools);
        $this->assertArrayHasKey('get_menu_margin_candidates', $tools);
        $this->assertArrayHasKey('prepare_action_proposal', $tools);
        $this->assertArrayHasKey('get_financing_readiness', $tools);
        $this->assertArrayHasKey('get_financial_evidence', $tools);
        $this->assertArrayHasKey('get_market_signals', $tools);
        $this->assertArrayHasKey('get_financial_performance_intelligence', $tools);
        $this->assertArrayHasKey('get_restock_recommendations', $tools);
        $this->assertArrayHasKey('generate_supplier_restock_message', $tools);
    }

    public function test_dispatch_fulfills_explainability_contract(): void
    {
        $res = $this->orchestrator->dispatchTool('get_today_sales', $this->tenantA->id, $this->outletA->id);

        $this->assertSame('SUCCESS', $res['status']);
        $this->assertArrayHasKey('explainability', $res);
        $this->assertArrayHasKey('what', $res['explainability']);
        $this->assertArrayHasKey('why', $res['explainability']);
        $this->assertArrayHasKey('calculation', $res['explainability']);
        $this->assertArrayHasKey('evidence', $res['explainability']);
        $this->assertArrayHasKey('limitations', $res['explainability']);
        $this->assertArrayHasKey('source', $res['explainability']);
        $this->assertArrayHasKey('time_period', $res['explainability']);

        // Governance tags
        $this->assertSame('PROPOSER_ONLY', $res['governance']['ai_role']);
        $this->assertFalse($res['governance']['mutation_allowed']);
        $this->assertSame('ActionPolicyService', $res['governance']['approval_firewall']);
    }

    public function test_demand_forecast_tool_dispatch(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Kopi Kenangan Senja',
            'category'  => 'Minuman',
            'price'     => 18000,
            'stock'     => 100,
        ]);

        $res = $this->orchestrator->dispatchTool('get_demand_forecast', $this->tenantA->id, $this->outletA->id, [
            'menu_id' => $menu->id,
        ]);

        $this->assertSame('SUCCESS', $res['status']);
        $this->assertArrayHasKey('forecast', $res['data']);
        $this->assertSame('MenuDemandForecastService', $res['explainability']['source']);
    }

    public function test_action_firewall_integration_via_proposal_tool(): void
    {
        // Valid business action: expense.create
        $res = $this->orchestrator->dispatchTool('prepare_action_proposal', $this->tenantA->id, $this->outletA->id, [
            'action'     => 'expense.create',
            'parameters' => [
                'name'   => 'Beli Kertas Struk 80mm',
                'amount' => 75000,
            ],
        ]);

        $this->assertSame('SUCCESS', $res['status']);
        $proposalData = $res['data'];
        $this->assertNotNull($proposalData['proposal_id']);
        $this->assertSame(ActionPolicyService::DECISION_REQUIRES_APPROVAL, $proposalData['decision']);
        $this->assertTrue($proposalData['approval_required']);
        $this->assertSame('MANAGER', $proposalData['required_role']);
    }

    public function test_action_firewall_blocks_parameter_injection(): void
    {
        // Attempt forbidden parameter injection: user_id override
        $res = $this->orchestrator->dispatchTool('prepare_action_proposal', $this->tenantA->id, $this->outletA->id, [
            'action'     => 'expense.create',
            'parameters' => [
                'name'    => 'Hack Attempt',
                'amount'  => 50000,
                'user_id' => 9999, // FORBIDDEN!
            ],
        ]);

        $proposalData = $res['data'];
        $this->assertNull($proposalData['proposal_id']);
        $this->assertSame(ActionPolicyService::DECISION_DENY, $proposalData['decision']);
        $this->assertStringContainsString('dilarang', $proposalData['reason']);
    }

    public function test_financing_readiness_tool_includes_statutory_disclaimer(): void
    {
        $res = $this->orchestrator->dispatchTool('get_financing_readiness', $this->tenantA->id, $this->outletA->id);

        $this->assertSame('SUCCESS', $res['status']);
        $this->assertStringContainsString('Kaching TIDAK memberikan pinjaman', $res['explainability']['limitations']);
    }

    public function test_unsupported_tool_name_fails_safely(): void
    {
        $res = $this->orchestrator->dispatchTool('arbitrary_malicious_tool', $this->tenantA->id, $this->outletA->id);

        $this->assertSame('UNSUPPORTED_TOOL', $res['status']);
    }

    public function test_financial_performance_intelligence_tool_dispatch(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Ayam Bakar Madu',
            'category'  => 'Makanan',
            'price'     => 35000,
            'cost_price' => 20000,
            'stock'     => 50,
        ]);

        $res = $this->orchestrator->dispatchTool('get_financial_performance_intelligence', $this->tenantA->id, $this->outletA->id, [
            'observation_window_days' => 90,
        ]);

        $this->assertSame('SUCCESS', $res['status']);
        $this->assertArrayHasKey('data', $res);
        $this->assertSame('FinancialPerformanceIntelligenceService', $res['data']['source']);
        $this->assertArrayHasKey('summary', $res['data']);
        $this->assertArrayHasKey('state_distribution', $res['data']);
        $this->assertArrayHasKey('items', $res['data']);
        $this->assertArrayHasKey('explainability', $res);
        $this->assertSame('PROPOSER_ONLY', $res['governance']['ai_role']);
        $this->assertFalse($res['governance']['mutation_allowed']);
    }

    public function test_financial_performance_intelligence_state_filtering(): void
    {
        $menuA = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Dormant Menu A',
            'category'  => 'Makanan',
            'price'     => 15000,
        ]);

        $res = $this->orchestrator->dispatchTool('get_financial_performance_intelligence', $this->tenantA->id, $this->outletA->id, [
            'performance_state' => 'DORMANT_CATALOG_ITEM',
        ]);

        $this->assertSame('SUCCESS', $res['status']);
        $items = $res['data']['items'];
        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertSame('DORMANT_CATALOG_ITEM', $item['performance_state']);
        }
    }

    public function test_financial_performance_intelligence_menu_id_filtering(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Single Filtered Menu',
            'category'  => 'Makanan',
            'price'     => 25000,
        ]);

        $res = $this->orchestrator->dispatchTool('get_financial_performance_intelligence', $this->tenantA->id, $this->outletA->id, [
            'menu_id' => $menu->id,
        ]);

        $this->assertSame('SUCCESS', $res['status']);
        $items = $res['data']['items'];
        $this->assertCount(1, $items);
        $this->assertSame($menu->id, $items[0]['menu_id']);
    }

    public function test_financial_performance_intelligence_empty_catalog_fails_closed(): void
    {
        $res = $this->orchestrator->dispatchTool('get_financial_performance_intelligence', $this->tenantB->id, $this->outletB->id);

        $this->assertSame('INSUFFICIENT_EVIDENCE', $res['data']['status']);
        $this->assertEmpty($res['data']['items']);
    }

    public function test_dispatch_cash_flow_forecast(): void
    {
        $res = $this->orchestrator->dispatchTool('get_cash_flow_forecast', $this->tenantA->id, $this->outletA->id);

        $this->assertSame('SUCCESS', $res['status']);
        $this->assertSame('CashFlowForecastService::forecast', $res['explainability']['source']);
        $this->assertArrayHasKey('forecast_period', $res['data']);
        $this->assertArrayHasKey('limitations', $res['data']);
    }

    public function test_dispatch_financial_scenario(): void
    {
        $res = $this->orchestrator->dispatchTool('get_financial_scenario', $this->tenantA->id, $this->outletA->id, [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10.0,
        ]);

        $this->assertSame('SUCCESS', $res['status']);
        $this->assertSame('FinancialScenarioService::simulate', $res['explainability']['source']);
        $this->assertArrayHasKey('status', $res['data']);
    }

    public function test_dispatch_financial_action_recommendations(): void
    {
        $res = $this->orchestrator->dispatchTool('get_financial_action_recommendations', $this->tenantA->id, $this->outletA->id);

        $this->assertSame('SUCCESS', $res['status']);
        $this->assertSame('FinancialActionRecommendationService::generateRecommendations', $res['explainability']['source']);
        $this->assertIsArray($res['data']);
    }

    public function test_dispatch_menu_margin_candidates(): void
    {
        $menu = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Es Teh Manis',
            'category'   => 'Minuman',
            'price'      => 5000,
            'cost_price' => 4500,
        ]);

        $res = $this->orchestrator->dispatchTool('get_menu_margin_candidates', $this->tenantA->id, $this->outletA->id);

        $this->assertSame('SUCCESS', $res['status']);
        $this->assertSame('MenuMarginActionBridgeService::getDiagnosticCandidates', $res['explainability']['source']);
        $this->assertIsArray($res['data']);
    }

    public function test_dispatch_financial_evidence_fails_closed_safely(): void
    {
        $res = $this->orchestrator->dispatchTool('get_financial_evidence', $this->tenantA->id, $this->outletA->id);

        $this->assertSame('SUCCESS', $res['status']);
        $this->assertSame('FinancialEvidenceService', $res['explainability']['source']);
        $this->assertSame('UNSUPPORTED_DIRECT_AGGREGATION', $res['data']['status']);
        $this->assertSame('REQUIRES_CONTRACT_SPECIFICATION', $res['data']['code']);
    }

    public function test_dispatch_sales_summary_yesterday(): void
    {
        $yesterday = \Carbon\Carbon::yesterday('Asia/Jakarta')->format('Y-m-d 14:00:00');
        DB::table('transactions')->insert([
            'user_id'                 => $this->tenantA->id,
            'outlet_id'               => $this->outletA->id,
            'app_uuid'                => 'tx-orch-yst-01',
            'official_invoice_number' => 'INV-ORCH-YST',
            'total_amount'            => 450000,
            'pay_amount'              => 450000,
            'payment_method'          => 'cash',
            'status'                  => 'Selesai',
            'created_at_device'       => $yesterday,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        $res = $this->orchestrator->dispatchTool('get_sales_summary', $this->tenantA->id, $this->outletA->id, [
            'period' => 'yesterday',
        ]);

        $this->assertSame('SUCCESS', $res['status']);
        $this->assertSame(450000.0, (float) $res['data']['revenue']);
        $this->assertSame(1, (int) $res['data']['records_count']);
        $this->assertSame('yesterday', $res['data']['period']);
        $this->assertStringContainsString('Rp 450.000', $res['data']['formatted_sales']);
    }

    public function test_dispatch_top_selling_products_ranking(): void
    {
        $nowUtc = \Carbon\Carbon::now('Asia/Jakarta')->format('Y-m-d 11:00:00');
        $txId = DB::table('transactions')->insertGetId([
            'user_id'                 => $this->tenantA->id,
            'outlet_id'               => $this->outletA->id,
            'app_uuid'                => 'tx-top-orch-01',
            'official_invoice_number' => 'INV-TOP-ORCH',
            'total_amount'            => 200000,
            'pay_amount'              => 200000,
            'payment_method'          => 'cash',
            'status'                  => 'Selesai',
            'created_at_device'       => $nowUtc,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        DB::table('transaction_items')->insert([
            [
                'transaction_id'  => $txId,
                'menu_name'       => 'Kopi Susu Gula Aren',
                'qty'             => 10,
                'price'           => 15000,
                'cost_price'      => 8000,
                'status'          => 'Selesai',
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
            [
                'transaction_id'  => $txId,
                'menu_name'       => 'Croissant Cokelat',
                'qty'             => 2,
                'price'           => 25000,
                'cost_price'      => 12000,
                'status'          => 'Selesai',
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
        ]);

        $res = $this->orchestrator->dispatchTool('get_top_selling_products', $this->tenantA->id, $this->outletA->id, [
            'period'  => 'today',
            'limit'   => 5,
            'sort_by' => 'quantity',
        ]);

        $this->assertSame('SUCCESS', $res['status']);
        $items = $res['data']['items'];
        $this->assertCount(2, $items);
        $this->assertSame('Kopi Susu Gula Aren', $items[0]['menu_name']);
        $this->assertSame(10.0, (float) $items[0]['qty_sold']);
        $this->assertSame('Croissant Cokelat', $items[1]['menu_name']);
        $this->assertSame(2.0, (float) $items[1]['qty_sold']);
    }

    public function test_sales_summary_empty_period_returns_deterministic_zero(): void
    {
        $res = $this->orchestrator->dispatchTool('get_sales_summary', $this->tenantA->id, $this->outletA->id, [
            'period' => 'yesterday',
        ]);

        $this->assertSame('SUCCESS', $res['status']);
        $this->assertSame(0.0, (float) $res['data']['revenue']);
        $this->assertSame(0, (int) $res['data']['records_count']);
        $this->assertSame('Rp 0', $res['data']['formatted_sales']);
    }

    public function test_dispatch_suppliers_tenant_isolation(): void
    {
        $itemA = \App\Models\Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Biji Kopi Arabika',
            'sku'        => 'BJI-001',
            'cost_price' => 50000,
            'base_uom'   => 'GRAM',
            'is_active'  => true,
        ]);

        $itemB = \App\Models\Item::create([
            'outlet_id'  => $this->outletB->id,
            'name'       => 'Susu Segar',
            'sku'        => 'SSU-001',
            'cost_price' => 20000,
            'base_uom'   => 'ML',
            'is_active'  => true,
        ]);

        // Create supplier for Tenant A
        $supA = \App\Models\Supplier::create([
            'name'           => 'Supplier Kopi Nusantara',
            'contact_person' => 'Budi Santoso',
            'phone'          => '08123456789',
            'email'          => 'budi@kopinusantara.id',
            'is_active'      => true,
        ]);
        $supA->items()->attach($itemA->id, ['supplier_price' => 50000]);

        // Create supplier for Tenant B
        $supB = \App\Models\Supplier::create([
            'name'           => 'Supplier Susu Murni Jaya',
            'contact_person' => 'Dewi Lestari',
            'phone'          => '08987654321',
            'is_active'      => true,
        ]);
        $supB->items()->attach($itemB->id, ['supplier_price' => 20000]);

        // Query as Tenant A
        $resA = $this->orchestrator->dispatchTool('get_suppliers', $this->tenantA->id, $this->outletA->id);
        $this->assertSame('SUCCESS', $resA['status']);
        $this->assertSame(1, $resA['data']['count']);
        $this->assertSame('Supplier Kopi Nusantara', $resA['data']['suppliers'][0]['name']);
        $this->assertCount(1, $resA['data']['suppliers'][0]['items']);
        $this->assertSame('Biji Kopi Arabika', $resA['data']['suppliers'][0]['items'][0]['name']);

        // Query as Tenant B
        $resB = $this->orchestrator->dispatchTool('get_suppliers', $this->tenantB->id, $this->outletB->id);
        $this->assertSame('SUCCESS', $resB['status']);
        $this->assertSame(1, $resB['data']['count']);
        $this->assertSame('Supplier Susu Murni Jaya', $resB['data']['suppliers'][0]['name']);
        $this->assertCount(1, $resB['data']['suppliers'][0]['items']);
        $this->assertSame('Susu Segar', $resB['data']['suppliers'][0]['items'][0]['name']);
    }

    public function test_dispatch_suppliers_empty_returns_clean_message(): void
    {
        $res = $this->orchestrator->dispatchTool('get_suppliers', $this->tenantA->id, $this->outletA->id);
        $this->assertSame('SUCCESS', $res['status']);
        $this->assertSame(0, $res['data']['count']);
        $this->assertSame('Belum ada data supplier yang terdaftar pada akun Anda.', $res['data']['message']);
    }

    public function test_dispatch_restock_recommendations_tool(): void
    {
        $res = $this->orchestrator->dispatchTool('get_restock_recommendations', $this->tenantA->id, $this->outletA->id, [
            'horizon_days' => 7,
            'limit'        => 10,
        ]);

        $this->assertSame('SUCCESS', $res['status']);
        $this->assertSame('PROPOSER_ONLY', $res['governance']['ai_role']);
        $this->assertFalse($res['governance']['mutation_allowed']);
        $this->assertArrayHasKey('recommendations', $res['data']);
        $this->assertArrayHasKey('summary', $res['data']);
        $this->assertSame('Sistem Manajemen Restock Kaching POS', $res['explainability']['source']);
        $this->assertStringNotContainsString('item_supplier', $res['explainability']['calculation']);
        $this->assertStringNotContainsString('suggested_qty', $res['explainability']['calculation']);
        $this->assertStringNotContainsString('SmartRestockOrchestratorService', $res['explainability']['source']);
    }
}
