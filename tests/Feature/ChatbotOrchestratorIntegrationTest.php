<?php

namespace Tests\Feature;

use App\Http\Controllers\ChatbotController;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotOrchestratorIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenantA;
    protected User $tenantB;
    protected Outlet $outletA;
    protected Outlet $outletB;
    protected ChatbotController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.groq.key' => 'gsk_test_fake_api_key_123456789']);

        $this->controller = app(ChatbotController::class);

        $this->tenantA = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'pro',
            'account_status'    => 'active',
        ]);

        $this->tenantB = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'pro',
            'account_status'    => 'active',
        ]);

        $this->outletA = Outlet::create([
            'user_id'   => $this->tenantA->id,
            'name'      => 'Outlet Sentral Tenant A',
            'device_id' => 'DEV-A-01',
            'timezone'  => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $this->outletB = Outlet::create([
            'user_id'   => $this->tenantB->id,
            'name'      => 'Outlet Sentral Tenant B',
            'device_id' => 'DEV-B-01',
            'timezone'  => 'Asia/Jakarta',
            'is_active' => true,
        ]);
    }

    /**
     * Helper to mock Groq API with a specific tool call followed by final assistant response
     */
    protected function mockGroqToolCall(string $toolName, array $toolArgs = [], string $finalReply = 'Jawaban AI setelah eksekusi alat.'): void
    {
        Http::fake([
            'https://api.groq.com/*' => Http::sequence()
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'role'       => 'assistant',
                                'content'    => null,
                                'tool_calls' => [
                                    [
                                        'id'       => 'call_orch_' . uniqid(),
                                        'type'     => 'function',
                                        'function' => [
                                            'name'      => $toolName,
                                            'arguments' => json_encode($toolArgs),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200)
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'role'    => 'assistant',
                                'content' => $finalReply,
                            ],
                        ],
                    ],
                ], 200),
        ]);
    }

    /**
     * 1. Authenticated Copilot request reaches orchestrator and exposes 14 tools
     */
    public function test_01_authenticated_copilot_request_reaches_orchestrator(): void
    {
        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'Halo dari Groq AI.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->tenantA)->postJson('/copilot/chat', [
            'message' => 'Halo Kaching Copilot',
        ]);

        $response->assertStatus(200);

        Http::assertSent(function ($request) {
            $tools = $request->data()['tools'] ?? [];
            $names = array_map(fn($t) => $t['function']['name'] ?? '', $tools);

            $required14 = [
                'get_today_sales',
                'get_financial_summary',
                'get_risk_radar',
                'get_cash_flow_forecast',
                'get_demand_forecast',
                'get_stock_risk',
                'get_material_price_forecast',
                'get_financial_scenario',
                'get_financial_action_recommendations',
                'get_menu_margin_candidates',
                'prepare_action_proposal',
                'get_financing_readiness',
                'get_financial_evidence',
                'get_market_signals',
            ];

            foreach ($required14 as $tool) {
                $this->assertContains($tool, $names, "Tool '{$tool}' wajib terdaftar dalam schema Copilot.");
            }

            return true;
        });
    }

    /**
     * 2. get_today_sales dispatches correctly
     */
    public function test_02_get_today_sales_dispatches_correctly(): void
    {
        $todayJakarta = Carbon::today('Asia/Jakarta')->format('Y-m-d 10:00:00');
        DB::table('transactions')->insert([
            'user_id'                 => $this->tenantA->id,
            'outlet_id'               => $this->outletA->id,
            'app_uuid'                => 'tx-sales-orch-01',
            'official_invoice_number' => 'INV-ORCH-01',
            'total_amount'            => 1250000,
            'pay_amount'              => 1250000,
            'payment_method'          => 'cash',
            'status'                  => 'Selesai',
            'created_at_device'       => $todayJakarta,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        $this->mockGroqToolCall('get_today_sales', []);

        $response = $this->actingAs($this->tenantA)
            ->withHeader('Outlet-ID', (string) $this->outletA->id)
            ->postJson('/copilot/chat', [
                'message' => 'Cek penjualan hari ini',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('success', $response->json('status'));
        $this->assertNotNull($response->json('financial_card'));
        $this->assertEquals('summary', $response->json('financial_card.type'));
        $this->assertEquals(1250000, $response->json('financial_card.data.today_sales'));
    }

    /**
     * 3. get_demand_forecast dispatches correctly
     */
    public function test_03_get_demand_forecast_dispatches_correctly(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Ayam Bakar Madu',
            'category'  => 'Makanan',
            'price'     => 25000,
            'stock'     => 50,
        ]);

        $request = new Request();
        $res = $this->controller->dispatchOrchestrationTool(
            $this->tenantA,
            $request,
            'get_demand_forecast',
            ['menu_id' => $menu->id]
        );

        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertArrayHasKey('explainability', $res);
        $this->assertArrayHasKey('governance', $res);
        $this->assertSame('PROPOSER_ONLY', $res['governance']['ai_role']);
        $this->assertFalse($res['governance']['mutation_allowed']);
        $this->assertArrayHasKey('forecast', $res['data']);
        $this->assertArrayHasKey('horizon_days', $res['data']);
    }

    /**
     * 4. get_stock_risk dispatches correctly
     */
    public function test_04_get_stock_risk_dispatches_correctly(): void
    {
        $request = new Request();
        $res = $this->controller->dispatchOrchestrationTool(
            $this->tenantA,
            $request,
            'get_stock_risk',
            []
        );

        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertArrayHasKey('explainability', $res);
        $this->assertEquals('StockoutIntelligenceService', $res['explainability']['source']);
        $this->assertArrayHasKey('summary', $res['data']);
        $this->assertArrayHasKey('menus', $res['data']);
    }

    /**
     * 5. get_material_price_forecast returns DATA_BLOCKED safely
     */
    public function test_05_get_material_price_forecast_returns_data_blocked_safely(): void
    {
        $itemId = DB::table('items')->insertGetId([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Biji Kopi Arabika',
            'base_uom'   => 'kg',
            'cost_price' => 120000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = new Request();
        $res = $this->controller->dispatchOrchestrationTool(
            $this->tenantA,
            $request,
            'get_material_price_forecast',
            ['item_id' => $itemId]
        );

        $this->assertArrayHasKey('explainability', $res);
        $this->assertEquals('MaterialPriceIntelligenceService', $res['explainability']['source']);
        $this->assertEquals('PRICE_HISTORY_UNAVAILABLE', $res['data']['status']);
        $this->assertEquals('DATA_BLOCKED', $res['data']['data_state']);
    }

    /**
     * 6. get_market_signals returns available calendar signals
     */
    public function test_06_get_market_signals_returns_available_calendar_signals(): void
    {
        $request = new Request();
        $res = $this->controller->dispatchOrchestrationTool(
            $this->tenantA,
            $request,
            'get_market_signals',
            ['target_date' => '2026-08-17']
        );

        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertArrayHasKey('signals', $res['data']);
        $cal = $res['data']['signals']['calendar'];
        $this->assertEquals('Hari Kemerdekaan Republik Indonesia ke-81', $cal['holiday_name']);
        $this->assertTrue($cal['is_public_holiday']);
    }

    /**
     * 7. Tenant isolation enforced
     */
    public function test_07_tenant_isolation_enforced(): void
    {
        // Seeding 1 transaksi untuk Tenant A
        DB::table('transactions')->insert([
            'user_id'                 => $this->tenantA->id,
            'outlet_id'               => $this->outletA->id,
            'app_uuid'                => 'tx-a-01',
            'official_invoice_number' => 'INV-A-01',
            'total_amount'            => 800000,
            'pay_amount'              => 800000,
            'payment_method'          => 'cash',
            'status'                  => 'Selesai',
            'created_at_device'       => Carbon::today('Asia/Jakarta')->format('Y-m-d 10:00:00'),
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        // Request oleh Tenant B TIDAK boleh melihat transaksi Tenant A
        $request = new Request();
        $resB = $this->controller->dispatchOrchestrationTool(
            $this->tenantB,
            $request,
            'get_today_sales',
            []
        );

        $this->assertEquals('SUCCESS', $resB['status']);
        $this->assertEquals(0, $resB['data']['value']);
    }

    /**
     * 8. Outlet isolation enforced
     */
    public function test_08_outlet_isolation_enforced(): void
    {
        $outletA2 = Outlet::create([
            'user_id'   => $this->tenantA->id,
            'name'      => 'Cabang Kedua Tenant A',
            'device_id' => 'DEV-A-02',
            'timezone'  => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        DB::table('transactions')->insert([
            'user_id'                 => $this->tenantA->id,
            'outlet_id'               => $outletA2->id,
            'app_uuid'                => 'tx-a-branch2',
            'official_invoice_number' => 'INV-A-02',
            'total_amount'            => 450000,
            'pay_amount'              => 450000,
            'payment_method'          => 'cash',
            'status'                  => 'Selesai',
            'created_at_device'       => Carbon::today('Asia/Jakarta')->format('Y-m-d 10:00:00'),
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        // Query ke outletA1 harus mengabaikan penjualan outletA2
        $request = new Request();
        $request->headers->set('Outlet-ID', (string) $this->outletA->id);

        $res = $this->controller->dispatchOrchestrationTool(
            $this->tenantA,
            $request,
            'get_today_sales',
            []
        );

        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertEquals(0, $res['data']['value']);
    }

    /**
     * 9. Forged user_id rejected / ignored
     */
    public function test_09_forged_user_id_rejected_or_ignored(): void
    {
        $request = new Request();
        // LLM mencoba menyuntikkan user_id milik Tenant B
        $res = $this->controller->dispatchOrchestrationTool(
            $this->tenantA,
            $request,
            'get_today_sales',
            ['user_id' => $this->tenantB->id]
        );

        // Harus tetap diproses dengan identitas tenantA
        $this->assertEquals('SUCCESS', $res['status']);
    }

    /**
     * 10. Forged outlet_id rejected / fails closed
     */
    public function test_10_forged_outlet_id_rejected_or_ignored(): void
    {
        $request = new Request();
        // LLM atau client mencoba menyuntikkan outlet milik tenant B
        $request->headers->set('Outlet-ID', (string) $this->outletB->id);

        $res = $this->controller->dispatchOrchestrationTool(
            $this->tenantA,
            $request,
            'get_today_sales',
            ['outlet_id' => $this->outletB->id]
        );

        $this->assertEquals('ERROR', $res['status']);
        $this->assertEquals('UNAUTHORIZED_OUTLET', $res['code']);
    }

    /**
     * 11. prepare_action_proposal does not execute mutation
     */
    public function test_11_prepare_action_proposal_does_not_execute_mutation(): void
    {
        $menu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA->id,
            'name'      => 'Kopi Gula Aren',
            'category'  => 'Minuman',
            'price'     => 18000,
            'stock'     => 10,
        ]);

        $request = new Request();
        $request->headers->set('Outlet-ID', (string) $this->outletA->id);

        $initialPrice = $menu->fresh()->price;
        $this->assertEquals(18000, $initialPrice);

        $res = $this->controller->dispatchOrchestrationTool(
            $this->tenantA,
            $request,
            'prepare_action_proposal',
            [
                'action'     => 'menu.price_change',
                'parameters' => [
                    'menu_id'   => $menu->id,
                    'new_price' => 22000,
                    'reason'    => 'Penyesuaian margin',
                ],
            ]
        );

        // Verifikasi proposal terbit tapi database TIDAK bermutasi
        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertNotEmpty($res['data']['proposal_id']);
        $this->assertEquals('REQUIRES_APPROVAL', $res['data']['decision']);

        $afterPrice = $menu->fresh()->price;
        $this->assertEquals(18000, $afterPrice, 'Proposal dilarang mengubah data sebelum approval resmi.');
    }

    /**
     * 12. Explainability contract survives controller -> orchestrator flow
     */
    public function test_12_explainability_contract_survives_controller_flow(): void
    {
        $request = new Request();
        $res = $this->controller->dispatchOrchestrationTool(
            $this->tenantA,
            $request,
            'get_risk_radar',
            []
        );

        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertArrayHasKey('explainability', $res);

        $exp = $res['explainability'];
        $this->assertArrayHasKey('what', $exp);
        $this->assertArrayHasKey('why', $exp);
        $this->assertArrayHasKey('calculation', $exp);
        $this->assertArrayHasKey('evidence', $exp);
        $this->assertArrayHasKey('limitations', $exp);
        $this->assertArrayHasKey('source', $exp);
        $this->assertArrayHasKey('time_period', $exp);

        $this->assertArrayHasKey('governance', $res);
        $this->assertEquals('PROPOSER_ONLY', $res['governance']['ai_role']);
        $this->assertFalse($res['governance']['mutation_allowed']);
        $this->assertEquals('ActionPolicyService', $res['governance']['approval_firewall']);
    }

    /**
     * 13. Existing legacy Copilot behavior remains functional
     */
    public function test_13_existing_legacy_copilot_behavior_remains_functional(): void
    {
        $this->mockGroqToolCall('get_active_financial_warnings', []);

        $response = $this->actingAs($this->tenantA)->postJson('/copilot/chat', [
            'message' => 'Cek peringatan risiko keuangan saya',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('success', $response->json('status'));
        $this->assertNotNull($response->json('financial_card'));
        $this->assertEquals('warning', $response->json('financial_card.type'));
    }

    /**
     * 14. Malformed/unknown tool call fails closed
     */
    public function test_14_malformed_unknown_tool_call_fails_closed(): void
    {
        $request = new Request();
        $res = $this->controller->dispatchOrchestrationTool(
            $this->tenantA,
            $request,
            'execute_unauthorized_shell_command',
            ['cmd' => 'rm -rf /']
        );

        $this->assertEquals('ERROR', $res['status']);
        $this->assertEquals('UNKNOWN_TOOL', $res['code']);
    }

    /**
     * 15. Schema get_restock_recommendations terdaftar di controller dan lolos filter context
     */
    public function test_15_restock_tool_schema_is_registered_and_available_in_controller(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('sendMessage');

        // Verify the tool exists in registered tools on orchestrator
        $orchestrator = app(\App\Services\Financial\FinanceCopilotOrchestrationService::class);
        $registeredTools = $orchestrator->getRegisteredTools();
        $this->assertArrayHasKey('get_restock_recommendations', $registeredTools);
        $this->assertSame('ACT', $registeredTools['get_restock_recommendations']['pillar']);
        $this->assertArrayHasKey('parameters', $registeredTools['get_restock_recommendations']);

        // Verify filterToolsForContext includes get_restock_recommendations for Finance Copilot
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_restock_recommendations',
                    'description' => 'Mengambil rekomendasi restock bahan baku kritis...',
                ],
            ],
        ];

        $filtered = \App\Services\ChatbotPromptBuilder::filterToolsForContext(
            $tools,
            \App\Services\ChatbotPromptBuilder::CONTEXT_FINANCE_COPILOT,
            'pro',
            $this->tenantA
        );

        $this->assertCount(1, $filtered);
        $this->assertSame('get_restock_recommendations', $filtered[0]['function']['name']);
    }

    /**
     * 16. Tool get_restock_recommendations diarahkan ke dispatcher yang benar
     */
    public function test_16_restock_tool_routes_to_correct_dispatcher_and_returns_success(): void
    {
        $request = new Request();
        $res = $this->controller->dispatchOrchestrationTool(
            $this->tenantA,
            $request,
            'get_restock_recommendations',
            ['horizon_days' => 7]
        );

        $this->assertEquals('SUCCESS', $res['status']);
        $this->assertArrayHasKey('explainability', $res);
        $this->assertArrayHasKey('governance', $res);
        $this->assertEquals('PROPOSER_ONLY', $res['governance']['ai_role']);
        $this->assertFalse($res['governance']['mutation_allowed']);
        $this->assertSame('Sistem Manajemen Restock Kaching POS', $res['explainability']['source']);
        $this->assertStringNotContainsString('item_supplier', $res['explainability']['calculation']);
        $this->assertStringNotContainsString('SmartRestockOrchestratorService', $res['explainability']['source']);
    }

    /**
     * 17. Argumen yang mencoba menentukan tenant/outlet asing tidak dapat mengesampingkan scope server
     */
    public function test_17_restock_tool_cannot_override_tenant_outlet_scope(): void
    {
        $request = new Request();
        // Tenant A attempting to access Tenant B's outlet
        $res = $this->controller->dispatchOrchestrationTool(
            $this->tenantA,
            $request,
            'get_restock_recommendations',
            ['outlet_id' => $this->outletB->id]
        );

        $this->assertEquals('ERROR', $res['status']);
        $this->assertEquals('UNAUTHORIZED_OUTLET', $res['code']);
    }

    /**
     * 18. Chatbot call dengan tool call get_restock_recommendations dieksekusi end-to-end
     */
    public function test_18_copilot_chat_executes_restock_tool_call_end_to_end(): void
    {
        $this->mockGroqToolCall('get_restock_recommendations', ['horizon_days' => 7], 'Berikut rekomendasi restock bahan baku Anda.');

        $response = $this->actingAs($this->tenantA)->postJson('/copilot/chat', [
            'message' => 'Bahan apa saja yang perlu saya restock minggu ini?',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('success', $response->json('status'));
        $this->assertNotNull($response->json('reply'));
    }
}
