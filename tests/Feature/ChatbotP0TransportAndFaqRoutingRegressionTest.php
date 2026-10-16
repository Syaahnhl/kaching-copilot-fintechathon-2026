<?php

namespace Tests\Feature;

use App\Http\Controllers\AiOrderController;
use App\Http\Controllers\ChatbotController;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FaqMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotP0TransportAndFaqRoutingRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected User $ownerUser;
    protected Outlet $outlet1;
    protected Outlet $outlet2;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.groq.key' => 'gsk_test_fake_api_key_123456789',
            'services.groq.model' => 'powdercombo',
            'services.groq.base_url' => 'http://127.0.0.1:20128/v1',
            'cache.default' => 'array',
        ]);

        $this->ownerUser = User::factory()->create([
            'email' => 'owner_p0@test.com',
            'role' => 'owner',
            'subscription_tier' => 'ultimate',
        ]);

        $this->outlet1 = Outlet::factory()->create([
            'user_id' => $this->ownerUser->id,
            'name' => 'Outlet Pusat P0',
        ]);

        $this->outlet2 = Outlet::factory()->create([
            'user_id' => $this->ownerUser->id,
            'name' => 'Outlet Cabang P0',
        ]);

        Menu::create([
            'user_id' => $this->ownerUser->id,
            'name' => 'Es Teh Manis Segar',
            'category' => 'Minuman',
            'price' => 5000,
            'is_available' => true,
        ]);

        Menu::create([
            'user_id' => $this->ownerUser->id,
            'name' => 'Nasi Goreng Spesial',
            'category' => 'Makanan',
            'price' => 25000,
            'is_available' => true,
        ]);
    }

    /**
     * TEST 1: Sapaan "Halo" dan "Tes" mencapai jalur yang sesuai (LLM / tidak tertangkap FAQ statis)
     */
    public function test_01_greetings_halo_and_tes_reach_llm_flow(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'id' => 'chatcmpl-test-01',
                'object' => 'chat.completion',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Halo Tuan! Ada yang bisa Hermes bantu?',
                        ],
                        'finish_reason' => 'stop',
                    ]
                ],
            ], 200),
        ]);

        // Tes "Halo" pada ChatbotController
        $controller = app(ChatbotController::class);
        $request = Request::create('/chat', 'POST', [
            'message' => 'Halo',
        ]);

        $response = $controller->sendMessage($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Halo Tuan', $data['reply'] ?? $data['data']['reply'] ?? '');

        // Verifikasi payload yang dikirim ke LLM memiliki 'stream' => false
        Http::assertSent(function ($request) {
            $data = $request->data();
            return isset($data['stream']) && $data['stream'] === false;
        });
    }

    /**
     * TEST 2: Website Order — "Berapa harga es teh?" TIDAK mengembalikan harga paket POS
     */
    public function test_02_website_order_es_teh_price_does_not_return_pos_subscription(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'id' => 'chatcmpl-order-02',
                'object' => 'chat.completion',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Harga Es Teh Manis Segar adalah Rp 5.000 ya kak! Mau dipesankan berapa porsi?',
                        ],
                        'finish_reason' => 'stop',
                    ]
                ],
            ], 200),
        ]);

        $controller = app(AiOrderController::class);
        $request = Request::create('/order/chat-ai', 'POST', [
            'userId' => $this->ownerUser->id,
            'message' => 'Berapa harga es teh?',
            'history' => [],
            'cart' => [],
        ]);

        $response = $controller->chatVirtualAssistant($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $reply = $data['reply'] ?? '';

        // HARUS TIDAK mengandung paket POS Basic 39rb
        $this->assertStringNotContainsString('39.000', $reply);
        $this->assertStringNotContainsString('Paket Basic', $reply);
        $this->assertStringNotContainsString('Kaching POS', $reply);
        $this->assertStringContainsString('5.000', $reply);

        // Verifikasi payload LLM menyertakan 'stream' => false
        Http::assertSent(function ($request) {
            $data = $request->data();
            return isset($data['stream']) && $data['stream'] === false;
        });
    }

    /**
     * TEST 3: Website Order — "Pesan Nasi Goreng 2" tetap dapat menjalankan alur pemesanan
     */
    public function test_03_website_order_add_to_cart_action_executed(): void
    {
        $llmResponseContent = "Siap kak, 2 porsi Nasi Goreng Spesial sudah Kachi masukkan ke keranjang ya! <ORDER_ACTION>{\"action\":\"ADD_TO_CART\",\"items\":[{\"name\":\"Nasi Goreng Spesial\",\"qty\":2}]}</ORDER_ACTION>";

        Http::fake([
            '*/chat/completions' => Http::response([
                'id' => 'chatcmpl-order-03',
                'object' => 'chat.completion',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => $llmResponseContent,
                        ],
                        'finish_reason' => 'stop',
                    ]
                ],
            ], 200),
        ]);

        $controller = app(AiOrderController::class);
        $request = Request::create('/order/chat-ai', 'POST', [
            'userId' => $this->ownerUser->id,
            'message' => 'Pesan Nasi Goreng 2',
            'history' => [],
            'cart' => [],
        ]);

        $response = $controller->chatVirtualAssistant($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('success', $data['status']);
        $this->assertNotEmpty($data['extracted_order']);
        $this->assertEquals(2, $data['extracted_order'][0]['qty']);
    }

    /**
     * TEST 4: Android Copilot — "Bagaimana omzet hari ini?" TIDAK dihentikan oleh FAQ statis dan memanggil tool
     */
    public function test_04_copilot_omzet_hari_ini_triggers_tool_get_today_sales(): void
    {
        // Step 1: LLM memutuskan memanggil tool 'get_today_sales'
        // Step 2: Controller mengeksekusi DB query
        // Step 3: LLM menyintesis hasil
        Http::fake([
            '*/chat/completions' => Http::sequence()
                ->push([
                    'id' => 'chatcmpl-tool-call',
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [
                                    [
                                        'id' => 'call_sales_123',
                                        'type' => 'function',
                                        'function' => [
                                            'name' => 'get_today_sales',
                                            'arguments' => json_encode(['outlet_id' => $this->outlet1->id]),
                                        ],
                                    ]
                                ],
                            ],
                            'finish_reason' => 'tool_calls',
                        ]
                    ],
                ], 200)
                ->push([
                    'id' => 'chatcmpl-tool-synthesis',
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => 'Omzet hari ini di Outlet Pusat adalah Rp 1.500.000 dari 15 transaksi.',
                            ],
                            'finish_reason' => 'stop',
                        ]
                    ],
                ], 200),
        ]);

        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'Bagaimana omzet hari ini?',
        ]);
        $request->setUserResolver(fn () => $this->ownerUser);

        $response = $controller->sendMessage($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $reply = $data['reply'] ?? $data['data']['reply'] ?? '';

        // Memastikan BUKAN respon FAQ statis fitur dashboard
        $this->assertStringNotContainsString('Buka menu Laporan > Ringkasan Harian', $reply);
        $this->assertStringContainsString('1.500.000', $reply);

        // Memastikan 2 request terkirim ke LLM (Step 1 tool_call + Step 3 synthesis), KEDUANYA dengan 'stream' => false
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            $data = $request->data();
            return isset($data['stream']) && $data['stream'] === false;
        });
    }

    /**
     * TEST 5: Command Center — Pertanyaan laporan/stok tidak otomatis dijawab deskripsi fitur statis
     */
    public function test_05_command_center_stok_query_not_hijacked_by_faq(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'id' => 'chatcmpl-stok-05',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Analisis stok menunjukkan bahan baku ayam dan telur dalam batas aman untuk 5 hari ke depan.',
                        ],
                        'finish_reason' => 'stop',
                    ]
                ],
            ], 200),
        ]);

        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'Bagaimana status stok bahan baku hari ini?',
        ]);
        $request->setUserResolver(fn () => $this->ownerUser);

        $response = $controller->sendMessage($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $reply = $data['reply'] ?? $data['data']['reply'] ?? '';

        // TIDAK boleh menghasilkan FAQ statis "Manajemen Stok Kaching mencakup..."
        $this->assertStringNotContainsString('Manajemen Stok Kaching mencakup', $reply);
        $this->assertStringContainsString('Analisis stok menunjukkan', $reply);
    }

    /**
     * TEST 6: Pertanyaan lintas outlet tanpa izin tetap ditolak (UNAUTHORIZED_OUTLET)
     */
    public function test_06_unauthorized_cross_outlet_rejected(): void
    {
        $otherUser = User::factory()->create([
            'email' => 'other_user@test.com',
            'subscription_tier' => 'ultimate',
        ]);
        $otherOutlet = Outlet::factory()->create([
            'user_id' => $otherUser->id,
            'name' => 'Outlet Milik Orang Lain',
        ]);

        Http::fake([
            '*/chat/completions' => Http::sequence()
                ->push([
                    'id' => 'chatcmpl-tool-unauth',
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [
                                    [
                                        'id' => 'call_unauth_sales',
                                        'type' => 'function',
                                        'function' => [
                                            'name' => 'get_today_sales',
                                            'arguments' => json_encode(['outlet_id' => $otherOutlet->id]),
                                        ],
                                    ]
                                ],
                            ],
                            'finish_reason' => 'tool_calls',
                        ]
                    ],
                ], 200)
                ->push([
                    'id' => 'chatcmpl-tool-unauth-reply',
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => 'Maaf Tuan, Anda tidak memiliki akses ke outlet tersebut.',
                            ],
                            'finish_reason' => 'stop',
                        ]
                    ],
                ], 200),
        ]);

        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'Cek omzet outlet ID ' . $otherOutlet->id,
        ]);
        $request->setUserResolver(fn () => $this->ownerUser);

        $response = $controller->sendMessage($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());

        // Verifikasi bahwa tool response yang dikirim ke Step 3 berisi UNAUTHORIZED_OUTLET
        Http::assertSent(function ($request) {
            $data = $request->data();
            $messages = $data['messages'] ?? [];
            foreach ($messages as $msg) {
                if (($msg['role'] ?? '') === 'tool') {
                    $toolContent = json_decode($msg['content'] ?? '{}', true);
                    return ($toolContent['code'] ?? '') === 'UNAUTHORIZED_OUTLET';
                }
            }
            return false;
        });
    }

    /**
     * TEST 7: Perubahan harga langsung tetap dibatasi Policy Engine (REQUIRES_APPROVAL / PROPOSER_ONLY)
     */
    public function test_07_direct_price_change_proposer_only(): void
    {
        Http::fake([
            '*/chat/completions' => Http::sequence()
                ->push([
                    'id' => 'chatcmpl-propose-action',
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [
                                    [
                                        'id' => 'call_propose_price',
                                        'type' => 'function',
                                        'function' => [
                                            'name' => 'propose_action',
                                            'arguments' => json_encode([
                                                'action' => 'menu.price_change',
                                                'parameters' => [
                                                    'menu_id' => 1,
                                                    'new_price' => 50000,
                                                ],
                                            ]),
                                        ],
                                    ]
                                ],
                            ],
                            'finish_reason' => 'tool_calls',
                        ]
                    ],
                ], 200)
                ->push([
                    'id' => 'chatcmpl-propose-action-reply',
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => 'Usulan perubahan harga telah disiapkan dan menunggu persetujuan PIN Owner.',
                            ],
                            'finish_reason' => 'stop',
                        ]
                    ],
                ], 200),
        ]);

        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'Ubah harga menu 1 jadi 50000 langsung',
        ]);
        $request->setUserResolver(fn () => $this->ownerUser);

        $response = $controller->sendMessage($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());

        // Verifikasi bahwa tool response propose_action menghasilkan decision REQUIRES_APPROVAL dan proposal_id
        Http::assertSent(function ($request) {
            $data = $request->data();
            $messages = $data['messages'] ?? [];
            foreach ($messages as $msg) {
                if (($msg['role'] ?? '') === 'tool') {
                    $toolContent = json_decode($msg['content'] ?? '{}', true);
                    return ($toolContent['decision'] ?? '') === 'REQUIRES_APPROVAL' && !empty($toolContent['proposal_id']);
                }
            }
            return false;
        });
    }

    /**
     * TEST 8: Error provider / timeout menghasilkan fallback yang aman, bukan exception tak tertangani
     */
    public function test_08_provider_connection_error_safe_fallback(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response(null, 503),
        ]);

        // Uji pada ChatbotController
        $controller = app(ChatbotController::class);
        $request = Request::create('/chat', 'POST', [
            'message' => 'Pertanyaan unik tanpa FAQ',
        ]);

        $response = $controller->sendMessage($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $reply = $data['reply'] ?? $data['data']['reply'] ?? '';
        $this->assertEquals('AI_PROVIDER_UNAVAILABLE', $data['code'] ?? $data['data']['code'] ?? '');
        $this->assertTrue($data['is_fallback'] ?? false);
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', $reply);
        $this->assertNotEmpty($reply);

        // Uji pada AiOrderController (Fallback menghasilkan HTTP 503 dengan payload JSON rapi)
        $orderController = app(AiOrderController::class);
        $orderRequest = Request::create('/order/chat-ai', 'POST', [
            'userId' => $this->ownerUser->id,
            'message' => 'Pertanyaan pesanan saat AI down',
            'history' => [],
            'cart' => [],
        ]);

        $orderResponse = $orderController->chatVirtualAssistant($orderRequest);
        $orderData = json_decode($orderResponse->getContent(), true);

        $this->assertEquals(503, $orderResponse->getStatusCode());
        $this->assertEquals('success', $orderData['status']);
        $this->assertStringContainsString('Kachi agak tersendat', $orderData['reply']);
    }

    /**
     * TEST 9: Landing Page Guest — FAQ Marketing tetap bekerja untuk pertanyaan produk/harga langganan
     */
    public function test_09_landing_page_guest_marketing_faq_still_works(): void
    {
        Http::fake(); // Harusnya zero HTTP requests

        $controller = app(ChatbotController::class);
        $request = Request::create('/chat', 'POST', [
            'message' => 'Berapa harga paket Kaching POS?',
        ]);

        $response = $controller->sendMessage($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $reply = $data['reply'] ?? $data['data']['reply'] ?? '';

        $this->assertStringContainsString('Paket Kaching POS', $reply);
        $this->assertStringContainsString('39.000', $reply);
        $this->assertEquals('faq', $data['data']['source'] ?? $data['source'] ?? '');

        // Memastikan tidak ada panggilan LLM yang terbuang
        Http::assertNothingSent();
    }
}
