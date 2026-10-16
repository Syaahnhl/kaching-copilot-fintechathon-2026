<?php

namespace Tests\Unit;

use App\Http\Controllers\ChatbotController;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Financial\FinanceCopilotOrchestrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CopilotChatReliabilityRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenantA;
    protected User $tenantB;
    protected Outlet $outletA;
    protected Outlet $outletB;
    protected FinanceCopilotOrchestrationService $orchestrator;
    protected ChatbotController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = User::factory()->create([
            'name'  => 'Tenant A Owner',
            'email' => 'tenantA@kaching.test',
        ]);
        $this->outletA = Outlet::factory()->create([
            'user_id' => $this->tenantA->id,
            'name'    => 'Outlet Pusat A',
        ]);

        $this->tenantB = User::factory()->create([
            'name'  => 'Tenant B Owner',
            'email' => 'tenantB@kaching.test',
        ]);
        $this->outletB = Outlet::factory()->create([
            'user_id' => $this->tenantB->id,
            'name'    => 'Outlet Cabang B',
        ]);

        $this->orchestrator = app(FinanceCopilotOrchestrationService::class);
        $this->controller = app(ChatbotController::class);
    }

    /**
     * TEST 1: Exact & partial supplier query matching works as expected.
     */
    public function test_get_suppliers_exact_and_partial_match(): void
    {
        $sup1 = Supplier::create([
            'user_id'        => $this->tenantA->id,
            'name'           => 'CV Harto Sumber Segar',
            'contact_person' => 'Pak Harto',
            'phone'          => '081225628894',
            'is_active'      => true,
        ]);

        $sup2 = Supplier::create([
            'user_id'        => $this->tenantA->id,
            'name'           => 'PT Berkah Kopi Nusantara',
            'contact_person' => 'Budi Santoso',
            'phone'          => '081234567890',
            'is_active'      => true,
        ]);

        // Exact name keyword
        $resExact = $this->orchestrator->dispatchTool('get_suppliers', $this->tenantA->id, $this->outletA->id, [
            'query' => 'Harto',
        ]);
        $this->assertSame(1, $resExact['data']['count']);
        $this->assertSame('CV Harto Sumber Segar', $resExact['data']['suppliers'][0]['name']);
        $this->assertStringContainsString("cocok dengan kata kunci 'Harto'", $resExact['data']['message']);

        // Partial contact person keyword (case insensitive)
        $resPartial = $this->orchestrator->dispatchTool('get_suppliers', $this->tenantA->id, $this->outletA->id, [
            'query' => 'budi',
        ]);
        $this->assertSame(1, $resPartial['data']['count']);
        $this->assertSame('PT Berkah Kopi Nusantara', $resPartial['data']['suppliers'][0]['name']);
    }

    /**
     * TEST 2: No supplier match returns explicit clean empty response.
     */
    public function test_get_suppliers_no_match_returns_clean_empty_result(): void
    {
        Supplier::create([
            'user_id'        => $this->tenantA->id,
            'name'           => 'Supplier Kopi Gayo',
            'contact_person' => 'Ahmad',
            'phone'          => '08123456789',
            'is_active'      => true,
        ]);

        $res = $this->orchestrator->dispatchTool('get_suppliers', $this->tenantA->id, $this->outletA->id, [
            'query' => 'Galon Mineral Non-Existent',
        ]);

        $this->assertSame(0, $res['data']['count']);
        $this->assertEmpty($res['data']['suppliers']);
        $this->assertStringContainsString("Tidak ditemukan data supplier yang cocok dengan kata kunci", $res['data']['message']);
    }

    /**
     * TEST 3: Result limit is bounded (default 5, capped at 10).
     */
    public function test_get_suppliers_result_limit_is_bounded(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            Supplier::create([
                'user_id'        => $this->tenantA->id,
                'name'           => sprintf('Supplier Bahan %02d', $i),
                'contact_person' => 'Kontak ' . $i,
                'phone'          => '0812000000' . $i,
                'is_active'      => true,
            ]);
        }

        // Default limit must be 5 (not 15 or 20)
        $resDefault = $this->orchestrator->dispatchTool('get_suppliers', $this->tenantA->id, $this->outletA->id);
        $this->assertSame(5, $resDefault['data']['count']);
        $this->assertCount(5, $resDefault['data']['suppliers']);

        // Requested limit capped at 10 even if 15 requested
        $resCapped = $this->orchestrator->dispatchTool('get_suppliers', $this->tenantA->id, $this->outletA->id, [
            'limit' => 50,
        ]);
        $this->assertSame(10, $resCapped['data']['count']);
        $this->assertCount(10, $resCapped['data']['suppliers']);
    }

    /**
     * TEST 4: Cross-tenant and cross-outlet isolation.
     */
    public function test_get_suppliers_strict_cross_tenant_isolation(): void
    {
        $supA = Supplier::create([
            'user_id'        => $this->tenantA->id,
            'name'           => 'Supplier Milik Tenant A',
            'contact_person' => 'Agus',
            'phone'          => '0811111111',
            'is_active'      => true,
        ]);

        $supB = Supplier::create([
            'user_id'        => $this->tenantB->id,
            'name'           => 'Supplier Milik Tenant B',
            'contact_person' => 'Bambang',
            'phone'          => '0822222222',
            'is_active'      => true,
        ]);

        $resA = $this->orchestrator->dispatchTool('get_suppliers', $this->tenantA->id, $this->outletA->id);
        $this->assertSame(1, $resA['data']['count']);
        $this->assertSame('Supplier Milik Tenant A', $resA['data']['suppliers'][0]['name']);

        $resB = $this->orchestrator->dispatchTool('get_suppliers', $this->tenantB->id, $this->outletB->id);
        $this->assertSame(1, $resB['data']['count']);
        $this->assertSame('Supplier Milik Tenant B', $resB['data']['suppliers'][0]['name']);
    }

    /**
     * TEST 5: No leakage of unnecessary supplier fields into tool payload.
     */
    public function test_get_suppliers_does_not_leak_unnecessary_fields(): void
    {
        $sup = Supplier::create([
            'user_id'        => $this->tenantA->id,
            'name'           => 'Supplier Bersih Token',
            'contact_person' => 'Dewi',
            'phone'          => '0833333333',
            'email'          => 'dewi@supplier.test',
            'address'        => 'Jl. Industri No 45, Jakarta',
            'is_active'      => true,
        ]);

        $res = $this->orchestrator->dispatchTool('get_suppliers', $this->tenantA->id, $this->outletA->id);
        $supplierData = $res['data']['suppliers'][0];

        // Allowed fields
        $this->assertArrayHasKey('id', $supplierData);
        $this->assertArrayHasKey('name', $supplierData);
        $this->assertArrayHasKey('contact_person', $supplierData);
        $this->assertArrayHasKey('phone', $supplierData);
        $this->assertArrayHasKey('items_count', $supplierData);
        $this->assertArrayHasKey('items', $supplierData);

        // Disallowed / token-wasting fields
        $this->assertArrayNotHasKey('user_id', $supplierData);
        $this->assertArrayNotHasKey('email', $supplierData);
        $this->assertArrayNotHasKey('address', $supplierData);
        $this->assertArrayNotHasKey('category', $supplierData);
        $this->assertArrayNotHasKey('payment_terms', $supplierData);
        $this->assertArrayNotHasKey('lead_time', $supplierData);
        $this->assertArrayNotHasKey('moq', $supplierData);
        $this->assertArrayNotHasKey('created_at', $supplierData);
        $this->assertArrayNotHasKey('updated_at', $supplierData);
    }

    /**
     * TEST 6: Standalone greetings in executeDeterministicFallback return friendly 200 non-error.
     */
    public function test_standalone_greetings_return_friendly_non_error_fallback(): void
    {
        $greetings = ['halo', 'hai', 'hi', 'tes', 'test', 'pagi', 'siang', 'sore', 'malam', 'Halo Kachi', 'selamat pagi', 'test 123'];

        foreach ($greetings as $greeting) {
            $request = Request::create('/copilot/chat', 'POST', ['message' => $greeting]);
            $request->setUserResolver(fn() => $this->tenantA);

            $response = $this->controller->executeDeterministicFallback($this->tenantA, $request, $greeting);

            $this->assertSame(200, $response->getStatusCode(), "Greeting '{$greeting}' must return HTTP 200");
            $data = json_decode($response->getContent(), true);

            $this->assertSame('success', $data['status'], "Greeting '{$greeting}' must return status success");
            $this->assertStringContainsString('Halo', $data['reply'], "Greeting '{$greeting}' must contain friendly greeting reply");
            $this->assertNull($data['financial_card']);
        }
    }

    /**
     * TEST 7: Substring and financial queries are NOT classified as greetings.
     */
    public function test_financial_and_substring_queries_are_not_greetings(): void
    {
        // 1. Substrings inside unrelated words (protes, kesiangan)
        $nonGreetings = ['protes', 'kesiangan'];
        foreach ($nonGreetings as $word) {
            $request = Request::create('/copilot/chat', 'POST', ['message' => $word]);
            $request->setUserResolver(fn() => $this->tenantA);
            $response = $this->controller->executeDeterministicFallback($this->tenantA, $request, $word);
            $data = json_decode($response->getContent(), true);

            // Substrings must NOT match standalone greeting handler
            $this->assertNotSame('Halo', substr($data['reply'] ?? '', 0, 4), "Word '{$word}' should not match standalone greeting handler");
        }

        // 2. Financial request containing 'halo' or 'pagi' must route to financial summary, not greeting
        $financialMsg = 'halo omzet hari ini berapa?';
        $request = Request::create('/copilot/chat', 'POST', ['message' => $financialMsg]);
        $request->setUserResolver(fn() => $this->tenantA);
        $response = $this->controller->executeDeterministicFallback($this->tenantA, $request, $financialMsg);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertSame('success', $data['status']);
        $this->assertNotNull($data['financial_card'] ?? null, "Message '{$financialMsg}' must generate financial card, not greeting response");
    }
}
