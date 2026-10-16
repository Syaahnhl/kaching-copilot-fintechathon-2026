<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Supplier;
use App\Http\Controllers\ChatbotController;
use App\Services\Supplier\SupplierRestockMessageService;
use App\Services\Financial\FinanceCopilotOrchestrationService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class CopilotMultiTurnRestockFailureReproductionTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenantUser;
    protected User $otherTenantUser;
    protected Supplier $supplier;
    protected Supplier $otherSupplier;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setLocale('id');
        // Tanggal uji acuan: Selasa, 13 Oktober 2026, jam 14:00 WIB
        Carbon::setTestNow(Carbon::create(2026, 10, 13, 14, 0, 0, 'Asia/Jakarta'));

        $this->tenantUser = User::factory()->create([
            'name'              => 'Tenant Restock Owner',
            'subscription_tier' => 'PRO',
        ]);

        $this->otherTenantUser = User::factory()->create([
            'name'              => 'Other Tenant Owner',
            'subscription_tier' => 'PRO',
        ]);

        $this->supplier = Supplier::create([
            'user_id'        => $this->tenantUser->id,
            'name'           => 'Toko Sembako Berkah Jaya',
            'contact_person' => 'Pak Slamet E2E',
            'phone'          => '0852-1122-3344',
            'address'        => 'Jl. Pasar Tradisional No. 10',
            'is_active'      => true,
        ]);

        $this->otherSupplier = Supplier::create([
            'user_id'        => $this->otherTenantUser->id,
            'name'           => 'Supplier Rahasia Tenant Lain',
            'contact_person' => 'Pak Budi',
            'phone'          => '0812-9988-7766',
            'address'        => 'Jl. Terlarang No. 1',
            'is_active'      => true,
        ]);
    }

    /**
     * TEST 1: Pemulihan konteks restock multi-turn dari balasan asisten natural
     * dan ekstraksi kuantitas 20 kg dari pesan terkini.
     */
    public function test_extract_restock_context_succeeds_on_natural_assistant_confirmation_and_latest_turn(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'tolong bantu aku chat ke supplierku'],
            ['role' => 'assistant', 'content' => "Berikut 5 supplier terdaftar Anda:\n1. Toko Sembako Berkah Jaya (0852-1122-3344)\n2. CV Sumber Rejeki\n3. PT Pangan Abadi\n4. Toko Beras Makmur\n5. Distributor Sembako Nusantara\n\nSilakan pilih supplier yang ingin Anda hubungi."],
            ['role' => 'user', 'content' => 'sembako bro beras 1kg'],
            ['role' => 'assistant', 'content' => 'Baik, Toko Sembako Berkah Jaya telah dipilih untuk produk Beras 1kg. Mohon sebutkan berapa jumlah yang ingin dipesan dan tanggal pengiriman yang diinginkan?'],
            ['role' => 'user', 'content' => '20kg tgl pengiriman minggu'],
        ];

        $controller = app(ChatbotController::class);
        $refMethod = new \ReflectionMethod($controller, 'extractRestockContextFromHistory');
        $refMethod->setAccessible(true);

        $extracted = $refMethod->invoke($controller, $history, $this->tenantUser->id);

        $this->assertNotNull($extracted, 'extractRestockContextFromHistory wajib berhasil mengekstrak konteks supplier');
        $this->assertEquals($this->supplier->id, $extracted['supplier_id']);
        $this->assertEquals('Toko Sembako Berkah Jaya', $extracted['supplier_name']);
        $this->assertCount(1, $extracted['items']);
        $this->assertEquals(20.0, $extracted['items'][0]['quantity']);
        $this->assertEquals('kg', $extracted['items'][0]['unit']);
        $this->assertEquals('Minggu', $extracted['delivery_date']);
    }

    /**
     * TEST 2: Multi-turn restock berhasil menghasilkan draf pesan dan WhatsApp link
     * ketika kuantitas 20 kg dan tanggal valid diberikan.
     */
    public function test_multi_turn_restock_generates_draft_with_valid_date_and_quantity(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'tolong bantu aku chat ke supplierku'],
            ['role' => 'assistant', 'content' => "Berikut 5 supplier terdaftar Anda:\n1. Toko Sembako Berkah Jaya (0852-1122-3344)..."],
            ['role' => 'user', 'content' => 'sembako bro beras 1kg'],
            ['role' => 'assistant', 'content' => 'Baik, Toko Sembako Berkah Jaya telah dipilih untuk produk Beras 1kg. Mohon sebutkan berapa jumlah yang ingin dipesan dan tanggal pengiriman yang diinginkan?'],
        ];

        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => '20kg tgl pengiriman minggu',
            'history' => $history,
        ]);

        $response = $controller->executeDeterministicFallback($this->tenantUser, $request, '20kg tgl pengiriman minggu');

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertEquals('success', $data['status']);
        $this->assertArrayHasKey('supplier_order', $data['data']);
        $order = $data['data']['supplier_order'];

        $this->assertEquals('Toko Sembako Berkah Jaya', $order['supplier']['name']);
        $this->assertEquals(20, $order['items'][0]['quantity']);
        $this->assertEquals('kg', $order['items'][0]['unit']);
        $this->assertStringContainsString('Minggu', $order['message']);
        $this->assertStringContainsString('20 kg', $order['message']);
        $this->assertTrue($order['whatsapp_available']);
        $this->assertStringContainsString('https://wa.me/6285211223344', $order['whatsapp_url']);
    }

    /**
     * TEST 3: Frasa tanggal kontradiktif ("besok minggu") menghasilkan permintaan klarifikasi
     * tanpa menebak tanggal dan tanpa jatuh ke generic error sistem sibuk.
     */
    public function test_contradictory_date_phrase_triggers_clarification_without_guessing(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'tolong bantu aku chat ke supplierku'],
            ['role' => 'assistant', 'content' => "Berikut 5 supplier terdaftar Anda:\n1. Toko Sembako Berkah Jaya..."],
            ['role' => 'user', 'content' => 'sembako bro beras 1kg'],
            ['role' => 'assistant', 'content' => 'Baik, Toko Sembako Berkah Jaya telah dipilih untuk produk Beras 1kg. Mohon sebutkan berapa jumlah yang ingin dipesan dan tanggal pengiriman yang diinginkan?'],
        ];

        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => '20kg tgl pengiriman besok minggu',
            'history' => $history,
        ]);

        $response = $controller->executeDeterministicFallback($this->tenantUser, $request, '20kg tgl pengiriman besok minggu');

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertEquals('success', $data['status']);
        $this->assertEquals('DATE_AMBIGUITY', $data['data']['clarification']);
        $this->assertStringContainsString('klarifikasi', strtolower($data['reply']));
        $this->assertStringContainsString('Rabu', $data['reply']);
        $this->assertStringContainsString('Minggu', $data['reply']);
        // Pastikan bukan pesan sistem sibuk
        $this->assertNotEquals(__('copilot.system.busy'), $data['reply']);
    }

    /**
     * TEST 4: Afirmasi tanpa konteks restock TIDAK membuat draf.
     */
    public function test_affirmation_without_restock_context_does_not_create_draft(): void
    {
        $controller = app(ChatbotController::class);
        $generalHistory = [
            ['role' => 'user', 'content' => 'bagaimana omzet hari ini?'],
            ['role' => 'assistant', 'content' => 'Omzet hari ini adalah Rp 1.500.000. Apakah ada yang ingin ditanyakan lagi?'],
        ];

        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'ya',
            'history' => $generalHistory,
        ]);

        $response = $controller->executeDeterministicFallback($this->tenantUser, $request, 'ya');
        $data = json_decode($response->getContent(), true);

        $this->assertEquals('error', $data['status']);
        $this->assertEquals(__('copilot.system.restock_context_missing'), $data['reply']);
        $this->assertEquals('RESTOCK_CONTEXT_MISSING', $data['code']);
        $this->assertTrue($data['is_fallback'] ?? false);
        $this->assertArrayNotHasKey('supplier_order', $data['data'] ?? []);
    }

    /**
     * TEST 5: Pesan parameter angka/satuan tanpa konteks restock TIDAK membuat draf.
     */
    public function test_parameter_without_restock_context_does_not_create_draft(): void
    {
        $controller = app(ChatbotController::class);

        $request = Request::create('/copilot/chat', 'POST', [
            'message' => '20kg',
            'history' => [],
        ]);

        $response = $controller->executeDeterministicFallback($this->tenantUser, $request, '20kg');
        $data = json_decode($response->getContent(), true);

        $this->assertEquals('error', $data['status']);
        $this->assertEquals(__('copilot.system.restock_context_missing'), $data['reply']);
        $this->assertEquals('RESTOCK_CONTEXT_MISSING', $data['code']);
        $this->assertTrue($data['is_fallback'] ?? false);
        $this->assertArrayNotHasKey('supplier_order', $data['data'] ?? []);
    }

    /**
     * TEST 6: User tidak terautentikasi (null / <= 0) ditolak fail-closed tanpa TypeError.
     */
    public function test_unauthenticated_user_rejected_without_type_error(): void
    {
        $orchestrator = app(FinanceCopilotOrchestrationService::class);

        // Uji dengan null
        $resNull = $orchestrator->dispatchTool('generate_supplier_restock_message', null, null, [
            'supplier_name' => 'Toko Sembako Berkah Jaya',
            'items'         => [['item_name' => 'Beras', 'quantity' => 20, 'unit' => 'kg']],
        ]);

        $this->assertEquals('ERROR', $resNull['status']);
        $this->assertEquals('UNAUTHORIZED_USER', $resNull['code']);
        $this->assertNull($resNull['data']);

        // Uji dengan userId <= 0
        $resZero = $orchestrator->dispatchTool('generate_supplier_restock_message', 0, null, [
            'supplier_name' => 'Toko Sembako Berkah Jaya',
            'items'         => [['item_name' => 'Beras', 'quantity' => 20, 'unit' => 'kg']],
        ]);

        $this->assertEquals('ERROR', $resZero['status']);
        $this->assertEquals('UNAUTHORIZED_USER', $resZero['code']);
        $this->assertNull($resZero['data']);
    }

    /**
     * TEST 7: Akses ke supplier milik tenant lain strictly ditolak (Tenant Isolation).
     */
    public function test_cross_tenant_supplier_access_strictly_rejected(): void
    {
        $service = app(SupplierRestockMessageService::class);

        // Tenant 1 mencoba mengakses supplier milik Tenant 2
        $result = $service->generateForSupplier($this->tenantUser->id, $this->otherSupplier->id, [
            ['item_name' => 'Bahan Rahasia', 'quantity' => 10, 'unit' => 'kg']
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('UNAUTHORIZED_SUPPLIER', $result['status']);
        $this->assertNull($result['supplier']);
        $this->assertNull($result['whatsapp_url']);
    }

    /**
     * TEST 8: SupplierRestockMessageService mempertahankan delivery_date opsional
     * ke dalam pesan draf dan return payload secara konsisten.
     */
    public function test_supplier_restock_service_preserves_optional_delivery_date(): void
    {
        $service = app(SupplierRestockMessageService::class);

        $items = [
            ['item_name' => 'Beras Premium', 'quantity' => 20, 'unit' => 'kg']
        ];

        // 1. Dengan delivery_date
        $resultWithDate = $service->generateForSupplier($this->tenantUser->id, $this->supplier->id, $items, 'Minggu, 18 Oktober 2026');

        $this->assertTrue($resultWithDate['success']);
        $this->assertEquals('Minggu, 18 Oktober 2026', $resultWithDate['delivery_date']);
        $this->assertStringContainsString('Rencana / tanggal pengiriman: Minggu, 18 Oktober 2026', $resultWithDate['message']);

        // 2. Tanpa delivery_date (backward compatibility)
        $resultWithoutDate = $service->generateForSupplier($this->tenantUser->id, $this->supplier->id, $items);

        $this->assertTrue($resultWithoutDate['success']);
        $this->assertNull($resultWithoutDate['delivery_date']);
        $this->assertStringNotContainsString('pengiriman:', $resultWithoutDate['message']);
    }

    /**
     * TEST 9: Penanganan aman untuk HTTP 500 rate limit, timeout, dan respons kosong dari provider (Fake HTTP).
     */
    public function test_upstream_failures_handled_safely_with_fake_http(): void
    {
        $this->actingAs($this->tenantUser);

        // 1. Simulasi HTTP 500 Rate Limit
        Http::fake([
            '*/chat/completions' => Http::response(['error' => 'Rate limit exceeded'], 500),
        ]);

        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'tolong bantu aku chat ke supplierku',
            'history' => [],
        ]);
        $request->setUserResolver(fn() => $this->tenantUser);

        $response = $controller->sendMessage($request);
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('success', $data['status']);
        // Fallback mengambil daftar supplier terdaftar milik tenant
        $this->assertStringContainsString('Toko Sembako Berkah Jaya', $data['reply']);

        // 2. Simulasi respons kosong (token limit reasoning) pada Step 1
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [
                    [
                        'finish_reason' => 'length',
                        'message' => [
                            'role' => 'assistant',
                            'content' => '',
                            'reasoning_content' => 'thinking...',
                        ]
                    ]
                ]
            ], 200),
        ]);

        $response2 = $controller->sendMessage($request);
        $this->assertEquals(200, $response2->getStatusCode());
        $data2 = json_decode($response2->getContent(), true);
        $this->assertEquals('success', $data2['status']);
        $this->assertStringContainsString('Toko Sembako Berkah Jaya', $data2['reply']);
    }

    /**
     * TEST 10: Pencocokan nama supplier lengkap.
     */
    public function test_natural_supplier_matching_full_name(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'tolong bantu aku chat ke supplierku'],
            ['role' => 'assistant', 'content' => 'Tentu, silakan sebutkan nama supplier dan item yang ingin dipesan.'],
            ['role' => 'user', 'content' => 'Toko Sembako Berkah Jaya beras 1kg'],
        ];

        $controller = app(ChatbotController::class);
        $refMethod = new \ReflectionMethod($controller, 'extractRestockContextFromHistory');
        $refMethod->setAccessible(true);

        $extracted = $refMethod->invoke($controller, $history, $this->tenantUser->id);

        $this->assertNotNull($extracted);
        $this->assertEquals($this->supplier->id, $extracted['supplier_id']);
        $this->assertEquals('Toko Sembako Berkah Jaya', $extracted['supplier_name']);
        $this->assertEquals('Beras', $extracted['items'][0]['item_name']);
        $this->assertEquals(1.0, $extracted['items'][0]['quantity']);
    }

    /**
     * TEST 11: Pencocokan nama supplier parsial khas 'sembako'.
     */
    public function test_natural_supplier_matching_partial_keyword_sembako(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'tolong bantu aku chat ke supplierku'],
            ['role' => 'assistant', 'content' => 'Tentu, silakan sebutkan nama supplier dan item yang ingin dipesan.'],
            ['role' => 'user', 'content' => 'sembako bro beras 1kg'],
        ];

        $controller = app(ChatbotController::class);
        $refMethod = new \ReflectionMethod($controller, 'extractRestockContextFromHistory');
        $refMethod->setAccessible(true);

        $extracted = $refMethod->invoke($controller, $history, $this->tenantUser->id);

        $this->assertNotNull($extracted);
        $this->assertEquals($this->supplier->id, $extracted['supplier_id']);
        $this->assertEquals('Toko Sembako Berkah Jaya', $extracted['supplier_name']);
        $this->assertEquals('Beras', $extracted['items'][0]['item_name']);
        $this->assertEquals(1.0, $extracted['items'][0]['quantity']);
    }

    /**
     * TEST 12: Variasi kapitalisasi dan tanda baca pada nama supplier dan item.
     */
    public function test_natural_supplier_matching_case_and_punctuation_variations(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'tolong bantu aku chat ke supplierku'],
            ['role' => 'assistant', 'content' => 'Tentu, silakan sebutkan nama supplier dan item.'],
            ['role' => 'user', 'content' => '   SEMBAKO, bro! BERAS: 1KG...   '],
        ];

        $controller = app(ChatbotController::class);
        $refMethod = new \ReflectionMethod($controller, 'extractRestockContextFromHistory');
        $refMethod->setAccessible(true);

        $extracted = $refMethod->invoke($controller, $history, $this->tenantUser->id);

        $this->assertNotNull($extracted);
        $this->assertEquals($this->supplier->id, $extracted['supplier_id']);
        $this->assertEquals('Toko Sembako Berkah Jaya', $extracted['supplier_name']);
        $this->assertEquals('Beras', $extracted['items'][0]['item_name']);
        $this->assertEquals(1.0, $extracted['items'][0]['quantity']);
    }

    /**
     * TEST 13: Dua supplier dengan kata umum yang sama memicu klarifikasi tanpa tebak sembarang.
     */
    public function test_two_suppliers_with_same_distinctive_token_triggers_clarification(): void
    {
        // Buat supplier kedua dengan kata 'Sembako'
        Supplier::create([
            'user_id'        => $this->tenantUser->id,
            'name'           => 'CV Sembako Makmur',
            'contact_person' => 'Pak Joko',
            'phone'          => '0813-7788-9900',
            'address'        => 'Jl. Raya No. 5',
            'is_active'      => true,
        ]);

        $history = [
            ['role' => 'user', 'content' => 'tolong bantu aku chat ke supplierku'],
            ['role' => 'assistant', 'content' => 'Tentu, silakan sebutkan nama supplier.'],
        ];

        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'sembako bro beras 1kg',
            'history' => $history,
        ]);

        $response = $controller->executeDeterministicFallback($this->tenantUser, $request, 'sembako bro beras 1kg');

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertEquals('success', $data['status']);
        $this->assertEquals('SUPPLIER_AMBIGUITY', $data['data']['clarification'] ?? null);
        $this->assertContains('Toko Sembako Berkah Jaya', $data['data']['candidates'] ?? []);
        $this->assertContains('CV Sembako Makmur', $data['data']['candidates'] ?? []);
        $this->assertStringContainsString('Toko Sembako Berkah Jaya', $data['reply']);
        $this->assertStringContainsString('CV Sembako Makmur', $data['reply']);
        $this->assertNotEquals(__('copilot.system.busy'), $data['reply']);
    }

    /**
     * TEST 14: Supplier milik tenant lain tidak dapat dipilih.
     */
    public function test_supplier_belonging_to_other_tenant_cannot_be_selected(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'tolong bantu aku chat ke supplierku'],
            ['role' => 'assistant', 'content' => 'Tentu, silakan sebutkan nama supplier.'],
            ['role' => 'user', 'content' => 'Supplier Rahasia Tenant Lain beras 10kg'],
        ];

        $controller = app(ChatbotController::class);
        $refMethod = new \ReflectionMethod($controller, 'extractRestockContextFromHistory');
        $refMethod->setAccessible(true);

        $extracted = $refMethod->invoke($controller, $history, $this->tenantUser->id);

        // Tenant 1 tidak memiliki supplier bernama 'Supplier Rahasia Tenant Lain'
        $this->assertNull($extracted);
    }

    /**
     * TEST 15: Percakapan dua turn persis seperti reproduksi B3.6.36 berhasil membuat draf WhatsApp.
     */
    public function test_exact_two_turn_reproduction_of_b3636_succeeds_on_provider_failure(): void
    {
        $this->actingAs($this->tenantUser);

        // Simulasi HTTP 500 / Rate Limit pada AI Gateway
        Http::fake([
            '*/chat/completions' => Http::response(['error' => 'Rate limit exceeded'], 500),
        ]);

        $controller = app(ChatbotController::class);

        // Turn 2: Pengguna membalas "sembako bro beras 1kg" dengan riwayat Turn 1
        $history = [
            ['role' => 'user', 'content' => 'tolong bantu aku chat ke supplierku'],
            ['role' => 'assistant', 'content' => 'Tentu, silakan sebutkan nama supplier dan item yang ingin dipesan.'],
        ];

        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'sembako bro beras 1kg',
            'history' => $history,
        ]);
        $request->setUserResolver(fn() => $this->tenantUser);

        $response = $controller->sendMessage($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertEquals('success', $data['status']);
        $this->assertNotEquals(__('copilot.system.busy'), $data['reply']);
        $this->assertStringContainsString('Toko Sembako Berkah Jaya', $data['reply']);
        $this->assertStringContainsString('Beras', $data['reply']);
        $this->assertStringContainsString('1 kg', $data['reply']);
        $this->assertArrayHasKey('supplier_order', $data['data'] ?? []);
        $this->assertStringContainsString('https://wa.me/', $data['data']['supplier_order']['whatsapp_url'] ?? '');
    }

    /**
     * TEST 16: Upstream ConnectionException (timeout) menghasilkan draf pesan via fallback.
     */
    public function test_upstream_connection_timeout_handled_seamlessly_by_fallback(): void
    {
        $this->actingAs($this->tenantUser);

        Http::fake([
            '*/chat/completions' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
            },
        ]);

        $controller = app(ChatbotController::class);

        $history = [
            ['role' => 'user', 'content' => 'tolong bantu aku chat ke supplierku'],
            ['role' => 'assistant', 'content' => 'Tentu, mau pesan apa ke supplier mana?'],
        ];

        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'sembako bro beras 1kg',
            'history' => $history,
        ]);
        $request->setUserResolver(fn() => $this->tenantUser);

        $response = $controller->sendMessage($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertEquals('success', $data['status']);
        $this->assertNotEquals(__('copilot.system.busy'), $data['reply']);
        $this->assertStringContainsString('Toko Sembako Berkah Jaya', $data['reply']);
        $this->assertStringContainsString('https://wa.me/6285211223344', $data['data']['supplier_order']['whatsapp_url']);
    }

    /**
     * TEST 17: Fail-closed ketika pengguna tidak terautentikasi (HTTP 401).
     */
    public function test_fail_closed_when_user_not_authenticated_on_send_message(): void
    {
        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'sembako bro beras 1kg',
            'history' => [],
        ]);

        $response = $controller->sendMessage($request);

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertTrue(
            str_contains(strtolower($data['reply']), 'autentikasi') || str_contains(strtolower($data['reply']), 'unauthenticated'),
            'Pesan harus menyatakan unauthenticated / belum terautentikasi'
        );
    }

    /**
     * TEST 18: Intent baru yang tidak terkait tidak mewarisi konteks restock lama.
     */
    public function test_new_unrelated_intent_does_not_inherit_restock_context(): void
    {
        $this->actingAs($this->tenantUser);

        Http::fake([
            '*/chat/completions' => Http::response(['error' => 'Rate limit exceeded'], 500),
        ]);

        $controller = app(ChatbotController::class);

        // Riwayat lama membahas restock
        $history = [
            ['role' => 'user', 'content' => 'tolong bantu aku chat ke supplierku'],
            ['role' => 'assistant', 'content' => 'Tentu, silakan sebutkan nama supplier dan item yang ingin dipesan.'],
        ];

        // Pesan baru adalah omzet (unrelated intent)
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'berapa omzet hari ini?',
            'history' => $history,
        ]);
        $request->setUserResolver(fn() => $this->tenantUser);

        $response = $controller->sendMessage($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        // Tidak boleh menghasilkan order supplier
        $this->assertArrayNotHasKey('supplier_order', $data['data'] ?? []);
        // Harus merespons omzet / sales summary, bukan pesan supplier restock
        $replyLower = strtolower($data['reply']);
        $this->assertTrue(
            str_contains($replyLower, 'omzet') || str_contains($replyLower, 'sales') || str_contains($replyLower, 'revenue'),
            'Respons harus berupa ringkasan omzet/penjualan'
        );
    }
}
