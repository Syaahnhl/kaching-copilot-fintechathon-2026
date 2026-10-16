<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Menu;
use App\Services\Financial\MenuMarginActionBridgeService;
use App\Services\Policy\ActionPolicyService;

class MenuMarginActionBridgeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected MenuMarginActionBridgeService $bridge;
    protected ActionPolicyService $policyService;
    protected User $tenantOwner;
    protected User $otherTenant;
    protected Outlet $outletA;
    protected Outlet $outletB;
    protected Menu $validMenu;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->policyService = app(ActionPolicyService::class);
        $this->bridge        = new MenuMarginActionBridgeService($this->policyService);

        $this->tenantOwner = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'pro',
        ]);

        $this->otherTenant = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'pro',
        ]);

        $this->outletA = Outlet::create([
            'user_id' => $this->tenantOwner->id,
            'name'    => 'Outlet Pusat Menteng',
            'address' => 'Jl. Menteng No. 1',
        ]);

        $this->outletB = Outlet::create([
            'user_id' => $this->tenantOwner->id,
            'name'    => 'Outlet Cabang BSD',
            'address' => 'Jl. BSD Boulevard No. 2',
        ]);

        $this->validMenu = Menu::create([
            'user_id'    => $this->tenantOwner->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Ayam Bakar Madu',
            'category'   => 'Makanan',
            'price'      => 20000.0,
            'cost_price' => 16400.0, // 18% current margin
        ]);
    }

    /**
     * 1. MENU_MARGIN_REVIEW diagnostic provides candidates ordered by lowest margin.
     */
    public function test_diagnostic_candidates_returns_menus_ordered_by_lowest_margin(): void
    {
        Menu::create([
            'user_id'    => $this->tenantOwner->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Es Teh Manis',
            'category'   => 'Minuman',
            'price'      => 5000.0,
            'cost_price' => 2500.0, // 50% margin
        ]);

        Menu::create([
            'user_id'    => $this->tenantOwner->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Kopi Susu Gula Aren',
            'category'   => 'Minuman',
            'price'      => 15000.0,
            'cost_price' => 13500.0, // 10% margin
        ]);

        $candidates = $this->bridge->getDiagnosticCandidates($this->tenantOwner->id, $this->outletA->id);

        $this->assertNotEmpty($candidates);
        $this->assertEquals('Kopi Susu Gula Aren', $candidates[0]['name']);
        $this->assertEquals(10.0, $candidates[0]['current_margin_pct']);
        $this->assertEquals('Ayam Bakar Madu', $candidates[1]['name']);
        $this->assertEquals(18.0, $candidates[1]['current_margin_pct']);
    }

    /**
     * 2. No selected menu returns REQUIRES_MENU_SELECTION.
     */
    public function test_no_selected_menu_returns_requires_menu_selection(): void
    {
        $result = $this->bridge->resolveAndSimulate($this->tenantOwner->id, $this->outletA->id, [
            'new_price' => 25000,
        ]);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_REQUIRES_MENU_SELECTION, $result['code']);
    }

    /**
     * 3. Multiple selected menus returns REQUIRES_MENU_SELECTION (strictly exactly one).
     */
    public function test_multiple_selected_menus_returns_requires_menu_selection(): void
    {
        $result = $this->bridge->resolveAndSimulate($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id' => [1, 2],
            'new_price'        => 25000,
        ]);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_REQUIRES_MENU_SELECTION, $result['code']);
    }

    /**
     * 4. Selected menu does not exist returns MENU_NOT_FOUND.
     */
    public function test_non_existent_menu_returns_menu_not_found(): void
    {
        $result = $this->bridge->resolveAndSimulate($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id' => 999999,
            'new_price'        => 25000,
        ]);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_MENU_NOT_FOUND, $result['code']);
    }

    /**
     * 5. Cross-tenant menu returns UNAUTHORIZED_MENU.
     */
    public function test_cross_tenant_menu_returns_unauthorized_menu(): void
    {
        $foreignMenu = Menu::create([
            'user_id'    => $this->otherTenant->id,
            'name'       => 'Menu Asing Rahasia',
            'category'   => 'Makanan',
            'price'      => 30000.0,
            'cost_price' => 20000.0,
        ]);

        $result = $this->bridge->resolveAndSimulate($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id' => $foreignMenu->id,
            'new_price'        => 35000,
        ]);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_UNAUTHORIZED_MENU, $result['code']);
    }

    /**
     * 6. Cross-outlet menu returns UNAUTHORIZED_MENU when outlet isolation applies.
     */
    public function test_cross_outlet_menu_returns_unauthorized_menu(): void
    {
        $menuOutletB = Menu::create([
            'user_id'    => $this->tenantOwner->id,
            'outlet_id'  => $this->outletB->id,
            'name'       => 'Menu Khusus BSD',
            'category'   => 'Makanan',
            'price'      => 20000.0,
            'cost_price' => 15000.0,
        ]);

        $result = $this->bridge->resolveAndSimulate($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id' => $menuOutletB->id,
            'new_price'        => 25000,
        ]);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_UNAUTHORIZED_MENU, $result['code']);
    }

    /**
     * 7. Deleted/trashed menu returns INVALID_MENU_DATA.
     */
    public function test_deleted_menu_returns_invalid_menu_data(): void
    {
        $this->validMenu->delete();

        $result = $this->bridge->resolveAndSimulate($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id' => $this->validMenu->id,
            'new_price'        => 25000,
        ]);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_INVALID_MENU_DATA, $result['code']);
    }

    /**
     * 8. Menu with invalid current price (<= 0) returns INVALID_MENU_DATA.
     */
    public function test_invalid_current_price_returns_invalid_menu_data(): void
    {
        $brokenPriceMenu = Menu::create([
            'user_id'    => $this->tenantOwner->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Menu Gratisan Rusak',
            'category'   => 'Makanan',
            'price'      => 0.0,
            'cost_price' => 10000.0,
        ]);

        $result = $this->bridge->resolveAndSimulate($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id' => $brokenPriceMenu->id,
            'new_price'        => 15000,
        ]);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_INVALID_MENU_DATA, $result['code']);
    }

    /**
     * 9. Menu with missing HPP (cost_price <= 0 or null) returns INVALID_MENU_DATA.
     */
    public function test_missing_cost_price_returns_invalid_menu_data(): void
    {
        $noHppMenu = Menu::create([
            'user_id'    => $this->tenantOwner->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Menu Tanpa HPP',
            'category'   => 'Makanan',
            'price'      => 20000.0,
            'cost_price' => 0.0,
        ]);

        $result = $this->bridge->resolveAndSimulate($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id' => $noHppMenu->id,
            'new_price'        => 25000,
        ]);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_INVALID_MENU_DATA, $result['code']);
    }

    /**
     * 10. Valid HPP and current price without new_price enters diagnostic inspection mode.
     */
    public function test_valid_menu_without_new_price_returns_diagnostic_detail(): void
    {
        $result = $this->bridge->resolveAndSimulate($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id' => $this->validMenu->id,
        ]);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertTrue($result['requires_price_input']);
        $this->assertEquals(20000.0, $result['current_price']);
        $this->assertEquals(16400.0, $result['cost_price']);
        $this->assertEquals(18.0, $result['current_margin_pct']);
    }

    /**
     * 11. Owner selects new_price: computes deterministic resulting gross margin.
     */
    public function test_owner_selects_new_price_computes_deterministic_resulting_margin(): void
    {
        $result = $this->bridge->resolveAndSimulate($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id' => $this->validMenu->id,
            'new_price'        => 23000,
        ]);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertFalse($result['requires_price_input']);
        $this->assertEquals(23000.0, $result['owner_selected_new_price']);
        $this->assertEquals(3000.0, $result['price_delta']);
        $this->assertEquals(15.0, $result['price_change_percent']); // (23000 - 20000) / 20000 = +15.0%

        // (23000 - 16400) / 23000 * 100 = 28.7%
        $this->assertEquals(28.7, $result['resulting_margin_pct']);
        $this->assertEquals(10.7, $result['margin_delta_pp']); // 28.7 - 18.0 = +10.7 pp
    }

    /**
     * 12. Owner enters exact price: Rp 27,000 is preserved without arbitrary rounding.
     */
    public function test_owner_explicit_price_is_preserved_without_alteration(): void
    {
        $result = $this->bridge->resolveAndSimulate($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id' => $this->validMenu->id,
            'new_price'        => 27000,
        ]);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals(27000.0, $result['owner_selected_new_price']);
        // (27000 - 16400) / 27000 * 100 = 39.26%
        $this->assertEquals(39.26, $result['resulting_margin_pct']);
    }

    /**
     * 13. Invalid owner new_price (<= 0 or non-numeric) returns INVALID_MENU_DATA.
     */
    public function test_invalid_owner_new_price_returns_invalid_menu_data(): void
    {
        $result = $this->bridge->resolveAndSimulate($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id' => $this->validMenu->id,
            'new_price'        => -500,
        ]);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_INVALID_MENU_DATA, $result['code']);
    }

    /**
     * 14. Proposal creation generates PENDING proposal in transient cache.
     */
    public function test_proposal_creation_generates_pending_proposal_in_transient_cache(): void
    {
        $result = $this->bridge->createProposal($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id' => $this->validMenu->id,
            'new_price'        => 25000,
            'reason'           => 'Kenaikan harga paha ayam di supplier',
        ]);

        $this->assertEquals(ActionPolicyService::DECISION_REQUIRES_APPROVAL, $result['decision']);
        $this->assertTrue($result['approval_required']);
        $this->assertEquals('OWNER', $result['required_role']);
        $this->assertEquals(ActionPolicyService::RISK_HIGH, $result['risk_level']);
        $this->assertNotEmpty($result['proposal_id']);

        // Check proposal parameters
        $this->assertEquals($this->validMenu->id, $result['parameters']['menu_id']);
        $this->assertEquals(25000.0, $result['parameters']['new_price']);

        // Check cached proposal
        $cached = $this->policyService->getProposal($result['proposal_id']);
        $this->assertNotNull($cached);
        $this->assertEquals(ActionPolicyService::DECISION_REQUIRES_APPROVAL, $cached['decision']);
        $this->assertTrue($cached['approval_required']);
        $this->assertTrue($cached['requires_pin']);
        $this->assertEquals(25000.0, $cached['parameters']['new_price']);
    }

    /**
     * 15. Proposal creation causes ZERO database mutations.
     */
    public function test_proposal_creation_causes_zero_database_mutations(): void
    {
        $originalPrice = $this->validMenu->price;

        $this->bridge->createProposal($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id' => $this->validMenu->id,
            'new_price'        => 25000,
        ]);

        $menuAfter = Menu::find($this->validMenu->id);
        $this->assertEquals($originalPrice, $menuAfter->price); // Database is completely unchanged
    }

    /**
     * 16. LLM cannot override user_id or inject tenant via input keys.
     */
    public function test_forbidden_parameters_injection_returns_forbidden_parameter_error(): void
    {
        $result = $this->bridge->resolveAndSimulate($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id' => $this->validMenu->id,
            'new_price'        => 25000,
            'user_id'          => $this->otherTenant->id, // Attempted tenant override
        ]);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_FORBIDDEN_PARAMETER, $result['code']);
    }

    /**
     * 17. LLM cannot override role or approval status.
     */
    public function test_forbidden_role_or_approval_status_injection_is_rejected(): void
    {
        $result = $this->bridge->resolveAndSimulate($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id' => $this->validMenu->id,
            'new_price'        => 25000,
            'approval_status'  => 'APPROVED', // Attempted bypass
        ]);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_FORBIDDEN_PARAMETER, $result['code']);
    }

    /**
     * 18. LLM cannot inject target_margin parameter.
     */
    public function test_target_margin_injection_is_forbidden(): void
    {
        $result = $this->bridge->resolveAndSimulate($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id'  => $this->validMenu->id,
            'new_price'         => 25000,
            'target_margin_pct' => 40.0, // Attempted target margin policy injection
        ]);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals(MenuMarginActionBridgeService::CODE_FORBIDDEN_PARAMETER, $result['code']);
    }

    /**
     * 19. Canonical DB server is the sole authority for current_price and cost_price.
     * Injected client price or cost is ignored because server reloads from DB.
     */
    public function test_server_reloads_canonical_price_and_cost_from_database(): void
    {
        $result = $this->bridge->resolveAndSimulate($this->tenantOwner->id, $this->outletA->id, [
            'selected_menu_id' => $this->validMenu->id,
            'new_price'        => 24000,
            'current_price'    => 1000, // Fabricated client price
            'cost_price'       => 500,  // Fabricated client cost
        ]);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals(20000.0, $result['current_price']); // Loaded from DB, not from client
        $this->assertEquals(16400.0, $result['cost_price']);    // Loaded from DB, not from client
    }

    /**
     * 20. ActionPolicyService rules remain authoritative for menu.price_change.
     */
    public function test_action_policy_firewall_validation_remains_active(): void
    {
        $result = $this->policyService->evaluateProposal(
            $this->tenantOwner->id,
            $this->outletA->id,
            'menu.price_change',
            [
                'menu_id'   => $this->validMenu->id,
                'new_price' => 25000,
            ]
        );

        $this->assertEquals(ActionPolicyService::DECISION_REQUIRES_APPROVAL, $result['decision']);
        $this->assertTrue($result['approval_required']);
        $this->assertEquals('OWNER', $result['required_role']);
        $this->assertEquals(ActionPolicyService::RISK_HIGH, $result['risk_level']);

        $cached = $this->policyService->getProposal($result['proposal_id']);
        $this->assertNotNull($cached);
        $this->assertTrue($cached['requires_pin']);
    }
}
