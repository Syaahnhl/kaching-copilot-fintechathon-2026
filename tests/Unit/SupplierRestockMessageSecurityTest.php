<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\Outlet;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Financial\FinanceCopilotOrchestrationService;
use App\Services\Supplier\SupplierRestockMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierRestockMessageSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenantA;
    protected User $tenantB;
    protected Outlet $outletA;
    protected Outlet $outletB;
    protected Supplier $supplierA;
    protected Supplier $supplierB;
    protected Item $itemA1;
    protected Item $itemA2;
    protected Item $itemB1;
    protected SupplierRestockMessageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SupplierRestockMessageService();

        // 1. Tenant A & Setup
        $this->tenantA = User::factory()->create([
            'email' => 'tenant_a@kaching.id',
            'role'  => 'owner',
        ]);
        $this->outletA = Outlet::create([
            'user_id' => $this->tenantA->id,
            'name'    => 'Kaching Outlet A',
        ]);
        $this->supplierA = Supplier::create([
            'user_id'        => $this->tenantA->id,
            'name'           => 'PT Makmur Jaya',
            'contact_person' => 'Pak/Bu',
            'phone'          => '081234567890',
            'is_active'      => true,
        ]);
        $this->itemA1 = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Ayam Fillet',
            'base_uom'      => 'kg',
            'reorder_point' => 15.0,
            'cost_price'    => 35000.0,
            'is_active'     => true,
        ]);
        $this->itemA2 = Item::create([
            'outlet_id'     => $this->outletA->id,
            'name'          => 'Minyak Goreng',
            'base_uom'      => 'liter',
            'reorder_point' => 20.0,
            'cost_price'    => 18000.0,
            'is_active'     => true,
        ]);

        // 2. Tenant B & Setup
        $this->tenantB = User::factory()->create([
            'email' => 'tenant_b@kaching.id',
            'role'  => 'owner',
        ]);
        $this->outletB = Outlet::create([
            'user_id' => $this->tenantB->id,
            'name'    => 'Kaching Outlet B',
        ]);
        $this->supplierB = Supplier::create([
            'user_id'        => $this->tenantB->id,
            'name'           => 'CV Berkah Pangan',
            'contact_person' => 'Bu Susi',
            'phone'          => '089876543210',
            'is_active'      => true,
        ]);
        $this->itemB1 = Item::create([
            'outlet_id'     => $this->outletB->id,
            'name'          => 'Tepung Terigu',
            'base_uom'      => 'kg',
            'reorder_point' => 50.0,
            'cost_price'    => 12000.0,
            'is_active'     => true,
        ]);
    }

    /**
     * TEST 01: Tenant A melihat supplier A.
     */
    public function test_01_tenant_a_can_view_own_supplier_a(): void
    {
        $suppliersA = Supplier::where('user_id', $this->tenantA->id)->get();
        $this->assertTrue($suppliersA->contains('id', $this->supplierA->id));
        $this->assertFalse($suppliersA->contains('id', $this->supplierB->id));
    }

    /**
     * TEST 02: Tenant B melihat supplier B.
     */
    public function test_02_tenant_b_can_view_own_supplier_b(): void
    {
        $suppliersB = Supplier::where('user_id', $this->tenantB->id)->get();
        $this->assertTrue($suppliersB->contains('id', $this->supplierB->id));
        $this->assertFalse($suppliersB->contains('id', $this->supplierA->id));
    }

    /**
     * TEST 03: Tenant A tidak dapat memilih supplier B (ownership check).
     */
    public function test_03_tenant_a_cannot_select_supplier_b(): void
    {
        $accessible = Supplier::where('user_id', $this->tenantA->id)
            ->where('id', $this->supplierB->id)
            ->exists();
        $this->assertFalse($accessible);
    }

    /**
     * TEST 04: Tenant B tidak dapat memilih supplier A (ownership check).
     */
    public function test_04_tenant_b_cannot_select_supplier_a(): void
    {
        $accessible = Supplier::where('user_id', $this->tenantB->id)
            ->where('id', $this->supplierA->id)
            ->exists();
        $this->assertFalse($accessible);
    }

    /**
     * TEST 05: Tenant A tidak dapat generate message menggunakan supplier B dengan manipulasi supplier_id.
     */
    public function test_05_tenant_a_cannot_generate_message_with_supplier_b_id_manipulation(): void
    {
        $items = [
            ['item_name' => 'Ayam Fillet', 'quantity' => 20, 'unit' => 'kg'],
        ];

        $res = $this->service->generateForSupplier($this->tenantA->id, $this->supplierB->id, $items);

        $this->assertFalse($res['success']);
        $this->assertEquals('UNAUTHORIZED_SUPPLIER', $res['status']);
        $this->assertNull($res['whatsapp_url']);
        $this->assertFalse($res['whatsapp_available']);
    }

    /**
     * TEST 06: Tenant B tidak dapat generate message menggunakan supplier A.
     */
    public function test_06_tenant_b_cannot_generate_message_with_supplier_a(): void
    {
        $items = [
            ['item_name' => 'Tepung Terigu', 'quantity' => 15, 'unit' => 'kg'],
        ];

        $res = $this->service->generateForSupplier($this->tenantB->id, $this->supplierA->id, $items);

        $this->assertFalse($res['success']);
        $this->assertEquals('UNAUTHORIZED_SUPPLIER', $res['status']);
        $this->assertNull($res['whatsapp_url']);
        $this->assertFalse($res['whatsapp_available']);
    }

    /**
     * TEST 07: Supplier tanpa phone -> WhatsApp button disabled/hidden (whatsapp_available = false).
     */
    public function test_07_supplier_without_phone_has_whatsapp_disabled(): void
    {
        $noPhoneSupplier = Supplier::create([
            'user_id'   => $this->tenantA->id,
            'name'      => 'Supplier Tanpa Nomor',
            'phone'     => null,
            'is_active' => true,
        ]);

        $items = [
            ['item_name' => 'Beras', 'quantity' => 25, 'unit' => 'kg'],
        ];

        $res = $this->service->generateForSupplier($this->tenantA->id, $noPhoneSupplier->id, $items);

        $this->assertTrue($res['success']);
        $this->assertFalse($res['whatsapp_available']);
        $this->assertNull($res['whatsapp_url']);
        $this->assertNotNull($res['message']);
        $this->assertStringContainsString('Nomor WhatsApp supplier belum tersedia', $res['note']);
    }

    /**
     * TEST 08: Invalid phone -> no WhatsApp link (Fail-Closed).
     */
    public function test_08_invalid_phone_formats_produce_no_whatsapp_link(): void
    {
        $invalidNumbers = ['12345', 'abcdef', '021555666', '++62811', '08'];

        foreach ($invalidNumbers as $badNum) {
            $normalized = $this->service->normalizePhoneNumber($badNum);
            $this->assertNull($normalized, "Failed asserting that '{$badNum}' normalizes to null (fail-closed).");
            $this->assertNull($this->service->buildWhatsAppUrl($badNum, "Halo"));
        }
    }

    /**
     * TEST 09: Multiple items same supplier -> one combined message.
     */
    public function test_09_multiple_items_same_supplier_combined_into_one_message(): void
    {
        $items = [
            ['item_name' => 'Ayam Fillet', 'quantity' => 20, 'unit' => 'kg'],
            ['item_name' => 'Minyak Goreng', 'quantity' => 10, 'unit' => 'liter'],
            ['item_name' => 'Beras', 'quantity' => 25, 'unit' => 'kg'],
        ];

        $res = $this->service->generateForSupplier($this->tenantA->id, $this->supplierA->id, $items);

        $this->assertTrue($res['success']);
        $msg = $res['message'];

        // Verifikasi semua item ada dalam satu pesan
        $this->assertStringContainsString('• Ayam Fillet — 20 kg', $msg);
        $this->assertStringContainsString('• Minyak Goreng — 10 liter', $msg);
        $this->assertStringContainsString('• Beras — 25 kg', $msg);

        // Header dan footer tepat 1 kali
        $this->assertEquals(1, substr_count($msg, 'Kami ingin melakukan restok bahan berikut:'));
        $this->assertEquals(1, substr_count($msg, 'Mohon diinformasikan ketersediaan, harga terbaru, dan estimasi pengiriman.'));
        $this->assertEquals(1, substr_count($msg, 'Terima kasih.'));
    }

    /**
     * TEST 10: Items different suppliers -> separate messages per supplier.
     */
    public function test_10_items_different_suppliers_separated_per_supplier(): void
    {
        $supplierA2 = Supplier::create([
            'user_id'   => $this->tenantA->id,
            'name'      => 'Toko Bahan Kue A2',
            'phone'     => '081234567899',
            'is_active' => true,
        ]);

        $mixedItems = [
            ['supplier_id' => $this->supplierA->id, 'item_name' => 'Ayam Fillet', 'quantity' => 20, 'unit' => 'kg'],
            ['supplier_id' => $supplierA2->id, 'item_name' => 'Baking Powder', 'quantity' => 5, 'unit' => 'kaleng'],
        ];

        $grouped = $this->service->generateMessagesGroupedBySupplier($this->tenantA->id, $mixedItems);

        $this->assertCount(2, $grouped);
        $this->assertEquals($this->supplierA->id, $grouped[0]['supplier']['id']);
        $this->assertStringContainsString('Ayam Fillet', $grouped[0]['message']);
        $this->assertEquals($supplierA2->id, $grouped[1]['supplier']['id']);
        $this->assertStringContainsString('Baking Powder', $grouped[1]['message']);
    }

    /**
     * TEST 11: Message URL encoding -> special characters/newlines survive correctly.
     */
    public function test_11_message_url_encoding_preserves_newlines_and_special_chars(): void
    {
        $rawMessage = "Halo Pak/Bu,\n\nKami ingin melakukan restok bahan berikut:\n\n• Ayam Fillet — 20 kg & Bumbu 100%\n\nTerima kasih.";
        $url = $this->service->buildWhatsAppUrl('081234567890', $rawMessage);

        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://wa.me/6281234567890?text=', $url);

        // Parse query string and decode
        $queryStr = parse_url($url, PHP_URL_QUERY);
        parse_str($queryStr, $params);
        $decodedText = $params['text'];

        $this->assertEquals($rawMessage, $decodedText);
        $this->assertStringContainsString('%0A', $url); // Newline encoded
        $this->assertStringContainsString('%E2%80%A2', $url); // Bullet point encoded
    }

    /**
     * TEST 12: No database mutation when generating message.
     */
    public function test_12_no_database_mutation_when_generating_message(): void
    {
        $suppliersCountBefore = Supplier::count();
        $itemsCountBefore = Item::count();
        $ordersCountBefore = DB::table('stock_movements')->count();

        $items = [
            ['item_name' => 'Ayam Fillet', 'quantity' => 20, 'unit' => 'kg'],
        ];

        $res = $this->service->generateForSupplier($this->tenantA->id, $this->supplierA->id, $items);

        $this->assertTrue($res['success']);
        $this->assertEquals($suppliersCountBefore, Supplier::count());
        $this->assertEquals($itemsCountBefore, Item::count());
        $this->assertEquals($ordersCountBefore, DB::table('stock_movements')->count());
    }

    /**
     * TEST 13: No WhatsApp request is sent server-side (only deep link returned).
     */
    public function test_13_no_whatsapp_request_sent_server_side(): void
    {
        // Spy/Verify: Tidak ada panggilan Http::post atau external network request
        \Illuminate\Support\Facades\Http::fake();

        $items = [
            ['item_name' => 'Ayam Fillet', 'quantity' => 20, 'unit' => 'kg'],
        ];

        $res = $this->service->generateForSupplier($this->tenantA->id, $this->supplierA->id, $items);

        $this->assertTrue($res['success']);
        $this->assertNotNull($res['whatsapp_url']);

        // Pastikan tidak ada outgoing HTTP request yang terjadi di backend
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    /**
     * TEST 14: ACCEPTANCE TEST (Scenario Section 14)
     * Tenant A memiliki:
     * Supplier: PT Makmur Jaya, Phone: 081234567890
     * Stock: Ayam Fillet: 8 kg, Restock recommendation: 20 kg
     * User klik: "Buat Pesan Restok"
     * Expected:
     * Message:
     * Halo Pak/Bu,
     *
     * Kami ingin melakukan restok bahan berikut:
     *
     * • Ayam Fillet — 20 kg
     *
     * Mohon diinformasikan ketersediaan, harga terbaru, dan estimasi pengiriman.
     *
     * Terima kasih.
     *
     * WhatsApp: 6281234567890
     */
    public function test_14_acceptance_test_scenario_section_14(): void
    {
        $items = [
            [
                'item_name' => 'Ayam Fillet',
                'quantity'  => 20.0,
                'unit'      => 'kg',
            ],
        ];

        $res = $this->service->generateForSupplier($this->tenantA->id, $this->supplierA->id, $items);

        $this->assertTrue($res['success']);
        $this->assertEquals('PT Makmur Jaya', $res['supplier']['name']);
        $this->assertEquals('6281234567890', $res['supplier']['clean_phone']);

        $expectedMessage = "Halo Pak/Bu,\n\n"
            . "Kami ingin melakukan restok bahan berikut:\n\n"
            . "• Ayam Fillet — 20 kg\n\n"
            . "Mohon diinformasikan ketersediaan, harga terbaru, dan estimasi pengiriman.\n\n"
            . "Terima kasih.";

        $this->assertEquals($expectedMessage, $res['message']);
        $this->assertTrue($res['whatsapp_available']);
        $this->assertEquals(
            'https://wa.me/6281234567890?text=' . rawurlencode($expectedMessage),
            $res['whatsapp_url']
        );
    }

    /**
     * TEST 15: Phone normalization handles standard Indonesian patterns:
     * 081234567890 -> 6281234567890
     * +6281234567890 -> 6281234567890
     * 6281234567890 -> 6281234567890
     */
    public function test_15_phone_normalization_indonesian_patterns(): void
    {
        $this->assertEquals('6281234567890', $this->service->normalizePhoneNumber('081234567890'));
        $this->assertEquals('6281234567890', $this->service->normalizePhoneNumber('+6281234567890'));
        $this->assertEquals('6281234567890', $this->service->normalizePhoneNumber('6281234567890'));
        $this->assertEquals('6281234567890', $this->service->normalizePhoneNumber('0812-3456-7890'));
        $this->assertEquals('6281234567890', $this->service->normalizePhoneNumber('+62 812 3456 7890'));
    }

    /**
     * TEST 16: Copilot Tool `generate_supplier_restock_message` Integration Test
     */
    public function test_16_copilot_tool_integration_read_only_and_tenant_guarded(): void
    {
        /** @var FinanceCopilotOrchestrationService $copilot */
        $copilot = app(FinanceCopilotOrchestrationService::class);

        // 1. Success path
        $resA = $copilot->dispatchTool('generate_supplier_restock_message', $this->tenantA->id, $this->outletA->id, [
            'supplier_id' => $this->supplierA->id,
            'items'       => [
                ['item_name' => 'Ayam Fillet', 'quantity' => 20, 'unit' => 'kg'],
            ],
        ]);

        $this->assertEquals('SUCCESS', $resA['status']);
        $this->assertArrayHasKey('explainability', $resA);
        $this->assertArrayHasKey('data', $resA);
        $resultData = $resA['data'];
        $this->assertTrue($resultData['success']);
        $this->assertEquals('PT Makmur Jaya', $resultData['supplier']['name']);
        $this->assertTrue($resultData['whatsapp_available']);

        // 2. Cross-tenant path (Fail-Closed)
        $resCross = $copilot->dispatchTool('generate_supplier_restock_message', $this->tenantA->id, $this->outletA->id, [
            'supplier_id' => $this->supplierB->id,
            'items'       => [
                ['item_name' => 'Ayam Fillet', 'quantity' => 20, 'unit' => 'kg'],
            ],
        ]);

        $this->assertEquals('SUCCESS', $resCross['status']);
        $crossResult = $resCross['data'];
        $this->assertFalse($crossResult['success']);
        $this->assertEquals('UNAUTHORIZED_SUPPLIER', $crossResult['status']);
    }

    /**
     * TEST 17: Web Controller Route Tests (HTTP JSON endpoints)
     */
    public function test_17_procurement_controller_endpoints(): void
    {
        // 1. Authenticated as Tenant A
        $this->actingAs($this->tenantA);

        // GET recommendations
        $response = $this->getJson(route('inventory.procurement.recommendations'));
        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'items', 'suppliers']);
        $response->assertJsonFragment(['name' => 'PT Makmur Jaya']);
        $response->assertJsonMissing(['name' => 'CV Berkah Pangan']);

        // POST generate message (Authorized)
        $postRes = $this->postJson(route('inventory.procurement.generate-message'), [
            'supplier_id' => $this->supplierA->id,
            'items'       => [
                ['item_name' => 'Ayam Fillet', 'quantity' => 20, 'unit' => 'kg'],
            ],
        ]);
        $postRes->assertStatus(200);
        $postRes->assertJsonFragment(['name' => 'PT Makmur Jaya']);
        $postRes->assertJsonFragment(['whatsapp_available' => true]);

        // POST generate message (Unauthorized cross-tenant attempt)
        $unauthRes = $this->postJson(route('inventory.procurement.generate-message'), [
            'supplier_id' => $this->supplierB->id,
            'items'       => [
                ['item_name' => 'Ayam Fillet', 'quantity' => 20, 'unit' => 'kg'],
            ],
        ]);
        $unauthRes->assertStatus(403);
    }
}
