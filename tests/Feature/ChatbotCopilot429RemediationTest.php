<?php

namespace Tests\Feature;

use App\Http\Controllers\ChatbotController;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ChatbotPromptBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ChatbotCopilot429RemediationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('id');

        config([
            'services.ai.key'       => 'fake-test-key',
            'services.groq.key'     => 'gsk_test_fake_api_key_123456789',
            'services.ai.base_url'   => 'http://127.0.0.1:20128/v1',
            'services.groq.base_url' => 'http://127.0.0.1:20128/v1',
        ]);

        $this->owner = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'pro',
            'account_status'    => 'active',
        ]);

        $this->outlet = Outlet::create([
            'user_id' => $this->owner->id,
            'name'    => 'Outlet Pusat Remediasi',
            'address' => 'Jl. Merdeka No. 1',
        ]);
    }

    // ================================================================
    // A. RETRY-AFTER PARSER TESTS
    // ================================================================

    public function test_parse_retry_after_header_numeric(): void
    {
        $response = new HttpResponse(
            new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => '23.417'], '{"error": "rate_limit"}')
        );

        $this->assertEquals(23.417, ChatbotController::parseRetryAfter($response));
    }

    public function test_parse_retry_after_header_ms(): void
    {
        $response = new HttpResponse(
            new \GuzzleHttp\Psr7\Response(429, ['retry-after-ms' => '2500'], '{"error": "rate_limit"}')
        );

        $this->assertEquals(2.5, ChatbotController::parseRetryAfter($response));
    }

    public function test_parse_retry_after_body_pattern(): void
    {
        $body = json_encode([
            'error' => [
                'message' => 'Rate limit reached for model qwen-2.5-32b. Please try again in 23.417s. Visit https://groq.com',
                'type'    => 'tokens',
                'code'    => 'rate_limit_exceeded',
            ]
        ]);

        $response = new HttpResponse(
            new \GuzzleHttp\Psr7\Response(429, [], $body)
        );

        $this->assertEquals(23.417, ChatbotController::parseRetryAfter($response));
    }

    public function test_parse_retry_after_null_when_absent(): void
    {
        $response = new HttpResponse(
            new \GuzzleHttp\Psr7\Response(429, [], '{"error": "generic error without wait time"}')
        );

        $this->assertNull(ChatbotController::parseRetryAfter($response));
    }

    // ================================================================
    // B. RATE LIMIT HANDLING & CASCADE ELIMINATION
    // ================================================================

    public function test_long_retry_after_skips_sleep_and_uses_deterministic_fallback(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        // Seed omzet hari ini
        $now = Carbon::today('Asia/Jakarta')->setTime(12, 0, 0);
        Carbon::setTestNow($now);

        DB::table('transactions')->insert([
            'user_id'                 => $this->owner->id,
            'outlet_id'               => $this->outlet->id,
            'app_uuid'                => 'uuid-' . uniqid('', true),
            'total_amount'            => 350000,
            'pay_amount'              => 350000,
            'payment_method'          => 'cash',
            'status'                  => 'Selesai',
            'official_invoice_number' => 'INV-REMED-01',
            'created_at_device'       => $now->format('Y-m-d H:i:s'),
            'created_at'              => $now->copy()->utc(),
            'updated_at'              => $now->copy()->utc(),
        ]);

        Http::fake([
            'http://127.0.0.1:20128/v1/*' => Http::response([
                'error' => [
                    'message' => 'Rate limit reached. Please try again in 23.417s.',
                    'code'    => 'rate_limit_exceeded',
                ]
            ], 429, ['Retry-After' => '23.417']),
            'https://api.groq.com/*' => Http::response([
                'error' => [
                    'message' => 'Rate limit reached. Please try again in 23.417s.',
                    'code'    => 'rate_limit_exceeded',
                ]
            ], 429, ['Retry-After' => '23.417']),
            '*/chat/completions' => Http::response([
                'error' => [
                    'message' => 'Rate limit reached. Please try again in 23.417s.',
                    'code'    => 'rate_limit_exceeded',
                ]
            ], 429, ['Retry-After' => '23.417']),
        ]);

        $start = microtime(true);
        $response = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $this->outlet->id)
            ->postJson('/copilot/chat', [
                'message' => 'Berapa omzet hari ini?',
            ]);
        $duration = microtime(true) - $start;

        // Harus selesai cepat tanpa tidur 23 detik (budget synchronous wait max 3s)
        $this->assertLessThan(4.0, $duration);

        // Hanya 1 request dikirim, tidak ada resend langsung karena Retry-After > 3s
        Http::assertSentCount(1);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $this->assertStringContainsString('350.000', $response->json('reply'));
        $this->assertEquals(350000, $response->json('financial_card.data.revenue'));
    }

    public function test_short_retry_after_retries_once_and_succeeds(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $seq = Http::sequence()
            ->push(['error' => 'Rate limit'], 429, ['Retry-After' => '1'])
            ->push([
                'choices' => [
                    [
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'Halo! Ini respons sukses setelah retry.',
                        ]
                    ]
                ]
            ], 200);

        Http::fake([
            '*/chat/completions' => $seq,
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $this->outlet->id)
            ->postJson('/copilot/chat', [
                'message' => 'Tolong sapa saya',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('Halo! Ini respons sukses setelah retry.', $response->json('reply'));

        // Terbukti melakukan tepat 2 request (request awal 429 + retry 200)
        Http::assertSentCount(2);
    }

    public function test_step2_rate_limit_failure_uses_tool_fallback_summary(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $now = Carbon::today('Asia/Jakarta')->setTime(10, 0, 0);
        Carbon::setTestNow($now);

        DB::table('transactions')->insert([
            'user_id'                 => $this->owner->id,
            'outlet_id'               => $this->outlet->id,
            'app_uuid'                => 'uuid-' . uniqid('', true),
            'total_amount'            => 500000,
            'pay_amount'              => 500000,
            'payment_method'          => 'cash',
            'status'                  => 'Selesai',
            'official_invoice_number' => 'INV-REMED-02',
            'created_at_device'       => $now->format('Y-m-d H:i:s'),
            'created_at'              => $now->copy()->utc(),
            'updated_at'              => $now->copy()->utc(),
        ]);

        // Step 1: LLM returns tool_call get_sales_summary
        // Step 2: LLM returns 429 rate limit
        $seq2 = Http::sequence()
            ->push([
                'choices' => [
                    [
                        'message' => [
                            'role'       => 'assistant',
                            'tool_calls' => [
                                [
                                    'id'       => 'call_step1_123',
                                    'function' => [
                                        'name'      => 'get_sales_summary',
                                        'arguments' => json_encode(['period' => 'today']),
                                    ],
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
            ->push([
                'error' => 'Rate limit on step 2'
            ], 429, ['Retry-After' => '10']);

        Http::fake([
            '*/chat/completions' => $seq2,
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $this->outlet->id)
            ->postJson('/copilot/chat', [
                'message' => 'Berapa omzet hari ini?',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $this->assertStringContainsString('500.000', $response->json('reply'));
        $this->assertEquals(500000, $response->json('financial_card.data.revenue'));
    }

    public function test_ambiguous_prompt_fallback_returns_busy_without_fabricated_figures(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Http::fake([
            'http://127.0.0.1:20128/v1/*' => Http::response([
                'error' => 'Rate limit reached. Please try again in 30s.',
            ], 429, ['Retry-After' => '30']),
            'https://api.groq.com/*' => Http::response([
                'error' => 'Rate limit reached. Please try again in 30s.',
            ], 429, ['Retry-After' => '30']),
            '*/chat/completions' => Http::response([
                'error' => 'Rate limit reached. Please try again in 30s.',
            ], 429, ['Retry-After' => '30']),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $this->outlet->id)
            ->postJson('/copilot/chat', [
                'message' => 'Tolong berikan masukan umum untuk operasional',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('code', 'AI_PROVIDER_UNAVAILABLE');
        $response->assertJsonPath('is_fallback', true);
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $response->json('reply'));
        $this->assertNotEmpty($response->json('reply'));
    }

    // ================================================================
    // C. INTENT-BASED TOOL SELECTION TESTS
    // ================================================================

    protected function getSample25Tools(): array
    {
        $toolNames = [
            'get_sales_summary', 'get_top_selling_products', 'get_today_sales', 'get_active_financial_warnings',
            'get_financial_scenario', 'get_cash_flow_forecast', 'get_external_business_radar', 'get_suppliers',
            'propose_action', 'calculate_financing_readiness', 'simulate_purchase', 'get_menu_intelligence',
            'get_financial_action_recommendations', 'get_financial_summary', 'get_risk_radar', 'get_demand_forecast',
            'get_stock_risk', 'get_material_price_forecast', 'get_menu_margin_candidates', 'prepare_action_proposal',
            'get_financing_readiness', 'get_financial_evidence', 'get_market_signals', 'get_financial_performance_intelligence',
            'get_restock_recommendations',
        ];

        return array_map(fn($name) => [
            'type'     => 'function',
            'function' => [
                'name'        => $name,
                'description' => "Tool description for {$name}",
                'parameters'  => ['type' => 'object', 'properties' => []],
            ]
        ], $toolNames);
    }

    public function test_intent_tool_selection_for_clusters(): void
    {
        $allTools = $this->getSample25Tools();
        $this->assertCount(25, $allTools);

        // 1. Sales intent
        $salesTools = ChatbotPromptBuilder::filterToolsForContext($allTools, ChatbotPromptBuilder::CONTEXT_FINANCE_COPILOT, 'pro', $this->owner, 'Berapa omzet hari ini?');
        $salesNames = array_column(array_column($salesTools, 'function'), 'name');
        $this->assertContains('get_today_sales', $salesNames);
        $this->assertContains('get_sales_summary', $salesNames);
        $this->assertNotContains('get_suppliers', $salesNames);
        $this->assertNotContains('get_financial_scenario', $salesNames);

        // 2. Menu intent
        $menuTools = ChatbotPromptBuilder::filterToolsForContext($allTools, ChatbotPromptBuilder::CONTEXT_FINANCE_COPILOT, 'pro', $this->owner, 'Menu mana yang marginnya tipis?');
        $menuNames = array_column(array_column($menuTools, 'function'), 'name');
        $this->assertContains('get_menu_margin_candidates', $menuNames);
        $this->assertContains('get_stock_risk', $menuNames);
        $this->assertNotContains('get_suppliers', $menuNames);

        // 3. Cashflow intent
        $cashflowTools = ChatbotPromptBuilder::filterToolsForContext($allTools, ChatbotPromptBuilder::CONTEXT_FINANCE_COPILOT, 'pro', $this->owner, 'Berapa proyeksi cash flow seminggu ke depan?');
        $cashflowNames = array_column(array_column($cashflowTools, 'function'), 'name');
        $this->assertContains('get_cash_flow_forecast', $cashflowNames);
        $this->assertNotContains('get_menu_margin_candidates', $cashflowNames);

        // 4. Expenses intent
        $expenseTools = ChatbotPromptBuilder::filterToolsForContext($allTools, ChatbotPromptBuilder::CONTEXT_FINANCE_COPILOT, 'pro', $this->owner, 'Siapa supplier kopi kita?');
        $expenseNames = array_column(array_column($expenseTools, 'function'), 'name');
        $this->assertContains('get_suppliers', $expenseNames);
        $this->assertNotContains('get_cash_flow_forecast', $expenseNames);

        // 5. Financing intent
        $financingTools = ChatbotPromptBuilder::filterToolsForContext($allTools, ChatbotPromptBuilder::CONTEXT_FINANCE_COPILOT, 'pro', $this->owner, 'Simulasikan kenaikan harga 10%');
        $financingNames = array_column(array_column($financingTools, 'function'), 'name');
        $this->assertContains('get_financial_scenario', $financingNames);
        $this->assertNotContains('get_suppliers', $financingNames);

        // 6. Risk intent
        $riskTools = ChatbotPromptBuilder::filterToolsForContext($allTools, ChatbotPromptBuilder::CONTEXT_FINANCE_COPILOT, 'pro', $this->owner, 'Apa ada sinyal risiko pasar?');
        $riskNames = array_column(array_column($riskTools, 'function'), 'name');
        $this->assertContains('get_risk_radar', $riskNames);
        $this->assertContains('get_market_signals', $riskNames);
    }

    public function test_multi_intent_prompt_returns_combined_deduplicated_clusters(): void
    {
        $allTools = $this->getSample25Tools();
        $filtered = ChatbotPromptBuilder::filterToolsForContext(
            $allTools,
            ChatbotPromptBuilder::CONTEXT_FINANCE_COPILOT,
            'pro',
            $this->owner,
            'Bandingkan omzet penjualan dengan stok bahan baku'
        );

        $names = array_column(array_column($filtered, 'function'), 'name');

        // Contains tools from both Sales and Menu clusters
        $this->assertContains('get_today_sales', $names);
        $this->assertContains('get_stock_risk', $names);

        // Ensures no duplicate tools in the returned array
        $this->assertEquals(count($names), count(array_unique($names)));
    }

    public function test_follow_up_prompt_inherits_intent_from_history(): void
    {
        $allTools = $this->getSample25Tools();
        $history = [
            ['role' => 'user', 'content' => 'Menu mana yang marginnya paling tipis?'],
            ['role' => 'assistant', 'content' => 'Menu Cappuccino memiliki margin 12%.'],
        ];

        $filtered = ChatbotPromptBuilder::filterToolsForContext(
            $allTools,
            ChatbotPromptBuilder::CONTEXT_FINANCE_COPILOT,
            'pro',
            $this->owner,
            'Kenapa begitu?',
            $history
        );

        $names = array_column(array_column($filtered, 'function'), 'name');
        $this->assertContains('get_menu_margin_candidates', $names);
        $this->assertContains('get_stock_risk', $names);
    }

    public function test_tenant_isolation_on_unauthorized_outlet_fallback(): void
    {
        $otherUser = User::factory()->create([
            'role' => 'owner',
            'subscription_tier' => 'pro',
        ]);
        $otherOutlet = Outlet::create([
            'user_id' => $otherUser->id,
            'name'    => 'Outlet Milik Orang Lain',
            'address' => 'Jl. Lain',
        ]);

        Http::fake([
            'http://127.0.0.1:20128/v1/*' => Http::response(['error' => 'Rate limit'], 429, ['Retry-After' => '30']),
            'https://api.groq.com/*'       => Http::response(['error' => 'Rate limit'], 429, ['Retry-After' => '30']),
            '*/chat/completions'          => Http::response(['error' => 'Rate limit'], 429, ['Retry-After' => '30']),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $otherOutlet->id)
            ->postJson('/copilot/chat', [
                'message' => 'Berapa omzet hari ini?',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'UNAUTHORIZED_OUTLET');
    }

    // ================================================================
    // D. FOLLOW-UP REMEDIATION (TASK-04): INTENTS, CORE & FALLBACK
    // ================================================================

    public function test_intent_detection_keywords_and_boundary_checks(): void
    {
        // 1. Valid Indonesian financial terms mapped to correct clusters
        $intentsLaba = ChatbotPromptBuilder::detectIntents('Cek laba bersih bulan ini');
        $this->assertContains(ChatbotPromptBuilder::CLUSTER_SALES, $intentsLaba);

        $intentsUntung = ChatbotPromptBuilder::detectIntents('Berapa untung kita hari ini?');
        $this->assertContains(ChatbotPromptBuilder::CLUSTER_SALES, $intentsUntung);

        $intentsRugi = ChatbotPromptBuilder::detectIntents('Apakah bulan ini toko rugi?');
        $this->assertContains(ChatbotPromptBuilder::CLUSTER_SALES, $intentsRugi);

        $intentsPiutang = ChatbotPromptBuilder::detectIntents('Bagaimana posisi piutang toko?');
        $this->assertContains(ChatbotPromptBuilder::CLUSTER_CASHFLOW, $intentsPiutang);

        $intentsNeraca = ChatbotPromptBuilder::detectIntents('Tolong tampilkan neraca keuangan');
        $this->assertContains(ChatbotPromptBuilder::CLUSTER_CASHFLOW, $intentsNeraca);

        // 2. Boundary and false-positive checks (should NOT trigger sales cluster)
        $this->assertEmpty(ChatbotPromptBuilder::detectIntents('untungnya kasir teliti'));
        $this->assertEmpty(ChatbotPromptBuilder::detectIntents('merugikan pihak lain'));
        $this->assertEmpty(ChatbotPromptBuilder::detectIntents('peruntungan masa depan'));
    }

    public function test_core_cluster_balanced_size_and_tool_composition(): void
    {
        $clusters = ChatbotPromptBuilder::getToolClusters();
        $coreTools = $clusters[ChatbotPromptBuilder::CLUSTER_CORE] ?? [];

        // Core cluster contains 18 tools that satisfy orchestrator and general inquiries
        $this->assertCount(18, $coreTools);

        // Must include all 14 tools required by orchestrator contract
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
            'generate_supplier_restock_message',
        ];
        foreach ($required14 as $tool) {
            $this->assertContains($tool, $coreTools, "Tool '{$tool}' must be present in core cluster.");
        }

        // Must exclude specialized/heavy tools reserved for targeted clusters
        $this->assertNotContains('simulate_purchase', $coreTools);
        $this->assertNotContains('calculate_financing_readiness', $coreTools);
        $this->assertNotContains('propose_action', $coreTools);
        $this->assertNotContains('get_suppliers', $coreTools);
        $this->assertNotContains('get_restock_recommendations', $coreTools);
        $this->assertNotContains('get_external_business_radar', $coreTools);
    }

    public function test_fallback_markdown_rendering_for_forecast_and_recommendations(): void
    {
        $controller = app(ChatbotController::class);
        $method = new \ReflectionMethod(ChatbotController::class, 'generateToolFallbackSummary');
        $method->setAccessible(true);

        // 1. Successful forecast & recommendations
        $forecast = [
            'status' => 'SUCCESS',
            'horizon_days' => 7,
            'forecast_period' => [
                'start_date' => '2026-10-01',
                'end_date'   => '2026-10-07',
            ],
            'projected_inflow' => 15000000,
            'projected_outflow' => 8000000,
            'projected_net_operating_cash_flow' => 7000000,
        ];

        $recommendations = [
            'status' => 'SUCCESS',
            'recommendations' => [
                [
                    'what'     => 'Kaji ulang porsi bahan baku ayam goreng',
                    'why'      => 'Biaya bahan baku ayam mengalami kenaikan 15%',
                    'priority' => 'HIGH',
                ],
                [
                    'what'     => 'Pantau pengeluaran listrik bulanan',
                    'why'      => 'Beban operasional meningkat di atas target',
                    'priority' => 'MEDIUM',
                ]
            ],
        ];

        $output = $method->invoke(
            $controller,
            null, // todaySales
            null, // warnings
            [],   // scenarios (array)
            $forecast,
            null, // performance
            $recommendations,
            [],   // radars (array)
            [],   // menuIntelligence (array)
            null, // topSelling
            null  // suppliers
        );

        $this->assertStringContainsString('Proyeksi Arus Kas (7 Hari', $output);
        $this->assertStringContainsString('Rp 15.000.000', $output);
        $this->assertStringContainsString('Rp 8.000.000', $output);
        $this->assertStringContainsString('+Rp 7.000.000', $output);

        $this->assertStringContainsString('Rekomendasi Tindakan Finansial (2 item)', $output);
        $this->assertStringContainsString('Prioritas 🔴 Tinggi', $output);
        $this->assertStringContainsString('Kaji ulang porsi bahan baku ayam goreng', $output);
        $this->assertStringContainsString('Biaya bahan baku ayam mengalami kenaikan 15%', $output);

        // 2. Insufficient history forecast & tier upgrade recommendations
        $insufficientForecast = [
            'status'       => 'INSUFFICIENT_HISTORY',
            'horizon_days' => 14,
            'message'      => 'Riwayat transaksi belum mencukupi untuk memproyeksikan arus kas.',
        ];

        $upgradeRecommendations = [
            'status'  => 'TIER_UPGRADE_REQUIRED',
            'message' => 'Fitur rekomendasi khusus paket PRO.',
        ];

        $output2 = $method->invoke(
            $controller,
            null,
            null,
            [], // scenarios
            $insufficientForecast,
            null,
            $upgradeRecommendations,
            [], // radars
            [], // menuIntelligence
            null,
            null
        );

        $this->assertStringContainsString('Riwayat transaksi belum mencukupi', $output2);
        $this->assertStringContainsString(__('copilot.summaries.tier_upgrade_required'), $output2);
    }

    // ================================================================
    // E. PHASE B3.6.46 SURGICAL FALLBACK & CONTRACT REGRESSION TESTS
    // ================================================================

    /**
     * Regresi 1: Pertanyaan umum tanpa kegagalan provider menghasilkan CLARIFICATION_NEEDED.
     */
    public function test_general_inquiry_without_provider_failure_returns_clarification_needed(): void
    {
        $controller = app(ChatbotController::class);
        $request = \Illuminate\Http\Request::create('/copilot/chat', 'POST', [
            'message' => 'Bagaimana cara meningkatkan performa operasional toko saya?',
        ]);

        $response = $controller->executeDeterministicFallback($this->owner, $request, 'Bagaimana cara meningkatkan performa operasional toko saya?');
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('success', $data['status']);
        $this->assertEquals('CLARIFICATION_NEEDED', $data['code']);
        $this->assertTrue($data['is_fallback']);
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $data['reply']);
        $this->assertStringContainsString('Kachi belum memahami', $data['reply']);
    }

    /**
     * Regresi 2: Outlet baru tanpa transaksi penjualan mengembalikan respons informatif, bukan error sibuk.
     */
    public function test_empty_sales_data_returns_informative_summary(): void
    {
        $emptyOutlet = Outlet::create([
            'user_id' => $this->owner->id,
            'name'    => 'Outlet Baru Bersih',
            'address' => 'Jl. Baru No. 2',
        ]);

        Http::fake([
            '*/chat/completions' => Http::response(['error' => 'Rate limit'], 429, ['Retry-After' => '30']),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $emptyOutlet->id)
            ->postJson('/copilot/chat', [
                'message' => 'Berapa omzet hari ini?',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $response->json('reply'));
        $this->assertStringContainsString('Ringkasan Penjualan', $response->json('reply'));
        $this->assertStringContainsString('Rp 0', $response->json('reply'));
        $this->assertEquals(0, $response->json('financial_card.data.revenue'));
    }

    /**
     * Regresi 3: Tenant tanpa supplier menghasilkan pemberitahuan informatif, bukan drop-through sibuk.
     */
    public function test_empty_suppliers_returns_informative_notice(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response(['error' => 'Rate limit'], 429, ['Retry-After' => '30']),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $this->outlet->id)
            ->postJson('/copilot/chat', [
                'message' => 'Siapa saja supplier saya?',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $response->json('reply'));
        $this->assertStringContainsString('Belum ada data supplier yang terdaftar', $response->json('reply'));
    }

    /**
     * Regresi 4: Afirmasi tanpa konteks restock (fail-closed) menghasilkan RESTOCK_CONTEXT_MISSING.
     */
    public function test_affirmation_without_restock_context_returns_fail_closed(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response(['error' => 'Rate limit'], 429, ['Retry-After' => '30']),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $this->outlet->id)
            ->postJson('/copilot/chat', [
                'message' => 'Ya',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('code', 'RESTOCK_CONTEXT_MISSING');
        $response->assertJsonPath('is_fallback', true);
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $response->json('reply'));
    }

    /**
     * Regresi 5: Input kuantitas natural tanpa konteks supplier menghasilkan RESTOCK_CONTEXT_MISSING.
     */
    public function test_quantity_input_without_restock_context_returns_fail_closed(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response(['error' => 'Rate limit'], 429, ['Retry-After' => '30']),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $this->outlet->id)
            ->postJson('/copilot/chat', [
                'message' => '4 galon',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('code', 'RESTOCK_CONTEXT_MISSING');
        $response->assertJsonPath('is_fallback', true);
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $response->json('reply'));
    }

    /**
     * Regresi 6: Permintaan restock eksplisit saat tenant belum punya supplier menghasilkan NO_TENANT_SUPPLIERS.
     */
    public function test_explicit_restock_without_registered_suppliers_returns_no_tenant_suppliers(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response(['error' => 'Rate limit'], 429, ['Retry-After' => '30']),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $this->outlet->id)
            ->postJson('/copilot/chat', [
                'message' => 'Buatkan draft pesan WA untuk beli beras 10 kg ke supplier',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('code', 'NO_TENANT_SUPPLIERS');
        $response->assertJsonPath('is_fallback', true);
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $response->json('reply'));
        $this->assertStringContainsString('belum memiliki supplier yang terdaftar', $response->json('reply'));
    }

    /**
     * Regresi 7: Kegagalan timeout koneksi (cURL error 28) menghasilkan AI_PROVIDER_UNAVAILABLE.
     */
    public function test_provider_timeout_exception_returns_ai_provider_unavailable(): void
    {
        Http::fake([
            '*/chat/completions' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 20000 milliseconds');
            },
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $this->outlet->id)
            ->postJson('/copilot/chat', [
                'message' => 'Tolong berikan masukan umum untuk operasional',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('code', 'AI_PROVIDER_UNAVAILABLE');
        $response->assertJsonPath('is_fallback', true);
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $response->json('reply'));
    }

    /**
     * Regresi 8: Upstream provider HTTP 500 / 502 / 503 menghasilkan AI_PROVIDER_UNAVAILABLE.
     */
    public function test_provider_5xx_returns_ai_provider_unavailable(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response('Internal Server Error', 500),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $this->outlet->id)
            ->postJson('/copilot/chat', [
                'message' => 'Pertanyaan umum saat provider crash',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('code', 'AI_PROVIDER_UNAVAILABLE');
        $response->assertJsonPath('is_fallback', true);
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $response->json('reply'));
    }

    /**
     * Regresi 9: Respons kosong atau malformed dari AI provider menghasilkan AI_PROVIDER_UNAVAILABLE.
     */
    public function test_malformed_provider_response_returns_ai_provider_unavailable(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response(['choices' => []], 200),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $this->outlet->id)
            ->postJson('/copilot/chat', [
                'message' => 'Pertanyaan saat AI mengembalikan array kosong',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('code', 'AI_PROVIDER_UNAVAILABLE');
        $response->assertJsonPath('is_fallback', true);
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $response->json('reply'));
    }

    /**
     * Regresi 10: Memverifikasi pemisahan tegas antara conversational fallback (200) vs fatal error (401 / 403).
     */
    public function test_conversational_fallback_differs_from_fatal_errors(): void
    {
        // 1. Conversational Fallback: status 200 dengan is_fallback = true (dirender sebagai bubble chat)
        Http::fake([
            '*/chat/completions' => Http::response(['error' => 'Rate limit'], 429, ['Retry-After' => '30']),
        ]);

        $convResponse = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $this->outlet->id)
            ->postJson('/copilot/chat', ['message' => 'Pertanyaan ambigu']);

        $convResponse->assertStatus(200);
        $convResponse->assertJsonPath('is_fallback', true);

        // 2. Fatal Auth Error (Copilot unauthenticated): status 401
        $controller = app(ChatbotController::class);
        $unauthReq = \Illuminate\Http\Request::create('/copilot/chat', 'POST', ['message' => 'Pertanyaan tanpa login']);
        $unauthResponse = $controller->sendMessage($unauthReq);
        $this->assertEquals(401, $unauthResponse->getStatusCode());
        $unauthData = json_decode($unauthResponse->getContent(), true);
        $this->assertEquals('UNAUTHENTICATED', $unauthData['code'] ?? '');

        // 3. Fatal Security Error (Akses outlet milik tenant lain): status 403
        $otherUser = User::factory()->create(['role' => 'owner', 'subscription_tier' => 'pro']);
        $foreignOutlet = Outlet::create(['user_id' => $otherUser->id, 'name' => 'Foreign', 'address' => 'Jl. Asing']);

        $unauthOutletResponse = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $foreignOutlet->id)
            ->postJson('/copilot/chat', ['message' => 'Berapa omzet?']);

        $unauthOutletResponse->assertStatus(403);
        $unauthOutletResponse->assertJsonPath('code', 'UNAUTHORIZED_OUTLET');
    }
}
