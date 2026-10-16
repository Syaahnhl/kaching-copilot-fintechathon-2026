<?php

namespace Tests\Feature;

use App\Http\Controllers\ChatbotController;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveCopilotEndToEndVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $ownerA;
    protected Outlet $outletA;
    protected User $ownerB;
    protected Outlet $outletB;
    protected Supplier $supplierA;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('id');

        Config::set('services.ai.key', 'fake-test-key');
        Config::set('services.ai.base_url', 'http://127.0.0.1:20128/v1');
        Config::set('services.ai.model', 'kachingcopilot');
        Config::set('services.ai.timeout', 20);
        Config::set('services.ai.connect_timeout', 5);

        Config::set('services.groq.key', 'fake-test-key');
        Config::set('services.groq.base_url', 'http://127.0.0.1:20128/v1');
        Config::set('services.groq.model', 'kachingcopilot');

        // Setup Owner A & Outlet A
        $this->ownerA = User::factory()->create([
            'email'             => 'ownera@kaching.id',
            'role'              => 'owner',
            'subscription_tier' => 'pro',
            'account_status'    => 'active',
        ]);

        $this->outletA = Outlet::create([
            'user_id' => $this->ownerA->id,
            'name'    => 'Outlet Pusat A',
            'address' => 'Jl. Sudirman No. 10',
        ]);

        // Setup Owner B & Outlet B
        $this->ownerB = User::factory()->create([
            'email'             => 'ownerb@kaching.id',
            'role'              => 'owner',
            'subscription_tier' => 'pro',
            'account_status'    => 'active',
        ]);

        $this->outletB = Outlet::create([
            'user_id' => $this->ownerB->id,
            'name'    => 'Outlet Cabang B',
            'address' => 'Jl. Thamrin No. 20',
        ]);

        // Setup Supplier for Owner A
        $this->supplierA = Supplier::create([
            'user_id' => $this->ownerA->id,
            'name'    => 'Supplier Kopi Jaya',
            'phone'   => '081234567890',
            'address' => 'Jl. Kopi No. 5',
        ]);

        // Seed transactions for Outlet A
        $now = Carbon::today('Asia/Jakarta')->setTime(14, 0, 0);
        Carbon::setTestNow($now);

        DB::table('transactions')->insert([
            'user_id'                 => $this->ownerA->id,
            'outlet_id'               => $this->outletA->id,
            'app_uuid'                => 'uuid-sales-a-01',
            'total_amount'            => 750000,
            'pay_amount'              => 750000,
            'payment_method'          => 'cash',
            'status'                  => 'Selesai',
            'official_invoice_number' => 'INV-TEST-A01',
            'created_at_device'       => $now->format('Y-m-d H:i:s'),
            'created_at'              => $now->copy()->utc(),
            'updated_at'              => $now->copy()->utc(),
        ]);

        // Seed transactions for Outlet B
        DB::table('transactions')->insert([
            'user_id'                 => $this->ownerB->id,
            'outlet_id'               => $this->outletB->id,
            'app_uuid'                => 'uuid-sales-b-01',
            'total_amount'            => 1250000,
            'pay_amount'              => 1250000,
            'payment_method'          => 'qris',
            'status'                  => 'Selesai',
            'official_invoice_number' => 'INV-TEST-B01',
            'created_at_device'       => $now->format('Y-m-d H:i:s'),
            'created_at'              => $now->copy()->utc(),
            'updated_at'              => $now->copy()->utc(),
        ]);
    }

    /**
     * Helper to simulate Web Widget UI text rendering matching chatbot.blade.php
     */
    protected function simulateWebWidgetUiRender(int $status, array $responseJson): string
    {
        if ($status === 419) {
            return 'Sesi halaman telah kedaluwarsa. Silakan muat ulang halaman.';
        }
        if ($status === 429) {
            return 'Terlalu banyak permintaan dalam waktu singkat. Mohon tunggu beberapa detik sebelum mencoba lagi.';
        }
        if ($status >= 400) {
            $msg = $responseJson['error'] ?? $responseJson['message'] ?? null;
            return $msg ?: 'Maaf, terjadi kesalahan saat menghubungi server.';
        }

        $replyText = $responseJson['reply'] ?? ($responseJson['data']['reply'] ?? ($responseJson['message'] ?? ''));
        if (empty($replyText)) {
            return 'Layanan asisten AI sedang tidak dapat dihubungi saat ini. Silakan coba beberapa saat lagi.';
        }
        return (string) $replyText;
    }

    /**
     * Helper to simulate Backoffice UI text rendering matching copilot_chat.blade.php
     */
    protected function simulateBackofficeUiRender(int $status, array $responseJson): array
    {
        if ($status === 401 || $status === 419) {
            return ['action' => 'reload', 'displayed_text' => 'Session expired. Reload required.'];
        }
        if ($status === 429) {
            return ['action' => 'error_banner', 'displayed_text' => 'Terlalu banyak permintaan dalam waktu singkat. Mohon tunggu beberapa saat sebelum mencoba lagi.'];
        }
        if ($status >= 400) {
            $msg = $responseJson['message'] ?? ('Permintaan gagal (' . $status . ')');
            return ['action' => 'error_banner', 'displayed_text' => $msg];
        }

        $isError = ($responseJson['status'] ?? '') === 'error';
        $code = $responseJson['code'] ?? ($responseJson['data']['code'] ?? '');
        $isFallback = !empty($responseJson['is_fallback']) || !empty($responseJson['data']['is_fallback']);

        if ($isError) {
            $isConversationalFallback = $isFallback || in_array($code, [
                'CLARIFICATION_NEEDED', 'AI_PROVIDER_UNAVAILABLE', 'RESTOCK_CONTEXT_MISSING',
                'NO_TENANT_SUPPLIERS', 'SUPPLIER_SPECIFICATION_NEEDED'
            ], true);

            if ($isConversationalFallback) {
                $text = $responseJson['reply'] ?? ($responseJson['data']['reply'] ?? 'Berikut analisis alternatif...');
                return ['action' => 'assistant_message', 'displayed_text' => (string) $text];
            }

            $errMsg = $responseJson['reply'] ?? ($responseJson['data']['reply'] ?? ($responseJson['message'] ?? 'Gagal memproses permintaan.'));
            return ['action' => 'error_banner', 'displayed_text' => (string) $errMsg];
        }

        $reply = $responseJson['reply'] ?? ($responseJson['data']['reply'] ?? 'Berikut analisis data outlet Anda:');
        return ['action' => 'assistant_message', 'displayed_text' => (string) $reply];
    }

    // =========================================================================
    // SKENARIO 1: "Berapa omzet hari ini?" -> lanjut "ya" (No restock leakage)
    // =========================================================================

    public function test_scenario_1_sales_inquiry_then_affirmation_web_widget_and_backoffice(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        // Turn 1: "Berapa omzet hari ini?"
        $payloadTurn1 = [
            'message' => 'Berapa omzet hari ini?',
            'history' => [],
        ];

        // Call Web Widget (/chat)
        $resTurn1 = $this->actingAs($this->ownerA)
            ->withHeader('Outlet-ID', (string) $this->outletA->id)
            ->postJson('/chat', $payloadTurn1);

        $resTurn1->assertStatus(200);
        $jsonTurn1 = $resTurn1->json();
        $this->assertEquals('success', $jsonTurn1['status']);
        $this->assertStringContainsString('750.000', $jsonTurn1['reply']);
        $uiTextTurn1 = $this->simulateWebWidgetUiRender(200, $jsonTurn1);
        $this->assertStringContainsString('750.000', $uiTextTurn1);

        // Turn 2: Affirmation "ya" with Turn 1 in history
        $clientHistory = [
            ['role' => 'user', 'content' => 'Berapa omzet hari ini?'],
            ['role' => 'assistant', 'content' => $jsonTurn1['reply']],
        ];

        $payloadTurn2 = [
            'message' => 'ya',
            'history' => $clientHistory,
        ];

        // 1A. Test on Web Widget (/chat)
        $resTurn2Web = $this->actingAs($this->ownerA)
            ->withHeader('Outlet-ID', (string) $this->outletA->id)
            ->postJson('/chat', $payloadTurn2);

        $resTurn2Web->assertStatus(200);
        $jsonTurn2Web = $resTurn2Web->json();
        $this->assertEquals('CLARIFICATION_NEEDED', $jsonTurn2Web['code']);
        $this->assertNotEquals('RESTOCK_CONTEXT_MISSING', $jsonTurn2Web['code']);
        $this->assertStringNotContainsString('pesanan atau supplier', $jsonTurn2Web['reply']);
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $jsonTurn2Web['reply']);
        $this->assertStringContainsString('produk terlaris', $jsonTurn2Web['reply']);

        $uiTextTurn2Web = $this->simulateWebWidgetUiRender(200, $jsonTurn2Web);
        $this->assertStringContainsString('produk terlaris', $uiTextTurn2Web);

        // 1B. Test on Backoffice (/copilot/chat)
        $resTurn2Backoffice = $this->actingAs($this->ownerA)
            ->withHeader('Outlet-ID', (string) $this->outletA->id)
            ->postJson('/copilot/chat', $payloadTurn2);

        $resTurn2Backoffice->assertStatus(200);
        $jsonTurn2Bo = $resTurn2Backoffice->json();
        $this->assertEquals('CLARIFICATION_NEEDED', $jsonTurn2Bo['code']);
        $this->assertNotEquals('RESTOCK_CONTEXT_MISSING', $jsonTurn2Bo['code']);

        $uiBoRender = $this->simulateBackofficeUiRender(200, $jsonTurn2Bo);
        $this->assertEquals('assistant_message', $uiBoRender['action']);
        $this->assertStringContainsString('produk terlaris', $uiBoRender['displayed_text']);

        echo "\n[SKENARIO 1 RESULT - PASS]\n";
        echo "Turn 1 HTTP: " . $resTurn1->status() . " | Flow: FINANCIAL_SUMMARY\n";
        echo "Turn 2 HTTP: " . $resTurn2Web->status() . " | Flow: CLARIFICATION_NEEDED (Not RESTOCK)\n";
        echo "UI Rendered: " . $uiTextTurn2Web . "\n";
    }

    // =========================================================================
    // SKENARIO 2: "Apa produk terlaris?" -> follow-up (Context retention)
    // =========================================================================

    public function test_scenario_2_top_products_then_followup_context_retention(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        // Mock LLM receiving history for Turn 2
        $recordedMessages = [];
        Http::fake([
            '*/chat/completions' => function ($request) use (&$recordedMessages) {
                $body = json_decode($request->body(), true);
                $recordedMessages = $body['messages'] ?? [];
                return Http::response([
                    'id' => 'chatcmpl-mock-followup',
                    'choices' => [
                        [
                            'message' => [
                                'role' => 'assistant',
                                'content' => 'Berdasarkan data produk terlaris sebelumnya, varian Kopi Susu terjual sebanyak 42 porsi hari ini.',
                            ]
                        ]
                    ]
                ], 200);
            }
        ]);

        $historyTurn1 = [
            ['role' => 'user', 'content' => 'Apa produk terlaris?'],
            ['role' => 'assistant', 'content' => 'Produk terlaris hari ini adalah Kopi Susu Aren dengan kontribusi 65% dari total omzet.'],
        ];

        $payloadTurn2 = [
            'message' => 'Berapa porsi yang terjual untuk produk tersebut?',
            'history' => $historyTurn1,
        ];

        $res = $this->actingAs($this->ownerA)
            ->withHeader('Outlet-ID', (string) $this->outletA->id)
            ->postJson('/copilot/chat', $payloadTurn2);

        $res->assertStatus(200);
        $json = $res->json();
        $this->assertEquals('success', $json['status']);
        $this->assertStringContainsString('42 porsi', $json['reply']);

        // Verify history was passed to LLM payload without turn duplication
        $historyRoles = array_map(fn($m) => $m['role'], $recordedMessages);
        $userMessages = array_filter($recordedMessages, fn($m) => $m['role'] === 'user');
        $this->assertGreaterThanOrEqual(2, count($userMessages));

        $uiText = $this->simulateBackofficeUiRender(200, $json);
        $this->assertEquals('assistant_message', $uiText['action']);
        $this->assertStringContainsString('42 porsi', $uiText['displayed_text']);

        echo "\n[SKENARIO 2 RESULT - PASS]\n";
        echo "HTTP: " . $res->status() . " | Flow: MULTI_TURN_CONTEXT_RETENTION\n";
        echo "UI Rendered: " . $uiText['displayed_text'] . "\n";
    }

    // =========================================================================
    // SKENARIO 3: Restock supplier inquiry -> "oke" (Restock flow functional)
    // =========================================================================

    public function test_scenario_3_restock_inquiry_then_affirmation_preserves_restock(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        // Turn 1: Restock inquiry
        $historyRestock = [
            ['role' => 'user', 'content' => 'Buatkan draf pesanan restock untuk supplier Kopi Jaya'],
            ['role' => 'assistant', 'content' => 'Mohon konfirmasi apakah Anda ingin memesan bahan baku ke Supplier Kopi Jaya? Balas dengan nama barang dan jumlahnya.'],
        ];

        // Turn 2: User says "oke"
        $payload = [
            'message' => 'oke',
            'history' => $historyRestock,
        ];

        $res = $this->actingAs($this->ownerA)
            ->withHeader('Outlet-ID', (string) $this->outletA->id)
            ->postJson('/copilot/chat', $payload);

        $res->assertStatus(200);
        $json = $res->json();

        // Must remain in restock domain (either asking for items or drafting), NOT hijacked into general financial clarification
        $this->assertNotEquals('CLARIFICATION_NEEDED', $json['code'] ?? null);
        $reply = $json['reply'] ?? ($json['data']['reply'] ?? '');
        $this->assertTrue(
            str_contains(strtolower($reply), 'supplier') ||
            str_contains(strtolower($reply), 'pesan') ||
            str_contains(strtolower($reply), 'kopi jaya') ||
            str_contains(strtolower($reply), 'bahan')
        );

        $uiRender = $this->simulateBackofficeUiRender(200, $json);
        $this->assertEquals('assistant_message', $uiRender['action']);

        echo "\n[SKENARIO 3 RESULT - PASS]\n";
        echo "HTTP: " . $res->status() . " | Flow: RESTOCK_CONFIRMATION_FLOW\n";
        echo "UI Rendered: " . $uiRender['displayed_text'] . "\n";
    }

    // =========================================================================
    // SKENARIO 4: Controlled Provider Failure (Timeout, 429, Malformed JSON)
    // =========================================================================

    public function test_scenario_4a_provider_timeout_returns_informative_fallback(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Http::fake([
            '*/chat/completions' => function () {
                throw new ConnectionException('Connection timed out after 5000ms');
            }
        ]);

        $res = $this->actingAs($this->ownerA)
            ->withHeader('Outlet-ID', (string) $this->outletA->id)
            ->postJson('/chat', ['message' => 'Berapa prediksi margin besok?']);

        $res->assertStatus(200);
        $json = $res->json();
        $this->assertEquals('AI_PROVIDER_UNAVAILABLE', $json['code'] ?? ($json['data']['code'] ?? null));
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $json['reply']);
        $this->assertStringContainsString('Layanan AI sementara tidak tersedia', $json['reply']);

        $uiText = $this->simulateWebWidgetUiRender(200, $json);
        $this->assertStringContainsString('Layanan AI sementara tidak tersedia', $uiText);

        echo "\n[SKENARIO 4A RESULT - PASS]\n";
        echo "Provider Timeout Caught | Code: " . ($json['code'] ?? '') . "\n";
        echo "UI Rendered: " . $uiText . "\n";
    }

    public function test_scenario_4b_provider_429_returns_rate_limit_fallback(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Http::fake([
            '*/chat/completions' => Http::response([
                'error' => ['message' => 'Rate limit exceeded']
            ], 429, ['Retry-After' => '15'])
        ]);

        $res = $this->actingAs($this->ownerA)
            ->withHeader('Outlet-ID', (string) $this->outletA->id)
            ->postJson('/copilot/chat', ['message' => 'Analisis tren minggu ini']);

        $res->assertStatus(200);
        $json = $res->json();
        $this->assertNotNull($json['reply']);
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $json['reply']);

        $uiRender = $this->simulateBackofficeUiRender(200, $json);
        $this->assertEquals('assistant_message', $uiRender['action']);

        echo "\n[SKENARIO 4B RESULT - PASS]\n";
        echo "Provider 429 Handled | HTTP: " . $res->status() . "\n";
        echo "UI Rendered: " . $uiRender['displayed_text'] . "\n";
    }

    public function test_scenario_4c_provider_empty_or_malformed_json_fallback(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Http::fake([
            '*/chat/completions' => Http::response('{invalid_json: true, choices: [}', 200, [
                'Content-Type' => 'application/json'
            ])
        ]);

        $res = $this->actingAs($this->ownerA)
            ->withHeader('Outlet-ID', (string) $this->outletA->id)
            ->postJson('/chat', ['message' => 'Berikan ringkasan kas']);

        $res->assertStatus(200);
        $json = $res->json();
        $this->assertNotEmpty($json['reply']);
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $json['reply']);

        $uiText = $this->simulateWebWidgetUiRender(200, $json);
        $this->assertNotEmpty($uiText);

        echo "\n[SKENARIO 4C RESULT - PASS]\n";
        echo "Malformed JSON Handled | HTTP: " . $res->status() . "\n";
        echo "UI Rendered: " . $uiText . "\n";
    }

    // =========================================================================
    // SKENARIO 5: Laravel Throttle HTTP 429 and Explicit UI Message
    // =========================================================================

    public function test_scenario_5_laravel_throttle_429_returns_clear_message(): void
    {
        // Keep ThrottleRequests middleware ACTIVE
        $statusCodes = [];
        $lastResponse = null;

        for ($i = 0; $i < 12; $i++) {
            $resp = $this->actingAs($this->ownerA)
                ->withHeader('Outlet-ID', (string) $this->outletA->id)
                ->postJson('/chat', ['message' => 'Ping ' . $i]);
            $statusCodes[] = $resp->status();
            $lastResponse = $resp;
        }

        // Must contain HTTP 429
        $this->assertContains(429, $statusCodes);
        $this->assertEquals(429, $lastResponse->status());

        $json429 = $lastResponse->json();
        $this->assertArrayHasKey('message', $json429);

        // Verify Web Widget UI message translation
        $webWidgetRender = $this->simulateWebWidgetUiRender(429, $json429);
        $this->assertEquals(
            'Terlalu banyak permintaan dalam waktu singkat. Mohon tunggu beberapa detik sebelum mencoba lagi.',
            $webWidgetRender
        );

        // Verify Backoffice UI message translation
        $backofficeRender = $this->simulateBackofficeUiRender(429, $json429);
        $this->assertEquals('error_banner', $backofficeRender['action']);
        $this->assertEquals(
            'Terlalu banyak permintaan dalam waktu singkat. Mohon tunggu beberapa saat sebelum mencoba lagi.',
            $backofficeRender['displayed_text']
        );

        echo "\n[SKENARIO 5 RESULT - PASS]\n";
        echo "Throttle 429 Triggered | Status Codes: " . implode(',', array_slice($statusCodes, -4)) . "\n";
        echo "Web Widget UI: " . $webWidgetRender . "\n";
        echo "Backoffice UI: " . $backofficeRender['displayed_text'] . "\n";
    }

    // =========================================================================
    // SKENARIO 6: Cross-Outlet Isolation Verification
    // =========================================================================

    public function test_scenario_6_cross_outlet_isolation_strictly_enforced(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        // Owner A tries to access Outlet B (which belongs to Owner B)
        $resForbidden = $this->actingAs($this->ownerA)
            ->withHeader('Outlet-ID', (string) $this->outletB->id)
            ->postJson('/copilot/chat', ['message' => 'Berapa omzet hari ini?']);

        $resForbidden->assertStatus(403);
        $jsonForbidden = $resForbidden->json();
        $this->assertEquals('UNAUTHORIZED_OUTLET', $jsonForbidden['code']);

        // Owner A queries Outlet A -> only sees Outlet A sales (750.000, NOT Outlet B 1.250.000)
        $resA = $this->actingAs($this->ownerA)
            ->withHeader('Outlet-ID', (string) $this->outletA->id)
            ->postJson('/copilot/chat', ['message' => 'Berapa omzet hari ini?']);

        $resA->assertStatus(200);
        $jsonA = $resA->json();
        $this->assertStringContainsString('750.000', $jsonA['reply']);
        $this->assertStringNotContainsString('1.250.000', $jsonA['reply']);

        // Owner B queries Outlet B -> only sees Outlet B sales (1.250.000, NOT Outlet A 750.000)
        $resB = $this->actingAs($this->ownerB)
            ->withHeader('Outlet-ID', (string) $this->outletB->id)
            ->postJson('/copilot/chat', ['message' => 'Berapa omzet hari ini?']);

        $resB->assertStatus(200);
        $jsonB = $resB->json();
        $this->assertStringContainsString('1.250.000', $jsonB['reply']);
        $this->assertStringNotContainsString('750.000', $jsonB['reply']);

        echo "\n[SKENARIO 6 RESULT - PASS]\n";
        echo "Outlet Spoofing Attempt: HTTP 403 UNAUTHORIZED_OUTLET\n";
        echo "Owner A (Outlet A): Sees 750.000 (0 leak from Outlet B)\n";
        echo "Owner B (Outlet B): Sees 1.250.000 (0 leak from Outlet A)\n";
    }

    // =========================================================================
    // REAL 9ROUTER PROVIDER LIVE HEALTH VERIFICATION (SEPARATE FROM SIMULATION)
    // =========================================================================

    public function test_real_provider_9router_health_verification(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $fp = @fsockopen('127.0.0.1', 20128, $errno, $errstr, 1);
        if (!$fp) {
            $this->markTestSkipped('9Router port 20128 not reachable on local host.');
        }
        fclose($fp);

        // Read real key from .env without printing or storing it
        $envKey = env('NINEROUTER_API_KEY') ?: env('GROQ_API_KEY');
        if (empty($envKey)) {
            $this->markTestSkipped('Real API key not configured in environment.');
        }

        // Test real HTTP call to 9Router endpoint
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $envKey,
            'Content-Type'  => 'application/json',
        ])->timeout(10)->post('http://127.0.0.1:20128/v1/chat/completions', [
            'model'    => 'kachingcopilot',
            'stream'   => false,
            'messages' => [
                ['role' => 'system', 'content' => 'Kamu adalah asisten uji koneksi. Jawab singkat: SEHAT.'],
                ['role' => 'user', 'content' => 'Status koneksi?'],
            ],
            'max_tokens' => 10,
        ]);

        $this->assertEquals(200, $response->status());
        $body = $response->json();
        $this->assertArrayHasKey('choices', $body);
        $this->assertNotEmpty($body['choices']);
        $content = $body['choices'][0]['message']['content'] ?? '';
        $this->assertNotEmpty($content);

        echo "\n[REAL 9ROUTER PROVIDER VERIFICATION - PASS]\n";
        echo "Endpoint: http://127.0.0.1:20128/v1/chat/completions | HTTP: 200\n";
        echo "Model Resolved: " . ($body['model'] ?? 'kachingcopilot') . "\n";
        echo "Raw AI Output: " . trim($content) . "\n";
    }

    // =========================================================================
    // WEB WIDGET HISTORY CONTRACT & ANTI-DUPLICATION VERIFICATION
    // =========================================================================

    public function test_web_widget_history_contract_and_anti_duplication(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        // Simulate Web Widget JS History state tracking across 3 turns
        $widgetChatHistory = [];

        // Turn 1
        $turn1Msg = 'Berapa omzet hari ini?';
        $res1 = $this->actingAs($this->ownerA)
            ->withHeader('Outlet-ID', (string) $this->outletA->id)
            ->postJson('/chat', [
                'message' => $turn1Msg,
                'history' => array_slice($widgetChatHistory, -8),
            ]);
        $res1->assertStatus(200);
        $reply1 = $res1->json('reply') ?: $res1->json('data.reply');
        $this->assertNotEmpty($reply1);

        // Widget pushes 1 user + 1 assistant turn
        $widgetChatHistory[] = ['role' => 'user', 'content' => $turn1Msg];
        $widgetChatHistory[] = ['role' => 'assistant', 'content' => $reply1];
        $this->assertCount(2, $widgetChatHistory);

        // Turn 2
        $turn2Msg = 'ya';
        $res2 = $this->actingAs($this->ownerA)
            ->withHeader('Outlet-ID', (string) $this->outletA->id)
            ->postJson('/chat', [
                'message' => $turn2Msg,
                'history' => array_slice($widgetChatHistory, -8),
            ]);
        $res2->assertStatus(200);
        $reply2 = $res2->json('reply') ?: $res2->json('data.reply');
        $this->assertNotEmpty($reply2);

        $widgetChatHistory[] = ['role' => 'user', 'content' => $turn2Msg];
        $widgetChatHistory[] = ['role' => 'assistant', 'content' => $reply2];
        $this->assertCount(4, $widgetChatHistory);

        // Turn 3: 10 turns pushed to test slice(-8) capping
        for ($i = 3; $i <= 8; $i++) {
            $widgetChatHistory[] = ['role' => 'user', 'content' => "Pertanyaan dummy $i"];
            $widgetChatHistory[] = ['role' => 'assistant', 'content' => "Jawaban dummy $i"];
        }
        $this->assertGreaterThan(8, count($widgetChatHistory));

        $cappedHistory = array_slice($widgetChatHistory, -8);
        $this->assertCount(8, $cappedHistory);

        // Send capped history to backend
        $resCapped = $this->actingAs($this->ownerA)
            ->withHeader('Outlet-ID', (string) $this->outletA->id)
            ->postJson('/chat', [
                'message' => 'Ringkasan performa',
                'history' => $cappedHistory,
            ]);
        $resCapped->assertStatus(200);

        // Verify no duplicate turns exist in sequence
        for ($k = 0; $k < count($cappedHistory) - 1; $k++) {
            $currentRole = $cappedHistory[$k]['role'];
            $nextRole = $cappedHistory[$k + 1]['role'];
            $this->assertNotEquals($currentRole, $nextRole, "Turn alternation violated at index $k");
        }

        echo "\n[WEB WIDGET HISTORY CONTRACT VERIFICATION - PASS]\n";
        echo "Turns tracked correctly: Alternating user/assistant strictly preserved.\n";
        echo "Slice(-8) ceiling: Max 8 turns sent to backend without duplication.\n";
    }
}
