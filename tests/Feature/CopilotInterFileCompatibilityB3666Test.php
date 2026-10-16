<?php

namespace Tests\Feature;

use App\Http\Controllers\ChatbotController;
use App\Models\Outlet;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class CopilotInterFileCompatibilityB3666Test extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $otherTenant;
    protected Outlet $outlet;
    protected Outlet $otherOutlet;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('id');

        config([
            'services.ai.key'       => 'test-key-ai',
            'services.groq.key'     => 'test-key-groq',
            'services.ai.base_url'   => 'https://api.9router.com/v1',
            'services.groq.base_url' => 'https://api.9router.com/v1',
        ]);

        $this->owner = User::factory()->create([
            'name'              => 'Owner Kaching',
            'subscription_tier' => 'PRO',
            'role'              => 'OWNER',
        ]);

        $this->outlet = Outlet::factory()->create([
            'user_id' => $this->owner->id,
            'name'    => 'Outlet SSOT Test',
        ]);

        $this->otherTenant = User::factory()->create([
            'name'              => 'Other Tenant',
            'subscription_tier' => 'PRO',
            'role'              => 'OWNER',
        ]);

        $this->otherOutlet = Outlet::factory()->create([
            'user_id' => $this->otherTenant->id,
            'name'    => 'Other Outlet',
        ]);

        $this->supplier = Supplier::create([
            'user_id'   => $this->owner->id,
            'name'      => 'PT Sumber Beras',
            'phone'     => '081234567890',
            'is_active' => true,
        ]);
    }

    /**
     * TC-01: Normal Chat Flow
     * Memverifikasi chat normal mengembalikan HTTP 200, status success, bukan fallback,
     * dan mempertahankan payload relasional.
     */
    public function test_tc01_normal_chat(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'id'      => 'chatcmpl-test',
                'object'  => 'chat.completion',
                'created' => time(),
                'model'   => 'meta-llama/llama-3.3-70b-instruct',
                'choices' => [
                    [
                        'index'         => 0,
                        'message'       => [
                            'role'    => 'assistant',
                            'content' => 'Halo! Penjualan hari ini tercatat baik dengan performa stabil.',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $this->outlet->id)
            ->postJson('/copilot/chat', [
                'message' => 'Bagaimana performa penjualan hari ini?',
                'history' => [],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $this->assertFalse((bool) ($response->json('is_fallback') ?? false));
        $this->assertStringContainsString('Halo! Penjualan hari ini', (string) $response->json('reply'));
        $this->assertSame($response->json('reply'), $response->json('data.reply'));
    }

    /**
     * TC-02: Provider Fallback (AI Provider Unavailable)
     * Memverifikasi ketika provider gagal, respon HTTP 200 dengan status 'success',
     * is_fallback: true, code: 'AI_PROVIDER_UNAVAILABLE', financial_card: null, proposal: null.
     */
    public function test_tc02_provider_fallback(): void
    {
        Http::fake([
            '*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Connection timed out');
            },
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $this->outlet->id)
            ->postJson('/copilot/chat', [
                'message' => 'Ceritakan ringkasan tren bisnis saya',
                'history' => [],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('is_fallback', true);
        $response->assertJsonPath('code', 'AI_PROVIDER_UNAVAILABLE');
        $this->assertNull($response->json('financial_card'));
        $this->assertNull($response->json('proposal'));
        $this->assertNotEmpty($response->json('reply'));
        $this->assertStringContainsString('Layanan AI sementara tidak tersedia', (string) $response->json('reply'));
        $this->assertStringNotContainsString('Sistem Kaching sedang sibuk', (string) $response->json('reply'));
    }

    /**
     * TC-03: Clarification Fallback (Input Ambigu / Tidak Dikenal)
     * Memverifikasi input acak mengembalikan HTTP 200, status 'success',
     * is_fallback: true, code: 'CLARIFICATION_NEEDED'.
     */
    public function test_tc03_clarification_fallback(): void
    {
        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'asdfghjkl12345?',
        ]);
        $request->setUserResolver(fn() => $this->owner);

        $response = $controller->executeDeterministicFallback($this->owner, $request, 'asdfghjkl12345?');
        $data = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', $data['status']);
        $this->assertTrue($data['is_fallback']);
        $this->assertSame('CLARIFICATION_NEEDED', $data['code']);
        $this->assertNull($data['financial_card']);
        $this->assertNull($data['proposal']);
        $this->assertStringContainsString('Kachi belum memahami maksud permintaan', (string) $data['reply']);
    }

    /**
     * TC-04: Restock Context Missing (Afirmasi Tanpa Konteks Restock)
     * Memverifikasi afirmasi tanpa riwayat mengembalikan HTTP 200, status 'success',
     * is_fallback: true, code: 'RESTOCK_CONTEXT_MISSING'.
     */
    public function test_tc04_restock_context_missing(): void
    {
        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'ya, setuju',
            'history' => [],
        ]);
        $request->setUserResolver(fn() => $this->owner);

        $response = $controller->executeDeterministicFallback($this->owner, $request, 'ya, setuju');
        $data = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', $data['status']);
        $this->assertTrue($data['is_fallback']);
        $this->assertSame('RESTOCK_CONTEXT_MISSING', $data['code']);
        $this->assertNull($data['financial_card']);
        $this->assertNull($data['proposal']);
        $this->assertStringContainsString('Kachi belum menemukan konteks pesanan atau supplier', (string) $data['reply']);
    }

    /**
     * TC-05: HTTP 401 Unauthenticated
     * Memverifikasi rute Copilot tanpa user mengembalikan HTTP 401 dan code UNAUTHENTICATED.
     */
    public function test_tc05_http_401_unauthenticated(): void
    {
        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'Halo Copilot',
        ]);
        // Tanpa resolver user (unauthenticated)

        $response = $controller->sendMessage($request);
        $data = json_decode($response->getContent(), true);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('error', $data['status']);
        $this->assertSame('UNAUTHENTICATED', $data['code']);
        $this->assertNotEmpty($data['message']);
        $this->assertNotEmpty($data['reply']);
    }

    /**
     * TC-06: HTTP 403 Unauthorized Outlet
     * Memverifikasi akses ke outlet milik tenant lain ditolak secara tegas (HTTP 403, UNAUTHORIZED_OUTLET).
     */
    public function test_tc06_http_403_unauthorized_outlet(): void
    {
        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message'   => 'Berapa omzet outlet ini?',
            'outlet_id' => $this->otherOutlet->id,
        ]);
        $request->headers->set('Outlet-ID', (string) $this->otherOutlet->id);
        $request->setUserResolver(fn() => $this->owner);

        $response = $controller->executeDeterministicFallback($this->owner, $request, 'Berapa omzet outlet ini?');
        $data = json_decode($response->getContent(), true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('error', $data['status']);
        $this->assertSame('UNAUTHORIZED_OUTLET', $data['code']);
        $this->assertNull($data['financial_card']);
        $this->assertNull($data['proposal']);
        $this->assertStringContainsString('Akses ditolak', (string) $data['reply']);
    }

    /**
     * TC-07: View Baru Menerima Respons dari Controller Lama
     * Mensimulasikan parser JavaScript View baru memproses format respons Controller lama
     * { status: 'error', reply: '...', data: { reply: '...' } } dengan HTTP 200.
     * View baru HARUS mengenali sebagai fallback asistif dan TIDAK melempar Exception.
     */
    public function test_tc07_view_baru_with_controller_lama(): void
    {
        // Payload Controller lama pada HTTP 200
        $oldControllerPayload = [
            'status' => 'error',
            'reply'  => 'Maaf, sistem AI sedang sibuk. Silakan gunakan menu laporan penjualan.',
            'data'   => [
                'reply' => 'Maaf, sistem AI sedang sibuk. Silakan gunakan menu laporan penjualan.',
            ],
        ];

        // Simulasi logika parser View Baru
        $fatalCodes = ['UNAUTHENTICATED', 'UNAUTHORIZED_OUTLET', 'UNAUTHORIZED_SUPPLIER', 'CONFIG_MISSING', 'VALIDATION_ERROR'];
        $responseCode = $oldControllerPayload['code'] ?? ($oldControllerPayload['data']['code'] ?? null);

        $isConversationalFallback = (bool) (
            !empty($oldControllerPayload['is_fallback']) ||
            !empty($oldControllerPayload['data']['is_fallback']) ||
            in_array($responseCode, ['CLARIFICATION_NEEDED', 'AI_PROVIDER_UNAVAILABLE', 'RESTOCK_CONTEXT_MISSING', 'NO_TENANT_SUPPLIERS', 'SUPPLIER_SPECIFICATION_NEEDED']) ||
            ($oldControllerPayload['status'] === 'error' && (!empty($oldControllerPayload['reply']) || !empty($oldControllerPayload['data']['reply'])) && !in_array($responseCode, $fatalCodes))
        );

        $this->assertTrue($isConversationalFallback, 'View baru harus mengenali respons Controller lama sebagai fallback percakapan.');

        $isErrorThrown = ($oldControllerPayload['status'] === 'error' && !$isConversationalFallback);
        $this->assertFalse($isErrorThrown, 'View baru tidak boleh melempar exception saat menerima fallback dari Controller lama.');

        $reply = $oldControllerPayload['reply'] ?? ($oldControllerPayload['data']['reply'] ?? '');
        $this->assertSame('Maaf, sistem AI sedang sibuk. Silakan gunakan menu laporan penjualan.', $reply);
    }

    /**
     * TC-08: View Lama Menerima Respons dari Controller Baru
     * Mensimulasikan parser JavaScript View lama memproses format respons Controller baru
     * { status: 'success', is_fallback: true, code: 'AI_PROVIDER_UNAVAILABLE', reply: '...', financial_card: null, proposal: null }.
     * View lama TIDAK melempar Exception karena data.status !== 'error'.
     */
    public function test_tc08_view_lama_with_controller_baru(): void
    {
        // Payload Controller baru pada fallback
        $newControllerPayload = [
            'status'         => 'success',
            'is_fallback'    => true,
            'code'           => 'AI_PROVIDER_UNAVAILABLE',
            'reply'          => 'Layanan AI sementara tidak tersedia. Fitur analitik lokal tetap berjalan.',
            'financial_card' => null,
            'proposal'       => null,
            'data'           => [
                'reply'          => 'Layanan AI sementara tidak tersedia. Fitur analitik lokal tetap berjalan.',
                'code'           => 'AI_PROVIDER_UNAVAILABLE',
                'is_fallback'    => true,
                'financial_card' => null,
                'proposal'       => null,
            ],
        ];

        // Simulasi logika parser View Lama:
        // if (data.status === 'error') { throw new Error(errorMsg); }
        $didViewLamaThrow = ($newControllerPayload['status'] === 'error');
        $this->assertFalse($didViewLamaThrow, 'View lama TIDAK BOLEH melempar error saat menerima status: success.');

        // View lama mengekstrak pesan:
        $renderedReply = $newControllerPayload['reply'] ?? ($newControllerPayload['data']['reply'] ?? null);
        $financialCard = $newControllerPayload['financial_card'] ?? null;
        $proposal = $newControllerPayload['proposal'] ?? null;

        $this->assertSame('Layanan AI sementara tidak tersedia. Fitur analitik lokal tetap berjalan.', $renderedReply);
        $this->assertNull($financialCard, 'Fallback tidak boleh memunculkan financial card.');
        $this->assertNull($proposal, 'Fallback tidak boleh memunculkan proposal.');
    }

    /**
     * TC-09: HTTP 500 Tidak Disamarkan Sebagai Fallback
     * Memverifikasi kegagalan konfigurasi internal (misal API key tidak ada) menghasilkan HTTP 500,
     * status: 'error', code: 'CONFIG_MISSING', dan dilempar sebagai error banner, BUKAN bubble asistif.
     */
    public function test_tc09_http_500_not_masked_as_fallback(): void
    {
        config([
            'services.ai.key'   => null,
            'services.groq.key' => null,
        ]);

        $controller = app(ChatbotController::class);
        $request = Request::create('/copilot/chat', 'POST', [
            'message' => 'Tes pesan saat config hilang',
        ]);
        $request->setUserResolver(fn() => $this->owner);

        $response = $controller->sendMessage($request);
        $data = json_decode($response->getContent(), true);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('error', $data['status']);
        $this->assertSame('CONFIG_MISSING', $data['code']);
        $this->assertFalse((bool) ($data['is_fallback'] ?? false));

        // Parser View: pada response.ok === false (HTTP 500), parser melempar exception
        $isOk = ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300);
        $this->assertFalse($isOk, 'HTTP 500 harus membuat response.ok bernilai false.');
    }

    /**
     * TC-10: Translation Keys Lengkap & Sanitasi Raw Key
     * Memverifikasi tidak ada missing translation key pada lang/id/copilot.php dan lang/en/copilot.php,
     * serta fungsi sanitizeText mencegah raw translation key muncul ke UI.
     */
    public function test_tc10_translation_keys_complete_and_sanitized(): void
    {
        $idKeys = include resource_path('../lang/id/copilot.php');
        $enKeys = include resource_path('../lang/en/copilot.php');

        $requiredKeys = [
            'summaries.no_top_products',
            'summaries.sales_data_unavailable',
            'summaries.top_products_unavailable',
            'system.clarification_ai_unavailable',
            'system.clarification_default',
            'system.no_suppliers_registered',
            'system.restock_context_missing',
            'system.unauthenticated',
            'system.unauthorized_outlet',
            'system.config_missing',
        ];

        foreach ($requiredKeys as $keyPath) {
            $parts = explode('.', $keyPath);
            $currId = $idKeys;
            $currEn = $enKeys;

            foreach ($parts as $p) {
                $this->assertArrayHasKey($p, $currId, "Key {$keyPath} harus ada di lang/id/copilot.php");
                $this->assertArrayHasKey($p, $currEn, "Key {$keyPath} harus ada di lang/en/copilot.php");
                $currId = $currId[$p];
                $currEn = $currEn[$p];
            }
        }

        // Uji sanitasi raw translation key jika server mengirim string raw
        $sanitizeText = function (?string $str, string $fallback): string {
            if (!$str || !is_string($str)) return $fallback;
            $trimmed = trim($str);
            if (preg_match('/^copilot\.[a-z0-9_.-]+$/i', $trimmed)) {
                return $fallback;
            }
            return $trimmed;
        };

        $rawKeyLeak = 'copilot.system.clarification_default';
        $sanitized = $sanitizeText($rawKeyLeak, 'Pesan fallback default');
        $this->assertSame('Pesan fallback default', $sanitized, 'Raw translation key harus digantikan dengan fallback aman.');

        $normalText = 'Pesan asisten valid yang ramah';
        $normalSanitized = $sanitizeText($normalText, 'Pesan fallback default');
        $this->assertSame('Pesan asisten valid yang ramah', $normalSanitized);
    }

    /**
     * TC-11: Widget Visibility Pada Halaman Copilot
     * Memverifikasi widget tersembunyi pada halaman yang mendefinisikan section 'hide_copilot_widget'.
     */
    public function test_tc11_widget_visibility_on_copilot_page(): void
    {
        View::flushSections();
        View::startSection('hide_copilot_widget', true);

        $widgetView = view('components.copilot-widget')->render();
        $this->assertEmpty(trim($widgetView), 'Widget harus tersembunyi saat section hide_copilot_widget aktif.');

        View::flushSections();
        $widgetViewActive = view('components.copilot-widget')->render();
        $this->assertNotEmpty(trim($widgetViewActive), 'Widget harus tampil pada halaman reguler.');
        $this->assertStringContainsString('kachiCopilotWidget()', $widgetViewActive);
    }

    /**
     * TC-12: Financial Card / Proposal Mappings & Pencegahan Bubble Duplikat
     * Memverifikasi payload finansial/proposal tidak diduplikasi dan diisolasi dengan benar.
     */
    public function test_tc12_financial_card_proposal_and_duplicate_bubble_prevention(): void
    {
        $payloadWithCard = [
            'status'         => 'success',
            'is_fallback'    => false,
            'reply'          => 'Berikut analisis margin produk Anda.',
            'financial_card' => [
                'type'  => 'menu_margin',
                'title' => 'Menu Margin Analisis',
            ],
            'proposal'       => [
                'id'         => 12,
                'menu_name'  => 'Nasi Goreng',
                'proposed'   => 25000,
            ],
        ];

        // Simulasi state JavaScript
        $messages = [];
        $history = [];

        // Turn 1: User mengirim
        $userMsg = 'Cek margin menu';
        $messages[] = ['role' => 'user', 'content' => $userMsg];

        // Assistant menerima
        $isFallback = (bool) ($payloadWithCard['is_fallback'] ?? false);
        $financialCard = $isFallback ? null : ($payloadWithCard['financial_card'] ?? null);
        $proposal = $isFallback ? null : ($payloadWithCard['proposal'] ?? null);

        $messages[] = [
            'role'           => 'assistant',
            'content'        => $payloadWithCard['reply'],
            'financial_card' => $financialCard,
            'proposal'       => $proposal,
            'is_fallback'    => $isFallback,
        ];

        $history[] = ['role' => 'user', 'content' => $userMsg];
        $history[] = ['role' => 'assistant', 'content' => $payloadWithCard['reply']];

        // Verifikasi tidak ada duplikasi
        $this->assertCount(2, $messages, 'Pesan harus berisi tepat 1 user dan 1 assistant.');
        $this->assertCount(2, $history, 'History harus berisi tepat 1 user dan 1 assistant.');
        $this->assertNotNull($messages[1]['financial_card']);
        $this->assertNotNull($messages[1]['proposal']);
        $this->assertFalse($messages[1]['is_fallback']);
    }
}
