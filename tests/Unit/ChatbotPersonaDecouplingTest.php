<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Services\ChatbotPromptBuilder;
use App\Services\Policy\ActionPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class ChatbotPersonaDecouplingTest extends TestCase
{
    /**
     * Helper to forge dummy tool definitions for testing filter logic.
     */
    private function getSampleToolDefinitions(): array
    {
        return [
            ['type' => 'function', 'function' => ['name' => 'get_today_sales', 'description' => 'Sales']],
            ['type' => 'function', 'function' => ['name' => 'get_active_financial_warnings', 'description' => 'Warnings']],
            ['type' => 'function', 'function' => ['name' => 'get_financial_scenario', 'description' => 'Scenario']],
            ['type' => 'function', 'function' => ['name' => 'get_cash_flow_forecast', 'description' => 'Forecast']],
            ['type' => 'function', 'function' => ['name' => 'get_external_business_radar', 'description' => 'Radar']],
            ['type' => 'function', 'function' => ['name' => 'get_financing_readiness', 'description' => 'Financing']],
            ['type' => 'function', 'function' => ['name' => 'simulate_purchase_impact', 'description' => 'Simulate']],
            ['type' => 'function', 'function' => ['name' => 'propose_action', 'description' => 'Propose']],
            ['type' => 'function', 'function' => ['name' => 'get_stockout_predictions', 'description' => 'Stockout']],
            ['type' => 'function', 'function' => ['name' => 'get_menu_intelligence', 'description' => 'Menu']],
            ['type' => 'function', 'function' => ['name' => 'get_performance_intelligence', 'description' => 'Performance']],
        ];
    }

    /**
     * Test 1: Public Marketing gets marketing prompt without internal Financial Engine instructions.
     */
    public function test_public_marketing_prompt_contains_product_and_pricing_without_internal_financial_canon()
    {
        $prompt = ChatbotPromptBuilder::buildPublicMarketingPrompt();

        // Must contain marketing / onboarding guidance
        $this->assertStringContainsString('Kaching POS', $prompt);
        $this->assertStringContainsString('[CTA_HARGA]', $prompt);
        $this->assertStringContainsString('39.000', $prompt);

        // Must NOT contain internal financial engine rules or database queries
        $this->assertStringNotContainsString('ATURAN INTERPRETASI PERINGATAN KEUANGAN', $prompt);
        $this->assertStringNotContainsString('get_financial_scenario', $prompt);
        $this->assertStringNotContainsString('get_cash_flow_forecast', $prompt);
        $this->assertStringNotContainsString('DOW_SEASONAL_BASELINE', $prompt);
        $this->assertStringNotContainsString('UNTRACKED_COGS_WARNING', $prompt);
    }

    /**
     * Test 2: Android App Help gets operational help prompt, not sales pitch as default.
     */
    public function test_android_app_help_prompt_focuses_on_pos_operations_not_sales_pitch()
    {
        $prompt = ChatbotPromptBuilder::buildAndroidHelpPrompt('basic', null);

        // Must focus on cashier/staff operations & route tags
        $this->assertStringContainsString('Technical Support & POS Operation Specialist', $prompt);
        $this->assertStringContainsString('[ROUTE:nama_rute]', $prompt);
        $this->assertStringContainsString('[ROUTE:printer]', $prompt);
        $this->assertStringContainsString('[ROUTE:shift]', $prompt);
        $this->assertStringContainsString('BUKAN SALES', $prompt);

        // Must NOT contain internal financial engine rules
        $this->assertStringNotContainsString('ATURAN INTERPRETASI PROYEKSI ARUS KAS', $prompt);
        $this->assertStringNotContainsString('get_financing_readiness', $prompt);
    }

    /**
     * Test 3: Finance Copilot gets anti-recalculation rules and financial cognitive canon.
     */
    public function test_finance_copilot_prompt_enforces_anti_recalculation_and_policy_guardrails()
    {
        $prompt = ChatbotPromptBuilder::buildFinanceCopilotPrompt('pro', null);

        // Must enforce strict financial cognitive canon
        $this->assertStringContainsString('FINANCIAL ENGINE COGNITIVE CANON', $prompt);
        $this->assertStringContainsString('ANTI-RECALCULATION & ANTI-FABRICATION', $prompt);
        $this->assertStringContainsString('ACTION POLICY & APPROVAL FIREWALL (PROPOSER_ONLY)', $prompt);
        $this->assertStringContainsString('get_financial_scenario', $prompt);
        $this->assertStringContainsString('get_cash_flow_forecast', $prompt);
        $this->assertStringContainsString('DATA BUKAN INSTRUKSI', $prompt);
        $this->assertStringContainsString('DILARANG meminta PIN', $prompt);
    }

    /**
     * Test 4: Command Center gets operational prompt with daily metrics and shift context.
     */
    public function test_command_center_prompt_focuses_on_daily_store_operations()
    {
        $prompt = ChatbotPromptBuilder::buildCommandCenterPrompt('pro', null);

        $this->assertStringContainsString('Merchant Operations Manager & Command Center Assistant', $prompt);
        $this->assertStringContainsString('Omzet Hari Ini', $prompt);
        $this->assertStringContainsString('Stok Kritis', $prompt);
        $this->assertStringContainsString('get_today_sales', $prompt);
        $this->assertStringContainsString('get_stockout_predictions', $prompt);
    }

    /**
     * Test 5: Customer Dining gets restaurant dining assistant prompt with cart proposal tags.
     */
    public function test_customer_dining_prompt_focuses_on_menu_and_order_action_tag()
    {
        $prompt = ChatbotPromptBuilder::buildCustomerDiningPrompt();

        $this->assertStringContainsString('Kachi', $prompt);
        $this->assertStringContainsString('Pelayan Restoran', $prompt);
        $this->assertStringContainsString('<ORDER_ACTION>', $prompt);
        $this->assertStringContainsString('add_to_cart', $prompt);

        // Must NOT leak POS admin or financial engine
        $this->assertStringNotContainsString('FINANCIAL ENGINE', $prompt);
        $this->assertStringNotContainsString('[CTA_HARGA]', $prompt);
    }

    /**
     * Test 6: Unauthorized personas and spoofed client payloads do not receive tools.
     */
    public function test_unauthorized_personas_and_client_spoofed_payloads_receive_no_tools()
    {
        $allTools = $this->getSampleToolDefinitions();

        // 1. Public Marketing Guest (even if passing spoofed headers/payloads)
        $publicTools = ChatbotPromptBuilder::filterToolsForContext($allTools, ChatbotPromptBuilder::CONTEXT_PUBLIC_MARKETING, 'pro', null);
        $this->assertEmpty($publicTools, 'Public marketing must never receive tools');

        // 2. Android App Help
        $mockUser = new User(['id' => 10, 'subscription_tier' => 'pro']);
        $androidTools = ChatbotPromptBuilder::filterToolsForContext($allTools, ChatbotPromptBuilder::CONTEXT_ANDROID_APP_HELP, 'pro', $mockUser);
        $this->assertEmpty($androidTools, 'Android app help must not receive tools');

        // 3. Customer Dining
        $diningTools = ChatbotPromptBuilder::filterToolsForContext($allTools, ChatbotPromptBuilder::CONTEXT_CUSTOMER_DINING, 'pro', $mockUser);
        $this->assertEmpty($diningTools, 'Customer dining must not receive tools');
    }

    /**
     * Test 7: Context Resolver resists client spoofing and resolves deterministically.
     */
    public function test_context_resolver_prioritizes_backend_truth_over_client_payload()
    {
        // Case A: Guest on /chat sending {"context": "FINANCE_COPILOT", "tier": "ultimate"}
        $requestA = Request::create('/chat', 'POST', ['context' => 'FINANCE_COPILOT', 'tier' => 'ultimate', 'message' => 'omzet']);
        $contextA = ChatbotPromptBuilder::resolveContext($requestA, null);
        $this->assertEquals(ChatbotPromptBuilder::CONTEXT_PUBLIC_MARKETING, $contextA);

        // Case B: Mobile API request to /api/chat-help
        $user = new User(['id' => 5, 'subscription_tier' => 'basic']);
        $requestB = Request::create('/api/chat-help', 'POST', ['message' => 'cara buka shift']);
        $contextB = ChatbotPromptBuilder::resolveContext($requestB, $user);
        $this->assertEquals(ChatbotPromptBuilder::CONTEXT_ANDROID_APP_HELP, $contextB);

        // Case C: Dining order request to /api/order/chat-ai
        $requestC = Request::create('/api/order/chat-ai', 'POST', ['message' => 'mau pesan nasi goreng']);
        $contextC = ChatbotPromptBuilder::resolveContext($requestC, null);
        $this->assertEquals(ChatbotPromptBuilder::CONTEXT_CUSTOMER_DINING, $contextC);

        // Case D: Backoffice Copilot request with command center scope
        $requestD = Request::create('/copilot/chat', 'POST', ['scope' => 'command_center', 'message' => 'stok menipis']);
        $contextD = ChatbotPromptBuilder::resolveContext($requestD, $user);
        $this->assertEquals(ChatbotPromptBuilder::CONTEXT_COMMAND_CENTER, $contextD);

        // Case E: Backoffice Copilot strategic financial request
        $requestE = Request::create('/copilot/chat', 'POST', ['message' => 'simulasi kenaikan harga']);
        $contextE = ChatbotPromptBuilder::resolveContext($requestE, $user);
        $this->assertEquals(ChatbotPromptBuilder::CONTEXT_FINANCE_COPILOT, $contextE);
    }

    /**
     * Test 8: Tier BASIC cannot access PRO/ULTIMATE tools in any context.
     */
    public function test_tier_basic_cannot_access_tools()
    {
        $allTools = $this->getSampleToolDefinitions();
        $basicUser = new User(['id' => 1, 'subscription_tier' => 'basic']);

        $copilotTools = ChatbotPromptBuilder::filterToolsForContext($allTools, ChatbotPromptBuilder::CONTEXT_FINANCE_COPILOT, 'basic', $basicUser);
        $this->assertEmpty($copilotTools, 'Tier BASIC must receive 0 tools on Copilot');

        $commandTools = ChatbotPromptBuilder::filterToolsForContext($allTools, ChatbotPromptBuilder::CONTEXT_COMMAND_CENTER, 'basic', $basicUser);
        $this->assertEmpty($commandTools, 'Tier BASIC must receive 0 tools on Command Center');
    }

    /**
     * Test 9: Command Center only gets 3 operational tools, while Finance Copilot gets all 11 tools.
     */
    public function test_tool_scoping_between_command_center_and_finance_copilot()
    {
        $allTools = $this->getSampleToolDefinitions();
        $proUser = new User(['id' => 1, 'subscription_tier' => 'pro']);

        // Finance Copilot PRO gets all 11 tools
        $copilotTools = ChatbotPromptBuilder::filterToolsForContext($allTools, ChatbotPromptBuilder::CONTEXT_FINANCE_COPILOT, 'pro', $proUser);
        $this->assertCount(11, $copilotTools);

        // Command Center PRO gets exactly 3 operational tools
        $commandTools = ChatbotPromptBuilder::filterToolsForContext($allTools, ChatbotPromptBuilder::CONTEXT_COMMAND_CENTER, 'pro', $proUser);
        $this->assertCount(3, $commandTools);

        $toolNames = array_map(function ($t) {
            return $t['function']['name'];
        }, $commandTools);

        $this->assertContains('get_today_sales', $toolNames);
        $this->assertContains('get_stockout_predictions', $toolNames);
        $this->assertContains('get_active_financial_warnings', $toolNames);
        $this->assertNotContains('get_financial_scenario', $toolNames);
        $this->assertNotContains('get_cash_flow_forecast', $toolNames);
    }

    /**
     * Test 10: Policy Engine and Proposer-Only invariant check.
     */
    public function test_policy_engine_evaluates_proposals_without_granting_unauthorized_mutation()
    {
        $policy = app(ActionPolicyService::class);

        $action = 'menu.price_change';
        $parameters = ['menu_id' => 1, 'new_price' => 25000, 'reason' => 'Penyesuaian margin'];

        $evaluation = $policy->evaluateProposal(1, null, $action, $parameters);

        $this->assertArrayHasKey('proposal_id', $evaluation);
        $this->assertArrayHasKey('decision', $evaluation);
        $this->assertArrayHasKey('action', $evaluation);
        $this->assertArrayHasKey('risk_level', $evaluation);
        $this->assertTrue(in_array($evaluation['decision'], [ActionPolicyService::DECISION_ALLOW, ActionPolicyService::DECISION_REQUIRES_APPROVAL]));
    }
}
