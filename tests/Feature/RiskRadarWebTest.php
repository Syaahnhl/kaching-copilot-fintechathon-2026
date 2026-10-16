<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use App\Services\Financial\FinancialWarningService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RiskRadarWebTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;
    protected Outlet $outlet;
    protected Outlet $otherOutlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'testing.merchant@kaching.test',
            'role'  => 'owner',
        ]);

        $this->outlet = Outlet::create([
            'user_id'   => $this->user->id,
            'name'      => 'Outlet Utama Merchant A',
            'device_id' => 'DEV-RISK-RADAR-01',
        ]);

        $this->otherUser = User::factory()->create([
            'email' => 'other.merchant@kaching.test',
            'role'  => 'owner',
        ]);

        $this->otherOutlet = Outlet::create([
            'user_id'   => $this->otherUser->id,
            'name'      => 'Outlet Tenant Lain',
            'device_id' => 'DEV-OTHER-01',
        ]);
    }

    /**
     * Helper to insert standard transaction.
     */
    protected function insertTransaction(array $overrides = []): int
    {
        $defaults = [
            'user_id'                 => $this->user->id,
            'outlet_id'               => $this->outlet->id,
            'app_uuid'                => 'tx-radar-' . uniqid('', true),
            'official_invoice_number' => 'INV-' . uniqid(),
            'total_amount'            => 100000,
            'pay_amount'              => 100000,
            'payment_method'          => 'cash',
            'status'                  => 'Selesai',
            'created_at_device'       => Carbon::now()->subDays(2)->format('Y-m-d H:i:s'),
            'created_at'              => now(),
            'updated_at'              => now(),
        ];

        return DB::table('transactions')->insertGetId(array_merge($defaults, $overrides));
    }

    /**
     * Helper to insert transaction item.
     */
    protected function insertItem(int $txId, array $overrides = []): int
    {
        $defaults = [
            'transaction_id' => $txId,
            'menu_id'        => 101,
            'menu_name'      => 'Menu Sample',
            'qty'            => 1,
            'price'          => 100000,
            'cost_price'     => 50000,
            'created_at'     => now(),
            'updated_at'     => now(),
        ];

        return DB::table('transaction_items')->insertGetId(array_merge($defaults, $overrides));
    }

    /**
     * Helper to insert expense.
     */
    protected function insertExpense(array $overrides = []): int
    {
        $defaults = [
            'user_id'    => $this->user->id,
            'outlet_id'  => $this->outlet->id,
            'name'       => 'Operational Expense',
            'amount'     => 200000,
            'category'   => 'Operasional',
            'date'       => Carbon::now()->subDays(3)->format('Y-m-d'),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('expenses')->insertGetId(array_merge($defaults, $overrides));
    }

    /**
     * Helper to insert shift.
     */
    protected function insertShift(array $overrides = []): int
    {
        $defaults = [
            'user_id'             => $this->user->id,
            'outlet_id'           => $this->outlet->id,
            'cashier_name'        => 'Kasir A',
            'start_time'          => Carbon::now()->subDays(1)->setTime(8, 0, 0)->format('Y-m-d H:i:s'),
            'end_time'            => Carbon::now()->subDays(1)->setTime(17, 0, 0)->format('Y-m-d H:i:s'),
            'start_cash'          => 200000,
            'total_cash_sales'    => 1000000,
            'total_expense'       => 0,
            'cash_drop'           => 0,
            'total_cash_refunded' => 0,
            'expected_cash'       => 1200000,
            'end_cash'            => 1200000,
            'status'              => 'closed',
            'created_at'          => now(),
            'updated_at'          => now(),
        ];

        return DB::table('shifts')->insertGetId(array_merge($defaults, $overrides));
    }

    /**
     * 1. Unauthenticated request must redirect to login.
     */
    public function test_unauthenticated_request_redirects_to_login(): void
    {
        $response = $this->get(route('copilot.intelligence.risk-radar'));
        $response->assertStatus(302);
        $response->assertRedirect('/login');
    }

    /**
     * 2. Authorized page renders successfully with proper titles.
     */
    public function test_authorized_page_renders_successfully(): void
    {
        $response = $this->actingAs($this->user)->get(route('copilot.intelligence.risk-radar'));
        $response->assertStatus(200);
        $response->assertSee('Kaching Risk Radar');
        $response->assertSee('Pantau sinyal risiko operasional dan keuangan dari data Kaching.');
        $response->assertSee('Deterministik — Berbasis Data Operasional');
    }

    /**
     * 3. Authorized outlet renders outlet context correctly.
     */
    public function test_authorized_outlet_renders_context(): void
    {
        $response = $this->actingAs($this->user)->get(route('copilot.intelligence.risk-radar', [
            'outlet_id' => $this->outlet->id,
        ]));
        $response->assertStatus(200);
        $response->assertSee('Outlet Utama Merchant A');
    }

    /**
     * 4. Cross-tenant outlet access must be rejected with 403 (fail-closed, no merchant fallback).
     */
    public function test_cross_tenant_outlet_access_is_rejected(): void
    {
        // User other mencoba mengakses outlet milik User A pada web UI
        $responseUi = $this->actingAs($this->otherUser)->get(route('copilot.intelligence.risk-radar', [
            'outlet_id' => $this->outlet->id,
        ]));
        $responseUi->assertStatus(403);

        // User other mencoba mengakses outlet milik User A pada POST evaluate API
        $responseApi = $this->actingAs($this->otherUser)->postJson(route('copilot.intelligence.risk-radar.evaluate'), [
            'outlet_id' => $this->outlet->id,
        ]);
        $responseApi->assertStatus(403);
        $responseApi->assertJson([
            'status' => 'ERROR',
            'code'   => 'UNAUTHORIZED_OUTLET',
        ]);
    }

    /**
     * 5. Forbidden user_id parameter in POST/API endpoint is rejected with 422.
     */
    public function test_forbidden_user_id_parameter_is_rejected(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('copilot.intelligence.risk-radar.evaluate'), [
            'user_id' => 9999,
        ]);
        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'ERROR',
            'code'   => 'FORBIDDEN_PARAMETER',
        ]);
    }

    /**
     * 6. Warning payload rendered: all primary cards exist in UI.
     */
    public function test_warning_payload_rendered_with_primary_cards(): void
    {
        $response = $this->actingAs($this->user)->get(route('copilot.intelligence.risk-radar'));
        $response->assertStatus(200);

        $response->assertSee('Revenue Decline');
        $response->assertSee('Expense Spike');
        $response->assertSee('Margin Compression');
        $response->assertSee('Void / Refund Anomaly');
        $response->assertSee('Drawer Discrepancy');
        $response->assertSee('Financing Readiness');
    }

    /**
     * 7. Revenue Decline card rendering.
     */
    public function test_revenue_decline_rendering(): void
    {
        // Buat data transaksi baseline 10 hari lalu
        for ($i = 0; $i < 5; $i++) {
            $this->insertTransaction([
                'total_amount'      => 500000,
                'created_at_device' => Carbon::now()->subDays(10)->format('Y-m-d H:i:s'),
            ]);
        }

        $response = $this->actingAs($this->user)->get(route('copilot.intelligence.risk-radar'));
        $response->assertStatus(200);
        $response->assertSee('Revenue Decline');
        $response->assertSee('REVENUE_DECLINE');
    }

    /**
     * 8. Expense Spike card rendering.
     */
    public function test_expense_spike_rendering(): void
    {
        // Baseline 20 hari lalu
        $this->insertExpense([
            'amount' => 500000,
            'date'   => Carbon::now()->subDays(20)->format('Y-m-d'),
        ]);

        // Lonjakan 2 hari lalu
        $this->insertExpense([
            'amount' => 3000000,
            'date'   => Carbon::now()->subDays(2)->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->user)->get(route('copilot.intelligence.risk-radar'));
        $response->assertStatus(200);
        $response->assertSee('Expense Spike');
        $response->assertSee('EXPENSE_SPIKE');
    }

    /**
     * 9. Margin Compression INSUFFICIENT_DATA transparent rendering (not converted to NORMAL).
     */
    public function test_margin_compression_insufficient_data_rendering(): void
    {
        // Transaksi dengan 100% untracked COGS (cost_price = 0)
        $tx = $this->insertTransaction([
            'total_amount'      => 1000000,
            'created_at_device' => Carbon::now()->subDays(2)->format('Y-m-d H:i:s'),
        ]);
        $this->insertItem($tx, [
            'price'      => 1000000,
            'cost_price' => 0,
        ]);

        $response = $this->actingAs($this->user)->get(route('copilot.intelligence.risk-radar'));
        $response->assertStatus(200);

        $response->assertSee('Margin Compression');
        $response->assertSee('INSUFFICIENT_DATA');
        $response->assertSee('Data belum cukup untuk mengevaluasi indikator ini.');
        // Pastikan tidak dikonversi secara palsu menjadi NORMAL
        $response->assertDontSee('NORMAL / Margin Compression');
    }

    /**
     * 10. Drawer Discrepancy card rendering.
     */
    public function test_drawer_discrepancy_rendering(): void
    {
        // Shift dengan selisih kas fisik
        $this->insertShift([
            'expected_cash' => 2000000,
            'end_cash'      => 1800000, // Defisit Rp 200.000 (10%)
        ]);

        $response = $this->actingAs($this->user)->get(route('copilot.intelligence.risk-radar'));
        $response->assertStatus(200);
        $response->assertSee('Drawer Discrepancy');
        $response->assertSee('DRAWER_DISCREPANCY');
    }

    /**
     * 11. Cash Depletion explicitly SUSPENDED rendering with disclaimer.
     */
    public function test_cash_depletion_suspended_rendering(): void
    {
        $response = $this->actingAs($this->user)->get(route('copilot.intelligence.risk-radar'));
        $response->assertStatus(200);
        $response->assertSee('Cash Depletion (Suspended by Design)');
        $response->assertSee('SUSPENDED');
        $response->assertSee('Kalkulasi runway kas laci kasir ditangguhkan');
    }

    /**
     * 12. Financing Readiness disclaimer rendered.
     */
    public function test_financing_readiness_disclaimer_rendered(): void
    {
        $response = $this->actingAs($this->user)->get(route('copilot.intelligence.risk-radar'));
        $response->assertStatus(200);
        $response->assertSee('Financing Readiness');
        $response->assertSee('Catatan Kepatuhan Finansial:');
        $response->assertSee('Financing Readiness Index mengukur kelengkapan data operasional');
    }

    /**
     * 13. Deterministic service output: two consecutive API calls yield identical JSON.
     */
    public function test_deterministic_service_output(): void
    {
        $res1 = $this->actingAs($this->user)->postJson(route('copilot.intelligence.risk-radar.evaluate'), [
            'outlet_id' => $this->outlet->id,
        ]);
        $res1->assertStatus(200);

        $res2 = $this->actingAs($this->user)->postJson(route('copilot.intelligence.risk-radar.evaluate'), [
            'outlet_id' => $this->outlet->id,
        ]);
        $res2->assertStatus(200);

        $this->assertSame($res1->json('warnings'), $res2->json('warnings'));
        $this->assertSame($res1->json('financing_readiness.score'), $res2->json('financing_readiness.score'));
    }

    /**
     * 14. Zero database mutation during evaluation.
     */
    public function test_zero_database_mutation(): void
    {
        $this->insertTransaction();
        $this->insertExpense();
        $this->insertShift();

        $txCountBefore = DB::table('transactions')->count();
        $itemCountBefore = DB::table('transaction_items')->count();
        $expenseCountBefore = DB::table('expenses')->count();
        $shiftCountBefore = DB::table('shifts')->count();
        $outletCountBefore = DB::table('outlets')->count();
        $userCountBefore = DB::table('users')->count();

        // Panggil GET UI dan POST Evaluate
        $this->actingAs($this->user)->get(route('copilot.intelligence.risk-radar'));
        $this->actingAs($this->user)->postJson(route('copilot.intelligence.risk-radar.evaluate'));

        $this->assertEquals($txCountBefore, DB::table('transactions')->count());
        $this->assertEquals($itemCountBefore, DB::table('transaction_items')->count());
        $this->assertEquals($expenseCountBefore, DB::table('expenses')->count());
        $this->assertEquals($shiftCountBefore, DB::table('shifts')->count());
        $this->assertEquals($outletCountBefore, DB::table('outlets')->count());
        $this->assertEquals($userCountBefore, DB::table('users')->count());
    }

    /**
     * 15. Existing Intelligence Overview warning card works after method-name micro-fix.
     */
    public function test_existing_intelligence_overview_works_after_micro_fix(): void
    {
        $this->insertTransaction([
            'total_amount'      => 1000000,
            'created_at_device' => Carbon::now()->subDays(1)->format('Y-m-d H:i:s'),
        ]);

        $response = $this->actingAs($this->user)->get(route('copilot.intelligence'));
        $response->assertStatus(200);
        $response->assertSee('Financial Intelligence');
        $response->assertSee('Risk Radar');
        $response->assertSee('Scenario Simulator');
    }

    /**
     * 16. Route alias works identically.
     */
    public function test_route_alias_works(): void
    {
        $response = $this->actingAs($this->user)->get(route('copilot.intelligence.risk-radar.alias'));
        $response->assertStatus(200);
        $response->assertSee('Kaching Risk Radar');
    }
}
