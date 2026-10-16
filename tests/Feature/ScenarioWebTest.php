<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use App\Services\Financial\FinancialScenarioService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScenarioWebTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'testing.merchant@kaching.test',
            'role'  => 'owner',
        ]);

        $this->outlet = Outlet::create([
            'user_id'   => $this->user->id,
            'name'      => 'Test Outlet Scenario',
            'device_id' => 'DEV-SCENARIO-WEB-01',
        ]);
    }

    /**
     * Helper to seed standard baseline data:
     * - Total Revenue: 50,000,000 IDR
     * - Total COGS: 20,000,000 IDR
     * - Operating Expenses: 10,000,000 IDR
     */
    protected function seedStandardBaseline(
        float $revenue = 50000000.0,
        float $cogs = 20000000.0,
        float $expense = 10000000.0,
        ?int $userId = null,
        ?int $outletId = null,
        bool $trackedCogs = true
    ): void {
        $uId = $userId ?? $this->user->id;
        $oId = $outletId ?? $this->outlet->id;

        $trxDate = Carbon::now()->subDays(5)->format('Y-m-d H:i:s');

        $trxId = DB::table('transactions')->insertGetId([
            'user_id'                 => $uId,
            'outlet_id'               => $oId,
            'app_uuid'                => 'tx-web-' . uniqid('', true),
            'official_invoice_number' => 'INV-' . uniqid(),
            'total_amount'            => $revenue,
            'pay_amount'              => $revenue,
            'payment_method'          => 'cash',
            'status'                  => 'Selesai',
            'created_at_device'       => $trxDate,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        if ($revenue > 0) {
            DB::table('transaction_items')->insert([
                'transaction_id' => $trxId,
                'menu_id'        => 101,
                'menu_name'      => 'Standard Item',
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
                'name'       => 'Operational Rent',
                'amount'     => $expense,
                'category'   => 'Operasional',
                'date'       => Carbon::now()->subDays(5)->format('Y-m-d'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * 1. Unauthenticated request must redirect to login.
     */
    public function test_unauthenticated_request_redirects_to_login(): void
    {
        $responseGet = $this->get('/intelligence/scenario');
        $responseGet->assertRedirect('/login');

        $responsePost = $this->postJson('/intelligence/scenario/simulate', [
            'scenario_type' => 'PRICE_CHANGE',
            'price_change_percent' => 10,
        ]);
        $responsePost->assertStatus(401);
    }

    /**
     * 2. Authorized user can render scenario simulator page.
     */
    public function test_authorized_page_renders(): void
    {
        $this->seedStandardBaseline();

        $response = $this->actingAs($this->user)->get('/intelligence/scenario');
        $response->assertStatus(200);
        $response->assertSee('Kaching Scenario Simulator');
        $response->assertSee('Simulasikan perubahan finansial tanpa mengubah data operasional.');
        $response->assertSee('Simulasikan');
        $response->assertSee('Reset');
    }

    /**
     * 3. Simulation succeeds with valid parameters.
     */
    public function test_simulation_success(): void
    {
        $this->seedStandardBaseline();

        $response = $this->actingAs($this->user)->postJson('/intelligence/scenario/simulate', [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
            'outlet_id'            => $this->outlet->id,
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals('SUCCESS', $data['status']);
        $this->assertEquals('PRICE_CHANGE', $data['scenario']['type']);
        $this->assertEquals(50000000.0, $data['baseline']['revenue']);
        $this->assertEquals(55000000.0, $data['result']['revenue']);
        $this->assertEquals(5000000.0, $data['delta']['revenue']);
        $this->assertEquals(10.0, $data['delta']['revenue_percent']);
        $this->assertArrayHasKey('evidence', $data);
        $this->assertEquals('DETERMINISTIC_SIMULATION', $data['evidence']['calculation_type']);
    }

    /**
     * 4. Invalid parameter rejected fail-closed.
     */
    public function test_invalid_parameter_rejected(): void
    {
        $this->seedStandardBaseline();

        // Missing required price_change_percent for PRICE_CHANGE
        $response = $this->actingAs($this->user)->postJson('/intelligence/scenario/simulate', [
            'scenario_type' => 'PRICE_CHANGE',
            'outlet_id'     => $this->outlet->id,
        ]);

        $response->assertStatus(422);
        $data = $response->json();
        $this->assertEquals('ERROR', $data['status']);
        $this->assertEquals('MISSING_REQUIRED_PARAMETER', $data['code']);

        // Invalid scenario type
        $responseInvalidType = $this->actingAs($this->user)->postJson('/intelligence/scenario/simulate', [
            'scenario_type' => 'UNKNOWN_SCENARIO',
            'outlet_id'     => $this->outlet->id,
        ]);
        $responseInvalidType->assertStatus(422);
        $this->assertEquals('INVALID_SCENARIO_TYPE', $responseInvalidType->json('code'));
    }

    /**
     * 5. Parameter out-of-bounds rejected.
     */
    public function test_out_of_bounds_rejected(): void
    {
        $this->seedStandardBaseline();

        // PRICE_CHANGE bounds: [-50.0, 100.0] -> 250% should be rejected
        $response = $this->actingAs($this->user)->postJson('/intelligence/scenario/simulate', [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 250,
            'outlet_id'            => $this->outlet->id,
        ]);

        $response->assertStatus(422);
        $data = $response->json();
        $this->assertEquals('ERROR', $data['status']);
        $this->assertEquals('PARAMETER_OUT_OF_BOUNDS', $data['code']);
    }

    /**
     * 6. Cross-tenant outlet rejected fail-closed.
     */
    public function test_cross_tenant_outlet_rejected(): void
    {
        $otherUser = User::factory()->create([
            'email' => 'other.merchant@kaching.test',
        ]);
        $otherOutlet = Outlet::create([
            'user_id'   => $otherUser->id,
            'name'      => 'Other Merchant Outlet',
            'device_id' => 'DEV-OTHER-OUTLET',
        ]);

        $response = $this->actingAs($this->user)->postJson('/intelligence/scenario/simulate', [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
            'outlet_id'            => $otherOutlet->id,
        ]);

        $response->assertStatus(403);
        $data = $response->json();
        $this->assertEquals('ERROR', $data['status']);
        $this->assertEquals('UNAUTHORIZED_OUTLET', $data['code']);
    }

    /**
     * 7. Response strictly uses deterministic service output.
     */
    public function test_response_uses_deterministic_service_output(): void
    {
        $this->seedStandardBaseline();

        /** @var FinancialScenarioService $service */
        $service = app(FinancialScenarioService::class);
        $expectedServiceOutput = $service->simulate($this->user->id, $this->outlet->id, [
            'scenario_type'        => 'COST_CHANGE',
            'cost_change_percent'  => 20.0,
            'baseline_period'      => '30_DAYS',
        ]);

        $response = $this->actingAs($this->user)->postJson('/intelligence/scenario/simulate', [
            'scenario_type'       => 'COST_CHANGE',
            'cost_change_percent' => 20.0,
            'baseline_period'     => '30_DAYS',
            'outlet_id'           => $this->outlet->id,
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals($expectedServiceOutput['baseline']['revenue'], $data['baseline']['revenue']);
        $this->assertEquals($expectedServiceOutput['baseline']['cogs'], $data['baseline']['cogs']);
        $this->assertEquals($expectedServiceOutput['result']['cogs'], $data['result']['cogs']);
        $this->assertEquals($expectedServiceOutput['delta']['cogs'], $data['delta']['cogs']);
        $this->assertEquals($expectedServiceOutput['delta']['gross_profit'], $data['delta']['gross_profit']);
        $this->assertEquals($expectedServiceOutput['delta']['gross_margin_pp'], $data['delta']['gross_margin_pp']);
    }

    /**
     * 8. Read-only guarantee: zero database mutations during simulation.
     */
    public function test_no_database_mutation(): void
    {
        $this->seedStandardBaseline();

        $trxCountBefore   = DB::table('transactions')->count();
        $itemCountBefore  = DB::table('transaction_items')->count();
        $expCountBefore   = DB::table('expenses')->count();
        $outletCountBefore = DB::table('outlets')->count();
        $userCountBefore  = DB::table('users')->count();

        $response = $this->actingAs($this->user)->postJson('/intelligence/scenario/simulate', [
            'scenario_type'        => 'COMBINED_SCENARIO',
            'price_change_percent' => 10,
            'cost_change_percent'  => 15,
            'outlet_id'            => $this->outlet->id,
        ]);

        $response->assertStatus(200);

        $this->assertEquals($trxCountBefore, DB::table('transactions')->count(), 'Transactions mutated!');
        $this->assertEquals($itemCountBefore, DB::table('transaction_items')->count(), 'Transaction items mutated!');
        $this->assertEquals($expCountBefore, DB::table('expenses')->count(), 'Expenses mutated!');
        $this->assertEquals($outletCountBefore, DB::table('outlets')->count(), 'Outlets mutated!');
        $this->assertEquals($userCountBefore, DB::table('users')->count(), 'Users mutated!');
    }

    /**
     * 9. Assumptions are returned by the endpoint.
     */
    public function test_assumptions_returned(): void
    {
        $this->seedStandardBaseline();

        $response = $this->actingAs($this->user)->postJson('/intelligence/scenario/simulate', [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
            'outlet_id'            => $this->outlet->id,
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('assumptions', $data);
        $this->assertIsArray($data['assumptions']);
        $this->assertNotEmpty($data['assumptions']);

        $joinedAssumptions = implode(' ', $data['assumptions']);
        $this->assertStringContainsString('Zero elasticity', $joinedAssumptions);
    }

    /**
     * 10. Limitations are returned by the endpoint.
     */
    public function test_limitations_returned(): void
    {
        $this->seedStandardBaseline();

        $response = $this->actingAs($this->user)->postJson('/intelligence/scenario/simulate', [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
            'outlet_id'            => $this->outlet->id,
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('limitations', $data);
        $this->assertIsArray($data['limitations']);
        $this->assertNotEmpty($data['limitations']);

        $joinedLimitations = implode(' ', $data['limitations']);
        $this->assertStringContainsString('penurunan volume', $joinedLimitations);
    }

    /**
     * 11. Data quality warning is preserved when COGS is untracked.
     */
    public function test_data_quality_warning_preserved(): void
    {
        // Seed with trackedCogs = false
        $this->seedStandardBaseline(50000000.0, 0.0, 10000000.0, null, null, false);

        $response = $this->actingAs($this->user)->postJson('/intelligence/scenario/simulate', [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
            'outlet_id'            => $this->outlet->id,
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals('UNTRACKED_COGS_WARNING', $data['data_quality']);
        $this->assertTrue($data['baseline']['data_quality']['has_untracked_cogs']);
        $this->assertEquals(100.0, $data['baseline']['data_quality']['untracked_items_percentage']);
    }

    /**
     * 12. Forbidden parameter injection rejected.
     */
    public function test_forbidden_parameters_rejected(): void
    {
        $this->seedStandardBaseline();

        $response = $this->actingAs($this->user)->postJson('/intelligence/scenario/simulate', [
            'scenario_type' => 'PRICE_CHANGE',
            'price_change_percent' => 10,
            'user_id' => 9999,
        ]);

        $response->assertStatus(422);
        $this->assertEquals('FORBIDDEN_PARAMETER', $response->json('code'));
    }

    /**
     * 13. Route aliases /copilot/intelligence/scenario work identically.
     */
    public function test_route_aliases_work_identically(): void
    {
        $this->seedStandardBaseline();

        $responseGet = $this->actingAs($this->user)->get('/copilot/intelligence/scenario');
        $responseGet->assertStatus(200);
        $responseGet->assertSee('Kaching Scenario Simulator');

        $responsePost = $this->actingAs($this->user)->postJson('/copilot/intelligence/scenario/simulate', [
            'scenario_type'        => 'PRICE_CHANGE',
            'price_change_percent' => 10,
            'outlet_id'            => $this->outlet->id,
        ]);
        $responsePost->assertStatus(200);
        $this->assertEquals('SUCCESS', $responsePost->json('status'));
    }
}
