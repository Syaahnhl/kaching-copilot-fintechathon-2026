<?php

namespace Tests\Unit;

use App\Http\Controllers\ChatbotController;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ChatbotPromptBuilder;
use App\Services\Financial\FinanceCopilotOrchestrationService;
use App\Services\Supplier\SupplierRestockMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierWhatsAppDirectChatReproductionTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant;
    protected Outlet $outlet;
    protected Supplier $supplier;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = User::factory()->create([
            'email' => 'berkah@kaching.id',
            'role'  => 'owner',
        ]);

        $this->outlet = Outlet::create([
            'user_id' => $this->tenant->id,
            'name'    => 'Kaching Mart',
        ]);

        $this->supplier = Supplier::create([
            'user_id'        => $this->tenant->id,
            'name'           => 'Toko Sembako Berkah Jaya',
            'contact_person' => 'Pak/Bu',
            'phone'          => '0852-1122-3344',
            'is_active'      => true,
        ]);

        $this->item = Item::create([
            'outlet_id'     => $this->outlet->id,
            'name'          => 'Beras Premium',
            'base_uom'      => 'kg',
            'cost_price'    => 14500.0,
            'reorder_point' => 50.0,
            'is_active'     => true,
        ]);
    }

    /**
     * TEST R1: Verifikasi normalisasi nomor 0852-1122-3344 menjadi 6285211223344
     */
    public function test_r1_phone_normalization_for_berkah_jaya(): void
    {
        $service = new SupplierRestockMessageService();
        $normalized = $service->normalizePhoneNumber($this->supplier->phone);

        $this->assertEquals('6285211223344', $normalized);
    }

    /**
     * TEST R2: Verifikasi tool generate_supplier_restock_message terdaftar di Orchestrator
     */
    public function test_r2_tool_is_registered_in_orchestrator(): void
    {
        /** @var FinanceCopilotOrchestrationService $copilot */
        $copilot = app(FinanceCopilotOrchestrationService::class);
        $tools = $copilot->getRegisteredTools();

        $this->assertArrayHasKey('generate_supplier_restock_message', $tools);
    }

    /**
     * TEST R3: Verifikasi dispatch tool generate_supplier_restock_message berhasil dan aman
     */
    public function test_r3_dispatch_tool_generate_supplier_restock_message(): void
    {
        /** @var FinanceCopilotOrchestrationService $copilot */
        $copilot = app(FinanceCopilotOrchestrationService::class);

        $result = $copilot->dispatchTool('generate_supplier_restock_message', $this->tenant->id, $this->outlet->id, [
            'supplier_id' => $this->supplier->id,
            'items'       => [
                ['item_name' => 'Beras Premium', 'quantity' => 30, 'unit' => 'kg'],
            ],
        ]);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertArrayHasKey('data', $result);
        $this->assertTrue($result['data']['success']);
        $this->assertEquals('6285211223344', $result['data']['supplier']['clean_phone']);
        $this->assertTrue($result['data']['whatsapp_available']);
        $this->assertStringStartsWith('https://wa.me/6285211223344?text=', $result['data']['whatsapp_url']);
    }

    /**
     * TEST R4: Verifikasi intent detection untuk follow-up request WhatsApp direct chat
     */
    public function test_r4_intent_detection_for_whatsapp_follow_up(): void
    {
        $userMessage = 'ya buatkan chatnya untuk direct ke wa';
        $intents = ChatbotPromptBuilder::detectIntents($userMessage);

        $this->assertTrue(
            in_array(ChatbotPromptBuilder::CLUSTER_EXPENSES, $intents, true) ||
            in_array(ChatbotPromptBuilder::CLUSTER_MENU, $intents, true),
            'Pesan follow-up WA harus dikenali sebagai cluster EXPENSES atau MENU.'
        );
    }

    /**
     * TEST R5: Verifikasi isFollowUpQuestion mengenali konfirmasi/tindak lanjut singkat
     */
    public function test_r5_is_follow_up_recognizes_affirmative_requests(): void
    {
        $this->assertTrue(ChatbotPromptBuilder::isFollowUpQuestion('ya buatkan chatnya untuk direct ke wa'));
        $this->assertTrue(ChatbotPromptBuilder::isFollowUpQuestion('buatkan chatnya untuk direct ke wa'));
        $this->assertTrue(ChatbotPromptBuilder::isFollowUpQuestion('hubungi via wa'));
    }

    /**
     * TEST R6: Verifikasi deterministic fallback memulihkan konteks dari turn 2 dan menghasilkan tautan WhatsApp
     */
    public function test_r6_deterministic_fallback_recovers_context_and_produces_whatsapp_link(): void
    {
        app()->setLocale('id');
        $this->actingAs($this->tenant);

        // Riwayat 2 turn sebelumnya:
        $history = [
            [
                'role'    => 'user',
                'content' => 'Tolong pesankan beras ke Toko Sembako Berkah Jaya',
            ],
            [
                'role'    => 'assistant',
                'content' => "Rekomendasi Restock:\n"
                    . "Item: Beras Premium\n"
                    . "Quantity: 30 kg\n"
                    . "Unit price: Rp14.500/kg\n"
                    . "Estimated total: Rp435.000\n"
                    . "Supplier: Toko Sembako Berkah Jaya\n"
                    . "Contact: 0852-1122-3344",
            ],
        ];

        $request = Request::create('/api/chatbot/chat', 'POST', [
            'message' => 'ya buatkan chatnya untuk direct ke wa',
            'history' => $history,
        ]);
        $request->setUserResolver(fn () => $this->tenant);

        /** @var ChatbotController $controller */
        $controller = app(ChatbotController::class);
        $response = $controller->executeDeterministicFallback($this->tenant, $request, 'ya buatkan chatnya untuk direct ke wa');

        $this->assertEquals(200, $response->getStatusCode());
        $payload = $response->getData(true);

        $this->assertEquals('success', $payload['status']);
        $this->assertStringContainsString('Toko Sembako Berkah Jaya', $payload['reply']);
        $this->assertStringContainsString('Beras Premium — 30 kg', $payload['reply']);
        $this->assertStringContainsString('https://wa.me/6285211223344?text=', $payload['reply']);
        $this->assertStringContainsString('Buka WhatsApp untuk Konfirmasi Pesanan', $payload['reply']);
    }

    /**
     * TEST R7: Verifikasi ZERO mutasi database (tanpa PO, mutasi kas, atau mutasi inventori)
     */
    public function test_r7_zero_database_mutations_and_zero_po_creation(): void
    {
        $this->actingAs($this->tenant);

        $suppliersCountBefore = Supplier::count();
        $itemsCountBefore = Item::count();
        $outletsCountBefore = Outlet::count();
        $transactionsCountBefore = DB::table('transactions')->count();

        $history = [
            [
                'role'    => 'assistant',
                'content' => "Item: Beras Premium\nQuantity: 30 kg\nSupplier: Toko Sembako Berkah Jaya\nContact: 0852-1122-3344",
            ],
        ];

        $request = Request::create('/api/chatbot/chat', 'POST', [
            'message' => 'ya buatkan chatnya untuk direct ke wa',
            'history' => $history,
        ]);
        $request->setUserResolver(fn () => $this->tenant);

        /** @var ChatbotController $controller */
        $controller = app(ChatbotController::class);
        $response = $controller->executeDeterministicFallback($this->tenant, $request, 'ya buatkan chatnya untuk direct ke wa');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($suppliersCountBefore, Supplier::count());
        $this->assertEquals($itemsCountBefore, Item::count());
        $this->assertEquals($outletsCountBefore, Outlet::count());
        $this->assertEquals($transactionsCountBefore, DB::table('transactions')->count());
    }

    /**
     * TEST R8: Verifikasi respons dwibahasa mengikuti active locale
     */
    public function test_r8_bilingual_response_follows_active_locale(): void
    {
        $this->actingAs($this->tenant);

        $history = [
            [
                'role'    => 'assistant',
                'content' => "Item: Beras Premium\nQuantity: 30 kg\nSupplier: Toko Sembako Berkah Jaya\nContact: 0852-1122-3344",
            ],
        ];

        // 1. Indonesian locale
        app()->setLocale('id');
        $requestId = Request::create('/api/chatbot/chat', 'POST', [
            'message' => 'ya buatkan chatnya untuk direct ke wa',
            'history' => $history,
        ]);
        $requestId->setUserResolver(fn () => $this->tenant);

        $controller = app(ChatbotController::class);
        $resId = $controller->executeDeterministicFallback($this->tenant, $requestId, 'ya buatkan chatnya untuk direct ke wa');
        $payloadId = $resId->getData(true);
        $this->assertStringContainsString('Berikut draf pesan pemesanan restock', $payloadId['reply']);
        $this->assertStringContainsString('Buka WhatsApp untuk Konfirmasi Pesanan', $payloadId['reply']);

        // 2. English locale
        app()->setLocale('en');
        $requestEn = Request::create('/api/chatbot/chat', 'POST', [
            'message' => 'please prepare the whatsapp chat direct link',
            'history' => $history,
        ]);
        $requestEn->setUserResolver(fn () => $this->tenant);

        $resEn = $controller->executeDeterministicFallback($this->tenant, $requestEn, 'please prepare the whatsapp chat direct link');
        $payloadEn = $resEn->getData(true);
        $this->assertStringContainsString('Here is the restock order draft', $payloadEn['reply']);
        $this->assertStringContainsString('Open WhatsApp to Confirm Order', $payloadEn['reply']);

        // Reset locale
        app()->setLocale('id');
    }

    /**
     * TEST R9: Verifikasi Tenant Isolation: Tenant lain tidak dapat memicu draf supplier tenant ini
     */
    public function test_r9_tenant_isolation_enforced(): void
    {
        $tenantB = User::factory()->create([
            'email' => 'other_tenant@kaching.id',
            'role'  => 'owner',
        ]);
        $this->actingAs($tenantB);

        $history = [
            [
                'role'    => 'assistant',
                'content' => "Item: Beras Premium\nQuantity: 30 kg\nSupplier: Toko Sembako Berkah Jaya\nContact: 0852-1122-3344",
            ],
        ];

        $request = Request::create('/api/chatbot/chat', 'POST', [
            'message' => 'ya buatkan chatnya untuk direct ke wa',
            'history' => $history,
        ]);
        $request->setUserResolver(fn () => $tenantB);

        $controller = app(ChatbotController::class);
        $response = $controller->executeDeterministicFallback($tenantB, $request, 'ya buatkan chatnya untuk direct ke wa');
        $payload = $response->getData(true);

        // Tenant B tidak memiliki supplier Berkah Jaya, sehingga draf tidak terbentuk
        $this->assertNotEquals('success', $payload['status']);
    }
}
