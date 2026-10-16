<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Staff;
use App\Models\Menu;
use App\Models\Expense;
use App\Services\Policy\ActionPolicyService;

class CopilotActionProposalBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected User $ownerPro;
    protected User $ownerOther;
    protected User $userBasic;
    protected Outlet $outletPusat;
    protected Outlet $outletOther;
    protected Staff $staffManager;
    protected Menu $menuKopi;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('pin-attempt:*');
        config(['services.groq.key' => 'gsk_test_fake_api_key_bridge_12345']);

        // Pro Merchant
        $this->ownerPro = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'pro',
        ]);

        $this->outletPusat = Outlet::create([
            'user_id' => $this->ownerPro->id,
            'name'    => 'Outlet Pusat Jakarta',
            'address' => 'Jl. Sudirman No. 1',
        ]);

        // Staff Manager with PIN 654321
        $this->staffManager = Staff::create([
            'user_id'    => $this->ownerPro->id,
            'outlet_id'  => $this->outletPusat->id,
            'staff_name' => 'Manager Budi',
            'role'       => 'OWNER',
            'pin'        => '654321',
            'is_active'  => 1,
        ]);

        $this->menuKopi = Menu::create([
            'user_id'    => $this->ownerPro->id,
            'outlet_id'  => $this->outletPusat->id,
            'name'       => 'Kopi Susu Senja',
            'price'      => 18000,
            'cost_price' => 8000,
            'category'   => 'Minuman',
            'stock'      => 50,
            'is_active'  => 1,
        ]);

        // Other Merchant (Tenant Isolation)
        $this->ownerOther = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'pro',
        ]);

        $this->outletOther = Outlet::create([
            'user_id' => $this->ownerOther->id,
            'name'    => 'Outlet Lawan',
            'address' => 'Jl. Antah Berantah No. 99',
        ]);

        // Basic User (Free Tier)
        $this->userBasic = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'basic',
        ]);
    }

    /**
     * A. Successful propose_action -> response contains structured proposal.
     */
    public function test_a_successful_propose_action_returns_structured_proposal(): void
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
                                        'id'       => 'call_prop_exp_01',
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
                                'content' => 'Saya telah membuatkan proposal pencatatan pengeluaran Rp 15.000. Silakan verifikasi di aplikasi.',
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/chat-help', [
            'message' => 'Catat beli tisu makan 15rb',
        ]);

        $response->assertStatus(200);

        // Assert response envelope structure
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
            ],
            'proposal' => [
                'proposal_id',
                'action',
                'parameters',
                'required_role',
                'decision',
                'risk_level',
                'expires_at',
            ],
        ]);

        $proposal = $response->json('data.proposal');
        $this->assertNotNull($proposal);
        $this->assertStringStartsWith('prop_', $proposal['proposal_id']);
        $this->assertEquals('expense.create', $proposal['action']);
        $this->assertEquals('REQUIRES_APPROVAL', $proposal['decision']);
        $this->assertEquals('MANAGER', $proposal['required_role']);
        $this->assertEquals('MEDIUM', $proposal['risk_level']);
        $this->assertEquals('Beli Tisu Makan', $proposal['parameters']['name']);
        $this->assertEquals(15000, $proposal['parameters']['amount']);
        $this->assertNotEmpty($proposal['expires_at']);
    }

    /**
     * B. proposal_id originates from server-side proposal and is executable via B.8 approval endpoint.
     */
    public function test_b_proposal_id_originates_from_server_cache_and_can_be_approved(): void
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
                                        'id'       => 'call_prop_exp_02',
                                        'type'     => 'function',
                                        'function' => [
                                            'name'      => 'propose_action',
                                            'arguments' => json_encode([
                                                'action'     => 'expense.create',
                                                'parameters' => [
                                                    'name'     => 'Beli Kertas Thermal',
                                                    'amount'   => 45000,
                                                    'category' => 'Operasional',
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
                                'content' => 'Proposal dibuat.',
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $response = $this->actingAs($this->ownerPro, 'sanctum')
            ->withHeader('Outlet-ID', (string) $this->outletPusat->id)
            ->postJson('/api/chat-help', [
                'message' => 'Catat beli kertas thermal 45rb',
            ]);

        $response->assertStatus(200);
        $proposalId = $response->json('data.proposal.proposal_id');
        $this->assertNotEmpty($proposalId);

        // Verify proposal is stored in server-side cache
        $cachedProposal = Cache::get('copilot:proposal:' . $proposalId);
        $this->assertNotNull($cachedProposal);
        $this->assertEquals($this->ownerPro->id, $cachedProposal['user_id']);
        $this->assertEquals($this->outletPusat->id, $cachedProposal['outlet_id']);
        $this->assertEquals('Beli Kertas Thermal', $cachedProposal['parameters']['name']);

        // Execute approval via B.8 endpoint with PIN
        $initialExpenseCount = Expense::count();
        $approvalResponse = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/copilot/actions/approve', [
            'proposal_id' => $proposalId,
            'staff_id'    => $this->staffManager->id,
            'pin'         => '654321',
        ]);

        $approvalResponse->assertStatus(200);
        $approvalResponse->assertJson([
            'status' => 'success',
            'code'   => 'ACTION_EXECUTED',
        ]);

        $this->assertEquals($initialExpenseCount + 1, Expense::count());
    }

    /**
     * C. action, parameters, and required_role strictly follow policy result and sanitize unwhitelisted inputs.
     */
    public function test_c_action_parameters_and_required_role_follow_policy_result(): void
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
                                        'id'       => 'call_prop_price_01',
                                        'type'     => 'function',
                                        'function' => [
                                            'name'      => 'propose_action',
                                            'arguments' => json_encode([
                                                'action'     => 'menu.price_change',
                                                'parameters' => [
                                                    'menu_id'      => $this->menuKopi->id,
                                                    'new_price'    => 22000,
                                                    'unwhitelisted' => 'malicious_input',
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
                                'content' => 'Proposal perubahan harga kopi telah dibuat.',
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/chat-help', [
            'message' => 'Naikkan harga kopi senja jadi 22rb',
        ]);

        $response->assertStatus(200);
        $proposal = $response->json('data.proposal');

        $this->assertEquals('menu.price_change', $proposal['action']);
        $this->assertEquals('OWNER', $proposal['required_role']); // price change requires owner
        $this->assertEquals('HIGH', $proposal['risk_level']);
        $this->assertEquals($this->menuKopi->id, $proposal['parameters']['menu_id']);
        $this->assertEquals(22000, $proposal['parameters']['new_price']);
        $this->assertArrayNotHasKey('unwhitelisted', $proposal['parameters']);
    }

    /**
     * D. DENY -> No executable proposal in response.
     */
    public function test_d_deny_returns_no_executable_proposal(): void
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
                                        'id'       => 'call_prop_deny_01',
                                        'type'     => 'function',
                                        'function' => [
                                            'name'      => 'propose_action',
                                            'arguments' => json_encode([
                                                'action'     => 'expense.create',
                                                'parameters' => [
                                                    'name'   => 'Pengeluaran Negatif',
                                                    'amount' => -10000, // Invalid: negative amount triggers DENY
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
                                'content' => 'Pengeluaran tidak valid karena nominal negatif.',
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/chat-help', [
            'message' => 'Catat pengeluaran minus 10rb',
        ]);

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('proposal', $response->json('data'));
        $this->assertArrayNotHasKey('proposal', $response->json());
        $this->assertNotEmpty($response->json('reply'));
    }

    /**
     * E. UNSUPPORTED action -> No executable proposal in response.
     */
    public function test_e_unsupported_action_returns_no_executable_proposal(): void
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
                                        'id'       => 'call_prop_unsupported_01',
                                        'type'     => 'function',
                                        'function' => [
                                            'name'      => 'propose_action',
                                            'arguments' => json_encode([
                                                'action'     => 'database.drop_all',
                                                'parameters' => [],
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
                                'content' => 'Tindakan tersebut tidak didukung oleh sistem.',
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/chat-help', [
            'message' => 'Hapus semua database',
        ]);

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('proposal', $response->json('data'));
        $this->assertArrayNotHasKey('proposal', $response->json());
        $this->assertNotEmpty($response->json('reply'));
    }

    /**
     * F. Unauthorized outlet -> No proposal in response.
     */
    public function test_f_unauthorized_outlet_returns_no_proposal(): void
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
                                        'id'       => 'call_prop_cross_01',
                                        'type'     => 'function',
                                        'function' => [
                                            'name'      => 'propose_action',
                                            'arguments' => json_encode([
                                                'action'     => 'expense.create',
                                                'parameters' => [
                                                    'name'   => 'Beli Gula',
                                                    'amount' => 20000,
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
                                'content' => 'Outlet tidak ditemukan atau bukan milik pengguna.',
                            ],
                        ],
                    ],
                ], 200),
        ]);

        // Request with header pointing to someone else's outlet
        $response = $this->actingAs($this->ownerPro, 'sanctum')
            ->withHeader('Outlet-ID', (string) $this->outletOther->id)
            ->postJson('/api/chat-help', [
                'message' => 'Catat beli gula 20rb di outlet lawan',
            ]);

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('proposal', $response->json('data'));
        $this->assertArrayNotHasKey('proposal', $response->json());
    }

    /**
     * G. Existing tools (e.g. get_today_sales) remain functional without returning proposal.
     */
    public function test_g_existing_tools_remain_functional_without_proposal(): void
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
                                        'id'       => 'call_sales_01',
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
                                'content' => 'Omzet hari ini adalah Rp 0 karena belum ada transaksi.',
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/chat-help', [
            'message' => 'Cek performa transaksi toko saat ini',
        ]);

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('proposal', $response->json('data'));
        $this->assertArrayNotHasKey('proposal', $response->json());
        $this->assertStringContainsString('Omzet hari ini', $response->json('reply'));
        $this->assertStringContainsString('Omzet hari ini', $response->json('data.reply'));
    }

    /**
     * H. Response backward compatibility: reply is preserved and existing structure unbroken.
     */
    public function test_h_response_backward_compatibility_plain_chat(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => [
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Halo! Saya Kaching Copilot, asisten keuangan Anda.',
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($this->ownerPro, 'sanctum')->postJson('/api/chat-help', [
            'message' => 'Halo siapa kamu?',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'reply'  => 'Halo! Saya Kaching Copilot, asisten keuangan Anda.',
            'data'   => [
                'reply' => 'Halo! Saya Kaching Copilot, asisten keuangan Anda.',
            ],
        ]);
        $this->assertArrayNotHasKey('proposal', $response->json('data'));
        $this->assertArrayNotHasKey('proposal', $response->json());
    }

    /**
     * I. Unauthenticated request is rejected with 401.
     */
    public function test_i_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/chat-help', [
            'message' => 'Catat beli tisu',
        ]);

        $response->assertStatus(401);
    }

    /**
     * J. Basic tier user cannot trigger proposal tool.
     */
    public function test_j_basic_tier_user_cannot_trigger_proposal(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => function ($request) {
                $payload = json_decode($request->body(), true);
                // Basic users must not have tools registered in Groq payload
                $this->assertArrayNotHasKey('tools', $payload);

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => 'Fitur Copilot membutuhkan langganan Pro atau Ultimate.',
                            ],
                        ],
                    ],
                ], 200);
            },
        ]);

        $response = $this->actingAs($this->userBasic, 'sanctum')->postJson('/api/chat-help', [
            'message' => 'Catat beli tisu',
        ]);

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('proposal', $response->json('data'));
        $this->assertArrayNotHasKey('proposal', $response->json());
    }
}
