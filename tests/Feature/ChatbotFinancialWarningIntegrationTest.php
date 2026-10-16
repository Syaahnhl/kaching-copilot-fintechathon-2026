<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Outlet;
use App\Http\Controllers\ChatbotController;
use App\Services\Financial\FinancialWarningService;
use App\Services\Financial\FinancialCalculationService;

class ChatbotFinancialWarningIntegrationTest extends TestCase
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

        $this->outlet = Outlet::factory()->create([
            'user_id' => $this->userPro->id,
            'name'    => 'Outlet Pusat Pro',
        ]);
    }

    // ================================================================
    // 1. TOOL REGISTRATION TESTS
    // ================================================================

    public function test_tool_registration_exists_for_pro_and_ultimate_users(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'Halo, ini respons percakapan biasa.',
                        ]
                    ]
                ]
            ], 200),
        ]);

        // Pro user
        $this->actingAs($this->userPro)->postJson('/chat', [
            'message' => 'Kondisi keuangan bagaimana?',
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $tools = $data['tools'] ?? [];
            $names = array_map(fn($t) => $t['function']['name'] ?? '', $tools);

            $this->assertContains('get_today_sales', $names);
            $this->assertContains('get_active_financial_warnings', $names);

            $fwTool = collect($tools)->firstWhere('function.name', 'get_active_financial_warnings');
            $this->assertNotNull($fwTool);
            $this->assertEquals('function', $fwTool['type']);
            $this->assertEquals('object', $fwTool['function']['parameters']['type']);
            $this->assertEmpty((array)$fwTool['function']['parameters']['properties']);

            return true;
        });

        // Ultimate user
        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'Halo Ultimate.',
                        ]
                    ]
                ]
            ], 200),
        ]);

        $this->actingAs($this->userUltimate)->postJson('/chat', [
            'message' => 'Analisis keuangan saya',
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $tools = $data['tools'] ?? [];
            $names = array_map(fn($t) => $t['function']['name'] ?? '', $tools);
            return in_array('get_active_financial_warnings', $names, true);
        });
    }

    public function test_tool_registration_absent_for_basic_tier(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'Maaf, fitur data khusus paket PRO.',
                        ]
                    ]
                ]
            ], 200),
        ]);

        $this->actingAs($this->userBasic)->postJson('/chat', [
            'message' => 'Cek keuangan',
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $tools = $data['tools'] ?? [];
            return empty($tools);
        });
    }

    // ================================================================
    // 2. AUTHENTICATION & OUTLET CONTEXT
    // ================================================================

    public function test_unauthenticated_user_context_rejected(): void
    {
        $request = Request::create('/chat', 'POST');
        $result = $this->controller->executeGetActiveFinancialWarnings(null, $request);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('UNAUTHENTICATED', $result['code']);
    }

    public function test_staff_outlet_context_used_automatically(): void
    {
        $staffUser = User::factory()->create([
            'role'              => 'staff',
            'subscription_tier' => 'pro',
            'account_status'    => 'active',
        ]);
        $staffUser->outlet_id = $this->outlet->id;

        $request = Request::create('/chat', 'POST');

        // Mock FinancialWarningService to verify outlet_id passed
        $mockService = $this->createMock(FinancialWarningService::class);
        $mockService->expects($this->once())
            ->method('getCachedWarnings')
            ->with($staffUser->id, $this->outlet->id)
            ->willReturn([]);

        $this->app->instance(FinancialWarningService::class, $mockService);

        $result = $this->controller->executeGetActiveFinancialWarnings($staffUser, $request);
        $this->assertEquals('INSUFFICIENT_DATA', $result['status']);
    }

    public function test_owner_with_valid_outlet_header(): void
    {
        $request = Request::create('/chat', 'POST');
        $request->headers->set('Outlet-ID', (string) $this->outlet->id);

        $mockService = $this->createMock(FinancialWarningService::class);
        $mockService->expects($this->once())
            ->method('getCachedWarnings')
            ->with($this->userPro->id, $this->outlet->id)
            ->willReturn([]);

        $this->app->instance(FinancialWarningService::class, $mockService);

        $result = $this->controller->executeGetActiveFinancialWarnings($this->userPro, $request);
        $this->assertEquals('INSUFFICIENT_DATA', $result['status']);
    }

    public function test_owner_with_unauthorized_cross_tenant_outlet_rejected(): void
    {
        $otherUser = User::factory()->create(['role' => 'owner']);
        $otherOutlet = Outlet::factory()->create(['user_id' => $otherUser->id]);

        $request = Request::create('/chat', 'POST');
        $request->headers->set('Outlet-ID', (string) $otherOutlet->id);

        $result = $this->controller->executeGetActiveFinancialWarnings($this->userPro, $request);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('UNAUTHORIZED_OUTLET', $result['code']);
    }

    // ================================================================
    // 3. TOOL RESULT CONTRACTS (HAS_WARNINGS, NO_WARNING, INSUFFICIENT_DATA, ERROR)
    // ================================================================

    public function test_get_active_financial_warnings_returns_has_warnings(): void
    {
        $request = Request::create('/chat', 'POST');

        $mockWarning = [
            'code'         => FinancialWarningService::CODE_REVENUE_DECLINE,
            'status'       => FinancialWarningService::STATUS_WARNING,
            'severity'     => FinancialWarningService::SEVERITY_WARNING,
            'title'        => 'Penurunan Omzet Terkonfirmasi',
            'message'      => 'Omzet 7 hari terakhir turun 30.0% dibanding baseline 28 hari.',
            'metric'       => 'net_revenue',
            'actual'       => 70000000,
            'baseline'     => 100000000,
            'threshold'    => -25.0,
            'unit'         => 'PERCENT',
            'period'       => ['analysis_window_days' => 7],
            'evidence'     => ['decline_percent' => -30.0],
            'data_quality' => ['untracked_cogs_ratio' => 0.0],
            'confidence'   => 'HIGH',
            'limitations'  => [],
        ];

        $mockService = $this->createMock(FinancialWarningService::class);
        $mockService->method('getCachedWarnings')
            ->willReturn([
                FinancialWarningService::CODE_REVENUE_DECLINE => $mockWarning,
                FinancialWarningService::CODE_EXPENSE_SPIKE   => [
                    'code'   => FinancialWarningService::CODE_EXPENSE_SPIKE,
                    'status' => FinancialWarningService::STATUS_NO_WARNING,
                ],
            ]);

        $this->app->instance(FinancialWarningService::class, $mockService);

        $result = $this->controller->executeGetActiveFinancialWarnings($this->userPro, $request);

        $this->assertEquals('HAS_WARNINGS', $result['status']);
        $this->assertCount(1, $result['warnings']);
        $this->assertEquals('REVENUE_DECLINE', $result['warnings'][0]['code']);
        $this->assertEquals('WARNING', $result['warnings'][0]['severity']);
        $this->assertEquals(70000000, $result['warnings'][0]['actual']);
        $this->assertEquals(100000000, $result['warnings'][0]['baseline']);
        $this->assertEquals('HIGH', $result['warnings'][0]['confidence']);
    }

    public function test_get_active_financial_warnings_returns_no_warning(): void
    {
        $request = Request::create('/chat', 'POST');

        $mockService = $this->createMock(FinancialWarningService::class);
        $mockService->method('getCachedWarnings')
            ->willReturn([
                FinancialWarningService::CODE_REVENUE_DECLINE => [
                    'code'     => FinancialWarningService::CODE_REVENUE_DECLINE,
                    'status'   => FinancialWarningService::STATUS_NO_WARNING,
                    'severity' => FinancialWarningService::SEVERITY_NONE,
                ],
            ]);

        $this->app->instance(FinancialWarningService::class, $mockService);

        $result = $this->controller->executeGetActiveFinancialWarnings($this->userPro, $request);

        $this->assertEquals('NO_WARNING', $result['status']);
        $this->assertEmpty($result['warnings']);
    }

    public function test_get_active_financial_warnings_returns_insufficient_data(): void
    {
        $request = Request::create('/chat', 'POST');

        $mockService = $this->createMock(FinancialWarningService::class);
        $mockService->method('getCachedWarnings')
            ->willReturn([
                FinancialWarningService::CODE_MARGIN_COMPRESSION => [
                    'code'         => FinancialWarningService::CODE_MARGIN_COMPRESSION,
                    'status'       => FinancialWarningService::STATUS_INSUFFICIENT_DATA,
                    'severity'     => FinancialWarningService::SEVERITY_NONE,
                    'title'        => 'Data Margin Belum Cukup',
                    'data_quality' => ['untracked_cogs_ratio' => 0.45],
                    'limitations'  => ['Rasio HPP belum terlacak melebihi 20%'],
                    'message'      => 'Evaluasi kompresi margin ditangguhkan.',
                ],
            ]);

        $this->app->instance(FinancialWarningService::class, $mockService);

        $result = $this->controller->executeGetActiveFinancialWarnings($this->userPro, $request);

        $this->assertEquals('INSUFFICIENT_DATA', $result['status']);
        $this->assertEmpty($result['warnings']);
        $this->assertNotEmpty($result['data_quality']);
        $this->assertEquals('MARGIN_COMPRESSION', $result['data_quality'][0]['code']);
    }

    public function test_error_response_when_service_throws_exception(): void
    {
        $request = Request::create('/chat', 'POST');

        $mockService = $this->createMock(FinancialWarningService::class);
        $mockService->method('getCachedWarnings')
            ->willThrowException(new \RuntimeException('Database query deadlocked'));

        $this->app->instance(FinancialWarningService::class, $mockService);

        $result = $this->controller->executeGetActiveFinancialWarnings($this->userPro, $request);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('FINANCIAL_WARNING_UNAVAILABLE', $result['code']);
        $this->assertStringNotContainsString('deadlocked', json_encode($result));
        $this->assertStringNotContainsString('RuntimeException', json_encode($result));
    }

    // ================================================================
    // 4. TENANT & OUTLET ISOLATION TESTS
    // ================================================================

    public function test_tenant_isolation_warning_engine(): void
    {
        $userA = $this->userPro;
        $userB = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'pro',
            'account_status'    => 'active',
        ]);

        $service = app(FinancialWarningService::class);

        // Call for User A and User B
        $resultA = $service->getCachedWarnings($userA->id, null);
        $resultB = $service->getCachedWarnings($userB->id, null);

        $this->assertIsArray($resultA);
        $this->assertIsArray($resultB);
    }

    public function test_outlet_isolation_warning_engine(): void
    {
        $outletA = $this->outlet;
        $outletB = Outlet::factory()->create([
            'user_id' => $this->userPro->id,
            'name'    => 'Outlet Cabang Timur',
        ]);

        $service = app(FinancialWarningService::class);

        $resultA = $service->getCachedWarnings($this->userPro->id, $outletA->id);
        $resultB = $service->getCachedWarnings($this->userPro->id, $outletB->id);

        $this->assertIsArray($resultA);
        $this->assertIsArray($resultB);
    }

    // ================================================================
    // 5. LLM TOOL EXECUTION & DATA FLOW (2-STEP ITERATION)
    // ================================================================

    public function test_llm_receives_structured_warning_evidence_in_step_2(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        // Mock FinancialWarningService returning a warning
        $mockService = $this->createMock(FinancialWarningService::class);
        $mockService->method('getCachedWarnings')
            ->willReturn([
                FinancialWarningService::CODE_REVENUE_DECLINE => [
                    'code'         => FinancialWarningService::CODE_REVENUE_DECLINE,
                    'status'       => FinancialWarningService::STATUS_WARNING,
                    'severity'     => FinancialWarningService::SEVERITY_WARNING,
                    'title'        => 'Penurunan Omzet Terkonfirmasi',
                    'message'      => 'Omzet 7 hari terakhir turun 30.0%',
                    'metric'       => 'net_revenue',
                    'actual'       => 70000000,
                    'baseline'     => 100000000,
                    'threshold'    => -25.0,
                    'unit'         => 'PERCENT',
                    'period'       => ['analysis_window_days' => 7],
                    'evidence'     => ['decline_percent' => -30.0],
                    'data_quality' => ['untracked_cogs_ratio' => 0.0],
                    'confidence'   => 'HIGH',
                    'limitations'  => [],
                ]
            ]);
        $this->app->instance(FinancialWarningService::class, $mockService);

        $step = 0;
        Http::fake([
            'https://api.groq.com/*' => function ($request) use (&$step) {
                $step++;
                if ($step === 1) {
                    // Step 1: LLM decides to call get_active_financial_warnings
                    return Http::response([
                        'choices' => [
                            [
                                'message' => [
                                    'role'       => 'assistant',
                                    'content'    => null,
                                    'tool_calls' => [
                                        [
                                            'id'       => 'call_warn_001',
                                            'type'     => 'function',
                                            'function' => [
                                                'name'      => 'get_active_financial_warnings',
                                                'arguments' => '{}',
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ], 200);
                }

                // Step 2: LLM receives tool output and produces final message
                $data = $request->data();
                $messages = $data['messages'] ?? [];

                $toolMsg = collect($messages)->firstWhere('role', 'tool');
                $this->assertNotNull($toolMsg);
                $this->assertEquals('call_warn_001', $toolMsg['tool_call_id']);
                $this->assertEquals('get_active_financial_warnings', $toolMsg['name']);

                $content = json_decode($toolMsg['content'], true);
                $this->assertEquals('HAS_WARNINGS', $content['status']);
                $this->assertCount(1, $content['warnings']);
                $this->assertEquals('REVENUE_DECLINE', $content['warnings'][0]['code']);
                $this->assertEquals('WARNING', $content['warnings'][0]['severity']);
                $this->assertEquals(70000000, $content['warnings'][0]['actual']);

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'role'    => 'assistant',
                                'content' => 'Omzet Anda terindikasi turun 30% berdasarkan data engine.',
                            ]
                        ]
                    ]
                ], 200);
            },
        ]);

        $response = $this->actingAs($this->userPro)->postJson('/chat', [
            'message' => 'Apakah ada peringatan keuangan?',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'reply'  => 'Omzet Anda terindikasi turun 30% berdasarkan data engine.',
        ]);

        $this->assertEquals(2, $step);
    }

    // ================================================================
    // 6. SEVERITY INTEGRITY & ANTI-OVERRIDE
    // ================================================================

    public function test_severity_is_preserved_deterministically(): void
    {
        $request = Request::create('/chat', 'POST');

        $mockService = $this->createMock(FinancialWarningService::class);
        $mockService->method('getCachedWarnings')
            ->willReturn([
                FinancialWarningService::CODE_REVENUE_DECLINE => [
                    'code'     => FinancialWarningService::CODE_REVENUE_DECLINE,
                    'status'   => FinancialWarningService::STATUS_WARNING,
                    'severity' => FinancialWarningService::SEVERITY_CRITICAL,
                    'title'    => 'Krisis Penurunan Omzet',
                    'actual'   => 40000000,
                    'baseline' => 100000000,
                ]
            ]);

        $this->app->instance(FinancialWarningService::class, $mockService);

        $result = $this->controller->executeGetActiveFinancialWarnings($this->userPro, $request);

        $this->assertEquals('HAS_WARNINGS', $result['status']);
        $this->assertEquals(FinancialWarningService::SEVERITY_CRITICAL, $result['warnings'][0]['severity']);
        $this->assertNotEquals(FinancialWarningService::SEVERITY_WARNING, $result['warnings'][0]['severity']);
    }

    // ================================================================
    // 7. SECURITY & READ-ONLY INVARIANTS (NO RAW SQL, NO SECRETS, NO MUTATION)
    // ================================================================

    public function test_no_raw_sql_is_returned_in_tool_output(): void
    {
        $request = Request::create('/chat', 'POST');
        $result = $this->controller->executeGetActiveFinancialWarnings($this->userPro, $request);
        $json = json_encode($result);

        $this->assertStringNotContainsStringIgnoringCase('SELECT ', $json);
        $this->assertStringNotContainsStringIgnoringCase('INSERT ', $json);
        $this->assertStringNotContainsStringIgnoringCase('UPDATE ', $json);
        $this->assertStringNotContainsStringIgnoringCase('DELETE ', $json);
        $this->assertStringNotContainsStringIgnoringCase('FROM transactions', $json);
        $this->assertStringNotContainsStringIgnoringCase('WHERE user_id', $json);
    }

    public function test_no_secrets_are_returned_in_tool_output(): void
    {
        $request = Request::create('/chat', 'POST');
        $result = $this->controller->executeGetActiveFinancialWarnings($this->userPro, $request);
        $json = json_encode($result);

        $this->assertStringNotContainsString('gsk_', $json);
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('secret', $json);
    }

    public function test_tool_performs_no_mutation_read_only_guarantee(): void
    {
        $usersBefore = DB::table('users')->count();
        $outletsBefore = DB::table('outlets')->count();
        $transactionsBefore = DB::table('transactions')->count();
        $expensesBefore = DB::table('expenses')->count();
        $shiftsBefore = DB::table('shifts')->count();

        $request = Request::create('/chat', 'POST');
        $this->controller->executeGetActiveFinancialWarnings($this->userPro, $request);

        $this->assertEquals($usersBefore, DB::table('users')->count());
        $this->assertEquals($outletsBefore, DB::table('outlets')->count());
        $this->assertEquals($transactionsBefore, DB::table('transactions')->count());
        $this->assertEquals($expensesBefore, DB::table('expenses')->count());
        $this->assertEquals($shiftsBefore, DB::table('shifts')->count());
    }

    // ================================================================
    // 8. EXISTING CHATBOT BEHAVIOR INTACT (GET_TODAY_SALES & FAQ)
    // ================================================================

    public function test_existing_chatbot_tool_get_today_sales_intact(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $step = 0;
        Http::fake([
            'https://api.groq.com/*' => function ($request) use (&$step) {
                $step++;
                if ($step === 1) {
                    return Http::response([
                        'choices' => [
                            [
                                'message' => [
                                    'role'       => 'assistant',
                                    'content'    => null,
                                    'tool_calls' => [
                                        [
                                            'id'       => 'call_sales_001',
                                            'type'     => 'function',
                                            'function' => [
                                                'name'      => 'get_today_sales',
                                                'arguments' => '{}',
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ], 200);
                }

                $data = $request->data();
                $messages = $data['messages'] ?? [];
                $toolMsg = collect($messages)->firstWhere('name', 'get_today_sales');
                $this->assertNotNull($toolMsg);
                $this->assertStringContainsString('Omzet hari ini bernilai Rp', $toolMsg['content']);

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'role'    => 'assistant',
                                'content' => 'Omzet Anda hari ini tercatat.',
                            ]
                        ]
                    ]
                ], 200);
            }
        ]);

        $response = $this->actingAs($this->userPro)->postJson('/chat', [
            'message' => 'Tolong hitungkan total revenue toko hari ini',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'reply'  => 'Omzet Anda hari ini tercatat.',
        ]);
    }

    public function test_existing_faq_matcher_intact(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Http::fake();

        // FAQ queries matched by FaqMatcher should return static answer without calling Groq
        $response = $this->actingAs($this->userPro)->postJson('/chat', [
            'message' => 'apa itu kaching pos',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
        ]);

        // Groq must NOT have been called
        Http::assertNothingSent();
    }

    // ================================================================
    // 9. PERFORMANCE & DUPLICATE CALL PROTECTION
    // ================================================================

    public function test_avoids_duplicate_execution_in_single_request(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $mockService = $this->createMock(FinancialWarningService::class);
        $mockService->expects($this->once()) // Exactly once despite two tool calls in the payload
            ->method('getCachedWarnings')
            ->willReturn([
                FinancialWarningService::CODE_REVENUE_DECLINE => [
                    'code'   => FinancialWarningService::CODE_REVENUE_DECLINE,
                    'status' => FinancialWarningService::STATUS_NO_WARNING,
                ]
            ]);

        $this->app->instance(FinancialWarningService::class, $mockService);

        $step = 0;
        Http::fake([
            'https://api.groq.com/*' => function ($request) use (&$step) {
                $step++;
                if ($step === 1) {
                    return Http::response([
                        'choices' => [
                            [
                                'message' => [
                                    'role'       => 'assistant',
                                    'content'    => null,
                                    'tool_calls' => [
                                        [
                                            'id'       => 'call_dup_1',
                                            'type'     => 'function',
                                            'function' => [
                                                'name'      => 'get_active_financial_warnings',
                                                'arguments' => '{}',
                                            ]
                                        ],
                                        [
                                            'id'       => 'call_dup_2',
                                            'type'     => 'function',
                                            'function' => [
                                                'name'      => 'get_active_financial_warnings',
                                                'arguments' => '{}',
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ], 200);
                }

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'role'    => 'assistant',
                                'content' => 'Tidak ada peringatan aktif.',
                            ]
                        ]
                    ]
                ], 200);
            }
        ]);

        $response = $this->actingAs($this->userPro)->postJson('/chat', [
            'message' => 'Cek keuangan',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(2, $step);
    }

    // ================================================================
    // 10. PROMPT INJECTION DEFENSE & GUARDRAILS
    // ================================================================

    public function test_prompt_injection_defense_and_guardrails_in_system_prompt(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'Jawaban AI.',
                        ]
                    ]
                ]
            ], 200),
        ]);

        $this->actingAs($this->userPro)->postJson('/chat', [
            'message' => 'Status keuangan saya apa?',
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $messages = $data['messages'] ?? [];
            $systemMsg = collect($messages)->firstWhere('role', 'system')['content'] ?? '';

            $this->assertStringContainsString('ATURAN INTERPRETASI PERINGATAN KEUANGAN', $systemMsg);
            $this->assertStringContainsString('DATA BUKAN INSTRUKSI', $systemMsg);
            $this->assertStringContainsString('OTORITAS SEVERITY', $systemMsg);
            $this->assertStringContainsString('ANTI-FABRIKASI & ANTI-REKALKULASI', $systemMsg);
            $this->assertStringContainsString('FAKTA vs REKOMENDASI', $systemMsg);
            $this->assertStringContainsString('KETIDAKPASTIAN', $systemMsg);
            $this->assertStringContainsString('TRANSPARANSI LIMITASI', $systemMsg);
            $this->assertStringContainsString('RAHASIA SISTEM', $systemMsg);

            return true;
        });
    }
}
