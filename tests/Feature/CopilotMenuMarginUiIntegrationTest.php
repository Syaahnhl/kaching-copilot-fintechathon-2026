<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Menu;
use App\Services\Policy\ActionPolicyService;
use App\Services\Financial\MenuMarginActionBridgeService;

class CopilotMenuMarginUiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Outlet $outlet;
    protected User $otherUser;
    protected Outlet $otherOutlet;
    protected Menu $ownMenu;
    protected Menu $otherTenantMenu;
    protected Menu $otherOutletMenu;

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
            'name'    => 'Outlet Utama',
            'address' => 'Jl. Sudirman No. 1, Jakarta',
        ]);

        $this->otherUser = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'pro',
        ]);

        $this->otherOutlet = Outlet::create([
            'user_id' => $this->user->id,
            'name'    => 'Outlet Kedua User Sama',
            'address' => 'Jl. Thamrin No. 2, Jakarta',
        ]);

        $this->otherUserOutlet = Outlet::create([
            'user_id' => $this->otherUser->id,
            'name'    => 'Outlet Tetangga Beda Tenant',
            'address' => 'Jl. Gatot Subroto No. 3, Jakarta',
        ]);

        $this->otherTenantMenu = Menu::create([
            'user_id'    => $this->otherUser->id,
            'outlet_id'  => $this->otherUserOutlet->id,
            'name'       => 'Menu Tenant Lain',
            'category'   => 'Makanan',
            'price'      => 30000.0,
            'cost_price' => 15000.0,
            'is_active'  => 1,
        ]);

        $this->otherOutletMenu = Menu::create([
            'user_id'    => $this->user->id,
            'outlet_id'  => $this->otherOutlet->id,
            'name'       => 'Menu Outlet Tetangga',
            'category'   => 'Makanan',
            'price'      => 20000.0,
            'cost_price' => 10000.0,
            'is_active'  => 1,
        ]);

        $this->ownMenu = Menu::create([
            'user_id'    => $this->user->id,
            'outlet_id'  => $this->outlet->id,
            'name'       => 'Ayam Geprek Spesial',
            'category'   => 'Makanan',
            'price'      => 20000.0,
            'cost_price' => 16000.0, // 20% margin
            'is_active'  => 1,
        ]);
    }

    /**
     * Requirement 1 & 2: Candidate list uses correct semantic label and does not claim causal attribution.
     */
    public function test_candidates_endpoint_uses_correct_semantic_label_and_no_causal_attribution()
    {
        $response = $this->actingAs($this->user)
            ->getJson(route('copilot.menu-margin.candidates', ['outlet_id' => $this->outlet->id]));

        $response->assertStatus(200);
        $data = $response->json();

        // 1. Mandatory semantic label
        $this->assertEquals('Kandidat menu untuk ditinjau Owner', $data['semantic_label']);
        $this->assertEquals('EXPLORATORY_CANDIDATES_ONLY', $data['selection_context']);

        // 2. No causal attribution
        $diagnosticNote = $data['diagnostic_note'];
        $this->assertStringNotContainsString('penyebab margin turun', strtolower($diagnosticNote));
        $this->assertStringNotContainsString('penyebab margin compression', strtolower($diagnosticNote));
        $this->assertStringNotContainsString('penyebab margin drop', strtolower($diagnosticNote));
        $this->assertStringNotContainsString('ai menemukan menu penyebab', strtolower($diagnosticNote));
        $this->assertStringNotContainsString('harus dinaikkan', strtolower($diagnosticNote));
        $this->assertStringContainsString('bukan klaim penyebab', strtolower($diagnosticNote));
    }

    /**
     * Requirement 3: Deterministic fields only, no fabricated optimal price or target margin.
     */
    public function test_candidates_endpoint_returns_deterministic_fields_only()
    {
        $response = $this->actingAs($this->user)
            ->getJson(route('copilot.menu-margin.candidates', ['outlet_id' => $this->outlet->id]));

        $response->assertStatus(200);
        $candidates = $response->json('candidates');

        $this->assertNotEmpty($candidates);
        $first = $candidates[0];

        $this->assertArrayHasKey('menu_id', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('category', $first);
        $this->assertArrayHasKey('current_price', $first);
        $this->assertArrayHasKey('cost_price', $first);
        $this->assertArrayHasKey('current_margin_pct', $first);

        // Verify no fabrication
        $this->assertArrayNotHasKey('target_margin', $first);
        $this->assertArrayNotHasKey('target_price', $first);
        $this->assertArrayNotHasKey('optimal_price', $first);
        $this->assertArrayNotHasKey('recommended_price', $first);
        $this->assertArrayNotHasKey('elasticity', $first);
    }

    /**
     * Empty state when no menus with HPP exist.
     */
    public function test_candidates_endpoint_empty_state_when_no_menus_with_hpp()
    {
        // Update menu to have 0 HPP
        $this->ownMenu->update(['cost_price' => 0.0]);

        $response = $this->actingAs($this->user)
            ->getJson(route('copilot.menu-margin.candidates', ['outlet_id' => $this->outlet->id]));

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('count'));
        $this->assertEmpty($response->json('candidates'));
    }

    /**
     * Requirement 4: Missing menu selection is rejected with REQUIRES_MENU_SELECTION.
     */
    public function test_simulation_rejects_missing_selection()
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('copilot.menu-margin.simulate'), [
                'outlet_id' => $this->outlet->id,
                'new_price' => 25000,
            ]);

        $response->assertStatus(422);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_REQUIRES_MENU_SELECTION, $response->json('code'));
    }

    /**
     * Requirement 5: Multiple menu selection is rejected with REQUIRES_MENU_SELECTION.
     */
    public function test_simulation_rejects_multiple_selection()
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('copilot.menu-margin.simulate'), [
                'outlet_id' => $this->outlet->id,
                'menu_id'   => [$this->ownMenu->id, 999],
                'new_price' => 25000,
            ]);

        $response->assertStatus(422);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_REQUIRES_MENU_SELECTION, $response->json('code'));
    }

    /**
     * Requirement 6: Cross-tenant menu selection is rejected with UNAUTHORIZED_MENU.
     */
    public function test_simulation_rejects_cross_tenant_menu()
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('copilot.menu-margin.simulate'), [
                'outlet_id' => $this->outlet->id,
                'menu_id'   => $this->otherTenantMenu->id,
                'new_price' => 25000,
            ]);

        $response->assertStatus(422);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_UNAUTHORIZED_MENU, $response->json('code'));
    }

    /**
     * Requirement 7: Cross-outlet menu selection is rejected with UNAUTHORIZED_MENU.
     */
    public function test_simulation_rejects_cross_outlet_menu()
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('copilot.menu-margin.simulate'), [
                'outlet_id' => $this->outlet->id,
                'menu_id'   => $this->otherOutletMenu->id,
                'new_price' => 25000,
            ]);

        $response->assertStatus(422);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_UNAUTHORIZED_MENU, $response->json('code'));
    }

    /**
     * Requirement 8: Soft-deleted menu is rejected with INVALID_MENU_DATA.
     */
    public function test_simulation_rejects_deleted_menu()
    {
        $deletedMenu = Menu::create([
            'user_id'    => $this->user->id,
            'outlet_id'  => $this->outlet->id,
            'name'       => 'Menu Terhapus',
            'category'   => 'Makanan',
            'price'      => 20000.0,
            'cost_price' => 10000.0,
            'is_active'  => 1,
        ]);
        $deletedMenu->delete();

        $response = $this->actingAs($this->user)
            ->postJson(route('copilot.menu-margin.simulate'), [
                'outlet_id' => $this->outlet->id,
                'menu_id'   => $deletedMenu->id,
                'new_price' => 25000,
            ]);

        $response->assertStatus(422);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_INVALID_MENU_DATA, $response->json('code'));
    }

    /**
     * Requirement 9: Missing or zero HPP is rejected with INVALID_MENU_DATA.
     */
    public function test_simulation_rejects_missing_hpp()
    {
        $zeroHppMenu = Menu::create([
            'user_id'    => $this->user->id,
            'outlet_id'  => $this->outlet->id,
            'name'       => 'Menu Tanpa HPP',
            'category'   => 'Minuman',
            'price'      => 10000.0,
            'cost_price' => 0.0,
            'is_active'  => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('copilot.menu-margin.simulate'), [
                'outlet_id' => $this->outlet->id,
                'menu_id'   => $zeroHppMenu->id,
                'new_price' => 15000,
            ]);

        $response->assertStatus(422);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_INVALID_MENU_DATA, $response->json('code'));
    }

    /**
     * Requirement 10 & 11: Owner-selected price preserved exactly and resulting margin is deterministic.
     */
    public function test_simulation_preserves_owner_price_and_calculates_deterministic_margin()
    {
        // Current: price = 20,000, cost_price = 16,000 -> 20% margin
        // Owner chooses: 25,000 -> delta = 5,000 (+25%), new margin = (25000 - 16000) / 25000 = 36.0%, delta pp = +16.0 pp
        $response = $this->actingAs($this->user)
            ->postJson(route('copilot.menu-margin.simulate'), [
                'outlet_id' => $this->outlet->id,
                'menu_id'   => $this->ownMenu->id,
                'new_price' => 25000,
            ]);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals(20000.0, $data['current_price']);
        $this->assertEquals(25000.0, $data['new_price']);
        $this->assertEquals(5000.0, $data['price_delta']);
        $this->assertEquals(25.0, $data['price_change_percent']);
        $this->assertEquals(36.0, $data['resulting_margin_pct']);
        $this->assertEquals(16.0, $data['margin_delta_pp']);
    }

    /**
     * Requirement 12: Target margin injection is rejected with FORBIDDEN_PARAMETER.
     */
    public function test_simulation_rejects_target_margin_injection()
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('copilot.menu-margin.simulate'), [
                'outlet_id'     => $this->outlet->id,
                'menu_id'       => $this->ownMenu->id,
                'new_price'     => 25000,
                'target_margin' => 40.0,
            ]);

        $response->assertStatus(422);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_FORBIDDEN_PARAMETER, $response->json('code'));
    }

    /**
     * Requirement 13, 14, 15, 16: Proposal creation is PENDING, does not execute, current price remains unchanged in DB.
     */
    public function test_proposal_creation_remains_pending_without_database_execution()
    {
        $originalPrice = (float) $this->ownMenu->price;

        $response = $this->actingAs($this->user)
            ->postJson(route('copilot.menu-margin.propose'), [
                'outlet_id' => $this->outlet->id,
                'menu_id'   => $this->ownMenu->id,
                'new_price' => 26000,
                'reason'    => 'Penyesuaian margin unit hasil evaluasi Owner',
            ]);

        $response->assertStatus(200);
        $data = $response->json();

        // 13. Proposal is PENDING approval
        $this->assertEquals('SUCCESS', $data['status']);
        $this->assertEquals(ActionPolicyService::DECISION_REQUIRES_APPROVAL, $data['decision']);
        $this->assertEquals('PENDING', $data['proposal_status']);
        $this->assertTrue($data['approval_required']);
        $this->assertTrue($data['requires_pin']);
        $this->assertNotEmpty($data['proposal_id']);

        // 14. Proposal does not execute
        $this->assertFalse($data['is_executed']);
        $this->assertNull($data['execution_receipt']);

        // 15. Current price remains unchanged in database
        $refreshedMenu = Menu::find($this->ownMenu->id);
        $this->assertEquals($originalPrice, (float) $refreshedMenu->price);
        $this->assertNotEquals(26000.0, (float) $refreshedMenu->price);

        // 16. Proposal exists in transient cache with PENDING status and TTL ~15m
        $policyService = app(ActionPolicyService::class);
        $cachedProposal = $policyService->getProposal($data['proposal_id']);

        $this->assertNotNull($cachedProposal);
        $this->assertEquals(ActionPolicyService::DECISION_REQUIRES_APPROVAL, $cachedProposal['decision']);
        $this->assertTrue($cachedProposal['approval_required']);
        $this->assertEquals('menu.price_change', $cachedProposal['action']);
        $this->assertEquals(26000.0, $cachedProposal['parameters']['new_price']);
    }

    /**
     * LLM tool propose_action integration test with menu.price_change.
     */
    public function test_chatbot_propose_action_tool_routes_through_bridge_safely()
    {
        /** @var \App\Http\Controllers\ChatbotController $controller */
        $controller = app(\App\Http\Controllers\ChatbotController::class);

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('executeProposeAction');
        $method->setAccessible(true);

        $request = new \Illuminate\Http\Request();
        $request->merge(['outlet_id' => $this->outlet->id]);

        $arguments = [
            'action'     => 'menu.price_change',
            'parameters' => [
                'menu_id'   => $this->ownMenu->id,
                'new_price' => 28000,
                'reason'    => 'Owner proposal via Copilot tool',
            ],
        ];

        $result = $method->invoke($controller, $this->user, $request, $arguments);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals(ActionPolicyService::DECISION_REQUIRES_APPROVAL, $result['decision']);
        $this->assertEquals('PENDING', $result['proposal_status']);
        $this->assertFalse($result['is_executed']);
        $this->assertEquals(28000.0, $result['new_price']);

        // Ensure DB price unchanged
        $this->assertEquals(20000.0, (float) Menu::find($this->ownMenu->id)->price);
    }

    /**
     * Requirement: Unauthenticated requests are rejected with 401.
     */
    public function test_unauthenticated_requests_are_rejected()
    {
        $response = $this->getJson(route('copilot.menu-margin.candidates'));
        $response->assertStatus(401);
    }

    /**
     * Requirement: Unauthorized outlet is rejected with 403.
     */
    public function test_unauthorized_outlet_is_rejected()
    {
        $response = $this->actingAs($this->user)
            ->getJson(route('copilot.menu-margin.candidates', ['outlet_id' => $this->otherUserOutlet->id]));

        $response->assertStatus(403);
        $this->assertEquals('UNAUTHORIZED_OUTLET', $response->json('code'));
    }

    /**
     * Requirement: Chatbot get_financial_action_recommendations enriches MENU_MARGIN_REVIEW with candidates.
     */
    public function test_chatbot_get_financial_action_recommendations_enriches_menu_margin_review()
    {
        /** @var \App\Http\Controllers\ChatbotController $controller */
        $controller = app(\App\Http\Controllers\ChatbotController::class);

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('executeGetFinancialActionRecommendations');
        $method->setAccessible(true);

        $request = new \Illuminate\Http\Request();
        $request->merge(['outlet_id' => $this->outlet->id]);

        $result = $method->invoke($controller, $this->user, $request);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertIsArray($result['recommendations']);

        // Check if any recommendation is MENU_MARGIN_REVIEW, it must have candidates & semantic_label
        foreach ($result['recommendations'] as $rec) {
            if ($rec['action_type'] === 'MENU_MARGIN_REVIEW') {
                $this->assertEquals('Kandidat menu untuk ditinjau Owner', $rec['semantic_label']);
                $this->assertArrayHasKey('candidates', $rec);
                $this->assertArrayHasKey('diagnostic_note', $rec);
                $this->assertStringNotContainsString('penyebab margin turun', strtolower($rec['diagnostic_note']));
            }
        }
    }
}
