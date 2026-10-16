<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\ChatbotController;
use App\Models\AuditLog;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Financial\FinancialInvestigationService;
use App\Services\Financial\FinancingCapacitySimulatorService;
use App\Services\Policy\ActionPolicyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutonomousFinancialAgentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenantA;
    protected Outlet $outletA;
    protected User $tenantB;
    protected Outlet $outletB;

    protected FinancialInvestigationService $investigationService;
    protected FinancingCapacitySimulatorService $financingService;
    protected ActionPolicyService $actionPolicyService;
    protected ChatbotController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.groq.key' => 'gsk_test_fake_key_investigation_998877']);

        $this->tenantA = User::factory()->create([
            'email'             => 'tenant_a@kaching.test',
            'role'              => 'owner',
            'subscription_tier' => 'pro',
            'account_status'    => 'active',
        ]);

        $this->outletA = Outlet::create([
            'user_id'   => $this->tenantA->id,
            'name'      => 'Outlet Pusat Tenant A',
            'device_id' => 'DEV-A-01',
        ]);

        $this->tenantB = User::factory()->create([
            'email'             => 'tenant_b@kaching.test',
            'role'              => 'owner',
            'subscription_tier' => 'pro',
            'account_status'    => 'active',
        ]);

        $this->outletB = Outlet::create([
            'user_id'   => $this->tenantB->id,
            'name'      => 'Outlet Rahasia Tenant B',
            'device_id' => 'DEV-B-01',
        ]);

        $this->investigationService = app(FinancialInvestigationService::class);
        $this->financingService     = app(FinancingCapacitySimulatorService::class);
        $this->actionPolicyService  = app(ActionPolicyService::class);
        $this->controller           = new ChatbotController();
    }

    protected function seedTenantAOperatingData(?Carbon $anchor = null): void
    {
        $now = $anchor ?? Carbon::now('Asia/Jakarta');

        // Periode Sebelumnya (Days 31-60): Omzet tinggi, expense terkontrol
        for ($i = 35; $i <= 55; $i += 5) {
            $dt = $now->copy()->subDays($i)->format('Y-m-d H:i:s');
            $tId = DB::table('transactions')->insertGetId([
                'user_id'                 => $this->tenantA->id,
                'outlet_id'               => $this->outletA->id,
                'app_uuid'                => 'tx-prev-' . uniqid('', true),
                'official_invoice_number' => 'INV-PREV-' . $i,
                'total_amount'            => 10000000.0,
                'pay_amount'              => 10000000.0,
                'payment_method'          => 'cash',
                'status'                  => 'Selesai',
                'created_at_device'       => $dt,
                'created_at'              => $dt,
                'updated_at'              => $dt,
            ]);

            DB::table('transaction_items')->insert([
                'transaction_id' => $tId,
                'menu_id'        => 101,
                'menu_name'      => 'Kopi Susu Signature',
                'qty'            => 100,
                'price'          => 100000.0,
                'cost_price'     => 40000.0,
                'created_at'     => $dt,
                'updated_at'     => $dt,
            ]);
        }

        // Periode Berjalan (Days 1-30): Omzet turun atau beban melonjak
        for ($i = 5; $i <= 25; $i += 5) {
            $dt = $now->copy()->subDays($i)->format('Y-m-d H:i:s');
            $tId = DB::table('transactions')->insertGetId([
                'user_id'                 => $this->tenantA->id,
                'outlet_id'               => $this->outletA->id,
                'app_uuid'                => 'tx-curr-' . uniqid('', true),
                'official_invoice_number' => 'INV-CURR-' . $i,
                'total_amount'            => 6000000.0,
                'pay_amount'              => 6000000.0,
                'payment_method'          => 'cash',
                'status'                  => 'Selesai',
                'created_at_device'       => $dt,
                'created_at'              => $dt,
                'updated_at'              => $dt,
            ]);

            DB::table('transaction_items')->insert([
                'transaction_id' => $tId,
                'menu_id'        => 101,
                'menu_name'      => 'Kopi Susu Signature',
                'qty'            => 60,
                'price'          => 100000.0,
                'cost_price'     => 45000.0,
                'created_at'     => $dt,
                'updated_at'     => $dt,
            ]);

            // Lonjakan biaya operasional
            DB::table('expenses')->insert([
                'user_id'    => $this->tenantA->id,
                'outlet_id'  => $this->outletA->id,
                'name'       => 'Biaya Listrik & Operasional Naik',
                'amount'     => 3000000.0,
                'category'   => 'Operasional',
                'date'       => $now->copy()->subDays($i)->format('Y-m-d'),
                'created_at' => $dt,
                'updated_at' => $dt,
            ]);
        }
    }

    // =========================================================================
    // SECTION 7: ACCEPTANCE CRITERIA 5.16-A (INVESTIGATION AGENT)
    // =========================================================================

    public function testPhase516A_Q1_InvestigateKenapaProfitTurun(): void
    {
        $this->seedTenantAOperatingData();

        $result = $this->investigationService->investigate(
            (int) $this->tenantA->id,
            (int) $this->outletA->id,
            'Kenapa profit saya turun?'
        );

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals(FinancialInvestigationService::TYPE_PROFIT_DECLINE, $result['investigation_type']);
        $this->assertNotEmpty($result['root_causes']);
        $this->assertNotEmpty($result['recommendations']);
        $this->assertGreaterThanOrEqual(0.70, $result['confidence_score']);

        $output = $result['formatted_output'];
        $this->assertStringContainsString('FINANCIAL HEALTH', $output);
        $this->assertStringContainsString('CHANGE', $output);
        $this->assertStringContainsString('ROOT CAUSE', $output);
        $this->assertStringContainsString('CONFIDENCE', $output);
        $this->assertStringContainsString('ACTIONABLE RECOMMENDATION', $output);
    }

    public function testPhase516A_Q2_InvestigateKenapaOmzetTurun(): void
    {
        $this->seedTenantAOperatingData();

        $result = $this->investigationService->investigate(
            (int) $this->tenantA->id,
            (int) $this->outletA->id,
            'Kenapa omzet turun?'
        );

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals(FinancialInvestigationService::TYPE_SALES_DROP, $result['investigation_type']);
        $this->assertStringContainsString('Revenue', $result['formatted_output']);
        $this->assertNotEmpty($result['root_causes']);
    }

    public function testPhase516A_Q3_InvestigateApakahMasalahDariBiaya(): void
    {
        $this->seedTenantAOperatingData();

        $result = $this->investigationService->investigate(
            (int) $this->tenantA->id,
            (int) $this->outletA->id,
            'Apakah masalah saya dari biaya?'
        );

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals(FinancialInvestigationService::TYPE_EXPENSE_SURGE, $result['investigation_type']);
        $this->assertStringContainsString('Operasional', $result['formatted_output']);
    }

    public function testPhase516A_Q4_InvestigateKenapaCashMenipis(): void
    {
        $this->seedTenantAOperatingData();

        $result = $this->investigationService->investigate(
            (int) $this->tenantA->id,
            (int) $this->outletA->id,
            'Kenapa cash saya menipis?'
        );

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals(FinancialInvestigationService::TYPE_CASH_DRAIN, $result['investigation_type']);
        $this->assertStringContainsString('Kas', $result['formatted_output']);
    }

    public function testPhase516A_Q5_InvestigateApakahStokBerpengaruh(): void
    {
        $this->seedTenantAOperatingData();

        $result = $this->investigationService->investigate(
            (int) $this->tenantA->id,
            (int) $this->outletA->id,
            'Apakah stok berpengaruh?'
        );

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals(FinancialInvestigationService::TYPE_STOCK_IMPACT, $result['investigation_type']);
        $this->assertStringContainsString('stok', strtolower($result['formatted_output']));
    }

    public function testPhase516A_Q6_InvestigateTenantIsolationProbeFailsClosed(): void
    {
        $request = Request::create('/chat', 'POST', [
            'message'   => 'Kenapa profit saya turun?',
            'outlet_id' => $this->outletB->id,
        ]);
        $request->setUserResolver(fn() => $this->tenantA);

        $response = $this->controller->executeDeterministicFallback(
            $this->tenantA,
            $request,
            'Kenapa profit saya turun?'
        );

        $this->assertEquals(403, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertEquals('UNAUTHORIZED_OUTLET', $payload['code']);
    }

    public function testPhase516A_Q7_InvestigateInsufficientEvidenceStatesClearly(): void
    {
        $result = $this->investigationService->investigate(
            (int) $this->tenantA->id,
            (int) $this->outletA->id,
            'Kenapa laba saya turun?'
        );

        $this->assertEquals('INSUFFICIENT_EVIDENCE', $result['status']);
        $this->assertStringContainsString('DATA HISTORIS TIDAK MENCUKUPI', $result['formatted_output']);
        $this->assertStringContainsString('Minimal 7-14 hari data transaksi', $result['formatted_output']);
    }

    // =========================================================================
    // SECTION 13: ACCEPTANCE CRITERIA 5.16-B (FINANCING SIMULATOR)
    // =========================================================================

    public function testPhase516B_1_SimulatePinjam50Juta(): void
    {
        $this->seedTenantAOperatingData();

        $result = $this->financingService->simulate(
            (int) $this->tenantA->id,
            (int) $this->outletA->id,
            ['amount' => 50000000.0, 'tenor' => 12]
        );

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals(50000000.0, $result['input']['loan_amount']);
        $this->assertEquals(12, $result['input']['tenor_months']);
        $this->assertGreaterThan(0, $result['monthly_installment']);
        $this->assertGreaterThan(0, $result['safe_monthly_payment']);
        $this->assertArrayHasKey('dscr', $result);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('risk_level', $result);

        $output = $result['formatted_output'];
        $this->assertStringContainsString('SIMULASI KAPASITAS PEMBIAYAAN', $output);
        $this->assertStringContainsString('REKOMENDASI ANGSURAN AMAN', $output);
        $this->assertStringContainsString('STRESS TEST', $output);
    }

    public function testPhase516B_2_SimulateTenor12Bulan(): void
    {
        $this->seedTenantAOperatingData();

        $result = $this->financingService->simulate(
            (int) $this->tenantA->id,
            (int) $this->outletA->id,
            ['amount' => 30000000.0, 'tenor' => 12]
        );

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals(12, $result['input']['tenor_months']);
        $this->assertStringContainsString('12 bulan', $result['formatted_output']);
    }

    public function testPhase516B_3_Compare12Vs24Bulan(): void
    {
        $this->seedTenantAOperatingData();

        $result = $this->financingService->simulate(
            (int) $this->tenantA->id,
            (int) $this->outletA->id,
            ['amount' => 50000000.0, 'tenor' => 12]
        );

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertArrayHasKey('tenor_comparison', $result);
        $this->assertCount(3, $result['tenor_comparison']);

        $item12 = collect($result['tenor_comparison'])->firstWhere('tenor_months', 12);
        $item24 = collect($result['tenor_comparison'])->firstWhere('tenor_months', 24);
        $this->assertNotNull($item12);
        $this->assertNotNull($item24);

        $installment12 = $item12['installment'];
        $installment24 = $item24['installment'];
        $this->assertGreaterThan($installment24, $installment12);

        $output = $result['formatted_output'];
        $this->assertStringContainsString('Perbandingan Tenor', $output);
        $this->assertStringContainsString('12 bln', $output);
        $this->assertStringContainsString('24 bln', $output);
    }

    public function testPhase516B_4_EvaluateApakahMampuBayar(): void
    {
        $this->seedTenantAOperatingData();

        $result = $this->financingService->simulate(
            (int) $this->tenantA->id,
            (int) $this->outletA->id,
            ['amount' => 500000000.0, 'tenor' => 6]
        );

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertContains($result['verdict'], ['TIDAK MAMPU', 'BERISIKO TINGGI', 'KURANG DISARANKAN']);
        $this->assertLessThan(1.0, $result['dscr']);
        $this->assertStringContainsString('TIDAK MAMPU', $result['formatted_output']);
    }

    public function testPhase516B_5_DataCashflowTidakCukupStatesGracefully(): void
    {
        $result = $this->financingService->simulate(
            (int) $this->tenantA->id,
            (int) $this->outletA->id,
            ['amount' => 50000000.0, 'tenor' => 12]
        );

        $this->assertEquals('INSUFFICIENT_DATA', $result['status']);
        $this->assertStringContainsString('DATA ARUS KAS TIDAK MENCUKUPI', $result['formatted_output']);
    }

    public function testPhase516B_6_NegativeOrInvalidInputFailsClosed(): void
    {
        $resultAmount = $this->financingService->simulate(
            (int) $this->tenantA->id,
            (int) $this->outletA->id,
            ['amount' => -1000.0, 'tenor' => 12]
        );
        $this->assertTrue(in_array($resultAmount['status'], ['ERROR', 'INVALID_INPUT'], true));
        $this->assertEquals('INVALID_INPUT', $resultAmount['code']);

        $resultTenor = $this->financingService->simulate(
            (int) $this->tenantA->id,
            (int) $this->outletA->id,
            ['amount' => 50000000.0, 'tenor' => 0]
        );
        $this->assertTrue(in_array($resultTenor['status'], ['ERROR', 'INVALID_INPUT'], true));
        $this->assertEquals('INVALID_INPUT', $resultTenor['code']);
    }

    public function testPhase516B_7_TenantIsolationProbeFailsClosed(): void
    {
        $request = Request::create('/chat', 'POST', [
            'message'   => 'Kalau saya pinjam Rp50 juta?',
            'outlet_id' => $this->outletB->id,
        ]);
        $request->setUserResolver(fn() => $this->tenantA);

        $response = $this->controller->executeDeterministicFallback(
            $this->tenantA,
            $request,
            'Kalau saya pinjam Rp50 juta?'
        );

        $this->assertEquals(403, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertEquals('UNAUTHORIZED_OUTLET', $payload['code']);
    }

    // =========================================================================
    // SECTION 18: ACCEPTANCE CRITERIA 5.16-C (ACTION PROPOSAL & APPROVAL)
    // =========================================================================

    public function testPhase516C_1_GenerateStandardizedActionProposal(): void
    {
        $recommendation = [
            'title'       => 'Optimasi Harga Menu Kopi Susu',
            'action_type' => 'PRICE_ADJUSTMENT',
            'description' => 'Menaikkan harga Kopi Susu sebesar 5% untuk menutupi kenaikan HPP bahan baku susu.',
            'payload'     => [
                'menu_id'        => 101,
                'target_field'   => 'price',
                'current_value'  => 18000.0,
                'proposed_value' => 18900.0,
                'change_pct'     => 5.0,
            ],
            'estimated_impact' => '+Rp 450.000 laba kotor/bulan',
            'evidence'         => 'HPP susu naik 12% dalam 30 hari terakhir',
            'confidence'       => 0.88,
        ];

        $proposal = $this->actionPolicyService->buildProposalFromRecommendation(
            (int) $this->tenantA->id,
            (int) $this->outletA->id,
            $recommendation
        );

        $this->assertEquals('PROPOSED', $proposal['status']);
        $this->assertEquals('PRICE_ADJUSTMENT', $proposal['action_type']);
        $this->assertEquals(18000.0, $proposal['current_state']);
        $this->assertEquals(18900.0, $proposal['proposed_state']);
        $this->assertNotEmpty($proposal['estimated_impact']);
        $this->assertNotEmpty($proposal['evidence']);
        $this->assertEquals('LOW', $proposal['risk_level']);
        $this->assertFalse($proposal['requires_approval']);
        $this->assertStringContainsString('Human Review Required', $proposal['disclaimer']);
    }

    public function testPhase516C_2_AgentProducesProposalOnlyZeroDatabaseMutation(): void
    {
        $menu = Menu::create([
            'user_id'     => $this->tenantA->id,
            'outlet_id'   => $this->outletA->id,
            'name'        => 'Espresso Romano',
            'category'    => 'Minuman',
            'price'       => 20000.0,
            'cost_price'  => 8000.0,
            'is_active'   => true,
        ]);

        $recommendation = [
            'title'       => 'Penyesuaian Harga Espresso Romano',
            'action_type' => 'PRICE_ADJUSTMENT',
            'description' => 'Naikkan harga Espresso Romano menjadi Rp22.000',
            'payload'     => [
                'menu_id'        => $menu->id,
                'target_field'   => 'price',
                'current_value'  => 20000.0,
                'proposed_value' => 22000.0,
                'change_pct'     => 10.0,
            ],
            'estimated_impact' => '+Rp 200.000 laba',
            'evidence'         => 'Kompresi margin 8%',
            'confidence'       => 0.85,
        ];

        $proposal = $this->actionPolicyService->buildProposalFromRecommendation(
            (int) $this->tenantA->id,
            (int) $this->outletA->id,
            $recommendation
        );

        $this->assertEquals('PROPOSED', $proposal['status']);

        // VERIFIKASI ZERO MUTATION: Database menu price HARUS tetap Rp20.000
        $menuFresh = Menu::find($menu->id);
        $this->assertEquals(20000.0, (float) $menuFresh->price);
    }

    public function testPhase516C_3_ActionRequiresExplicitApprovalForHighRisk(): void
    {
        $highRiskRec = [
            'title'       => 'Pemotongan Plafon Biaya Promosi Ekstrem',
            'action_type' => 'BUDGET_CAP',
            'description' => 'Memangkas anggaran iklan sebesar 60%',
            'payload'     => [
                'target_field'   => 'monthly_cap',
                'current_value'  => 10000000.0,
                'proposed_value' => 4000000.0,
                'change_pct'     => -60.0,
            ],
            'estimated_impact' => 'Penghematan kas Rp6.000.000',
            'evidence'         => 'Burn rate melebihi runway aman',
            'confidence'       => 0.90,
        ];

        $proposal = $this->actionPolicyService->buildProposalFromRecommendation(
            (int) $this->tenantA->id,
            (int) $this->outletA->id,
            $highRiskRec
        );

        $this->assertEquals('HIGH', $proposal['risk_level']);
        $this->assertTrue($proposal['requires_approval']);
    }

    public function testPhase516C_4_UnauthorizedTenantCannotApproveAnotherProposal(): void
    {
        $proposal = [
            'id'                => 'prop-test-tenant-a-123',
            'user_id'           => $this->tenantA->id,
            'outlet_id'         => $this->outletA->id,
            'action_type'       => 'PRICE_ADJUSTMENT',
            'status'            => 'PROPOSED',
            'requires_approval' => true,
        ];

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->actionPolicyService->verifyTenantAuthorization(
            $proposal,
            (int) $this->tenantB->id
        );
    }

    public function testPhase516C_5_AuditLogRecordedUponExecution(): void
    {
        $proposal = [
            'id'                => 'prop-exec-777',
            'action_type'       => 'PRICE_ADJUSTMENT',
            'user_id'           => $this->tenantA->id,
            'outlet_id'         => $this->outletA->id,
            'current_state'     => 15000.0,
            'proposed_state'    => 16500.0,
            'estimated_impact'  => 'Kenaikan margin Rp150.000',
        ];

        $logEntry = $this->actionPolicyService->recordAuditLog(
            $proposal,
            (int) $this->tenantA->id,
            '123456'
        );

        $this->assertTrue($logEntry);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->tenantA->id,
            'action'  => 'COPILOT_ACTION_EXECUTED',
        ]);
    }
}
