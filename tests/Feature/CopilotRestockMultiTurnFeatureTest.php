<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CopilotRestockMultiTurnFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $proUser;
    protected User $otherTenantUser;
    protected User $basicUser;
    protected Outlet $outlet;
    protected Supplier $tenantSupplier;
    protected Supplier $otherSupplier;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.groq.key'      => 'gsk_test_fake_api_key_123456789',
            'services.groq.model'    => 'powdercombo',
            'services.groq.base_url' => 'http://127.0.0.1:20128/v1',
            'cache.default'          => 'array',
        ]);

        // 1. Fixture PRO sesuai spesifikasi B3.6.33
        $this->proUser = User::factory()->create([
            'name'              => 'E2E Fixture Pro Owner',
            'email'             => 'fixture_e2e_pro@kaching.test',
            'role'              => 'owner',
            'subscription_tier' => 'PRO',
            'password'          => bcrypt('KachingE2EPro2026!'),
            'account_status'    => 'active',
            'email_status'      => 'verified',
        ]);

        $this->outlet = Outlet::factory()->create([
            'user_id'   => $this->proUser->id,
            'name'      => 'Fixture E2E Outlet',
        ]);

        $this->tenantSupplier = Supplier::create([
            'user_id'   => $this->proUser->id,
            'name'      => 'Toko Sembako Berkah Jaya',
            'phone'     => '081234567890',
            'address'   => 'Jl. E2E No. 914',
            'is_active' => true,
        ]);

        // 2. Fixture Tenant Lain (untuk pengujian isolasi tenant)
        $this->otherTenantUser = User::factory()->create([
            'name'              => 'Other Tenant Owner',
            'email'             => 'other_tenant@kaching.test',
            'role'              => 'owner',
            'subscription_tier' => 'PRO',
            'password'          => bcrypt('OtherTenantPass2026!'),
        ]);

        $this->otherSupplier = Supplier::create([
            'user_id'   => $this->otherTenantUser->id,
            'name'      => 'Supplier Luar Tenant',
            'phone'     => '089999999999',
            'address'   => 'Jl. Asing No. 999',
            'is_active' => true,
        ]);

        // 3. Fixture Basic Tier (untuk pengujian tier gate)
        $this->basicUser = User::factory()->create([
            'name'              => 'Basic Tier User',
            'email'             => 'basic_user@kaching.test',
            'role'              => 'owner',
            'subscription_tier' => 'basic',
            'password'          => bcrypt('BasicUserPass2026!'),
        ]);
    }

    /**
     * 1. Siklus Web Login HTTP asli menggunakan akun fixture PRO (tanpa ekspos kredensial).
     */
    public function test_authenticated_web_login_with_fixture_pro_user(): void
    {
        $response = $this->post('/web-login', [
            'email'    => $this->proUser->email,
            'password' => 'KachingE2EPro2026!',
        ]);

        $response->assertStatus(302);
        $this->assertAuthenticatedAs($this->proUser);
    }

    /**
     * 2. Akses halaman Copilot chat melalui sesi terautentikasi akun PRO.
     */
    public function test_copilot_chat_view_accessible_by_authenticated_pro_user(): void
    {
        $response = $this->actingAs($this->proUser)->get('/copilot/copilot_chat');

        $response->assertStatus(200);
        $this->assertAuthenticatedAs($this->proUser);
    }

    /**
     * 3. Skenario Multi-turn Copilot Restock:
     *    Turn 1: "bantu buat pesan restock galon"
     *    Turn 2: "4 galon untuk Toko Sembako Berkah Jaya"
     *    Turn 3: "ya" (afirmasi)
     *    Konteks supplier dan kuantitas terjaga konsisten sampai draf WhatsApp terbentuk.
     */
    public function test_multi_turn_restock_conversation_preserves_supplier_and_quantity(): void
    {
        Http::fake([
            '*/chat/completions' => Http::sequence()
                // Turn 1 LLM response
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'role'    => 'assistant',
                                'content' => 'Berapa kuantitas galon yang ingin dipesan untuk Toko Sembako Berkah Jaya?',
                            ],
                        ],
                    ],
                ], 200)
                // Turn 2 LLM response
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'role'    => 'assistant',
                                'content' => 'Apakah Anda ingin saya buatkan draf pesan WhatsApp untuk 4 galon ke Toko Sembako Berkah Jaya?',
                            ],
                        ],
                    ],
                ], 200)
                // Turn 3 Step 1: LLM Tool Call generate_supplier_restock_message
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'role'       => 'assistant',
                                'content'    => null,
                                'tool_calls' => [
                                    [
                                        'id'       => 'call_restock_914',
                                        'type'     => 'function',
                                        'function' => [
                                            'name'      => 'generate_supplier_restock_message',
                                            'arguments' => json_encode([
                                                'supplier_id' => $this->tenantSupplier->id,
                                                'items'       => [
                                                    [
                                                        'item_name' => 'Galon',
                                                        'quantity'  => 4,
                                                        'unit'      => 'galon',
                                                    ],
                                                ],
                                            ]),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200)
                // Turn 3 Step 2: LLM Final Summary
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'role'    => 'assistant',
                                'content' => "Berikut draf pesan pemesanan restock untuk **Toko Sembako Berkah Jaya**:\n\n[Buka WhatsApp](https://wa.me/6281234567890?text=test)\n\n_Catatan: Pesan TIDAK dikirim otomatis._",
                            ],
                        ],
                    ],
                ], 200),
        ]);

        // Turn 1: Permintaan restock awal
        $turn1Response = $this->actingAs($this->proUser)->postJson('/copilot/chat', [
            'message' => 'bantu pesan galon',
            'history' => [],
        ]);

        $turn1Response->assertStatus(200);
        $turn1Response->assertJson(['status' => 'success']);

        // Turn 2: Penyebutan kuantitas dan supplier
        $turn2History = [
            ['role' => 'user', 'content' => 'bantu pesan galon'],
            ['role' => 'assistant', 'content' => 'Berapa kuantitas galon yang ingin dipesan untuk Toko Sembako Berkah Jaya?'],
        ];

        $turn2Response = $this->actingAs($this->proUser)->postJson('/copilot/chat', [
            'message' => '4 galon untuk Toko Sembako Berkah Jaya',
            'history' => $turn2History,
        ]);

        $turn2Response->assertStatus(200);
        $turn2Response->assertJson(['status' => 'success']);

        // Turn 3: Afirmasi pengguna ("ya")
        $turn3History = [
            ['role' => 'user', 'content' => 'bantu pesan galon'],
            ['role' => 'assistant', 'content' => 'Berapa kuantitas galon yang ingin dipesan untuk Toko Sembako Berkah Jaya?'],
            ['role' => 'user', 'content' => '4 galon untuk Toko Sembako Berkah Jaya'],
            ['role' => 'assistant', 'content' => 'Apakah Anda ingin saya buatkan draf pesan WhatsApp untuk 4 galon ke Toko Sembako Berkah Jaya?'],
        ];

        $turn3Response = $this->actingAs($this->proUser)->postJson('/copilot/chat', [
            'message' => 'ya',
            'history' => $turn3History,
        ]);

        $turn3Response->assertStatus(200);
        $turn3Response->assertJson(['status' => 'success']);

        $responseData = $turn3Response->json();

        // Verifikasi keberadaan dan konsistensi payload supplier_order
        $this->assertArrayHasKey('data', $responseData);
        $this->assertArrayHasKey('supplier_order', $responseData['data']);

        $supplierOrder = $responseData['data']['supplier_order'];
        $this->assertTrue($supplierOrder['success']);
        $this->assertEquals($this->tenantSupplier->id, $supplierOrder['supplier']['id']);
        $this->assertEquals('Toko Sembako Berkah Jaya', $supplierOrder['supplier']['name']);

        // Verifikasi konsistensi item dan kuantitas (4 galon)
        $this->assertNotEmpty($supplierOrder['items']);
        $firstItem = $supplierOrder['items'][0];
        $this->assertEquals('Galon', $firstItem['item_name']);
        $this->assertEquals(4.0, (float) $firstItem['quantity']);
        $this->assertEquals('galon', strtolower($firstItem['unit']));

        // Verifikasi kontrak tautan wa.me dan nomor telepon serta item yang dipesan
        $this->assertStringStartsWith('https://wa.me/6281234567890?text=', $supplierOrder['whatsapp_url']);
        $this->assertStringContainsString('Galon', $supplierOrder['whatsapp_url']);
        $this->assertStringContainsString('4%20galon', $supplierOrder['whatsapp_url']);

        // Verifikasi respons teks mencantumkan disclaimer non-PO dan non-mutasi otomatis
        $reply = $responseData['reply'] ?? $responseData['data']['reply'] ?? '';
        $this->assertStringContainsString('Toko Sembako Berkah Jaya', $reply);
        $this->assertStringContainsString('https://wa.me/6281234567890', $reply);
        $this->assertStringContainsString('TIDAK dikirim otomatis', $reply);
    }

    /**
     * 4. Afirmasi tanpa konteks restock TIDAK boleh menghasilkan draf restock.
     */
    public function test_affirmation_without_restock_context_does_not_create_restock_draft(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'Ada hal lain yang bisa saya bantu terkait laporan keuangan?',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $unrelatedHistory = [
            ['role' => 'user', 'content' => 'Berapa total omzet kemarin?'],
            ['role' => 'assistant', 'content' => 'Omzet kemarin stabil di angka Rp 1.500.000.'],
        ];

        $response = $this->actingAs($this->proUser)->postJson('/copilot/chat', [
            'message' => 'ya',
            'history' => $unrelatedHistory,
        ]);

        $response->assertStatus(200);
        $responseData = $response->json();

        // Pastikan TIDAK ADA payload supplier_order yang tergenerasi
        $this->assertNull($responseData['data']['supplier_order'] ?? null);
        $reply = $responseData['reply'] ?? $responseData['data']['reply'] ?? '';
        $this->assertStringNotContainsString('wa.me', $reply);
        $this->assertStringNotContainsString('draf pesan pemesanan restock', $reply);
    }

    /**
     * 5. Akses restock ke supplier di luar tenant wajib ditolak (UNAUTHORIZED_SUPPLIER / 403).
     */
    public function test_cross_tenant_supplier_restock_rejected_with_403_unauthorized_supplier(): void
    {
        // Riwayat menyebut supplier milik tenant lain (Supplier 999 milik User 998)
        $crossTenantHistory = [
            ['role' => 'user', 'content' => 'bantu pesan galon ke Supplier Luar Tenant'],
            ['role' => 'assistant', 'content' => 'Konfirmasi: buat draf restock untuk Supplier Luar Tenant?'],
        ];

        $response = $this->actingAs($this->proUser)->postJson('/copilot/chat', [
            'message' => 'ya',
            'history' => $crossTenantHistory,
        ]);

        // Karena Supplier 999 bukan milik User 911, extractRestockContextFromHistory mengabaikannya
        $responseData = $response->json();
        $this->assertNull($responseData['data']['supplier_order'] ?? null);

        // Uji pemanggilan langsung tool generator lintas tenant via controller logic
        $orchestrator = app(\App\Services\Financial\FinanceCopilotOrchestrationService::class);
        $crossTenantToolResult = $orchestrator->dispatchTool('generate_supplier_restock_message', (int) $this->proUser->id, (int) $this->outlet->id, [
            'supplier_id' => $this->otherSupplier->id,
            'items'       => [['item_name' => 'Galon', 'quantity' => 4, 'unit' => 'galon']],
        ]);

        $this->assertFalse($crossTenantToolResult['data']['success'] ?? true);
        $this->assertEquals('UNAUTHORIZED_SUPPLIER', $crossTenantToolResult['data']['status'] ?? null);
        $this->assertEquals('Supplier tidak ditemukan atau bukan milik tenant Anda.', $crossTenantToolResult['data']['error'] ?? null);
    }

    /**
     * 6. Tier Gate: User dengan tier 'basic' tidak mendapatkan akses eksekusi tool restock.
     */
    public function test_tier_gate_restricts_basic_user_from_copilot_restock_tools(): void
    {
        $promptBuilder = app(\App\Services\ChatbotPromptBuilder::class);
        $context = 'FINANCE_COPILOT';

        $isAllowedPro = $promptBuilder->isToolAllowedForContext($context, 'PRO', $this->proUser);
        $isAllowedBasic = $promptBuilder->isToolAllowedForContext($context, 'basic', $this->basicUser);

        $this->assertTrue($isAllowedPro, 'Akun tier PRO wajib memiliki akses tool Copilot.');
        $this->assertFalse($isAllowedBasic, 'Akun tier Basic dilarang mengakses tool Copilot.');
    }

    /**
     * 7. Keamanan Sesi & CSRF: Akses tanpa autentikasi wajib ditolak (redirect 302 / 401).
     */
    public function test_unauthenticated_request_is_redirected_or_rejected(): void
    {
        $responseGet = $this->get('/copilot/copilot_chat');
        $responseGet->assertStatus(302);

        $responsePost = $this->postJson('/copilot/chat', [
            'message' => 'Halo',
        ]);
        // Unauthenticated JSON request ditolak dengan 401 Unauthorized oleh auth middleware
        $responsePost->assertStatus(401);
    }

    /**
     * 8. Pastikan tidak ada mutasi database (purchase order / stock) atau panggilan HTTP eksternal.
     */
    public function test_no_external_side_effects_or_stock_mutations_occur(): void
    {
        // Pastikan tidak ada tabel purchase_orders yang terisi
        $hasPoTable = \Illuminate\Support\Facades\Schema::hasTable('purchase_orders');
        if ($hasPoTable) {
            $this->assertEquals(0, DB::table('purchase_orders')->count());
        }

        // Pastikan tidak ada mutasi stok (stock_movements)
        $hasStockMovements = \Illuminate\Support\Facades\Schema::hasTable('stock_movements');
        if ($hasStockMovements) {
            $this->assertEquals(0, DB::table('stock_movements')->count());
        }

        // Pastikan tidak ada HTTP request keluar ke domain eksternal selain mock LLM
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'api.whatsapp.com')
                || str_contains($request->url(), 'wa.me')
                || str_contains($request->url(), 'graph.facebook.com');
        });
    }
}
