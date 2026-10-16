<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Http\Controllers\ChatbotController;
use App\Services\Financial\CashFlowForecastService;
use App\Services\Financial\FinancialWarningService;
use App\Services\Financial\FinancialScenarioService;

class ChatbotCashFlowForecastIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $userPro;
    protected User $userUltimate;
    protected User $userBasic;
    protected Outlet $outlet;
    protected ChatbotController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.groq.key' => 'gsk_test_fake_api_key_123456789']);

        $this->controller = new ChatbotController();

        $this->userPro = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'pro',
            'account_status'    => 'active',
        ]);

        $this->userUltimate = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'ultimate',
            'account_status'    => 'active',
        ]);

        $this->userBasic = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'basic',
            'account_status'    => 'active',
        ]);

        $this->outlet = Outlet::create([
            'user_id'   => $this->userPro->id,
            'name'      => 'Outlet Pro Sentral',
            'device_id' => 'DEV-PRO-01',
            'timezone'  => 'Asia/Jakarta',
            'is_active' => true,
        ]);
    }

    /**
     * Helper to seed standard 28-day baseline data for forecast tests.
     */
    protected function seedStandardForecastBaseline(
        ?User $user = null,
        ?Outlet $outlet = null,
        int $days = 28,
        float $dailyRevenue = 1500000.0,
        float $dailyExpense = 300000.0
    ): void {
        $u = $user ?? $this->userPro;
        $o = $outlet ?? $this->outlet;

        $tz = $o->timezone ?: 'Asia/Jakarta';
        $now = Carbon::now($tz);

        for ($i = $days; $i >= 1; $i--) {
            $date = $now->copy()->subDays($i)->format('Y-m-d');
            $utcDateTime = Carbon::parse("{$date} 14:00:00", $tz)->setTimezone('UTC')->format('Y-m-d H:i:s');

            Transaction::create([
                'app_uuid'          => (string) \Illuminate\Support\Str::uuid(),
                'user_id'           => $u->id,
                'outlet_id'         => $o->id,
                'invoice_number'    => 'INV-' . $i . '-' . uniqid(),
                'total_amount'      => $dailyRevenue,
                'pay_amount'        => $dailyRevenue,
                'payment_method'    => 'Tunai',
                'status'            => 'Selesai',
                'created_at_device' => $utcDateTime,
            ]);

            DB::table('expenses')->insert([
                'user_id'    => $u->id,
                'outlet_id'  => $o->id,
                'name'       => 'Biaya Harian Operasional',
                'amount'     => $dailyExpense,
                'category'   => 'Operasional',
                'date'       => $date,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // ================================================================
    // 1. PRO TOOL REGISTERED
    // ================================================================

    public function test_tool_registered_for_pro_user(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'Halo Pro User.',
                        ]
                    ]
                ]
            ], 200),
        ]);

        $this->actingAs($this->userPro)->postJson('/chat', [
            'message' => 'Berapa proyeksi arus kas saya 7 hari ke depan?',
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $tools = $data['tools'] ?? [];
            $names = array_map(fn($t) => $t['function']['name'] ?? '', $tools);

            $this->assertContains('get_cash_flow_forecast', $names);

            $tool = collect($tools)->firstWhere('function.name', 'get_cash_flow_forecast');
            $this->assertNotNull($tool);
            $this->assertEquals('function', $tool['type']);
            $this->assertEquals('Mengambil proyeksi arus kas operasional deterministik 7 hari ke depan berdasarkan data historis Kaching.', $tool['function']['description']);
            $this->assertEquals('object', $tool['function']['parameters']['type']);
            $this->assertEquals([], $tool['function']['parameters']['required']);

            $props = (array) ($tool['function']['parameters']['properties'] ?? []);
            $this->assertEmpty($props);

            // Verifikasi tidak mengekspos parameter terlarang
            $this->assertArrayNotHasKey('user_id', $props);
            $this->assertArrayNotHasKey('outlet_id', $props);
            $this->assertArrayNotHasKey('horizon', $props);
            $this->assertArrayNotHasKey('date_range', $props);
            $this->assertArrayNotHasKey('sql', $props);
            $this->assertArrayNotHasKey('formula', $props);
            $this->assertArrayNotHasKey('threshold', $props);
            $this->assertArrayNotHasKey('model', $props);

            return true;
        });
    }

    // ================================================================
    // 2. ULTIMATE TOOL REGISTERED
    // ================================================================

    public function test_tool_registered_for_ultimate_user(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'Halo Ultimate User.',
                        ]
                    ]
                ]
            ], 200),
        ]);

        $this->actingAs($this->userUltimate)->postJson('/chat', [
            'message' => 'Proyeksikan cash flow seminggu ke depan',
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $tools = $data['tools'] ?? [];
            $names = array_map(fn($t) => $t['function']['name'] ?? '', $tools);

            $this->assertContains('get_cash_flow_forecast', $names);
            return true;
        });
    }

    // ================================================================
    // 3. BASIC TOOL EXCLUDED
    // ================================================================

    public function test_tool_excluded_for_basic_tier(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'Fitur proyeksi kas hanya tersedia pada paket PRO.',
                        ]
                    ]
                ]
            ], 200),
        ]);

        $this->actingAs($this->userBasic)->postJson('/chat', [
            'message' => 'Bagaimana forecast kas saya minggu depan?',
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();
            // Basic tier tidak boleh memiliki tools sama sekali
            $this->assertArrayNotHasKey('tools', $data);
            return true;
        });
    }

    // ================================================================
    // 4. UNAUTHENTICATED REQUEST REJECTED
    // ================================================================

    public function test_unauthenticated_request_rejected(): void
    {
        $request = Request::create('/chat', 'POST', []);
        $result = $this->controller->executeGetCashFlowForecast(null, $request, []);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('UNAUTHENTICATED', $result['code']);
        $this->assertEquals('User belum terautentikasi.', $result['message']);
    }

    // ================================================================
    // 5. STAFF OUTLET SCOPED CORRECTLY
    // ================================================================

    public function test_staff_outlet_scoped_correctly(): void
    {
        $staffUser = User::factory()->create([
            'role'              => 'cashier',
            'subscription_tier' => 'pro',
            'account_status'    => 'active',
        ]);
        $staffUser->outlet_id = $this->outlet->id;

        $mockService = $this->createMock(CashFlowForecastService::class);
        $mockService->expects($this->once())
            ->method('forecast')
            ->with($staffUser->id, $this->outlet->id)
            ->willReturn([
                'status' => 'SUCCESS',
                'horizon_days' => 7,
                'projected_inflow' => 10000000,
                'projected_outflow' => 3000000,
                'projected_net_operating_cash_flow' => 7000000,
            ]);

        $this->app->instance(CashFlowForecastService::class, $mockService);

        // Staff mencoba meminta outlet lain via header
        $request = Request::create('/chat', 'POST', []);
        $request->headers->set('Outlet-ID', '9999');

        $result = $this->controller->executeGetCashFlowForecast($staffUser, $request, []);
        $this->assertEquals('SUCCESS', $result['status']);
    }

    // ================================================================
    // 6. OWNER VALID OUTLET WORKS
    // ================================================================

    public function test_owner_valid_outlet_works(): void
    {
        $mockService = $this->createMock(CashFlowForecastService::class);
        $mockService->expects($this->once())
            ->method('forecast')
            ->with($this->userPro->id, $this->outlet->id)
            ->willReturn([
                'status' => 'SUCCESS',
                'horizon_days' => 7,
                'projected_inflow' => 10500000,
                'projected_outflow' => 2100000,
                'projected_net_operating_cash_flow' => 8400000,
            ]);

        $this->app->instance(CashFlowForecastService::class, $mockService);

        $request = Request::create('/chat', 'POST', []);
        $request->headers->set('Outlet-ID', (string) $this->outlet->id);

        $result = $this->controller->executeGetCashFlowForecast($this->userPro, $request, []);
        $this->assertEquals('SUCCESS', $result['status']);
    }

    // ================================================================
    // 7. OWNER UNAUTHORIZED OUTLET REJECTED
    // ================================================================

    public function test_owner_unauthorized_outlet_rejected(): void
    {
        $otherUser = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'pro',
        ]);
        $otherOutlet = Outlet::create([
            'user_id'   => $otherUser->id,
            'name'      => 'Outlet Toko Lain',
            'device_id' => 'DEV-OTHER-01',
        ]);

        $mockService = $this->createMock(CashFlowForecastService::class);
        $mockService->expects($this->never())->method('forecast');
        $this->app->instance(CashFlowForecastService::class, $mockService);

        $request = Request::create('/chat', 'POST', []);
        $request->headers->set('Outlet-ID', (string) $otherOutlet->id);

        $result = $this->controller->executeGetCashFlowForecast($this->userPro, $request, []);
        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('UNAUTHORIZED_OUTLET', $result['code']);
    }

    // ================================================================
    // 8. USER_ID ARGUMENT REJECTED
    // ================================================================

    public function test_user_id_argument_rejected(): void
    {
        $mockService = $this->createMock(CashFlowForecastService::class);
        $mockService->expects($this->never())->method('forecast');
        $this->app->instance(CashFlowForecastService::class, $mockService);

        $request = Request::create('/chat', 'POST', []);
        $result = $this->controller->executeGetCashFlowForecast($this->userPro, $request, ['user_id' => 999]);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('FORBIDDEN_PARAMETER', $result['code']);
        $this->assertStringContainsString('user_id', $result['message']);
    }

    // ================================================================
    // 9. OUTLET_ID ARGUMENT REJECTED
    // ================================================================

    public function test_outlet_id_argument_rejected(): void
    {
        $mockService = $this->createMock(CashFlowForecastService::class);
        $mockService->expects($this->never())->method('forecast');
        $this->app->instance(CashFlowForecastService::class, $mockService);

        $request = Request::create('/chat', 'POST', []);
        $result = $this->controller->executeGetCashFlowForecast($this->userPro, $request, ['outlet_id' => 888]);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('FORBIDDEN_PARAMETER', $result['code']);
        $this->assertStringContainsString('outlet_id', $result['message']);
    }

    // ================================================================
    // 10. SQL / QUERY ARGUMENT REJECTED
    // ================================================================

    public function test_sql_or_query_argument_rejected(): void
    {
        $mockService = $this->createMock(CashFlowForecastService::class);
        $mockService->expects($this->never())->method('forecast');
        $this->app->instance(CashFlowForecastService::class, $mockService);

        $request = Request::create('/chat', 'POST', []);

        $forbiddenTests = [
            ['sql' => 'SELECT * FROM transactions'],
            ['query' => 'DROP TABLE users'],
            ['formula' => 'inflow * 2'],
            ['threshold' => 50000],
            ['select' => '1'],
            ['drop' => '1'],
            ['insert' => '1'],
            ['update' => '1'],
            ['delete' => '1'],
        ];

        foreach ($forbiddenTests as $args) {
            $result = $this->controller->executeGetCashFlowForecast($this->userPro, $request, $args);
            $this->assertEquals('ERROR', $result['status']);
            $this->assertEquals('FORBIDDEN_PARAMETER', $result['code']);
        }
    }

    // ================================================================
    // 11. SUCCESSFUL FORECAST EXECUTION
    // ================================================================

    public function test_successful_forecast_execution(): void
    {
        $this->seedStandardForecastBaseline($this->userPro, $this->outlet, 28, 2000000.0, 500000.0);

        $request = Request::create('/chat', 'POST', []);
        $result = $this->controller->executeGetCashFlowForecast($this->userPro, $request, []);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals(7, $result['horizon_days']);
        $this->assertNotNull($result['projected_inflow']);
        $this->assertNotNull($result['projected_outflow']);
        $this->assertNotNull($result['projected_net_operating_cash_flow']);
        $this->assertCount(7, $result['daily_forecast']);
        $this->assertIsArray($result['assumptions']);
        $this->assertIsArray($result['limitations']);
    }

    // ================================================================
    // 12. STRUCTURED ENGINE RESULT PASSED THROUGH INTACT
    // ================================================================

    public function test_structured_engine_result_passed_through_intact(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $enginePayload = [
            'status' => 'SUCCESS',
            'forecast_period' => [
                'start_date' => '2026-09-26',
                'end_date' => '2026-10-02',
                'horizon_days' => 7,
                'anchor_date' => '2026-09-25',
                'timezone' => 'Asia/Jakarta',
            ],
            'horizon_days' => 7,
            'model' => 'DOW_SEASONAL_BASELINE',
            'data_quality' => 'SUFFICIENT',
            'data_quality_details' => ['active_days' => 28],
            'regularly_closed_weekdays' => [],
            'projected_inflow' => 14000000.0,
            'projected_outflow' => 3500000.0,
            'projected_net_operating_cash_flow' => 10500000.0,
            'daily_forecast' => [
                ['date' => '2026-09-26', 'day_of_week' => 'Saturday', 'projected_inflow' => 2000000.0, 'projected_outflow' => 500000.0, 'projected_net_operating_cash_flow' => 1500000.0],
            ],
            'backtest' => ['wape' => 0.08, 'mae' => 150000.0, 'bias' => 20000.0],
            'assumptions' => ['Kas masuk operasional berbasis median riil.'],
            'limitations' => ['Bukan saldo akhir bank.'],
        ];

        $mockService = $this->createMock(CashFlowForecastService::class);
        $mockService->method('forecast')->willReturn($enginePayload);
        $this->app->instance(CashFlowForecastService::class, $mockService);

        $step = 0;
        Http::fake([
            'https://api.groq.com/*' => function ($request) use (&$step, $enginePayload) {
                $step++;
                if ($step === 1) {
                    return Http::response([
                        'choices' => [
                            [
                                'message' => [
                                    'role' => 'assistant',
                                    'content' => null,
                                    'tool_calls' => [
                                        [
                                            'id' => 'call_cff_01',
                                            'type' => 'function',
                                            'function' => [
                                                'name' => 'get_cash_flow_forecast',
                                                'arguments' => '{}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ], 200);
                }

                $data = $request->data();
                $messages = $data['messages'];
                $toolMsg = collect($messages)->firstWhere('name', 'get_cash_flow_forecast');

                $this->assertNotNull($toolMsg);
                $this->assertEquals('tool', $toolMsg['role']);
                $content = json_decode($toolMsg['content'], true);

                $this->assertEquals('SUCCESS', $content['status']);
                $this->assertEquals('DOW_SEASONAL_BASELINE', $content['model']);
                $this->assertEquals(14000000.0, $content['projected_inflow']);
                $this->assertEquals(3500000.0, $content['projected_outflow']);
                $this->assertEquals(10500000.0, $content['projected_net_operating_cash_flow']);
                $this->assertEquals($enginePayload['forecast_period'], $content['forecast_period']);
                $this->assertEquals($enginePayload['backtest'], $content['backtest']);

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'role' => 'assistant',
                                'content' => 'Proyeksi kas bersih operasional Anda 7 hari ke depan adalah Rp 10.500.000.',
                            ],
                        ],
                    ],
                ], 200);
            },
        ]);

        $response = $this->actingAs($this->userPro)->postJson('/chat', [
            'message' => 'Tampilkan perkiraan arus kas 7 hari ke depan',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'reply' => 'Proyeksi kas bersih operasional Anda 7 hari ke depan adalah Rp 10.500.000.',
        ]);
        $this->assertEquals(2, $step);
    }

    // ================================================================
    // 13. INSUFFICIENT_HISTORY PROPAGATED
    // ================================================================

    public function test_insufficient_history_fail_closed_propagated(): void
    {
        // User baru tanpa transaksi
        $request = Request::create('/chat', 'POST', []);
        $result = $this->controller->executeGetCashFlowForecast($this->userPro, $request, []);

        $this->assertContains($result['status'], ['INSUFFICIENT_HISTORY', 'ZERO_BASELINE', 'SPARSE_DATA']);
        $this->assertNull($result['projected_inflow']);
        $this->assertNull($result['projected_outflow']);
        $this->assertNull($result['projected_net_operating_cash_flow']);
        $this->assertEmpty($result['daily_forecast']);
        $this->assertNotEmpty($result['limitations']);
    }

    // ================================================================
    // 14. REGULARLY_CLOSED_WEEKDAYS PROPAGATED
    // ================================================================

    public function test_regularly_closed_weekdays_propagated(): void
    {
        $mockService = $this->createMock(CashFlowForecastService::class);
        $mockService->method('forecast')->willReturn([
            'status' => 'SUCCESS',
            'horizon_days' => 7,
            'regularly_closed_weekdays' => [1], // Senin libur rutin
            'projected_inflow' => 6000000.0,
            'projected_outflow' => 1500000.0,
            'projected_net_operating_cash_flow' => 4500000.0,
            'daily_forecast' => [],
            'assumptions' => [],
            'limitations' => [],
        ]);

        $this->app->instance(CashFlowForecastService::class, $mockService);

        $request = Request::create('/chat', 'POST', []);
        $result = $this->controller->executeGetCashFlowForecast($this->userPro, $request, []);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals([1], $result['regularly_closed_weekdays']);
    }

    // ================================================================
    // 15. SERVICE EXCEPTION RETURNS CASH_FLOW_FORECAST_UNAVAILABLE
    // ================================================================

    public function test_service_exception_returns_cash_flow_forecast_unavailable(): void
    {
        $mockService = $this->createMock(CashFlowForecastService::class);
        $mockService->method('forecast')
            ->willThrowException(new \RuntimeException('Database server connection timeout.'));

        $this->app->instance(CashFlowForecastService::class, $mockService);

        $request = Request::create('/chat', 'POST', []);
        $result = $this->controller->executeGetCashFlowForecast($this->userPro, $request, []);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('CASH_FLOW_FORECAST_UNAVAILABLE', $result['code']);
        $this->assertEquals('Layanan proyeksi arus kas sedang tidak tersedia.', $result['message']);
    }

    // ================================================================
    // 16. REQUEST-LEVEL MEMOIZATION PREVENTS DUPLICATE EXECUTION
    // ================================================================

    public function test_request_level_memoization_prevents_duplicate_execution(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $mockService = $this->createMock(CashFlowForecastService::class);
        $mockService->expects($this->once()) // Harap tepat 1x dieksekusi walaupun ada 2 tool call
            ->method('forecast')
            ->willReturn([
                'status' => 'SUCCESS',
                'horizon_days' => 7,
                'projected_inflow' => 7000000.0,
                'projected_outflow' => 2000000.0,
                'projected_net_operating_cash_flow' => 5000000.0,
                'daily_forecast' => [],
                'assumptions' => [],
                'limitations' => [],
            ]);

        $this->app->instance(CashFlowForecastService::class, $mockService);

        $step = 0;
        Http::fake([
            'https://api.groq.com/*' => function ($request) use (&$step) {
                $step++;
                if ($step === 1) {
                    return Http::response([
                        'choices' => [
                            [
                                'message' => [
                                    'role' => 'assistant',
                                    'content' => null,
                                    'tool_calls' => [
                                        [
                                            'id' => 'call_cf_01',
                                            'type' => 'function',
                                            'function' => [
                                                'name' => 'get_cash_flow_forecast',
                                                'arguments' => '{}',
                                            ],
                                        ],
                                        [
                                            'id' => 'call_cf_02',
                                            'type' => 'function',
                                            'function' => [
                                                'name' => 'get_cash_flow_forecast',
                                                'arguments' => '{}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ], 200);
                }

                $data = $request->data();
                $messages = $data['messages'];
                $toolMessages = array_values(array_filter($messages, fn($m) => ($m['role'] ?? '') === 'tool'));

                $this->assertCount(2, $toolMessages);
                $this->assertEquals($toolMessages[0]['content'], $toolMessages[1]['content']);

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'role' => 'assistant',
                                'content' => 'Proyeksi net cash flow tercatat Rp 5.000.000.',
                            ],
                        ],
                    ],
                ], 200);
            },
        ]);

        $response = $this->actingAs($this->userPro)->postJson('/chat', [
            'message' => 'Cek forecast kas operasional',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(2, $step);
    }

    // ================================================================
    // 17. EXISTING GET_TODAY_SALES REMAINS INTACT
    // ================================================================

    public function test_existing_get_today_sales_remains_intact(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $todayJakarta = Carbon::today('Asia/Jakarta')->format('Y-m-d 10:00:00');
        DB::table('transactions')->insert([
            'user_id'                 => $this->userPro->id,
            'outlet_id'               => $this->outlet->id,
            'app_uuid'                => 'tx-uuid-sales-1',
            'official_invoice_number' => 'INV-SALES-1',
            'total_amount'            => 1500000,
            'pay_amount'              => 1500000,
            'payment_method'          => 'cash',
            'status'                  => 'Selesai',
            'created_at_device'       => $todayJakarta,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        $step = 0;
        Http::fake([
            'https://api.groq.com/*' => function ($request) use (&$step) {
                $step++;
                if ($step === 1) {
                    return Http::response([
                        'choices' => [
                            [
                                'message' => [
                                    'role' => 'assistant',
                                    'content' => null,
                                    'tool_calls' => [
                                        [
                                            'id' => 'call_sales_01',
                                            'type' => 'function',
                                            'function' => [
                                                'name' => 'get_today_sales',
                                                'arguments' => '{}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ], 200);
                }

                $data = $request->data();
                $toolMsg = collect($data['messages'])->firstWhere('name', 'get_today_sales');
                $this->assertNotNull($toolMsg);
                $this->assertStringContainsString('1.500.000', $toolMsg['content']);

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'role' => 'assistant',
                                'content' => 'Omzet hari ini adalah Rp 1.500.000.',
                            ],
                        ],
                    ],
                ], 200);
            },
        ]);

        $response = $this->actingAs($this->userPro)->postJson('/chat', [
            'message' => 'Tolong hitungkan total revenue toko hari ini',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(2, $step);
    }

    // ================================================================
    // 18. EXISTING GET_ACTIVE_FINANCIAL_WARNINGS REMAINS INTACT
    // ================================================================

    public function test_existing_get_active_financial_warnings_remains_intact(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $mockWarningService = $this->createMock(FinancialWarningService::class);
        $mockWarningService->method('getCachedWarnings')
            ->willReturn([
                FinancialWarningService::CODE_REVENUE_DECLINE => [
                    'code' => FinancialWarningService::CODE_REVENUE_DECLINE,
                    'status' => FinancialWarningService::STATUS_WARNING,
                    'severity' => FinancialWarningService::SEVERITY_WARNING,
                    'title' => 'Penurunan Omzet Terdeteksi',
                ],
            ]);

        $this->app->instance(FinancialWarningService::class, $mockWarningService);

        $step = 0;
        Http::fake([
            'https://api.groq.com/*' => function ($request) use (&$step) {
                $step++;
                if ($step === 1) {
                    return Http::response([
                        'choices' => [
                            [
                                'message' => [
                                    'role' => 'assistant',
                                    'content' => null,
                                    'tool_calls' => [
                                        [
                                            'id' => 'call_warn_01',
                                            'type' => 'function',
                                            'function' => [
                                                'name' => 'get_active_financial_warnings',
                                                'arguments' => '{}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ], 200);
                }

                $toolMsg = collect($request->data()['messages'])->firstWhere('name', 'get_active_financial_warnings');
                $this->assertNotNull($toolMsg);
                $content = json_decode($toolMsg['content'], true);
                $this->assertEquals('HAS_WARNINGS', $content['status']);

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'role' => 'assistant',
                                'content' => 'Ada peringatan penurunan omzet.',
                            ],
                        ],
                    ],
                ], 200);
            },
        ]);

        $response = $this->actingAs($this->userPro)->postJson('/chat', [
            'message' => 'Cek kondisi keuangan',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(2, $step);
    }

    // ================================================================
    // 19. EXISTING GET_FINANCIAL_SCENARIO REMAINS INTACT
    // ================================================================

    public function test_existing_get_financial_scenario_remains_intact(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $mockScenarioService = $this->createMock(FinancialScenarioService::class);
        $mockScenarioService->method('simulate')
            ->willReturn([
                'status' => 'SUCCESS',
                'scenario_type' => 'PRICE_CHANGE',
                'delta' => ['gross_profit' => 5000000],
            ]);

        $this->app->instance(FinancialScenarioService::class, $mockScenarioService);

        $step = 0;
        Http::fake([
            'https://api.groq.com/*' => function ($request) use (&$step) {
                $step++;
                if ($step === 1) {
                    return Http::response([
                        'choices' => [
                            [
                                'message' => [
                                    'role' => 'assistant',
                                    'content' => null,
                                    'tool_calls' => [
                                        [
                                            'id' => 'call_scen_01',
                                            'type' => 'function',
                                            'function' => [
                                                'name' => 'get_financial_scenario',
                                                'arguments' => json_encode([
                                                    'scenario_type' => 'PRICE_CHANGE',
                                                    'price_change_percent' => 10,
                                                ]),
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ], 200);
                }

                $toolMsg = collect($request->data()['messages'])->firstWhere('name', 'get_financial_scenario');
                $this->assertNotNull($toolMsg);
                $content = json_decode($toolMsg['content'], true);
                $this->assertEquals('SUCCESS', $content['status']);
                $this->assertEquals('PRICE_CHANGE', $content['scenario_type']);

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'role' => 'assistant',
                                'content' => 'Simulasi harga naik 10% selesai.',
                            ],
                        ],
                    ],
                ], 200);
            },
        ]);

        $response = $this->actingAs($this->userPro)->postJson('/chat', [
            'message' => 'Simulasi harga naik 10%',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(2, $step);
    }

    // ================================================================
    // 20. READ-ONLY GUARANTEE
    // ================================================================

    public function test_read_only_guarantee(): void
    {
        $this->seedStandardForecastBaseline($this->userPro, $this->outlet, 14, 1000000.0, 200000.0);

        $txCountBefore = Transaction::count();
        $expenseCountBefore = DB::table('expenses')->count();
        $outletCountBefore = Outlet::count();
        $userCountBefore = User::count();

        $request = Request::create('/chat', 'POST', []);
        $result = $this->controller->executeGetCashFlowForecast($this->userPro, $request, []);

        $this->assertNotNull($result);

        $this->assertEquals($txCountBefore, Transaction::count(), 'Transactions table must not be mutated.');
        $this->assertEquals($expenseCountBefore, DB::table('expenses')->count(), 'Expenses table must not be mutated.');
        $this->assertEquals($outletCountBefore, Outlet::count(), 'Outlets table must not be mutated.');
        $this->assertEquals($userCountBefore, User::count(), 'Users table must not be mutated.');
    }
}
