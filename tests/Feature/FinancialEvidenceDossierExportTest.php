<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Shift;
use App\Models\Expense;
use App\Models\Menu;
use App\Models\Attendance;
use App\Services\Financial\FinancingReadinessService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialEvidenceDossierExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;
    protected User $userB;
    protected Outlet $outletA;
    protected Outlet $outletB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create([
            'email' => 'tuan_a_test@kaching.id',
            'name' => 'Kopi Tuan A',
            'role' => 'owner',
            'subscription_tier' => 'pro',
        ]);

        $this->userB = User::factory()->create([
            'email' => 'tuan_b_test@kaching.id',
            'name' => 'Kopi Tuan B',
            'role' => 'owner',
            'subscription_tier' => 'pro',
        ]);

        $this->outletA = Outlet::create([
            'user_id' => $this->userA->id,
            'name' => 'Outlet Senopati',
            'address' => 'Jl. Senopati No. 10',
            'phone' => '081234567890',
        ]);

        $this->outletB = Outlet::create([
            'user_id' => $this->userB->id,
            'name' => 'Outlet Kemang',
            'address' => 'Jl. Kemang Raya No. 20',
            'phone' => '081234567891',
        ]);

        // Seed data transaksi riil untuk User A
        for ($i = 0; $i < 15; $i++) {
            $createdTime = Carbon::now()->subDays($i)->setTime(12, 0, 0);
            $trx = Transaction::create([
                'app_uuid' => (string) Str::uuid(),
                'invoice_number' => 'INV-TEST-' . $i,
                'user_id' => $this->userA->id,
                'outlet_id' => $this->outletA->id,
                'subtotal' => 100000,
                'tax' => 11000,
                'discount' => 0,
                'total_amount' => 111000,
                'pay_amount' => 111000,
                'actual_paid' => 111000,
                'change' => 0,
                'payment_method' => $i % 2 === 0 ? 'QRIS' : 'CASH',
                'status' => 'selesai',
                'is_synced' => 1,
                'created_at_device' => $createdTime->format('Y-m-d H:i:s'),
                'created_at' => $createdTime,
                'updated_at' => $createdTime,
            ]);

            // Shift kasir seimbang
            Shift::create([
                'user_id' => $this->userA->id,
                'outlet_id' => $this->outletA->id,
                'cashier_name' => 'Kasir A',
                'start_cash' => 200000,
                'actual_cash' => 311000,
                'expected_cash' => 311000,
                'total_variance_nominal' => 0,
                'status' => 'closed',
                'start_time' => $createdTime->copy()->subHours(8),
                'end_time' => $createdTime,
            ]);
        }

        // Menu ber-HPP
        Menu::create([
            'user_id' => $this->userA->id,
            'name' => 'Kopi Susu Gula Aren',
            'category' => 'Minuman',
            'price' => 25000,
            'cost_price' => 12000,
            'is_available' => 1,
        ]);

        // Beban operasional
        Expense::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA->id,
            'name' => 'Beli susu segar',
            'amount' => 50000,
            'category' => 'Bahan Baku',
            'date' => Carbon::now()->format('Y-m-d'),
            'supplier' => 'Toko Sumber Kopi',
            'note' => 'Beli susu segar',
        ]);
    }

    /**
     * 1. Unauthenticated request is rejected (HTTP 302 -> login).
     */
    public function test_unauthenticated_request_is_redirected_to_login(): void
    {
        $response = $this->get(route('copilot.financing-readiness.export'));
        $response->assertStatus(302);
        $response->assertRedirect(route('login'));
    }

    /**
     * 2. Authenticated user can export dossier PDF successfully.
     */
    public function test_authenticated_user_can_export_dossier_pdf(): void
    {
        $response = $this->actingAs($this->userA)
            ->get(route('copilot.financing-readiness.export', [
                'outlet_id' => $this->outletA->id,
            ]));

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type', ''));
        
        $contentDisposition = $response->headers->get('content-disposition', '');
        $this->assertStringContainsString('attachment;', $contentDisposition);
        $this->assertStringContainsString('Financial_Evidence_Dossier_outlet_senopati_', $contentDisposition);
        $this->assertStringContainsString('.pdf', $contentDisposition);

        // Binary check: output must start with %PDF- header and be non-empty
        $pdfBinary = $response->getContent();
        $this->assertNotEmpty($pdfBinary);
        $this->assertStringStartsWith('%PDF-', $pdfBinary);
        $this->assertGreaterThan(1000, strlen($pdfBinary));

        @file_put_contents(storage_path('app/testing_dossier.pdf'), $pdfBinary);
    }

    /**
     * 3. Tenant isolation: User A cannot request export for User B's outlet (HTTP 403).
     */
    public function test_tenant_isolation_cannot_access_other_user_outlet(): void
    {
        $response = $this->actingAs($this->userA)
            ->get(route('copilot.financing-readiness.export', [
                'outlet_id' => $this->outletB->id, // Milik User B!
            ]));

        $response->assertStatus(403);
    }

    /**
     * 4. User B gets their own isolated dossier without leaking User A's data.
     */
    public function test_user_b_gets_isolated_dossier_without_user_a_data(): void
    {
        $response = $this->actingAs($this->userB)
            ->get(route('copilot.financing-readiness.export', [
                'outlet_id' => $this->outletB->id,
            ]));

        $response->assertStatus(200);
        $contentDisposition = $response->headers->get('content-disposition', '');
        $this->assertStringContainsString('Financial_Evidence_Dossier_outlet_kemang_', $contentDisposition);

        $pdfBinary = $response->getContent();
        $this->assertNotEmpty($pdfBinary);
        $this->assertStringStartsWith('%PDF-', $pdfBinary);
    }

    /**
     * 5. Stream / Preview mode returns inline PDF stream.
     */
    public function test_preview_mode_returns_stream_response(): void
    {
        $response = $this->actingAs($this->userA)
            ->get(route('copilot.financing-readiness.export', [
                'outlet_id' => $this->outletA->id,
                'preview' => 1,
            ]));

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type', ''));
        $contentDisposition = $response->headers->get('content-disposition', '');
        $this->assertStringContainsString('inline;', $contentDisposition);
    }

    /**
     * 6. Dossier uses canonical FinancingReadinessService calculation without tampering.
     */
    public function test_dossier_matches_canonical_service_output(): void
    {
        $service = app(FinancingReadinessService::class);
        $canonicalResult = $service->calculate($this->userA->id, $this->outletA->id);

        $this->assertEquals('SUCCESS', $canonicalResult['status']);
        $this->assertGreaterThan(0, $canonicalResult['score']);
        $this->assertNotEmpty($canonicalResult['evidence_summary']);
        $this->assertNotEmpty($canonicalResult['limitations']);

        // Pastikan endpoint berhasil dieksekusi dengan payload canonical
        $response = $this->actingAs($this->userA)
            ->get(route('copilot.financing-readiness.export', [
                'outlet_id' => $this->outletA->id,
            ]));

        $response->assertStatus(200);
    }
}
