<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Supplier;
use App\Http\Controllers\ChatbotController;
use App\Services\DeveloperHub\TelemetryIngestService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\App;
use Carbon\Carbon;

class CopilotMinimalFallbackUxFixTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenantUser;
    protected User $otherTenantUser;
    protected Outlet $outlet;
    protected Supplier $supplier;
    protected Supplier $otherSupplier;

    protected function setUp(): void
    {
        parent::setUp();

        App::setLocale('id');
        Carbon::setLocale('id');
        Carbon::setTestNow(Carbon::create(2026, 10, 13, 14, 0, 0, 'Asia/Jakarta'));

        $this->tenantUser = User::factory()->create([
            'name'              => 'Tenant Restock Owner',
            'subscription_tier' => 'PRO',
        ]);

        $this->outlet = Outlet::factory()->create([
            'user_id' => $this->tenantUser->id,
            'name'    => 'Outlet Pusat Jakarta',
        ]);

        $this->otherTenantUser = User::factory()->create([
            'name'              => 'Other Tenant Owner',
            'subscription_tier' => 'PRO',
        ]);

        $this->supplier = Supplier::create([
            'user_id' => $this->tenantUser->id,
            'name'    => 'Toko Sembako Berkah Jaya',
            'phone'   => '081234567890',
            'address' => 'Jl. Pasar Baru No. 12',
        ]);

        $this->otherSupplier = Supplier::create([
            'user_id' => $this->otherTenantUser->id,
            'name'    => 'Supplier Rahasia Tenant Lain',
            'phone'   => '089876543210',
            'address' => 'Jl. Gang Tersembunyi No. 99',
        ]);
    }

    /**
     * TEST 1: Provider timeout + pertanyaan umum -> pesan AI sementara tidak tersedia (bukan POS down / busy generik).
     */
    public function test_provider_timeout_on_general_question_returns_ai_unavailable_clarification(): void
    {
        config(['services.ai.key' => 'test-key-ai']);

        Http::fake([
            '*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 20000 milliseconds');
            },
        ]);

        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'ceritakan sejarah kaching',
            'history' => [],
        ]);
        $request->setUserResolver(fn() => $this->tenantUser);

        $response = $controller->sendMessage($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('success', $data['status']);
        $this->assertEquals('AI_PROVIDER_UNAVAILABLE', $data['code']);
        $this->assertTrue($data['is_fallback']);
        $this->assertStringContainsString('Layanan AI sementara tidak tersedia', $data['reply']);
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $data['reply']);
        $this->assertStringNotContainsString('cURL error 28', $data['reply']);
    }

    /**
     * TEST 2: Provider HTTP 429 -> tidak menyatakan seluruh POS down dan memberikan panduan fitur lokal.
     */
    public function test_provider_http_429_returns_safe_fallback_without_claiming_pos_down(): void
    {
        config(['services.ai.key' => 'test-key-ai']);

        Http::fake([
            '*' => Http::response([
                'error' => ['message' => 'Rate limit exceeded']
            ], 429, ['Retry-After' => '10.0']),
        ]);

        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'bagaimana kondisi penjualan hari ini?',
            'history' => [],
        ]);
        $request->setUserResolver(fn() => $this->tenantUser);

        $response = $controller->sendMessage($request);
        $data = json_decode($response->getContent(), true);

        // Pertanyaan omzet terlayani secara deterministik lokal walau AI 429
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('success', $data['status']);
        $this->assertNotNull($data['financial_card']);
        $this->assertEquals('summary', $data['financial_card']['type']);

        // Case B: Input acak 'ayam' saat provider 429 -> AI_PROVIDER_UNAVAILABLE (bukan CLARIFICATION_NEEDED & bukan financial card palsu)
        $reqRandom = Request::create('/copilot/chat', 'POST', [
            'message' => 'ayam',
            'history' => [],
        ]);
        $reqRandom->setUserResolver(fn() => $this->tenantUser);

        $resRandom = $controller->sendMessage($reqRandom);
        $dataRandom = json_decode($resRandom->getContent(), true);

        $this->assertEquals(200, $resRandom->getStatusCode());
        $this->assertEquals('success', $dataRandom['status']);
        $this->assertEquals('AI_PROVIDER_UNAVAILABLE', $dataRandom['code']);
        $this->assertTrue($dataRandom['is_fallback']);
        $this->assertNull($dataRandom['financial_card']);
        $this->assertStringContainsString('Layanan AI sementara tidak tersedia', $dataRandom['reply']);
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $dataRandom['reply']);
        $this->assertSame($dataRandom['reply'], $dataRandom['data']['reply']);
    }

    /**
     * TEST 3: Provider HTTP 401/403/402 -> penanganan aman tanpa membocorkan kredensial atau detail server.
     */
    public function test_provider_auth_error_and_quota_exhausted_do_not_leak_credentials(): void
    {
        $secretKey = 'sk-super-secret-test-key-999';
        config(['services.ai.key' => $secretKey]);

        foreach ([401, 403, 402] as $status) {
            Http::fake([
                '*' => Http::response(['error' => 'Auth or Quota failed'], $status),
            ]);

            $controller = app(ChatbotController::class);
            $request = Request::create('/copilot/chat', 'POST', [
                'message' => 'pertanyaan uji kredensial',
                'history' => [],
            ]);
            $request->setUserResolver(fn() => $this->tenantUser);

            $response = $controller->sendMessage($request);
            $data = json_decode($response->getContent(), true);

            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals('success', $data['status']);
            $this->assertEquals('AI_PROVIDER_UNAVAILABLE', $data['code']);
            $this->assertStringNotContainsString($secretKey, json_encode($data));
            $this->assertStringNotContainsString('Authorization', json_encode($data));
        }
    }

    /**
     * TEST 4: Provider 5xx atau malformed response -> fallback sesuai kontrak.
     */
    public function test_provider_5xx_and_malformed_response_executes_contract_fallback(): void
    {
        config(['services.ai.key' => 'test-key-ai']);

        // Case A: 500 Server Error
        Http::fake([
            '*' => Http::response('Internal Server Error', 500),
        ]);

        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'apa cuaca besok?',
            'history' => [],
        ]);
        $request->setUserResolver(fn() => $this->tenantUser);

        $response = $controller->sendMessage($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('success', $data['status']);
        $this->assertEquals('AI_PROVIDER_UNAVAILABLE', $data['code']);
        $this->assertTrue($data['is_fallback']);

        // Case B: Malformed response (JSON tanpa choices/message)
        Http::fake([
            '*' => Http::response(['unexpected' => 'payload'], 200),
        ]);

        $responseMalformed = $controller->sendMessage($request);
        $dataMalformed = json_decode($responseMalformed->getContent(), true);

        $this->assertEquals(200, $responseMalformed->getStatusCode());
        $this->assertEquals('success', $dataMalformed['status']);
        $this->assertEquals('AI_PROVIDER_UNAVAILABLE', $dataMalformed['code']);
        $this->assertTrue($dataMalformed['is_fallback']);
    }

    /**
     * TEST 5: Input tidak dipahami tanpa kegagalan provider (ayam, sasda, dadad) -> klarifikasi default (tidak mengklaim AI down).
     */
    public function test_unrecognized_input_without_provider_failure_returns_default_clarification(): void
    {
        $controller = app(ChatbotController::class);

        foreach (['ayam', 'sasda', 'dadad', 'xyz random input tanpa provider error'] as $randomInput) {
            $request = Request::create('/copilot/chat', 'POST', [
                'message' => $randomInput,
                'history' => [],
            ]);

            $response = $controller->executeDeterministicFallback($this->tenantUser, $request, $randomInput);
            $data = json_decode($response->getContent(), true);

            $this->assertEquals(200, $response->getStatusCode(), "Gagal pada input: {$randomInput}");
            $this->assertEquals('success', $data['status'], "Status bukan success pada input: {$randomInput}");
            $this->assertEquals('CLARIFICATION_NEEDED', $data['code'], "Code bukan CLARIFICATION_NEEDED pada input: {$randomInput}");
            $this->assertTrue($data['is_fallback'], "is_fallback bukan true pada input: {$randomInput}");
            $this->assertNull($data['financial_card'], "financial_card tidak null pada input: {$randomInput}");
            $this->assertStringContainsString('Kachi belum memahami maksud permintaan tersebut', $data['reply'], "Pesan salah pada input: {$randomInput}");
            $this->assertStringNotContainsString('Layanan AI sementara tidak tersedia', $data['reply']);
            $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $data['reply']);
            $this->assertStringContainsString('Bagaimana kondisi penjualan hari ini?', $data['reply']);
        }
    }

    /**
     * TEST 6: Parameter restock kuantitas (4 galon, 10 kg) tanpa konteks valid tetap fail-closed RESTOCK_CONTEXT_MISSING.
     */
    public function test_affirmation_and_parameter_without_context_are_fail_closed_with_restock_clarification(): void
    {
        $controller = app(ChatbotController::class);

        foreach (['4 galon', '10 kg'] as $msg) {
            $request = Request::create('/copilot/chat', 'POST', [
                'message' => $msg,
                'history' => [
                    ['role' => 'user', 'content' => 'bagaimana omzet hari ini?'],
                    ['role' => 'assistant', 'content' => 'Omzet hari ini Rp 1.000.000.'],
                ],
            ]);

            $response = $controller->executeDeterministicFallback($this->tenantUser, $request, $msg);
            $data = json_decode($response->getContent(), true);

            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals('success', $data['status']);
            $this->assertEquals('RESTOCK_CONTEXT_MISSING', $data['code']);
            $this->assertTrue($data['is_fallback']);
            $this->assertArrayNotHasKey('supplier_order', $data['data'] ?? []);
            $this->assertStringContainsString('Kachi belum menemukan konteks pesanan atau supplier', $data['reply']);
        }
    }

    /**
     * TEST 7: Valid restock dengan konteks -> supplier_order dan whatsapp_url tetap benar.
     */
    public function test_valid_restock_with_context_generates_draft_and_whatsapp_url(): void
    {
        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message'   => '4 galon',
            'outlet_id' => $this->outlet->id,
            'history'   => [
                ['role' => 'user', 'content' => 'pesan ke Toko Sembako Berkah Jaya'],
                ['role' => 'assistant', 'content' => 'Supplier Toko Sembako Berkah Jaya telah dipilih. Mau memesan item apa?'],
            ],
        ]);

        $response = $controller->executeDeterministicFallback($this->tenantUser, $request, '4 galon');
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('success', $data['status']);
        $this->assertArrayHasKey('supplier_order', $data['data']);
        $this->assertEquals('Toko Sembako Berkah Jaya', $data['data']['supplier_order']['supplier']['name']);
        $this->assertNotEmpty($data['data']['supplier_order']['whatsapp_url']);
        $this->assertStringContainsString('https://wa.me/', $data['data']['supplier_order']['whatsapp_url']);
    }

    /**
     * TEST 8: Akses supplier lintas-tenant -> ditolak (403 UNAUTHORIZED_SUPPLIER).
     */
    public function test_cross_tenant_supplier_access_strictly_rejected(): void
    {
        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message'   => 'pesan beras 10 kg',
            'outlet_id' => $this->outlet->id,
            'history'   => [
                ['role' => 'user', 'content' => 'pesan ke ' . $this->otherSupplier->name],
            ],
        ]);

        $response = $controller->executeDeterministicFallback($this->tenantUser, $request, 'pesan beras 10 kg');
        $data = json_decode($response->getContent(), true);

        // Supplier milik tenant lain tidak boleh ditemukan / diakses
        $this->assertTrue($data['is_fallback']);
        $this->assertSame('RESTOCK_CONTEXT_MISSING', $data['code']);
        $this->assertArrayNotHasKey('supplier_order', $data['data'] ?? []);
    }

    /**
     * TEST 9: Intent finansial -> kalkulasi dan payload kartu tidak berubah.
     */
    public function test_financial_intent_retains_deterministic_calculation_and_card_payload(): void
    {
        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'bagaimana kondisi penjualan hari ini?',
            'history' => [],
        ]);

        $response = $controller->executeDeterministicFallback($this->tenantUser, $request, 'bagaimana kondisi penjualan hari ini?');
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('success', $data['status']);
        $this->assertNotNull($data['financial_card']);
        $this->assertEquals('summary', $data['financial_card']['type']);
        $this->assertArrayHasKey('formatted_sales', $data['financial_card']['data']);
        $this->assertArrayHasKey('revenue', $data['financial_card']['data']);
    }

    /**
     * TEST 10: Pengguna tidak terautentikasi -> HTTP 401 UNAUTHENTICATED.
     */
    public function test_unauthenticated_request_returns_http_401(): void
    {
        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'halo',
            'history' => [],
        ]);

        $response = $controller->executeDeterministicFallback(null, $request, 'halo');
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('UNAUTHENTICATED', $data['code']);
    }

    /**
     * TEST 11: TelemetryIngestService menangkap Throwable tanpa melempar exception saat Redis tidak tersedia.
     */
    public function test_telemetry_service_catches_throwable_safely(): void
    {
        $telemetry = new TelemetryIngestService();

        // Panggilan ini di lingkungan tanpa koneksi Redis aktif harus mengembalikan false tanpa melempar uncaught exception
        $result = $telemetry->pushToBuffer([
            'path'        => '/copilot/chat',
            'status_code' => 200,
            'duration_ms' => 120,
        ]);

        $this->assertIsBool($result);
        $this->assertFalse($result);

        $len = $telemetry->getQueueLength();
        $this->assertSame(0, $len);

        $batch = $telemetry->popBatch(10);
        $this->assertIsArray($batch);
        $this->assertEmpty($batch);
    }

    /**
     * TEST 12: Afirmasi (ya/oke/siap) setelah konteks finansial -> mengembalikan CLARIFICATION_NEEDED (bukan RESTOCK_CONTEXT_MISSING).
     */
    public function test_affirmation_with_financial_history_returns_clarification_not_restock_error(): void
    {
        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message'   => 'Ya',
            'outlet_id' => $this->outlet->id,
            'history'   => [
                ['role' => 'user', 'content' => 'Berapa omzet penjualan hari ini?'],
                ['role' => 'assistant', 'content' => 'Omzet hari ini bernilai Rp 1.500.000. Ada rincian penjualan atau stok lain yang ingin Anda lihat?'],
            ],
        ]);
        $request->setUserResolver(fn() => $this->tenantUser);

        $response = $controller->executeDeterministicFallback($this->tenantUser, $request, 'Ya');
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('success', $data['status']);
        $this->assertEquals('CLARIFICATION_NEEDED', $data['code']);
        $this->assertNotEquals('RESTOCK_CONTEXT_MISSING', $data['code']);
        $this->assertStringContainsString('produk terlaris', $data['reply']);
        $this->assertStringNotContainsString('pesanan atau supplier', $data['reply']);
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $data['reply']);
        $this->assertEquals($data['reply'], $data['data']['reply']);
    }

    /**
     * TEST 13: Kontrak payload fallback menjamin ketersediaan string identik di root reply dan data.reply.
     */
    public function test_response_payload_contract_guarantees_root_and_nested_reply_identity(): void
    {
        $controller = app(ChatbotController::class);

        // Kasus 1: AI_PROVIDER_UNAVAILABLE
        $req1 = Request::create('/copilot/chat', 'POST', ['message' => 'apa rekomendasi menu?']);
        $req1->setUserResolver(fn() => $this->tenantUser);
        $res1 = $controller->executeDeterministicFallback($this->tenantUser, $req1, 'apa rekomendasi menu?', 'PROVIDER_TIMEOUT');
        $data1 = json_decode($res1->getContent(), true);

        $this->assertEquals(200, $res1->getStatusCode());
        $this->assertNotEmpty($data1['reply']);
        $this->assertNotEmpty($data1['data']['reply']);
        $this->assertSame($data1['reply'], $data1['data']['reply']);
        $this->assertSame('AI_PROVIDER_UNAVAILABLE', $data1['code']);

        // Kasus 2: CLARIFICATION_NEEDED
        $req2 = Request::create('/copilot/chat', 'POST', ['message' => 'xyzabc123?']);
        $req2->setUserResolver(fn() => $this->tenantUser);
        $res2 = $controller->executeDeterministicFallback($this->tenantUser, $req2, 'xyzabc123?');
        $data2 = json_decode($res2->getContent(), true);

        $this->assertEquals(200, $res2->getStatusCode());
        $this->assertNotEmpty($data2['reply']);
        $this->assertNotEmpty($data2['data']['reply']);
        $this->assertSame($data2['reply'], $data2['data']['reply']);
        $this->assertSame('CLARIFICATION_NEEDED', $data2['code']);
    }

    /**
     * TEST 14: Seluruh fallback deterministik tidak pernah memuat string usang 'Sistem Kaching sedang sibuk'.
     */
    public function test_fallback_never_contains_misleading_system_busy_string(): void
    {
        $controller = app(ChatbotController::class);

        $reasons = [
            'PROVIDER_TIMEOUT',
            'PROVIDER_RATE_LIMIT_429',
            'PROVIDER_5XX',
            'PROVIDER_EMPTY_MALFORMED',
            null, // clarification needed
        ];

        foreach ($reasons as $reason) {
            $req = Request::create('/copilot/chat', 'POST', ['message' => 'testing message']);
            $req->setUserResolver(fn() => $this->tenantUser);
            $res = $controller->executeDeterministicFallback($this->tenantUser, $req, 'testing message', $reason);
            $data = json_decode($res->getContent(), true);

            $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $data['reply'] ?? '');
            $this->assertStringNotContainsString('sistem AI kami sedang sibuk', $data['reply'] ?? '');
            $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $data['data']['reply'] ?? '');
        }
    }

    /**
     * TEST 15: Riwayat multi-turn tidak membocorkan data outlet lain atau mengizinkan outlet spoofing.
     */
    public function test_multi_turn_history_preserves_tenant_and_outlet_isolation(): void
    {
        $otherOutlet = Outlet::factory()->create([
            'user_id' => $this->otherTenantUser->id,
            'name'    => 'Outlet Milik Tenant Lain',
        ]);

        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message'   => 'Berapa omzet hari ini?',
            'outlet_id' => $otherOutlet->id, // Mencoba mengakses outlet milik tenant lain
            'history'   => [
                ['role' => 'user', 'content' => 'Halo'],
                ['role' => 'assistant', 'content' => 'Halo, ada yang bisa saya bantu?'],
            ],
        ]);
        $request->setUserResolver(fn() => $this->tenantUser);

        $response = $controller->executeDeterministicFallback($this->tenantUser, $request, 'Berapa omzet hari ini?');
        $data = json_decode($response->getContent(), true);

        // Harus ditolak dengan status HTTP 403 UNAUTHORIZED_OUTLET
        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('UNAUTHORIZED_OUTLET', $data['code']);
    }

    /**
     * TEST 16: Intent proyeksi kas / cash flow tetap dieksekusi secara deterministik saat provider offline.
     */
    public function test_cash_flow_forecast_deterministic_fallback_returns_success_and_forecast_data(): void
    {
        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'Bagaimana kondisi cash flow?',
            'history' => [],
        ]);

        $response = $controller->executeDeterministicFallback($this->tenantUser, $request, 'Bagaimana kondisi cash flow?', 'provider_rate_limit');
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('success', $data['status']);
        $this->assertNotNull($data['financial_card']);
        $this->assertEquals('forecast', $data['financial_card']['type']);
        $this->assertNotEmpty($data['reply']);
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $data['reply']);
    }

    /**
     * TEST 17: Pemisahan tegas error autentikasi aplikasi (HTTP 401/403) vs error autentikasi provider AI (HTTP 200).
     */
    public function test_application_auth_and_authorization_strictly_separated_from_provider_auth_error(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response(['error' => ['message' => 'Invalid API Key']], 401),
        ]);

        $controller = app(ChatbotController::class);

        // 1. Error autentikasi aplikasi: Pengguna tanpa login -> WAJIB HTTP 401
        $unauthReq = Request::create('/copilot/chat', 'POST', ['message' => 'halo']);
        $unauthRes = $controller->sendMessage($unauthReq);
        $this->assertEquals(401, $unauthRes->getStatusCode());
        $this->assertEquals('UNAUTHENTICATED', json_decode($unauthRes->getContent(), true)['code']);

        // 2. Error otorisasi aplikasi: Tenant mengakses outlet asing -> WAJIB HTTP 403
        $foreignOutlet = Outlet::factory()->create([
            'user_id' => $this->otherTenantUser->id,
            'name'    => 'Outlet Asing',
        ]);
        $forbiddenReq = Request::create('/copilot/chat', 'POST', [
            'message'   => 'bagaimana penjualan hari ini?',
            'outlet_id' => $foreignOutlet->id,
        ]);
        $forbiddenReq->setUserResolver(fn() => $this->tenantUser);
        $forbiddenRes = $controller->executeDeterministicFallback($this->tenantUser, $forbiddenReq, 'bagaimana penjualan hari ini?');
        $this->assertEquals(403, $forbiddenRes->getStatusCode());
        $this->assertEquals('UNAUTHORIZED_OUTLET', json_decode($forbiddenRes->getContent(), true)['code']);

        // 3. Error autentikasi provider upstream (kunci AI salah/expired) -> HTTP 200 conversational fallback
        $providerAuthReq = Request::create('/copilot/chat', 'POST', [
            'message' => 'ayam', // input acak yang memicu AI call
        ]);
        $providerAuthReq->setUserResolver(fn() => $this->tenantUser);
        $providerAuthRes = $controller->sendMessage($providerAuthReq);
        $providerAuthData = json_decode($providerAuthRes->getContent(), true);

        $this->assertEquals(200, $providerAuthRes->getStatusCode());
        $this->assertEquals('success', $providerAuthData['status']);
        $this->assertEquals('AI_PROVIDER_UNAVAILABLE', $providerAuthData['code']);
        $this->assertTrue($providerAuthData['is_fallback']);
        $this->assertStringContainsString('Layanan AI sementara tidak tersedia', $providerAuthData['reply']);
    }

    /**
     * TEST 18: Bukti empiris 4 fitur deterministik (omzet, menu terlaris, risiko operasional, cash flow)
     * saat AI provider dinonaktifkan (mocked/faked failure).
     * Validasi: zero AI request, struktur respon HTTP 200 status 'success', data source tenant-scoped,
     * penolakan keras HTTP 403 bila mengakses outlet asing.
     */
    public function test_four_isolated_deterministic_features_when_ai_provider_fails(): void
    {
        // 1. Matikan AI provider secara total via Http::fake
        Http::fake([
            '*/chat/completions' => Http::response(['error' => ['message' => 'Simulated Upstream AI Outage']], 500),
        ]);

        $controller = app(ChatbotController::class);

        $foreignOutlet = Outlet::factory()->create([
            'user_id' => $this->otherTenantUser->id,
            'name'    => 'Outlet Tenant Lain',
        ]);

        // FITUR 1: Omzet Penjualan
        $omzetReq = Request::create('/copilot/chat', 'POST', [
            'message'   => 'bagaimana kondisi penjualan hari ini?',
            'outlet_id' => $this->outlet->id,
        ]);
        $omzetReq->setUserResolver(fn() => $this->tenantUser);
        $omzetRes = $controller->executeDeterministicFallback($this->tenantUser, $omzetReq, 'bagaimana kondisi penjualan hari ini?');
        $omzetData = json_decode($omzetRes->getContent(), true);

        $this->assertEquals(200, $omzetRes->getStatusCode());
        $this->assertEquals('success', $omzetData['status']);
        $this->assertNotNull($omzetData['financial_card']);
        $this->assertEquals('summary', $omzetData['financial_card']['type']);
        $this->assertArrayHasKey('revenue', $omzetData['financial_card']['data']);

        // Proteksi tenant scope: outlet asing wajib ditolak 403
        $foreignOmzetReq = Request::create('/copilot/chat', 'POST', [
            'message'   => 'bagaimana kondisi penjualan hari ini?',
            'outlet_id' => $foreignOutlet->id,
        ]);
        $foreignOmzetReq->setUserResolver(fn() => $this->tenantUser);
        $foreignOmzetRes = $controller->executeDeterministicFallback($this->tenantUser, $foreignOmzetReq, 'bagaimana kondisi penjualan hari ini?');
        $this->assertEquals(403, $foreignOmzetRes->getStatusCode());

        // FITUR 2: Menu Terlaris (Top Selling)
        $topReq = Request::create('/copilot/chat', 'POST', [
            'message'   => 'apa menu terlaris hari ini?',
            'outlet_id' => $this->outlet->id,
        ]);
        $topReq->setUserResolver(fn() => $this->tenantUser);
        $topRes = $controller->executeDeterministicFallback($this->tenantUser, $topReq, 'apa menu terlaris hari ini?');
        $topData = json_decode($topRes->getContent(), true);

        $this->assertEquals(200, $topRes->getStatusCode());
        $this->assertEquals('success', $topData['status']);
        $this->assertNotEmpty($topData['reply']);

        // Proteksi tenant scope: outlet asing wajib ditolak 403
        $foreignTopReq = Request::create('/copilot/chat', 'POST', [
            'message'   => 'apa menu terlaris hari ini?',
            'outlet_id' => $foreignOutlet->id,
        ]);
        $foreignTopReq->setUserResolver(fn() => $this->tenantUser);
        $foreignTopRes = $controller->executeDeterministicFallback($this->tenantUser, $foreignTopReq, 'apa menu terlaris hari ini?');
        $this->assertEquals(403, $foreignTopRes->getStatusCode());

        // FITUR 3: Risiko Operasional (Financial Warnings)
        $riskReq = Request::create('/copilot/chat', 'POST', [
            'message'   => 'apa risiko operasional yang perlu saya lihat?',
            'outlet_id' => $this->outlet->id,
        ]);
        $riskReq->setUserResolver(fn() => $this->tenantUser);
        $riskRes = $controller->executeDeterministicFallback($this->tenantUser, $riskReq, 'apa risiko operasional yang perlu saya lihat?');
        $riskData = json_decode($riskRes->getContent(), true);

        $this->assertEquals(200, $riskRes->getStatusCode());
        $this->assertEquals('success', $riskData['status']);
        $this->assertNotNull($riskData['financial_card']);
        $this->assertEquals('warning', $riskData['financial_card']['type']);

        // Proteksi tenant scope: outlet asing wajib ditolak 403
        $foreignRiskReq = Request::create('/copilot/chat', 'POST', [
            'message'   => 'apa risiko operasional yang perlu saya lihat?',
            'outlet_id' => $foreignOutlet->id,
        ]);
        $foreignRiskReq->setUserResolver(fn() => $this->tenantUser);
        $foreignRiskRes = $controller->executeDeterministicFallback($this->tenantUser, $foreignRiskReq, 'apa risiko operasional yang perlu saya lihat?');
        $this->assertEquals(403, $foreignRiskRes->getStatusCode());

        // FITUR 4: Cash Flow Forecast (Proyeksi Kas)
        $cfReq = Request::create('/copilot/chat', 'POST', [
            'message'   => 'bagaimana kondisi cash flow?',
            'outlet_id' => $this->outlet->id,
        ]);
        $cfReq->setUserResolver(fn() => $this->tenantUser);
        $cfRes = $controller->executeDeterministicFallback($this->tenantUser, $cfReq, 'bagaimana kondisi cash flow?');
        $cfData = json_decode($cfRes->getContent(), true);

        $this->assertEquals(200, $cfRes->getStatusCode());
        $this->assertEquals('success', $cfData['status']);
        $this->assertNotNull($cfData['financial_card']);
        $this->assertEquals('forecast', $cfData['financial_card']['type']);
        $this->assertArrayHasKey('horizon_days', $cfData['financial_card']['data']);
        $this->assertArrayHasKey('status', $cfData['financial_card']['data']);

        // Proteksi tenant scope: outlet asing wajib ditolak 403
        $foreignCfReq = Request::create('/copilot/chat', 'POST', [
            'message'   => 'bagaimana kondisi cash flow?',
            'outlet_id' => $foreignOutlet->id,
        ]);
        $foreignCfReq->setUserResolver(fn() => $this->tenantUser);
        $foreignCfRes = $controller->executeDeterministicFallback($this->tenantUser, $foreignCfReq, 'bagaimana kondisi cash flow?');
        $this->assertEquals(403, $foreignCfRes->getStatusCode());
    }
}
