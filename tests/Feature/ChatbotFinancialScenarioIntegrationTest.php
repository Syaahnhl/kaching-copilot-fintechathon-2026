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
use App\Http\Controllers\ChatbotController;
use App\Services\Financial\FinancialScenarioService;
use App\Services\Financial\FinancialWarningService;

class ChatbotFinancialScenarioIntegrationTest extends TestCase
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
            'name'      => 'Outlet Pusat Pro',
            'device_id' => 'DEV-PRO-01',
        ]);
    }

    /**
     * Helper to seed standard 30-day baseline data:
     * - Total Revenue: 50,000,000 IDR
     * - Total COGS: 20,000,000 IDR (Gross Profit: 30,000,000 IDR, Margin: 60.0%)
     * - Operating Expenses: 10,000,000 IDR (Net Profit: 20,000,000 IDR, Margin: 40.0%)
     */
    protected function seedStandardBaseline(
        float $revenue = 50000000.0,
        float $cogs = 20000000.0,
        float $expense = 10000000.0,
        ?int $userId = null,
        ?int $outletId = null,
        bool $trackedCogs = true
    ): void {
        $uId = $userId ?? $this->userPro->id;
        $oId = $outletId ?? $this->outlet->id;

        $recentDate = Carbon::now('Asia/Jakarta')->subDays(5)->format('Y-m-d H:i:s');
        $recentExpenseDate = Carbon::now('Asia/Jakarta')->subDays(5)->format('Y-m-d');

        $trxId = DB::table('transactions')->insertGetId([
            'user_id'                 => $uId,
            'outlet_id'               => $oId,
            'app_uuid'                => 'tx-uuid-' . uniqid('', true),
            'official_invoice_number' => 'INV-' . uniqid(),
            'total_amount'            => $revenue,
            'pay_amount'              => $revenue,
            'payment_method'          => 'cash',
            'status'                  => 'Selesai',
            'created_at_device'       => $recentDate,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        if ($revenue > 0) {
            DB::table('transaction_items')->insert([
                'transaction_id' => $trxId,
                'menu_id'        => 101,
                'menu_name'      => 'Menu Standard',
                'qty'            => 1,
                'price'          => $revenue,
                'cost_price'     => $trackedCogs ? $cogs : 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        if ($expense > 0) {
            DB::table('expenses')->insert([
                'user_id'    => $uId,
                'outlet_id'  => $oId,
                'name'       => 'Biaya Operasional',
                'amount'     => $expense,
                'category'   => 'Operasional',
                'date'       => $recentExpenseDate,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // ================================================================
    // 1. TOOL REGISTRATION
    // ================================================================

    public function test_tool_registered_for_pro_and_ultimate_users(): void
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
            'message' => 'Simulasi harga naik 10%',
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $tools = $data['tools'] ?? [];
            $names = array_map(fn($t) => $t['function']['name'] ?? '', $tools);

            $this->assertContains('get_financial_scenario', $names);

            $tool = collect($tools)->firstWhere('function.name', 'get_financial_scenario');
            $this->assertNotNull($tool);
            $this->assertEquals('function', $tool['type']);
            $this->assertEquals('object', $tool['function']['parameters']['type']);
            $this->assertEquals(['scenario_type'], $tool['function']['parameters']['required']);

            $props = $tool['function']['parameters']['properties'];
            $this->assertArrayHasKey('scenario_type', $props);
            $this->assertArrayHasKey('price_change_percent', $props);
            $this->assertArrayHasKey('cost_change_percent', $props);
            $this->assertArrayHasKey('revenue_change_percent', $props);
            $this->assertArrayHasKey('expense_change_percent', $props);
            $this->assertArrayHasKey('expense_change_amount', $props);

            // Verifikasi tidak ada forbidden parameter di schema LLM
            $this->assertArrayNotHasKey('user_id', $props);
            $this->assertArrayNotHasKey('outlet_id', $props);
            $this->assertArrayNotHasKey('sql', $props);
            $this->assertArrayNotHasKey('query', $props);
            $this->assertArrayNotHasKey('formula', $props);
            $this->assertArrayNotHasKey('threshold', $props);

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
            'message' => 'Simulasikan kenaikan harga 10%',
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $tools = $data['tools'] ?? [];
            $names = array_map(fn($t) => $t['function']['name'] ?? '', $tools);
            return in_array('get_financial_scenario', $names, true);
        });
    }

    // ================================================================
    // 2. CAPABILITY GATING (BASIC TIER EXCLUSION)
    // ================================================================

    public function test_tool_available_only_under_existing_financial_capability_gating(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'Maaf, fitur simulasi skenario khusus paket PRO.',
                        ]
                    ]
                ]
            ], 200),
        ]);

        $this->actingAs($this->userBasic)->postJson('/chat', [
            'message' => 'Kalau harga naik 10% laba saya jadi berapa?',
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $tools = $data['tools'] ?? [];
            return empty($tools);
        });
    }

    // ================================================================
    // 3. PRICE_CHANGE TOOL CALL
    // ================================================================

    public function test_price_change_tool_call(): void
    {
        $this->seedStandardBaseline();

        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
        ];

        $result = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals('PRICE_CHANGE', $result['scenario']['type']);
        $this->assertEquals(50000000.0, $result['baseline']['revenue']);
        $this->assertEquals(55000000.0, $result['result']['revenue']);
        $this->assertEquals(20000000.0, $result['result']['cogs']); // Zero elasticity
        $this->assertEquals(35000000.0, $result['result']['gross_profit']);
        $this->assertEquals(25000000.0, $result['result']['net_profit']);
        $this->assertEquals(5000000.0, $result['delta']['revenue']);
        $this->assertEquals(5000000.0, $result['delta']['gross_profit']);
        $this->assertEquals(5000000.0, $result['delta']['net_profit']);
        $this->assertNotEmpty($result['assumptions']);
    }

    // ================================================================
    // 4. COST_CHANGE TOOL CALL
    // ================================================================

    public function test_cost_change_tool_call(): void
    {
        $this->seedStandardBaseline();

        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type'       => 'COST_CHANGE',
            'cost_change_percent' => 15,
        ];

        $result = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals('COST_CHANGE', $result['scenario']['type']);
        $this->assertEquals(50000000.0, $result['result']['revenue']);
        $this->assertEquals(23000000.0, $result['result']['cogs']);
        $this->assertEquals(27000000.0, $result['result']['gross_profit']);
        $this->assertEquals(17000000.0, $result['result']['net_profit']);
        $this->assertEquals(3000000.0, $result['delta']['cogs']);
        $this->assertEquals(-3000000.0, $result['delta']['gross_profit']);
        $this->assertEquals(-3000000.0, $result['delta']['net_profit']);
    }

    // ================================================================
    // 5. REVENUE_CHANGE TOOL CALL
    // ================================================================

    public function test_revenue_change_tool_call(): void
    {
        $this->seedStandardBaseline();

        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type'          => 'REVENUE_CHANGE',
            'revenue_change_percent' => -20,
        ];

        $result = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals('REVENUE_CHANGE', $result['scenario']['type']);
        $this->assertEquals(40000000.0, $result['result']['revenue']);
        $this->assertEquals(16000000.0, $result['result']['cogs']);
        $this->assertEquals(24000000.0, $result['result']['gross_profit']);
        $this->assertEquals(14000000.0, $result['result']['net_profit']);
        $this->assertEquals(-10000000.0, $result['delta']['revenue']);
        $this->assertEquals(-4000000.0, $result['delta']['cogs']);
    }

    // ================================================================
    // 6. EXPENSE_CHANGE AMOUNT
    // ================================================================

    public function test_expense_change_amount(): void
    {
        $this->seedStandardBaseline();

        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type'         => 'EXPENSE_CHANGE',
            'expense_change_amount' => -1000000,
        ];

        $result = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals('EXPENSE_CHANGE', $result['scenario']['type']);
        $this->assertEquals(50000000.0, $result['result']['revenue']);
        $this->assertEquals(20000000.0, $result['result']['cogs']);
        $this->assertEquals(9000000.0, $result['result']['expenses']);
        $this->assertEquals(21000000.0, $result['result']['net_profit']);
        $this->assertEquals(-1000000.0, $result['delta']['expenses']);
        $this->assertEquals(1000000.0, $result['delta']['net_profit']);
    }

    // ================================================================
    // 7. EXPENSE_CHANGE PERCENT
    // ================================================================

    public function test_expense_change_percent(): void
    {
        $this->seedStandardBaseline();

        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type'          => 'EXPENSE_CHANGE',
            'expense_change_percent' => -10,
        ];

        $result = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals('EXPENSE_CHANGE', $result['scenario']['type']);
        $this->assertEquals(9000000.0, $result['result']['expenses']);
        $this->assertEquals(21000000.0, $result['result']['net_profit']);
        $this->assertEquals(-1000000.0, $result['delta']['expenses']);
        $this->assertEquals(1000000.0, $result['delta']['net_profit']);
    }

    // ================================================================
    // 8. COMBINED_SCENARIO
    // ================================================================

    public function test_combined_scenario(): void
    {
        $this->seedStandardBaseline();

        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type'          => 'COMBINED_SCENARIO',
            'price_change_percent'   => 10,
            'cost_change_percent'    => 5,
            'revenue_change_percent' => 10,
            'expense_change_amount'  => -500000,
        ];

        $result = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals('COMBINED_SCENARIO', $result['scenario']['type']);

        // Revenue: 50M * 1.10 (price) * 1.10 (vol) = 60.5M
        $this->assertEquals(60500000.0, $result['result']['revenue']);
        // COGS: 20M * 1.10 (vol) * 1.05 (cost) = 23.1M
        $this->assertEquals(23100000.0, $result['result']['cogs']);
        // Expenses: 10M - 500k = 9.5M
        $this->assertEquals(9500000.0, $result['result']['expenses']);
        // Gross profit: 60.5M - 23.1M = 37.4M
        $this->assertEquals(37400000.0, $result['result']['gross_profit']);
        // Net profit: 37.4M - 9.5M = 27.9M
        $this->assertEquals(27900000.0, $result['result']['net_profit']);
    }

    // ================================================================
    // 9. MISSING SCENARIO TYPE
    // ================================================================

    public function test_missing_scenario_type(): void
    {
        $this->seedStandardBaseline();

        $request = Request::create('/chat', 'POST');
        $arguments = [
            'price_change_percent' => 10,
        ];

        $result = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('MISSING_SCENARIO_TYPE', $result['code']);
    }

    // ================================================================
    // 10. INVALID SCENARIO TYPE
    // ================================================================

    public function test_invalid_scenario_type(): void
    {
        $this->seedStandardBaseline();

        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type'        => 'INVALID_FOO_BAR',
            'price_change_percent' => 10,
        ];

        $result = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('INVALID_SCENARIO_TYPE', $result['code']);
    }

    // ================================================================
    // 11. MISSING REQUIRED SCENARIO PARAMETER
    // ================================================================

    public function test_missing_required_scenario_parameter(): void
    {
        $this->seedStandardBaseline();

        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type' => 'PRICE_CHANGE',
            // Missing price_change_percent
        ];

        $result = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('MISSING_REQUIRED_PARAMETER', $result['code']);
    }

    // ================================================================
    // 12. FORBIDDEN USER_ID PARAMETER
    // ================================================================

    public function test_forbidden_user_id_parameter(): void
    {
        $this->seedStandardBaseline();

        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
            'user_id'              => 9999,
        ];

        $result = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('FORBIDDEN_PARAMETER', $result['code']);
    }

    // ================================================================
    // 13. FORBIDDEN OUTLET_ID PARAMETER
    // ================================================================

    public function test_forbidden_outlet_id_parameter(): void
    {
        $this->seedStandardBaseline();

        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
            'outlet_id'            => 9999,
        ];

        $result = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('FORBIDDEN_PARAMETER', $result['code']);
    }

    // ================================================================
    // 14. UNAUTHORIZED OUTLET
    // ================================================================

    public function test_unauthorized_outlet(): void
    {
        $otherUser = User::factory()->create(['role' => 'owner']);
        $otherOutlet = Outlet::create([
            'user_id'   => $otherUser->id,
            'name'      => 'Other Outlet',
            'device_id' => 'DEV-OTHER-01',
        ]);

        $request = Request::create('/chat', 'POST');
        $request->headers->set('Outlet-ID', (string) $otherOutlet->id);

        $arguments = [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
        ];

        $result = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('UNAUTHORIZED_OUTLET', $result['code']);
    }

    // ================================================================
    // 15. UNAUTHENTICATED REQUEST
    // ================================================================

    public function test_unauthenticated_request(): void
    {
        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
        ];

        $result = $this->controller->executeGetFinancialScenario(null, $request, $arguments);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('UNAUTHENTICATED', $result['code']);
    }

    // ================================================================
    // 16. ENGINE ERROR PROPAGATION
    // ================================================================

    public function test_engine_error_propagation(): void
    {
        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
        ];

        $mockService = $this->createMock(FinancialScenarioService::class);
        $mockService->method('simulate')
            ->willThrowException(new \RuntimeException('Internal simulation failure'));

        $this->app->instance(FinancialScenarioService::class, $mockService);

        $result = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('FINANCIAL_SCENARIO_UNAVAILABLE', $result['code']);
        $this->assertEquals('Simulasi finansial sedang tidak tersedia.', $result['message']);
        $this->assertStringNotContainsString('Internal simulation failure', json_encode($result));
    }

    // ================================================================
    // 17. INSUFFICIENT DATA PROPAGATION
    // ================================================================

    public function test_insufficient_data_propagation(): void
    {
        // No transactions seeded
        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
        ];

        $result = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('INSUFFICIENT_DATA', $result['code']);
    }

    // ================================================================
    // 18. UNTRACKED COGS LIMITATION PROPAGATION
    // ================================================================

    public function test_untracked_cogs_limitation_propagation(): void
    {
        // Seed with untracked COGS (cost_price = 0)
        $this->seedStandardBaseline(50000000.0, 0.0, 10000000.0, null, null, false);

        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
        ];

        $result = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertTrue($result['baseline']['data_quality']['has_untracked_cogs']);
        $this->assertEquals('WARNING', $result['baseline']['data_quality']['status']);
        $this->assertNotEmpty($result['limitations']);
    }

    // ================================================================
    // 19. SEVERITY OR FINANCIAL RESULT CANNOT BE FABRICATED
    // ================================================================

    public function test_severity_or_financial_result_cannot_be_fabricated(): void
    {
        $this->seedStandardBaseline();

        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
        ];

        $serviceDirect = app(FinancialScenarioService::class)->simulate($this->userPro->id, null, $arguments);
        $controllerResult = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        // Controller result must exactly match direct service output down to every penny
        $this->assertSame($serviceDirect['baseline']['revenue'], $controllerResult['baseline']['revenue']);
        $this->assertSame($serviceDirect['result']['gross_profit'], $controllerResult['result']['gross_profit']);
        $this->assertSame($serviceDirect['delta']['net_profit'], $controllerResult['delta']['net_profit']);
        $this->assertSame($serviceDirect['delta']['gross_margin_pp'], $controllerResult['delta']['gross_margin_pp']);
    }

    // ================================================================
    // 20. READ-ONLY MUTATION GUARANTEE
    // ================================================================

    public function test_read_only_mutation_guarantee(): void
    {
        $this->seedStandardBaseline();

        $usersBefore = DB::table('users')->count();
        $outletsBefore = DB::table('outlets')->count();
        $transactionsBefore = DB::table('transactions')->count();
        $itemsBefore = DB::table('transaction_items')->count();
        $expensesBefore = DB::table('expenses')->count();
        $shiftsBefore = DB::table('shifts')->count();

        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
        ];

        $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        $this->assertEquals($usersBefore, DB::table('users')->count());
        $this->assertEquals($outletsBefore, DB::table('outlets')->count());
        $this->assertEquals($transactionsBefore, DB::table('transactions')->count());
        $this->assertEquals($itemsBefore, DB::table('transaction_items')->count());
        $this->assertEquals($expensesBefore, DB::table('expenses')->count());
        $this->assertEquals($shiftsBefore, DB::table('shifts')->count());
    }

    // ================================================================
    // 21. PROMPT INJECTION PAYLOAD TREATED AS DATA
    // ================================================================

    public function test_prompt_injection_payload_treated_as_data(): void
    {
        $this->seedStandardBaseline();

        $request = Request::create('/chat', 'POST');
        $arguments = [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
            'sql'                  => "'; DROP TABLE users; --",
        ];

        $result = $this->controller->executeGetFinancialScenario($this->userPro, $request, $arguments);

        // Forbidden SQL parameter is rejected fail-closed
        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('FORBIDDEN_PARAMETER', $result['code']);
        $this->assertTrue(DB::table('users')->exists());
    }

    // ================================================================
    // 22. EXISTING GET_TODAY_SALES BEHAVIOR
    // ================================================================

    public function test_existing_get_today_sales_behavior(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        // Seed transaction today
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
                                    'role'       => 'assistant',
                                    'content'    => null,
                                    'tool_calls' => [
                                        [
                                            'id'       => 'call_today_sales_01',
                                            'type'     => 'function',
                                            'function' => [
                                                'name'      => 'get_today_sales',
                                                'arguments' => '{}',
                                            ],
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ], 200);
                }

                // Step 2 verification: check that tool output was injected
                $data = $request->data();
                $messages = $data['messages'];
                $toolMsg = collect($messages)->firstWhere('name', 'get_today_sales');

                $this->assertNotNull($toolMsg);
                $this->assertEquals('get_today_sales', $toolMsg['name']);
                $this->assertStringContainsString('1.500.000', $toolMsg['content']);

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'role'    => 'assistant',
                                'content' => 'Omzet toko hari ini tercatat Rp 1.500.000.',
                            ]
                        ]
                    ]
                ], 200);
            },
        ]);

        $response = $this->actingAs($this->userPro)->postJson('/chat', [
            'message' => 'Tolong hitungkan total revenue toko hari ini',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'reply'  => 'Omzet toko hari ini tercatat Rp 1.500.000.',
        ]);
        $this->assertEquals(2, $step);
    }

    // ================================================================
    // 23. EXISTING GET_ACTIVE_FINANCIAL_WARNINGS BEHAVIOR
    // ================================================================

    public function test_existing_get_active_financial_warnings_behavior(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $mockWarningService = $this->createMock(FinancialWarningService::class);
        $mockWarningService->method('getCachedWarnings')
            ->willReturn([
                FinancialWarningService::CODE_REVENUE_DECLINE => [
                    'code'     => FinancialWarningService::CODE_REVENUE_DECLINE,
                    'status'   => FinancialWarningService::STATUS_WARNING,
                    'severity' => FinancialWarningService::SEVERITY_WARNING,
                    'title'    => 'Penurunan Omzet Terdeteksi',
                    'actual'   => 70000000,
                    'baseline' => 100000000,
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
                                    'role'       => 'assistant',
                                    'content'    => null,
                                    'tool_calls' => [
                                        [
                                            'id'       => 'call_fw_01',
                                            'type'     => 'function',
                                            'function' => [
                                                'name'      => 'get_active_financial_warnings',
                                                'arguments' => '{}',
                                            ],
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ], 200);
                }

                $data = $request->data();
                $messages = $data['messages'];
                $toolMsg = collect($messages)->firstWhere('name', 'get_active_financial_warnings');

                $this->assertNotNull($toolMsg);
                $this->assertEquals('get_active_financial_warnings', $toolMsg['name']);
                $content = json_decode($toolMsg['content'], true);
                $this->assertEquals('HAS_WARNINGS', $content['status']);

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'role'    => 'assistant',
                                'content' => 'Terdeteksi penurunan omzet pada bisnis Anda.',
                            ]
                        ]
                    ]
                ], 200);
            },
        ]);

        $response = $this->actingAs($this->userPro)->postJson('/chat', [
            'message' => 'Bagaimana kondisi keuangan saya?',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'reply'  => 'Terdeteksi penurunan omzet pada bisnis Anda.',
        ]);
        $this->assertEquals(2, $step);
    }

    // ================================================================
    // 24. END-TO-END SCENARIO TWO-STEP ITERATION & MEMOIZATION
    // ================================================================

    public function test_end_to_end_scenario_tool_two_step_iteration_and_memoization(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->seedStandardBaseline();

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
                                            'id'       => 'call_scenario_01',
                                            'type'     => 'function',
                                            'function' => [
                                                'name'      => 'get_financial_scenario',
                                                'arguments' => json_encode([
                                                    'scenario_type'        => 'PRICE_CHANGE',
                                                    'price_change_percent' => 10,
                                                ]),
                                            ],
                                        ],
                                        // Duplicate tool call in same turn to test request-level memoization
                                        [
                                            'id'       => 'call_scenario_02',
                                            'type'     => 'function',
                                            'function' => [
                                                'name'      => 'get_financial_scenario',
                                                'arguments' => json_encode([
                                                    'scenario_type'        => 'PRICE_CHANGE',
                                                    'price_change_percent' => 10,
                                                ]),
                                            ],
                                        ],
                                    ]
                                ]
                            ]
                        ]
                    ], 200);
                }

                $data = $request->data();
                $messages = $data['messages'];

                // Should have 2 tool messages
                $toolMessages = array_values(array_filter($messages, fn($m) => ($m['role'] ?? '') === 'tool'));
                $this->assertCount(2, $toolMessages);

                $tool1 = json_decode($toolMessages[0]['content'], true);
                $tool2 = json_decode($toolMessages[1]['content'], true);

                $this->assertEquals('SUCCESS', $tool1['status']);
                $this->assertEquals('SUCCESS', $tool2['status']);
                $this->assertEquals($tool1, $tool2); // Memoized exact match

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'role'    => 'assistant',
                                'content' => 'Jika harga naik 10%, laba kotor Anda diproyeksikan menjadi Rp 35.000.000.',
                            ]
                        ]
                    ]
                ], 200);
            },
        ]);

        $response = $this->actingAs($this->userPro)->postJson('/chat', [
            'message' => 'Kalau harga naik 10% laba saya jadi berapa?',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'reply'  => 'Jika harga naik 10%, laba kotor Anda diproyeksikan menjadi Rp 35.000.000.',
        ]);
        $this->assertEquals(2, $step);
    }
}
