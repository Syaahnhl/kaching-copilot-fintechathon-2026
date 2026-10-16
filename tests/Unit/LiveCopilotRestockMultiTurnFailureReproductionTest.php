<?php

namespace Tests\Unit;

use App\Http\Controllers\ChatbotController;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ChatbotPromptBuilder;
use App\Services\Financial\FinanceCopilotOrchestrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class LiveCopilotRestockMultiTurnFailureReproductionTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant;
    protected Outlet $outlet;
    protected Supplier $supplierBerkah;
    protected Supplier $supplierDairy;
    protected Item $itemGalon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = User::factory()->create([
            'email' => 'tuan@kaching.id',
            'role'  => 'owner',
        ]);

        $this->outlet = Outlet::create([
            'user_id' => $this->tenant->id,
            'name'    => 'Kaching Outlet Pusat',
        ]);

        $this->supplierDairy = Supplier::create([
            'user_id'        => $this->tenant->id,
            'name'           => 'CV Fresh Farm Dairy',
            'contact_person' => 'Budi',
            'phone'          => '0813-4567-8901',
            'is_active'      => true,
        ]);

        $this->supplierBerkah = Supplier::create([
            'user_id'        => $this->tenant->id,
            'name'           => 'Toko Sembako Berkah Jaya',
            'contact_person' => 'Pak Joko',
            'phone'          => '0814-5678-9012',
            'is_active'      => true,
        ]);

        $this->itemGalon = Item::create([
            'outlet_id'     => $this->outlet->id,
            'name'          => 'Galon Air Mineral',
            'base_uom'      => 'galon',
            'cost_price'    => 19000.0,
            'reorder_point' => 5.0,
            'is_active'     => true,
        ]);
    }

    /**
     * REPRODUCTION 1: Guard 'strlen < 3' memblokir konfirmasi pendek 'ya' dan 'ok'
     * sehingga isFollowUpQuestion() selalu mengembalikan false.
     */
    public function test_reproduction_1_short_affirmation_fails_is_follow_up_due_to_length_guard(): void
    {
        // Assert intent detection returns empty for 'ya'
        $intentsYa = ChatbotPromptBuilder::detectIntents('ya');
        $this->assertEmpty($intentsYa, 'Kata ya tidak memiliki keyword intent eksplisit.');

        // Guard strlen < 3 di line 580 memotong 'ya' (2 chars) dan 'ok' (2 chars)
        $isFollowUpYa = ChatbotPromptBuilder::isFollowUpQuestion('ya');
        $this->assertFalse($isFollowUpYa, 'BUG TERBUKTI: isFollowUpQuestion(ya) mengembalikan false karena strlen(ya) < 3.');

        $isFollowUpOk = ChatbotPromptBuilder::isFollowUpQuestion('ok');
        $this->assertFalse($isFollowUpOk, 'BUG TERBUKTI: isFollowUpQuestion(ok) mengembalikan false karena strlen(ok) < 3.');
    }

    /**
     * REPRODUCTION 2: Input kuantitas natural '4 galon' dan nama supplier 'Toko Sembako Berkah Jaya'
     * gagal dikenali sebagai follow-up, menyebabkan intent restock dari history tidak diwariskan.
     */
    public function test_reproduction_2_conversational_turns_drop_history_intent_inheritance(): void
    {
        $this->assertEmpty(ChatbotPromptBuilder::detectIntents('4 galon'));
        $this->assertFalse(ChatbotPromptBuilder::isFollowUpQuestion('4 galon'));

        $this->assertEmpty(ChatbotPromptBuilder::detectIntents('Toko Sembako Berkah Jaya'));
        $this->assertFalse(ChatbotPromptBuilder::isFollowUpQuestion('Toko Sembako Berkah Jaya'));

        // Dummy all tools containing get_suppliers and get_today_sales
        $allTools = [
            ['function' => ['name' => 'get_today_sales']],
            ['function' => ['name' => 'get_suppliers']],
            ['function' => ['name' => 'generate_supplier_restock_message']],
        ];

        $history = [
            ['role' => 'user', 'content' => 'buatkan chat untuk pesan galon ke supplierku'],
        ];

        // Karena isFollowUpQuestion('4 galon') === false, history intent dibuang
        $toolsForQty = ChatbotPromptBuilder::resolveCopilotTools($allTools, '4 galon', $history);
        $toolNamesQty = array_column(array_column($toolsForQty, 'function'), 'name');

        $this->assertNotContains('get_suppliers', $toolNamesQty, 'BUG TERBUKTI: get_suppliers hilang dari tool list pada turn kuantitas 4 galon.');

        // Sama halnya pada giliran pemilihan supplier
        $toolsForSup = ChatbotPromptBuilder::resolveCopilotTools($allTools, 'Toko Sembako Berkah Jaya', $history);
        $toolNamesSup = array_column(array_column($toolsForSup, 'function'), 'name');

        $this->assertNotContains('get_suppliers', $toolNamesSup, 'BUG TERBUKTI: get_suppliers hilang saat user menyebut nama supplier.');
    }

    /**
     * REPRODUCTION 3: extractRestockContextFromHistory() gagal mengekstrak kuantitas
     * jika tidak menggunakan label header eksplisit (seperti Quantity:).
     */
    public function test_reproduction_3_extract_restock_context_fails_on_plain_quantity(): void
    {
        $controller = app(ChatbotController::class);
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('extractRestockContextFromHistory');
        $method->setAccessible(true);

        $naturalHistory = [
            ['role' => 'user', 'content' => 'buatkan chat untuk pesan galon ke supplierku'],
            ['role' => 'assistant', 'content' => "Daftar supplier:\n1. Toko Sembako Berkah Jaya\n2. CV Fresh Farm Dairy\nBerapa banyak galon?"],
            ['role' => 'user', 'content' => '4 galon'],
            ['role' => 'user', 'content' => 'Toko Sembako Berkah Jaya'],
        ];

        $extracted = $method->invoke($controller, $naturalHistory, $this->tenant->id, $this->outlet->id);

        $this->assertNull($extracted, 'BUG TERBUKTI: extractRestockContextFromHistory mengembalikan null karena 4 galon tidak memiliki label Quantity:.');
    }

    /**
     * REPRODUCTION 4: Eksekusi executeDeterministicFallback pada input 'ya'
     * berakhir dengan pesan fallback 200 'Sistem Kaching sedang sibuk, silakan hubungi kami sebentar lagi.'
     */
    public function test_reproduction_4_deterministic_fallback_on_ya_returns_system_busy(): void
    {
        app()->setLocale('id');

        $controller = app(ChatbotController::class);
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('executeDeterministicFallback');
        $method->setAccessible(true);

        $request = new Request([
            'message' => 'ya',
            'outlet_id' => $this->outlet->id,
            'history' => [
                ['role' => 'user', 'content' => 'buatkan chat untuk pesan galon ke supplierku'],
                ['role' => 'assistant', 'content' => "Daftar supplier:\n1. Toko Sembako Berkah Jaya\n2. CV Fresh Farm Dairy\nBerapa banyak galon?"],
                ['role' => 'user', 'content' => '4 galon'],
                ['role' => 'user', 'content' => 'Toko Sembako Berkah Jaya'],
            ],
        ]);

        /** @var \Illuminate\Http\JsonResponse $response */
        $response = $method->invoke($controller, $this->tenant, $request, 'ya');
        $data = $response->getData(true);

        $this->assertEquals(200, $response->getStatusCode(), 'Status code 200 OK.');
        $this->assertEquals('error', $data['status']);
        $this->assertEquals(__('copilot.system.busy'), $data['reply']);
        $this->assertEquals('Sistem Kaching sedang sibuk, silakan hubungi kami sebentar lagi.', $data['reply'], 'BUG TERBUKTI: Fallback pada ya mengembalikan string persis seperti laporan reproduksi.');
    }
}
