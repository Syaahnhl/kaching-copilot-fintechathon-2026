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

class LiveCopilotRestockMultiTurnRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant;
    protected User $otherTenant;
    protected Outlet $outlet;
    protected Supplier $supplierBerkah;
    protected Supplier $supplierDairy;
    protected Supplier $unauthorizedSupplier;
    protected Item $itemGalon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = User::factory()->create([
            'email' => 'tuan_recovery@kaching.id',
            'role'  => 'owner',
        ]);

        $this->otherTenant = User::factory()->create([
            'email' => 'hacker@other.id',
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

        $this->unauthorizedSupplier = Supplier::create([
            'user_id'        => $this->otherTenant->id,
            'name'           => 'Supplier Rahasia Tenant Lain',
            'contact_person' => 'Orang Asing',
            'phone'          => '0899-9999-9999',
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
     * TEST 1: 'ya' melanjutkan alur restock yang valid secara deterministik.
     */
    public function test_1_ya_continues_valid_restock_workflow(): void
    {
        app()->setLocale('id');

        $controller = app(ChatbotController::class);
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('executeDeterministicFallback');
        $method->setAccessible(true);

        $request = new Request([
            'message'   => 'ya',
            'outlet_id' => $this->outlet->id,
            'history'   => [
                ['role' => 'user', 'content' => 'buatkan chat untuk pesan galon ke supplierku'],
                ['role' => 'assistant', 'content' => "Daftar supplier:\n1. Toko Sembako Berkah Jaya\n2. CV Fresh Farm Dairy\nBerapa banyak galon?"],
                ['role' => 'user', 'content' => '4 galon'],
                ['role' => 'user', 'content' => 'Toko Sembako Berkah Jaya'],
            ],
        ]);

        /** @var \Illuminate\Http\JsonResponse $response */
        $response = $method->invoke($controller, $this->tenant, $request, 'ya');
        $data = $response->getData(true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('success', $data['status']);
        $this->assertStringContainsString('Toko Sembako Berkah Jaya', $data['reply']);
        $this->assertStringContainsString('4 galon', strtolower($data['reply']));
        $this->assertStringContainsString('wa.me', $data['reply']);
        $this->assertStringContainsString('TIDAK dikirim otomatis', $data['reply']);
        $this->assertNotNull($data['data']['supplier_order'] ?? null);
        $this->assertEquals($this->supplierBerkah->id, $data['data']['supplier_order']['supplier']['id']);
    }

    /**
     * TEST 2: '4 galon' diekstrak dengan jumlah (4.0) dan satuan ('galon') yang benar.
     */
    public function test_2_natural_quantity_4_galon_extracted_correctly(): void
    {
        $controller = app(ChatbotController::class);
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('extractRestockContextFromHistory');
        $method->setAccessible(true);

        $history = [
            ['role' => 'user', 'content' => 'pesan galon ke supplier Toko Sembako Berkah Jaya'],
            ['role' => 'user', 'content' => '4 galon'],
        ];

        $extracted = $method->invoke($controller, $history, $this->tenant->id);

        $this->assertNotNull($extracted);
        $this->assertEquals($this->supplierBerkah->id, $extracted['supplier_id']);
        $this->assertCount(1, $extracted['items']);
        $this->assertEquals(4.0, $extracted['items'][0]['quantity']);
        $this->assertEquals('galon', $extracted['items'][0]['unit']);
        $this->assertEquals('Galon', $extracted['items'][0]['item_name']);
    }

    /**
     * TEST 3: Supplier Toko Sembako Berkah Jaya tetap dipertahankan dari pilihan pengguna.
     */
    public function test_3_toko_sembako_berkah_jaya_preserved_over_assistant_recommendation(): void
    {
        $controller = app(ChatbotController::class);
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('extractRestockContextFromHistory');
        $method->setAccessible(true);

        // Assistant merekomendasikan Dairy dulu, tetapi user memilih Berkah Jaya
        $history = [
            ['role' => 'assistant', 'content' => "Rekomendasi:\n1. CV Fresh Farm Dairy\n2. Toko Sembako Berkah Jaya"],
            ['role' => 'user', 'content' => 'Toko Sembako Berkah Jaya'],
            ['role' => 'user', 'content' => '4 galon'],
            ['role' => 'assistant', 'content' => 'Apakah draf pesanan ingin dibuat untuk Toko Sembako Berkah Jaya?'],
        ];

        $extracted = $method->invoke($controller, $history, $this->tenant->id);

        $this->assertNotNull($extracted);
        $this->assertEquals($this->supplierBerkah->id, $extracted['supplier_id']);
        $this->assertEquals('Toko Sembako Berkah Jaya', $extracted['supplier_name']);
        $this->assertNotEquals($this->supplierDairy->id, $extracted['supplier_id']);
    }

    /**
     * TEST 4: Konteks restock tidak hilang setelah beberapa giliran percakapan.
     */
    public function test_4_restock_context_persists_across_multiple_turns(): void
    {
        $allTools = [
            ['function' => ['name' => 'get_today_sales']],
            ['function' => ['name' => 'get_suppliers']],
            ['function' => ['name' => 'generate_supplier_restock_message']],
        ];

        $history = [
            ['role' => 'user', 'content' => 'buatkan chat untuk pesan galon ke supplierku'],
            ['role' => 'assistant', 'content' => 'Pilih supplier dan jumlah pesanan.'],
        ];

        // Turn Qty
        $toolsQty = ChatbotPromptBuilder::resolveCopilotTools($allTools, '4 galon', $history);
        $toolNamesQty = array_column(array_column($toolsQty, 'function'), 'name');
        $this->assertContains('get_suppliers', $toolNamesQty);
        $this->assertContains('generate_supplier_restock_message', $toolNamesQty);

        // Turn Supplier
        $history[] = ['role' => 'user', 'content' => '4 galon'];
        $history[] = ['role' => 'assistant', 'content' => 'Ke supplier mana?'];
        $toolsSup = ChatbotPromptBuilder::resolveCopilotTools($allTools, 'Toko Sembako Berkah Jaya', $history);
        $toolNamesSup = array_column(array_column($toolsSup, 'function'), 'name');
        $this->assertContains('get_suppliers', $toolNamesSup);
        $this->assertContains('generate_supplier_restock_message', $toolNamesSup);

        // Turn Affirmation
        $history[] = ['role' => 'user', 'content' => 'Toko Sembako Berkah Jaya'];
        $history[] = ['role' => 'assistant', 'content' => 'Konfirmasi pembuatan pesan?'];
        $toolsYa = ChatbotPromptBuilder::resolveCopilotTools($allTools, 'ya', $history);
        $toolNamesYa = array_column(array_column($toolsYa, 'function'), 'name');
        $this->assertContains('get_suppliers', $toolNamesYa);
        $this->assertContains('generate_supplier_restock_message', $toolNamesYa);
    }

    /**
     * TEST 5: Hasil tool generate_supplier_restock_message diteruskan ke ringkasan akhir.
     */
    public function test_5_tool_result_preserved_in_fallback_summary(): void
    {
        app()->setLocale('id');

        $controller = app(ChatbotController::class);
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('generateToolFallbackSummary');
        $method->setAccessible(true);

        $supplierRestockData = [
            'success'      => true,
            'supplier'     => ['id' => $this->supplierBerkah->id, 'name' => 'Toko Sembako Berkah Jaya'],
            'message'      => "Halo Toko Sembako Berkah Jaya, kami ingin memesan:\n- Galon: 4 galon",
            'whatsapp_url' => 'https://wa.me/6281456789012?text=test',
        ];

        // Panggil generateToolFallbackSummary dengan parameter ke-11 ($supplierRestock)
        $summary = $method->invoke(
            $controller,
            null, // todaySales
            null, // warnings
            [],   // scenarios
            null, // forecast
            null, // performance
            null, // recommendations
            [],   // radars
            [],   // menuIntelligence
            null, // topSellingProducts
            null, // suppliers
            $supplierRestockData // supplierRestock (11th parameter)
        );

        $this->assertStringContainsString('Toko Sembako Berkah Jaya', $summary);
        $this->assertStringContainsString('Galon: 4 galon', $summary);
        $this->assertStringContainsString('wa.me/6281456789012', $summary);
        $this->assertStringContainsString('TIDAK dikirim otomatis', $summary);
    }

    /**
     * TEST 6: Afirmasi tanpa konteks restock TIDAK memicu pembuatan draf.
     */
    public function test_6_affirmation_without_restock_context_does_not_trigger_draft(): void
    {
        $controller = app(ChatbotController::class);
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('executeDeterministicFallback');
        $method->setAccessible(true);

        $request = new Request([
            'message'   => 'ya',
            'outlet_id' => $this->outlet->id,
            'history'   => [
                ['role' => 'user', 'content' => 'bagaimana penjualan hari ini?'],
                ['role' => 'assistant', 'content' => 'Penjualan hari ini mencapai Rp 500.000. Apakah ingin memeriksa detail transaksi?'],
            ],
        ]);

        /** @var \Illuminate\Http\JsonResponse $response */
        $response = $method->invoke($controller, $this->tenant, $request, 'ya');
        $data = $response->getData(true);

        // Harus tidak ada supplier_order dan tidak ada teks restock draf
        $this->assertArrayNotHasKey('supplier_order', $data['data'] ?? []);
        $this->assertStringNotContainsString('draf pesan pemesanan restock', $data['reply'] ?? '');
    }

    /**
     * TEST 7: Supplier yang tidak berhak diakses ditolak (isolasi tenant).
     */
    public function test_7_unauthorized_supplier_is_rejected(): void
    {
        $controller = app(ChatbotController::class);
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('extractRestockContextFromHistory');
        $method->setAccessible(true);

        // User mencoba mengarahkan ke supplier milik tenant lain
        $history = [
            ['role' => 'user', 'content' => 'buatkan pesan ke Supplier Rahasia Tenant Lain untuk 10 sak'],
        ];

        $extracted = $method->invoke($controller, $history, $this->tenant->id);

        // Harus ditolak (null) karena Supplier Rahasia Tenant Lain bukan milik tenant ini
        $this->assertNull($extracted);
    }

    /**
     * TEST 8: Kegagalan provider/tool menghasilkan fallback aman tanpa menyembunyikan exception dalam log.
     */
    public function test_8_provider_or_tool_failure_produces_safe_fallback_without_hiding_exceptions(): void
    {
        $controller = app(ChatbotController::class);
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('executeDeterministicFallback');
        $method->setAccessible(true);

        // Request kosong tanpa history
        $request = new Request([
            'message'   => 'tes pesan acak tanpa intent',
            'outlet_id' => $this->outlet->id,
            'history'   => [],
        ]);

        /** @var \Illuminate\Http\JsonResponse $response */
        $response = $method->invoke($controller, $this->tenant, $request, 'tes pesan acak tanpa intent');
        $data = $response->getData(true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('error', $data['status']);
        $this->assertEquals(__('copilot.system.clarification_default'), $data['reply']);
        $this->assertEquals('CLARIFICATION_NEEDED', $data['code']);
        $this->assertTrue($data['is_fallback'] ?? false);
    }

    /**
     * TEST 9: Alur supplier lain dan chat biasa tidak mengalami regresi.
     */
    public function test_9_other_supplier_flows_and_regular_chat_do_not_regress(): void
    {
        $controller = app(ChatbotController::class);
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('extractRestockContextFromHistory');
        $method->setAccessible(true);

        // Alur supplier dairy
        $dairyHistory = [
            ['role' => 'user', 'content' => 'buatkan draf order 10 liter susu segar ke CV Fresh Farm Dairy'],
        ];

        $extractedDairy = $method->invoke($controller, $dairyHistory, $this->tenant->id);
        $this->assertNotNull($extractedDairy);
        $this->assertEquals($this->supplierDairy->id, $extractedDairy['supplier_id']);
        $this->assertEquals('CV Fresh Farm Dairy', $extractedDairy['supplier_name']);
        $this->assertEquals(10.0, $extractedDairy['items'][0]['quantity']);
        $this->assertEquals('liter', $extractedDairy['items'][0]['unit']);

        // Chat biasa: prompt builder guard
        $this->assertFalse(ChatbotPromptBuilder::isFollowUpQuestion(''));
        $this->assertFalse(ChatbotPromptBuilder::isFollowUpQuestion('a'));
        $this->assertFalse(ChatbotPromptBuilder::isFollowUpQuestion('halo'));
        $this->assertTrue(ChatbotPromptBuilder::isFollowUpQuestion('kenapa omzet turun?'));
    }
}
