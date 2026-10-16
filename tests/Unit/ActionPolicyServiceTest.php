<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Expense;
use App\Services\Policy\ActionPolicyService;

class ActionPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ActionPolicyService $service;
    protected User $user;
    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = app(ActionPolicyService::class);

        $this->user = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'pro',
        ]);

        $this->outlet = Outlet::create([
            'user_id' => $this->user->id,
            'name'    => 'Outlet Pusat Jakarta',
            'address' => 'Jl. Sudirman No. 1',
        ]);
    }

    /**
     * 1. Supported action produces REQUIRES_APPROVAL proposal.
     */
    public function test_supported_action_generates_requires_approval_proposal(): void
    {
        $result = $this->service->evaluateProposal(
            $this->user->id,
            $this->outlet->id,
            'expense.create',
            [
                'name'           => 'Beli Telur 10kg',
                'amount'         => 280000,
                'category'       => 'Bahan Baku',
                'payment_method' => 'cash',
            ]
        );

        $this->assertEquals(ActionPolicyService::DECISION_REQUIRES_APPROVAL, $result['decision']);
        $this->assertTrue($result['approval_required']);
        $this->assertEquals('MANAGER', $result['required_role']);
        $this->assertEquals(ActionPolicyService::RISK_MEDIUM, $result['risk_level']);
        $this->assertEquals(ActionPolicyService::REVERSIBILITY_REVERSIBLE, $result['reversibility']);
        $this->assertStringStartsWith('prop_', $result['proposal_id']);
        $this->assertNotNull($result['expires_at']);

        // Pastikan tersimpan di cache
        $cached = $this->service->getProposal($result['proposal_id']);
        $this->assertNotNull($cached);
        $this->assertEquals($this->user->id, $cached['user_id']);
        $this->assertEquals($this->outlet->id, $cached['outlet_id']);
    }

    /**
     * 2. Unsupported action returns UNSUPPORTED and null proposal_id.
     */
    public function test_unsupported_action_returns_unsupported_decision(): void
    {
        $result = $this->service->evaluateProposal(
            $this->user->id,
            $this->outlet->id,
            'arbitrary.unsupported_action',
            ['key' => 'value']
        );

        $this->assertEquals(ActionPolicyService::DECISION_UNSUPPORTED, $result['decision']);
        $this->assertNull($result['proposal_id']);
        $this->assertStringContainsString('belum didukung', $result['reason']);
    }

    /**
     * 3. Forbidden parameters are strictly denied.
     */
    public function test_forbidden_parameters_trigger_deny(): void
    {
        $forbiddenKeys = ['user_id', 'outlet_id', 'role', 'sql', 'query', 'delete', 'drop', 'approval_status'];

        foreach ($forbiddenKeys as $forbiddenKey) {
            $result = $this->service->evaluateProposal(
                $this->user->id,
                $this->outlet->id,
                'expense.create',
                [
                    'name'         => 'Beli Gula',
                    'amount'       => 50000,
                    $forbiddenKey => 'malicious_payload',
                ]
            );

            $this->assertEquals(ActionPolicyService::DECISION_DENY, $result['decision'], "Failed asserting forbidden key: {$forbiddenKey}");
            $this->assertNull($result['proposal_id']);
            $this->assertStringContainsString("Parameter '{$forbiddenKey}' dilarang", $result['reason']);
        }
    }

    /**
     * 4. Missing required parameters trigger DENY.
     */
    public function test_missing_required_parameters_trigger_deny(): void
    {
        $result = $this->service->evaluateProposal(
            $this->user->id,
            $this->outlet->id,
            'expense.create',
            ['name' => 'Beli Minyak'] // amount hilang
        );

        $this->assertEquals(ActionPolicyService::DECISION_DENY, $result['decision']);
        $this->assertNull($result['proposal_id']);
        $this->assertStringContainsString('amount', $result['reason']);
    }

    /**
     * 5. Invalid parameter types / domain constraints trigger DENY.
     */
    public function test_invalid_domain_constraints_trigger_deny(): void
    {
        // Negative amount
        $resNegative = $this->service->evaluateProposal(
            $this->user->id,
            $this->outlet->id,
            'expense.create',
            ['name' => 'Beli Minyak', 'amount' => -1000]
        );
        $this->assertEquals(ActionPolicyService::DECISION_DENY, $resNegative['decision']);

        // Invalid stock adjust type
        $resStock = $this->service->evaluateProposal(
            $this->user->id,
            $this->outlet->id,
            'stock.adjust',
            ['menu_id' => 1, 'quantity' => 10, 'type' => 'invalid_type']
        );
        $this->assertEquals(ActionPolicyService::DECISION_DENY, $resStock['decision']);

        // Negative price
        $resPrice = $this->service->evaluateProposal(
            $this->user->id,
            $this->outlet->id,
            'menu.price_change',
            ['menu_id' => 1, 'new_price' => -500]
        );
        $this->assertEquals(ActionPolicyService::DECISION_DENY, $resPrice['decision']);
    }

    /**
     * 6. scenario.simulate_draft is no longer exposed as an action and returns UNSUPPORTED.
     */
    public function test_scenario_simulate_draft_is_unsupported_as_firewall_action(): void
    {
        $initialExpenseCount = Expense::count();

        $result = $this->service->evaluateProposal(
            $this->user->id,
            $this->outlet->id,
            'scenario.simulate_draft',
            [
                'scenario_type' => 'PRICE_CHANGE',
                'parameters'    => ['delta' => 10],
                'notes'         => 'Simulasi kenaikan harga',
            ]
        );

        $this->assertEquals(ActionPolicyService::DECISION_UNSUPPORTED, $result['decision']);
        $this->assertNull($result['proposal_id']);
        $this->assertStringContainsString('belum didukung', $result['reason']);

        // Verifikasi tidak ada mutasi ke database
        $this->assertEquals($initialExpenseCount, Expense::count());
    }

    /**
     * 7. Tenant and outlet isolation: foreign outlet triggers DENY.
     */
    public function test_foreign_outlet_triggers_deny(): void
    {
        $otherUser = User::factory()->create();
        $otherOutlet = Outlet::create([
            'user_id' => $otherUser->id,
            'name'    => 'Outlet Milik User Lain',
            'address' => 'Jl. Lain No. 99',
        ]);

        $result = $this->service->evaluateProposal(
            $this->user->id,
            $otherOutlet->id, // Outlet milik user lain
            'expense.create',
            ['name' => 'Beli Kopi', 'amount' => 100000]
        );

        $this->assertEquals(ActionPolicyService::DECISION_DENY, $result['decision']);
        $this->assertNull($result['proposal_id']);
        $this->assertStringContainsString('bukan milik merchant ini', $result['reason']);
    }

    /**
     * 8. Cryptographically unpredictable proposal IDs.
     */
    public function test_unpredictable_cryptographic_proposal_id(): void
    {
        $res1 = $this->service->evaluateProposal(
            $this->user->id,
            $this->outlet->id,
            'expense.create',
            ['name' => 'Beli Es Batu', 'amount' => 20000]
        );

        $res2 = $this->service->evaluateProposal(
            $this->user->id,
            $this->outlet->id,
            'expense.create',
            ['name' => 'Beli Es Batu', 'amount' => 20000]
        );

        $this->assertNotEquals($res1['proposal_id'], $res2['proposal_id']);
        $this->assertStringStartsWith('prop_', $res1['proposal_id']);
        $this->assertGreaterThan(32, strlen($res1['proposal_id']));
    }

    /**
     * 9. Parameter sanitization strictly strips unwhitelisted keys.
     */
    public function test_parameter_sanitization_strips_unwhitelisted_keys(): void
    {
        $result = $this->service->evaluateProposal(
            $this->user->id,
            $this->outlet->id,
            'expense.create',
            [
                'name'                 => 'Beli Sabun Cuci',
                'amount'               => 45000,
                'unwhitelisted_random' => 'must_be_stripped',
                'another_garbage'      => 12345,
            ]
        );

        $this->assertArrayHasKey('name', $result['parameters']);
        $this->assertArrayHasKey('amount', $result['parameters']);
        $this->assertArrayNotHasKey('unwhitelisted_random', $result['parameters']);
        $this->assertArrayNotHasKey('another_garbage', $result['parameters']);
    }

    /**
     * 10. Cache invalidation on proposal.
     */
    public function test_proposal_cache_invalidation(): void
    {
        $result = $this->service->evaluateProposal(
            $this->user->id,
            $this->outlet->id,
            'expense.create',
            ['name' => 'Beli Gas Elpiji', 'amount' => 150000]
        );

        $proposalId = $result['proposal_id'];
        $this->assertNotNull($this->service->getProposal($proposalId));

        $this->service->invalidateProposal($proposalId);
        $this->assertNull($this->service->getProposal($proposalId));
    }
}
