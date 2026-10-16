<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Menu;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Expense;

class ChatbotFinancialCardBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->user = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'pro',
        ]);

        $this->outlet = Outlet::create([
            'user_id' => $this->user->id,
            'name'    => 'Outlet Pusat Jakarta',
            'address' => 'Jl. Thamrin No. 1, Jakarta',
        ]);
    }

    /**
     * Helper untuk mock Groq completion API response
     */
    protected function mockGroqToolCalling(string $toolName, array $toolArgs, string $finalReply = 'Laporan finansial Anda siap.'): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'role'       => 'assistant',
                                'content'    => null,
                                'tool_calls' => [
                                    [
                                        'id'       => 'call_' . uniqid(),
                                        'type'     => 'function',
                                        'function' => [
                                            'name'      => $toolName,
                                            'arguments' => json_encode($toolArgs),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200)
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'role'    => 'assistant',
                                'content' => $finalReply,
                            ],
                        ],
                    ],
                ], 200),
        ]);
    }

    /**
     * Helper untuk mock Groq plain chat tanpa tool call
     */
    protected function mockGroqPlainReply(string $reply = 'Halo! Saya Kaching Copilot, asisten keuangan Anda.'): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role'    => 'assistant',
                            'content' => $reply,
                        ],
                    ],
                ],
            ], 200),
        ]);
    }

    /**
     * Seed data transaksi untuk forecast arus kas (14 hari)
     */
    protected function seedForecastHistory(): void
    {
        $today = Carbon::today('Asia/Jakarta');
        for ($i = 14; $i >= 1; $i--) {
            $date = $today->copy()->subDays($i)->setTime(12, 0, 0);
            Transaction::create([
                'user_id'           => $this->user->id,
                'outlet_id'         => $this->outlet->id,
                'app_uuid'          => (string) \Illuminate\Support\Str::uuid(),
                'invoice_number'    => 'INV-FC-' . $i,
                'total_amount'      => 1000000,
                'pay_amount'        => 1000000,
                'payment_method'    => 'cash',
                'status'            => 'Selesai',
                'created_at_device' => $date->format('Y-m-d H:i:s'),
                'created_at'        => $date,
                'updated_at'        => $date,
            ]);

            \Illuminate\Support\Facades\DB::table('expenses')->insert([
                'user_id'    => $this->user->id,
                'outlet_id'  => $this->outlet->id,
                'name'       => 'Beban Harian ' . $i,
                'amount'     => 300000,
                'category'   => 'Operasional',
                'date'       => $date->format('Y-m-d'),
                'created_at' => $date,
                'updated_at' => $date,
            ]);
        }
    }

    /**
     * Seed data menu dan transaksi untuk simulasi skenario finansial
     */
    protected function seedScenarioHistory(): void
    {
        $menu = Menu::create([
            'user_id'    => $this->user->id,
            'outlet_id'  => $this->outlet->id,
            'name'       => 'Kopi Espresso',
            'category'   => 'Minuman',
            'price'      => 20000,
            'cost_price' => 8000,
            'stock'      => 100,
        ]);

        $today = Carbon::today('Asia/Jakarta');
        for ($i = 10; $i >= 1; $i--) {
            $date = $today->copy()->subDays($i)->setTime(14, 0, 0);
            $trx = Transaction::create([
                'user_id'           => $this->user->id,
                'outlet_id'         => $this->outlet->id,
                'app_uuid'          => (string) \Illuminate\Support\Str::uuid(),
                'invoice_number'    => 'INV-SC-' . $i,
                'total_amount'      => 40000,
                'pay_amount'        => 40000,
                'payment_method'    => 'cash',
                'status'            => 'Selesai',
                'created_at_device' => $date->format('Y-m-d H:i:s'),
                'created_at'        => $date,
                'updated_at'        => $date,
            ]);

            TransactionItem::create([
                'transaction_id' => $trx->id,
                'menu_id'        => $menu->id,
                'menu_name'      => $menu->name,
                'price'          => $menu->price,
                'cost_price'     => $menu->cost_price,
                'qty'            => 2,
                'subtotal'       => 40000,
            ]);
        }

        \Illuminate\Support\Facades\DB::table('expenses')->insert([
            'user_id'    => $this->user->id,
            'outlet_id'  => $this->outlet->id,
            'name'       => 'Beban Operasional Toko',
            'amount'     => 50000,
            'category'   => 'Operasional',
            'date'       => $today->copy()->subDays(2)->format('Y-m-d'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * XML helper untuk mock External Radar RSS feed
     */
    protected function getSampleRssXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Google News</title>
    <item>
      <title>Harga Kopi Arabika Naik di Pasar Tradisional - Warta Dagang</title>
      <link>https://news.google.com/rss/articles/CBMi123</link>
      <pubDate>Fri, 25 Sep 2026 03:15:00 GMT</pubDate>
      <description>&lt;p&gt;Pasokan kopi menipis akibat cuaca ekstrem di sentra produksi.&lt;/p&gt;</description>
      <source url="https://wartadagang.com">Warta Dagang</source>
    </item>
  </channel>
</rss>
XML;
    }

    // =========================================================================
    // 1. PLAIN CHAT & NULLABILITY TESTS
    // =========================================================================

    public function test_plain_chat_has_null_financial_card_and_no_proposal(): void
    {
        $this->mockGroqPlainReply('Halo! Ada yang bisa saya bantu terkait keuangan toko?');

        $response = $this->actingAs($this->user)->postJson('/api/chat-help', [
            'message' => 'Halo Copilot',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'reply'  => 'Halo! Ada yang bisa saya bantu terkait keuangan toko?',
            'data'   => [
                'reply'          => 'Halo! Ada yang bisa saya bantu terkait keuangan toko?',
                'financial_card' => null,
            ],
        ]);

        $this->assertNull($response->json('data.financial_card'));
        $this->assertArrayNotHasKey('proposal', $response->json('data'));
    }

    // =========================================================================
    // 2. B.10 PROPOSAL COMPATIBILITY TESTS
    // =========================================================================

    public function test_proposal_chat_preserves_b10_proposal_contract_with_null_financial_card(): void
    {
        $this->mockGroqToolCalling('propose_action', [
            'action'     => 'expense.create',
            'parameters' => [
                'name'   => 'Beli Sabun Cuci Piring',
                'amount' => 25000,
            ],
        ], 'Saya telah menyiapkan draf pengeluaran sebesar Rp 25.000.');

        $response = $this->actingAs($this->user)->postJson('/api/chat-help', [
            'message' => 'Catat pengeluaran 25rb sabun cuci piring',
        ]);

        $response->assertStatus(200);

        // data.proposal harus tetap memenuhi contract B.10
        $response->assertJsonStructure([
            'status',
            'reply',
            'data' => [
                'reply',
                'proposal' => [
                    'proposal_id',
                    'action',
                    'parameters',
                    'required_role',
                    'decision',
                    'risk_level',
                    'expires_at',
                ],
                'financial_card',
            ],
        ]);

        $proposal = $response->json('data.proposal');
        $this->assertEquals('expense.create', $proposal['action']);
        $this->assertEquals(25000, $proposal['parameters']['amount']);
        $this->assertEquals('REQUIRES_APPROVAL', $proposal['decision']);
        $this->assertEquals('MEDIUM', $proposal['risk_level']);

        // Pastikan financial_card null pada proposal-only chat
        $this->assertNull($response->json('data.financial_card'));
    }

    // =========================================================================
    // 3. FINANCIAL SUMMARY (TODAY SALES) TEST
    // =========================================================================

    public function test_today_sales_returns_summary_financial_card(): void
    {
        $todayStr = Carbon::today('Asia/Jakarta')->format('Y-m-d');
        Transaction::create([
            'user_id'           => $this->user->id,
            'outlet_id'         => $this->outlet->id,
            'app_uuid'          => (string) \Illuminate\Support\Str::uuid(),
            'invoice_number'    => 'INV-TODAY-001',
            'total_amount'      => 350000,
            'pay_amount'        => 350000,
            'payment_method'    => 'cash',
            'status'            => 'Selesai',
            'created_at_device' => $todayStr . ' 10:00:00',
            'created_at'        => Carbon::today('Asia/Jakarta')->setTime(10, 0, 0),
            'updated_at'        => Carbon::today('Asia/Jakarta')->setTime(10, 0, 0),
        ]);

        $this->mockGroqToolCalling('get_today_sales', [], 'Omzet hari ini adalah Rp 350.000.');

        $response = $this->actingAs($this->user)->postJson('/api/chat-help', [
            'message' => 'Tolong periksa penjualan toko hari ini',
        ]);

        $response->assertStatus(200);

        $card = $response->json('data.financial_card');
        $this->assertNotNull($card);
        $this->assertEquals('summary', $card['type']);
        $this->assertEquals('success', $card['severity']);
        $this->assertEquals('Ringkasan Penjualan Hari Ini', $card['title']);

        $cardData = $card['data'];
        $this->assertEquals(350000.0, (float) $cardData['today_sales']);
        $this->assertEquals('Rp 350.000', $cardData['formatted_sales']);
        $this->assertEquals('IDR', $cardData['currency']);
        $this->assertEquals($todayStr, $cardData['date']);

        $this->assertSensitiveFieldsAbsent($card);
    }

    // =========================================================================
    // 4. FINANCIAL WARNING TEST
    // =========================================================================

    public function test_financial_warnings_returns_warning_financial_card(): void
    {
        $this->mockGroqToolCalling('get_active_financial_warnings', [], 'Berikut kondisi peringatan finansial toko Anda.');

        $response = $this->actingAs($this->user)->postJson('/api/chat-help', [
            'message' => 'Cek peringatan finansial toko saya',
        ]);

        $response->assertStatus(200);

        $card = $response->json('data.financial_card');
        $this->assertNotNull($card);
        $this->assertEquals('warning', $card['type']);
        $this->assertContains($card['severity'], ['critical', 'warning', 'watch', 'success']);
        $this->assertNotEmpty($card['title']);

        $cardData = $card['data'];
        $this->assertArrayHasKey('status', $cardData);
        $this->assertArrayHasKey('warnings_count', $cardData);
        $this->assertArrayHasKey('warnings', $cardData);
        $this->assertIsArray($cardData['warnings']);

        $this->assertSensitiveFieldsAbsent($card);
    }

    // =========================================================================
    // 5. CASH FLOW FORECAST TEST
    // =========================================================================

    public function test_cash_flow_forecast_returns_forecast_financial_card(): void
    {
        $this->seedForecastHistory();

        $this->mockGroqToolCalling('get_cash_flow_forecast', [
            'horizon_days' => 7,
        ], 'Berikut adalah proyeksi arus kas operasional toko 7 hari ke depan.');

        $response = $this->actingAs($this->user)->postJson('/api/chat-help', [
            'message' => 'Tampilkan proyeksi arus kas 7 hari ke depan',
        ]);

        $response->assertStatus(200);

        $card = $response->json('data.financial_card');
        $this->assertNotNull($card);
        $this->assertEquals('forecast', $card['type']);
        $this->assertContains($card['severity'], ['success', 'warning', 'watch']);

        $cardData = $card['data'];
        $this->assertArrayHasKey('status', $cardData);
        $this->assertEquals(7, $cardData['horizon_days']);

        if ($cardData['status'] === 'SUCCESS') {
            $this->assertArrayHasKey('start_date', $cardData);
            $this->assertArrayHasKey('end_date', $cardData);
            $this->assertArrayHasKey('projected_inflow', $cardData);
            $this->assertArrayHasKey('projected_outflow', $cardData);
            $this->assertArrayHasKey('projected_net_cash_flow', $cardData);
            $this->assertArrayHasKey('daily_forecast', $cardData);
            $this->assertIsArray($cardData['daily_forecast']);
        }

        $this->assertSensitiveFieldsAbsent($card);
    }

    // =========================================================================
    // 6. FINANCIAL SCENARIO TEST
    // =========================================================================

    public function test_financial_scenario_returns_scenario_financial_card(): void
    {
        $this->seedScenarioHistory();

        $this->mockGroqToolCalling('get_financial_scenario', [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10.0,
        ], 'Hasil simulasi menunjukkan kenaikan laba bersih sebesar 10%.');

        $response = $this->actingAs($this->user)->postJson('/api/chat-help', [
            'message' => 'Simulasikan kenaikan harga 10%',
        ]);

        $response->assertStatus(200);

        $card = $response->json('data.financial_card');
        $this->assertNotNull($card);
        $this->assertEquals('scenario', $card['type']);
        $this->assertContains($card['severity'], ['success', 'warning', 'watch']);

        $cardData = $card['data'];
        $this->assertArrayHasKey('status', $cardData);
        $this->assertEquals('PRICE_CHANGE', $cardData['scenario_type']);

        if ($cardData['status'] === 'SUCCESS') {
            $this->assertArrayHasKey('baseline', $cardData);
            $this->assertArrayHasKey('result', $cardData);
            $this->assertArrayHasKey('delta', $cardData);

            $this->assertArrayHasKey('revenue', $cardData['baseline']);
            $this->assertArrayHasKey('net_profit', $cardData['baseline']);

            $this->assertArrayHasKey('revenue', $cardData['result']);
            $this->assertArrayHasKey('net_profit', $cardData['result']);

            $this->assertArrayHasKey('revenue', $cardData['delta']);
            $this->assertArrayHasKey('net_profit', $cardData['delta']);
        }

        $this->assertSensitiveFieldsAbsent($card);
    }

    // =========================================================================
    // 7. EXTERNAL BUSINESS RADAR TEST
    // =========================================================================

    public function test_external_radar_returns_radar_financial_card(): void
    {
        // Mock external Google News RSS dan BMKG
        Http::fake([
            'https://news.google.com/*' => Http::response($this->getSampleRssXml(), 200),
            'https://data.bmkg.go.id/*' => Http::response([], 200),
            'https://api.groq.com/openai/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'role'       => 'assistant',
                                'content'    => null,
                                'tool_calls' => [
                                    [
                                        'id'       => 'call_' . uniqid(),
                                        'type'     => 'function',
                                        'function' => [
                                            'name'      => 'get_external_business_radar',
                                            'arguments' => json_encode(['category' => 'COMMODITY']),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200)
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'role'    => 'assistant',
                                'content' => 'Berikut sinyal radar pasar komoditas eksternal.',
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/chat-help', [
            'message' => 'Cek sinyal radar pasar komoditas eksternal',
        ]);

        $response->assertStatus(200);

        $card = $response->json('data.financial_card');
        $this->assertNotNull($card);
        $this->assertEquals('radar', $card['type']);
        $this->assertContains($card['severity'], ['critical', 'warning', 'watch', 'success']);
        $this->assertEquals('Radar Pasar & Bisnis Eksternal', $card['title']);

        $cardData = $card['data'];
        $this->assertEquals('SUCCESS', $cardData['status']);
        $this->assertArrayHasKey('signals_count', $cardData);
        $this->assertArrayHasKey('signals', $cardData);
        $this->assertIsArray($cardData['signals']);

        $this->assertSensitiveFieldsAbsent($card);
    }

    // =========================================================================
    // 8. SECURITY & DATA HYGIENE ASSERTION HELPER
    // =========================================================================

    /**
     * Memastikan tidak ada internal/sensitive fields yang bocor ke payload
     */
    protected function assertSensitiveFieldsAbsent(array $card): void
    {
        $forbiddenKeys = [
            'user_id',
            'outlet_id',
            'sql',
            'query',
            'token',
            'pin',
            'cache_key',
            'api_key',
            'connection',
            'password',
            'secret',
        ];

        $flattened = $this->arrayKeysRecursive($card);

        foreach ($forbiddenKeys as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $flattened,
                "Security Leak Violation: Found sensitive key '{$forbidden}' inside financial_card payload!"
            );
        }
    }

    /**
     * Helper rekursif untuk mengumpulkan semua keys di dalam array nested
     */
    protected function arrayKeysRecursive(array $array): array
    {
        $keys = [];
        foreach ($array as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $keys = array_merge($keys, $this->arrayKeysRecursive($value));
            }
        }
        return array_unique($keys);
    }
}
