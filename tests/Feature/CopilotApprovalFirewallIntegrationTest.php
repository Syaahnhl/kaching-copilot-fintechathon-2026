<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Staff;
use App\Models\Menu;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\AuditLog;
use App\Services\Policy\ActionPolicyService;

class CopilotApprovalFirewallIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $ownerPro;
    protected User $ownerOther;
    protected Outlet $outletPusat;
    protected Outlet $outletCabang;
    protected Outlet $outletOther;
    protected Staff $staffManager;
    protected Staff $staffKasir;
    protected Staff $staffOther;
    protected Menu $menuKopi;
    protected Transaction $transaction;
    protected ActionPolicyService $policyService;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('pin-attempt:*');
        config(['services.groq.key' => 'gsk_test_fake_api_key_firewall_12345']);

        $this->policyService = app(ActionPolicyService::class);

        // Merchant 1 (Pro Tier)
        $this->ownerPro = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'pro',
        ]);

        $this->outletPusat = Outlet::create([
            'user_id' => $this->ownerPro->id,
            'name'    => 'Outlet Pusat Jakarta',
            'address' => 'Jl. Sudirman No. 1',
        ]);

        $this->outletCabang = Outlet::create([
            'user_id' => $this->ownerPro->id,
            'name'    => 'Outlet Cabang Bandung',
            'address' => 'Jl. Asia Afrika No. 2',
        ]);

        // Staff Manager with PIN 654321 (scoped to outletPusat)
        $this->staffManager = Staff::create([
            'user_id'    => $this->ownerPro->id,
            'outlet_id'  => $this->outletPusat->id,
            'staff_name' => 'Manager Budi',
            'role'       => 'OWNER',
            'pin'        => '654321',
            'is_active'  => 1,
        ]);

        // Staff Kasir with PIN 112233
        $this->staffKasir = Staff::create([
            'user_id'    => $this->ownerPro->id,
            'outlet_id'  => $this->outletPusat->id,
            'staff_name' => 'Kasir Siti',
            'role'       => 'KASIR',
            'pin'        => '112233',
            'is_active'  => 1,
        ]);

        // Menu & Transaction
        $this->menuKopi = Menu::create([
            'user_id'   => $this->ownerPro->id,
            'outlet_id' => $this->outletPusat->id,
            'name'      => 'Kopi Susu Gula Aren',
            'price'     => 18000,
            'stock'     => 50,
            'category'  => 'Minuman',
            'is_active' => 1,
        ]);

        $this->transaction = Transaction::create([
            'app_uuid'       => (string) Str::uuid(),
            'user_id'        => $this->ownerPro->id,
            'outlet_id'      => $this->outletPusat->id,
            'invoice_number' => 'INV-TEST-001',
            'total_amount'   => 36000,
            'pay_amount'     => 36000,
            'payment_method' => 'cash',
            'status'         => 'Selesai',
        ]);

        // Merchant 2 (User Lain)
        $this->ownerOther = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'pro',
        ]);

        $this->outletOther = Outlet::create([
            'user_id' => $this->ownerOther->id,
            'name'    => 'Outlet Merchant Lain',
            'address' => 'Jl. Lain No. 9',
        ]);

        $this->staffOther = Staff::create([
            'user_id'    => $this->ownerOther->id,
            'outlet_id'  => $this->outletOther->id,
            'staff_name' => 'Manager Luar',
            'role'       => 'OWNER',
            'pin'        => '998877',
            'is_active'  => 1,
        ]);
    }

    /**
     * 1. Basic/Free Tier Restriction on Copilot tools.
     */
    public function test_1_basic_tier_restriction(): void
    {
        $basicUser = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'basic',
        ]);

        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Halo merchant, fitur tool tidak aktif untuk basic tier.']]
                ]
            ], 200),
        ]);

        $response = $this->actingAs($basicUser, 'sanctum')->postJson('/api/chat-help', [
            'message' => 'Catatkan pengeluaran 150000',
        ]);

        $response->assertStatus(200);
        // Memastikan tidak ada tools yang didaftarkan ke request AI pada basic tier
        Http::assertSent(function ($request) {
            $data = json_decode($request->body(), true);
            return empty($data['tools'] ?? []);
        });
    }

    /**
     * 2. Proposal Creation via ActionPolicyService.
     */
    public function test_2_proposal_creation(): void
    {
        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'expense.create',
            [
                'name'     => 'Beli Kopi Biji 2kg',
                'amount'   => 160000,
                'category' => 'Bahan Baku',
            ]
        );

        $this->assertEquals(ActionPolicyService::DECISION_REQUIRES_APPROVAL, $proposal['decision']);
        $this->assertTrue($proposal['approval_required']);
        $this->assertEquals('MANAGER', $proposal['required_role']);
        $this->assertStringStartsWith('prop_', $proposal['proposal_id']);
        $this->assertEquals(160000, $proposal['parameters']['amount']);

        $cached = $this->policyService->getProposal($proposal['proposal_id']);
        $this->assertNotNull($cached);
    }

    /**
     * 3. Proposal Ownership: Merchant can approve their own proposal.
     */
    public function test_3_proposal_ownership(): void
    {
        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'expense.create',
            ['name' => 'Beli Susu UHT', 'amount' => 75000]
        );

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposal['proposal_id'],
            'staff_id'    => $this->staffManager->id,
            'pin'         => '654321',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'EXECUTED');
    }

    /**
     * 4. Cross-User Rejection: Merchant 2 cannot approve Merchant 1's proposal.
     */
    public function test_4_cross_user_rejection(): void
    {
        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'expense.create',
            ['name' => 'Beli Sirup Vanila', 'amount' => 50000]
        );

        $response = $this->actingAs($this->ownerOther, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposal['proposal_id'],
            'staff_id'    => $this->staffOther->id,
            'pin'         => '998877',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'CROSS_USER_FORBIDDEN');
    }

    /**
     * 5. Cross-Outlet Rejection: Staff at Outlet Pusat cannot approve proposal for Outlet Cabang.
     */
    public function test_5_cross_outlet_rejection(): void
    {
        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletCabang->id, // Proposal untuk Outlet Cabang
            'expense.create',
            ['name' => 'Beli Gas Bandung', 'amount' => 120000]
        );

        // Staff Manager terikat pada Outlet Pusat
        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposal['proposal_id'],
            'staff_id'    => $this->staffManager->id,
            'pin'         => '654321',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'CROSS_OUTLET_STAFF');
    }

    /**
     * 6. Expired Proposal is rejected with 410.
     */
    public function test_6_expired_proposal(): void
    {
        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'expense.create',
            ['name' => 'Beli Cup Plastik', 'amount' => 80000]
        );

        // Manipulasi cache seolah-olah expired
        $cached = $this->policyService->getProposal($proposal['proposal_id']);
        $cached['expires_at'] = now()->subMinutes(5)->toIso8601String();
        Cache::put(ActionPolicyService::CACHE_PREFIX . $proposal['proposal_id'], $cached, 60);

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposal['proposal_id'],
            'staff_id'    => $this->staffManager->id,
            'pin'         => '654321',
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('code', 'PROPOSAL_EXPIRED');
    }

    /**
     * 7. Invalid Proposal ID returns 404.
     */
    public function test_7_invalid_proposal(): void
    {
        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => 'prop_non_existent_fake_uuid_123',
            'staff_id'    => $this->staffManager->id,
            'pin'         => '654321',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('code', 'PROPOSAL_NOT_FOUND');
    }

    /**
     * 8. Wrong Role: Kasir attempting to approve Manager-required action returns 403.
     */
    public function test_8_wrong_role(): void
    {
        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'expense.create',
            ['name' => 'Beli Serbet', 'amount' => 25000]
        );

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposal['proposal_id'],
            'staff_id'    => $this->staffKasir->id, // Role Kasir
            'pin'         => '112233',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'FORBIDDEN_ROLE');
    }

    /**
     * 9. Wrong PIN returns 403.
     */
    public function test_9_wrong_pin(): void
    {
        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'expense.create',
            ['name' => 'Beli Teh Celup', 'amount' => 15000]
        );

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposal['proposal_id'],
            'staff_id'    => $this->staffManager->id,
            'pin'         => '000000', // Salah PIN
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'INVALID_PIN');
    }

    /**
     * 10. PIN Rate Limiting: 5 failed attempts trigger 429.
     */
    public function test_10_pin_rate_limit(): void
    {
        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'expense.create',
            ['name' => 'Beli Sendok', 'amount' => 10000]
        );

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
                'proposal_id' => $proposal['proposal_id'],
                'staff_id'    => $this->staffManager->id,
                'pin'         => '999999',
            ]);
        }

        // Percobaan ke-6 harus kena 429
        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposal['proposal_id'],
            'staff_id'    => $this->staffManager->id,
            'pin'         => '654321', // Meskipun benar, tetap kena throttle
        ]);

        $response->assertStatus(429);
        $response->assertJsonPath('code', 'PIN_RATE_LIMITED');
    }

    /**
     * 11. Successful Approval and Execution.
     */
    public function test_11_successful_approval(): void
    {
        RateLimiter::clear('pin-attempt:' . $this->staffManager->id . ':127.0.0.1');

        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'expense.create',
            [
                'name'           => 'Beli Kopi Espresso Blend',
                'amount'         => 250000,
                'category'       => 'Bahan Baku',
                'payment_method' => 'cash',
            ]
        );

        $initialExpenseCount = Expense::count();

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposal['proposal_id'],
            'staff_id'    => $this->staffManager->id,
            'pin'         => '654321',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('code', 'ACTION_EXECUTED');
        $this->assertEquals($initialExpenseCount + 1, Expense::count());

        $newExpense = Expense::latest('id')->first();
        $this->assertEquals('Beli Kopi Espresso Blend', $newExpense->name);
        $this->assertEquals(250000, (float) $newExpense->amount);
    }

    /**
     * 12. Mutation executed exactly once (re-approval fails).
     */
    public function test_12_mutation_executed_exactly_once(): void
    {
        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'expense.create',
            ['name' => 'Beli Filter Kopi', 'amount' => 35000]
        );

        // Eksekusi pertama
        $res1 = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposal['proposal_id'],
            'staff_id'    => $this->staffManager->id,
            'pin'         => '654321',
        ]);
        $res1->assertStatus(200);

        // Eksekusi kedua (harus 404 karena proposal sudah di-invalidate)
        $res2 = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposal['proposal_id'],
            'staff_id'    => $this->staffManager->id,
            'pin'         => '654321',
        ]);
        $res2->assertStatus(404);
        $res2->assertJsonPath('code', 'PROPOSAL_NOT_FOUND');
    }

    /**
     * 13. Audit Log created with correct metadata.
     */
    public function test_13_audit_log_created(): void
    {
        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'expense.create',
            ['name' => 'Beli Air Mineral Galon', 'amount' => 40000]
        );

        $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposal['proposal_id'],
            'staff_id'    => $this->staffManager->id,
            'pin'         => '654321',
        ]);

        $log = AuditLog::where('user_id', $this->ownerPro->id)
            ->where('action', AuditLog::ACTION_EXPENSE_CREATE)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('copilot', $log->metadata['source'] ?? null);
        $this->assertEquals($proposal['proposal_id'], $log->metadata['proposal_id'] ?? null);
        $this->assertEquals($this->staffManager->id, $log->metadata['approver_staff_id'] ?? null);
    }

    /**
     * 14. Proposal invalidated after success.
     */
    public function test_14_proposal_invalidated_after_success(): void
    {
        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'expense.create',
            ['name' => 'Beli Sedotan Kertas', 'amount' => 20000]
        );

        $this->assertNotNull($this->policyService->getProposal($proposal['proposal_id']));

        $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposal['proposal_id'],
            'staff_id'    => $this->staffManager->id,
            'pin'         => '654321',
        ]);

        $this->assertNull($this->policyService->getProposal($proposal['proposal_id']));
    }

    /**
     * 15. Reject path invalidates proposal without database mutation.
     */
    public function test_15_reject_path(): void
    {
        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'expense.create',
            ['name' => 'Beli Kursi Tambahan', 'amount' => 300000]
        );

        $initialExpenseCount = Expense::count();

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/reject', [
            'proposal_id' => $proposal['proposal_id'],
            'reason'      => 'Anggaran tidak mencukupi minggu ini',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('code', 'ACTION_REJECTED');
        $this->assertNull($this->policyService->getProposal($proposal['proposal_id']));
        $this->assertEquals($initialExpenseCount, Expense::count());
    }

    /**
     * 16. Mutation failure handled gracefully with 500 code.
     */
    public function test_16_mutation_failure(): void
    {
        // Cancel transaction proposal dengan ID transaksi yang salah
        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'transaction.cancel',
            [
                'transaction_id' => 999999, // ID tidak ada
                'reason'         => 'Salah input pesanan',
            ]
        );

        // Otorisasi role OWNER dibutuhkan untuk cancel transaction
        $staffOwner = Staff::create([
            'user_id'    => $this->ownerPro->id,
            'outlet_id'  => $this->outletPusat->id,
            'staff_name' => 'Owner Utama',
            'role'       => 'OWNER',
            'pin'        => '555666',
            'is_active'  => 1,
        ]);

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposal['proposal_id'],
            'staff_id'    => $staffOwner->id,
            'pin'         => '555666',
        ]);

        $response->assertStatus(500);
        $response->assertJsonPath('code', 'MUTATION_FAILED');
    }

    /**
     * 17. No direct mutation from propose_action tool.
     */
    public function test_17_no_direct_mutation_from_propose_action(): void
    {
        $initialExpenseCount = Expense::count();

        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'content'    => null,
                                'tool_calls' => [
                                    [
                                        'id'       => 'call_prop_123',
                                        'type'     => 'function',
                                        'function' => [
                                            'name'      => 'propose_action',
                                            'arguments' => json_encode([
                                                'action'     => 'expense.create',
                                                'parameters' => [
                                                    'name'   => 'Beli Tisu Makan',
                                                    'amount' => 15000,
                                                ],
                                            ]),
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
                                'content' => 'Saya telah membuatkan proposal pencatatan pengeluaran Rp 15.000. Silakan verifikasi dengan PIN Manajer di aplikasi.',
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/chat-help', [
            'message' => 'Catat beli tisu makan 15rb',
        ]);

        $response->assertStatus(200);

        // Pastikan tidak ada data yang masuk ke tabel expenses
        $this->assertEquals($initialExpenseCount, Expense::count());
    }

    /**
     * 18. Forbidden dynamic dispatch/input rejected.
     */
    public function test_18_forbidden_dynamic_dispatch_and_input(): void
    {
        $res = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'expense.create',
            [
                'name'       => 'Test',
                'amount'     => 10000,
                'controller' => 'App\Http\Controllers\SomeController',
                'method'     => 'destroyEverything',
            ]
        );

        $this->assertEquals(ActionPolicyService::DECISION_DENY, $res['decision']);
        $this->assertNull($res['proposal_id']);
    }

    /**
     * 19. PIN is never logged in AuditLog or responses.
     */
    public function test_19_pin_never_logged(): void
    {
        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'expense.create',
            ['name' => 'Beli Sabun Cuci Tangan', 'amount' => 18000]
        );

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposal['proposal_id'],
            'staff_id'    => $this->staffManager->id,
            'pin'         => '654321',
        ]);

        $response->assertStatus(200);
        $this->assertStringNotContainsString('654321', $response->getContent());

        $latestLog = AuditLog::latest('id')->first();
        $this->assertNotNull($latestLog);
        $logJson = json_encode($latestLog->toArray());
        $this->assertStringNotContainsString('654321', $logJson);
    }

    /**
     * 20. Existing B.3/B.4/B.5/B.6/B.7 tools remain fully functional.
     */
    public function test_20_existing_tools_remain_functional(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'content'    => null,
                                'tool_calls' => [
                                    [
                                        'id'       => 'call_sales_123',
                                        'type'     => 'function',
                                        'function' => [
                                            'name'      => 'get_today_sales',
                                            'arguments' => '{}',
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
                                'content' => 'Omzet hari ini adalah Rp 36.000.',
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/chat-help', [
            'message' => 'Analisis performa penjualan toko hari ini',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('36.000', $response->json('reply'));
    }

    /**
     * 21. Concurrent approval: Atomic lock prevents double mutation.
     */
    public function test_21_concurrent_approval_atomic_lock_prevents_double_mutation(): void
    {
        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'expense.create',
            ['name' => 'Beli Biji Robusta', 'amount' => 150000]
        );

        $initialExpenseCount = Expense::count();
        $proposalId = $proposal['proposal_id'];

        // Simulasikan request A sedang memegang lock atomic di background
        $lockKey = "copilot:approval-lock:{$proposalId}";
        $activeLock = Cache::lock($lockKey, 15);
        $acquired = $activeLock->get();
        $this->assertTrue($acquired, 'Lock A harus berhasil didapatkan');

        // Request B mencoba approve proposal yang sama saat lock A masih aktif
        $responseB = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposalId,
            'staff_id'    => $this->staffManager->id,
            'pin'         => '654321',
        ]);

        // Request B harus ditolak dengan 409 PROPOSAL_LOCK_CONFLICT
        $responseB->assertStatus(409);
        $responseB->assertJsonPath('code', 'PROPOSAL_LOCK_CONFLICT');

        // Verifikasi tidak ada mutasi yang terjadi dari Request B
        $this->assertEquals($initialExpenseCount, Expense::count());

        // Rilis lock A (simulasi request A selesai / retry)
        $activeLock->release();

        // Eksekusi request A yang sah
        $responseA = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposalId,
            'staff_id'    => $this->staffManager->id,
            'pin'         => '654321',
        ]);

        $responseA->assertStatus(200);
        $responseA->assertJsonPath('code', 'ACTION_EXECUTED');
        $this->assertEquals($initialExpenseCount + 1, Expense::count(), 'Hanya boleh ada tepat satu mutasi');

        // Sekarang coba Request B kembali setelah proposal tuntas dieksekusi
        $responseBRetry = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposalId,
            'staff_id'    => $this->staffManager->id,
            'pin'         => '654321',
        ]);

        // Harus 404 karena proposal sudah di-invalidate
        $responseBRetry->assertStatus(404);
        $responseBRetry->assertJsonPath('code', 'PROPOSAL_NOT_FOUND');

        // Mutasi tetap tepat 1 (tidak pernah terjadi double mutation)
        $this->assertEquals($initialExpenseCount + 1, Expense::count());
    }

    /**
     * 22. Stock adjustment routes through canonical StockService and creates stock_movements ledger.
     */
    public function test_22_stock_adjust_routes_through_stock_service_and_creates_stock_movement(): void
    {
        $this->menuKopi->stock = 10;
        $this->menuKopi->save();

        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'stock.adjust',
            [
                'menu_id'  => $this->menuKopi->id,
                'quantity' => 5,
                'type'     => 'increment',
                'notes'    => 'Penambahan stok biji kopi via Copilot',
            ]
        );

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposal['proposal_id'],
            'staff_id'    => $this->staffManager->id,
            'pin'         => '654321',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('code', 'ACTION_EXECUTED');

        // Pastikan stok menu terupdate menjadi 15
        $this->menuKopi->refresh();
        $this->assertEquals(15, (float) $this->menuKopi->stock);

        // Pastikan entri riwayat audit tercatat di tabel stock_movements
        $movement = DB::table('stock_movements')
            ->where('menu_id', $this->menuKopi->id)
            ->where('user_id', $this->ownerPro->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($movement, 'Stock movement ledger harus tercatat');
        $this->assertEquals('increment', $movement->type);
        $this->assertEquals(5, (float) $movement->quantity_delta);
        $this->assertEquals(10, (float) $movement->balance_before);
        $this->assertEquals(15, (float) $movement->balance_after);
    }

    /**
     * 23. Transaction cancel persists cancel_reason to database.
     */
    public function test_23_transaction_cancel_persists_cancel_reason(): void
    {
        $proposal = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'transaction.cancel',
            [
                'transaction_id' => $this->transaction->id,
                'reason'         => 'Pelanggan membatalkan pesanan karena salah varian rasa',
            ]
        );

        $staffOwner = Staff::create([
            'user_id'    => $this->ownerPro->id,
            'outlet_id'  => $this->outletPusat->id,
            'staff_name' => 'Pak Owner',
            'role'       => 'OWNER',
            'pin'        => '888888',
            'is_active'  => true,
        ]);

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposal['proposal_id'],
            'staff_id'    => $staffOwner->id,
            'pin'         => '888888',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('code', 'ACTION_EXECUTED');

        $this->transaction->refresh();
        $this->assertEquals('Batal', $this->transaction->status);
        $this->assertEquals('Pelanggan membatalkan pesanan karena salah varian rasa', $this->transaction->cancel_reason);
    }

    /**
     * 24. scenario.simulate_draft is no longer exposed as a mutation action.
     */
    public function test_24_scenario_simulate_draft_is_unsupported(): void
    {
        $result = $this->policyService->evaluateProposal(
            $this->ownerPro->id,
            $this->outletPusat->id,
            'scenario.simulate_draft',
            [
                'scenario_type' => 'DISCOUNT_PROMO',
                'parameters'    => ['discount_pct' => 15],
            ]
        );

        $this->assertEquals(ActionPolicyService::DECISION_UNSUPPORTED, $result['decision']);
        $this->assertNull($result['proposal_id']);
    }
}
