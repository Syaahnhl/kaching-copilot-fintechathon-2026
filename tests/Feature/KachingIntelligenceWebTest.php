<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Outlet;
use App\Models\Menu;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Carbon\Carbon;

class KachingIntelligenceWebTest extends TestCase
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
            'email' => 'tuan_a@kaching.id',
            'role' => 'owner',
            'subscription_tier' => 'pro',
        ]);

        $this->userB = User::factory()->create([
            'email' => 'tuan_b@kaching.id',
            'role' => 'owner',
            'subscription_tier' => 'pro',
        ]);

        $this->outletA = Outlet::create([
            'user_id' => $this->userA->id,
            'name' => 'Outlet Tuan A',
            'address' => 'Jl. A No. 1',
        ]);

        $this->outletB = Outlet::create([
            'user_id' => $this->userB->id,
            'name' => 'Outlet Tuan B',
            'address' => 'Jl. B No. 2',
        ]);
    }

    /**
     * 1. Route & Auth Test: Tamu tanpa autentikasi diarahkan ke login (HTTP 302).
     */
    public function test_guest_redirected_to_login(): void
    {
        $resOverview = $this->get(route('copilot.intelligence'));
        $resOverview->assertStatus(302);
        $resOverview->assertRedirect(route('login'));

        $resMenu = $this->get(route('copilot.intelligence.menu'));
        $resMenu->assertStatus(302);
        $resMenu->assertRedirect(route('login'));
    }

    /**
     * 2. Authenticated user can access overview hub (HTTP 200).
     */
    public function test_authenticated_user_can_access_intelligence_overview(): void
    {
        $response = $this->actingAs($this->userA)->get(route('copilot.intelligence'));

        $response->assertStatus(200);
        $response->assertViewIs('backoffice.intelligence.overview');
        $response->assertViewHasAll([
            'menuSummary',
            'financialSummary',
            'rawMaterialSummary',
            'financingSummary',
        ]);

        // Verifikasi komponen header & kartu inti
        $response->assertSee('Kaching Intelligence');
        $response->assertSee('Insight bisnis dari data Kaching');
        $response->assertSee('Menu Intelligence');
        $response->assertSee('Financial Intelligence');
        $response->assertSee('Raw Material Intelligence');
        $response->assertSee('Financing Readiness');
        $response->assertSee('AI Copilot');
        $response->assertSee('Gunakan Kachi untuk memahami kondisi bisnis');
        $response->assertSee('Tanya Kachi');

        // Verifikasi disclaimer wajib financing readiness
        $response->assertSee('Financing Readiness merupakan evaluasi operasional kondisi dan kesiapan data finansial, bukan keputusan atau persetujuan kredit.');
    }

    /**
     * 3. Menu Intelligence Detail: Default period adalah 30 Hari.
     */
    public function test_menu_detail_default_period_is_30_days(): void
    {
        $response = $this->actingAs($this->userA)->get(route('copilot.intelligence.menu'));

        $response->assertStatus(200);
        $response->assertViewIs('backoffice.intelligence.menu_detail');
        $response->assertViewHas('period', '30days');
        $response->assertSee('30 Hari (Default)');
        $response->assertSee('Analisis BCG Matrix dan SAW');
    }

    /**
     * 4. Menu Intelligence Detail: Periode valid 7 Hari, 90 Hari, dan Tahun Ini.
     */
    public function test_menu_detail_valid_periods(): void
    {
        // 7 Hari
        $res7 = $this->actingAs($this->userA)->get(route('copilot.intelligence.menu', ['period' => '7days']));
        $res7->assertStatus(200);
        $res7->assertViewHas('period', '7days');

        // 90 Hari
        $res90 = $this->actingAs($this->userA)->get(route('copilot.intelligence.menu', ['period' => '90days']));
        $res90->assertStatus(200);
        $res90->assertViewHas('period', '90days');

        // Tahun Ini
        $resYear = $this->actingAs($this->userA)->get(route('copilot.intelligence.menu', ['period' => 'year']));
        $resYear->assertStatus(200);
        $resYear->assertViewHas('period', 'year');
    }

    /**
     * 5. Menu Intelligence Detail: Hari Ini dan Shift TIDAK TERSEDIA & DITOLAK.
     */
    public function test_menu_detail_rejects_today_and_shift_periods(): void
    {
        // Request period 'today' -> fallback ke 30days dan invalidPeriodRejected = true
        $resToday = $this->actingAs($this->userA)->get(route('copilot.intelligence.menu', ['period' => 'today']));
        $resToday->assertStatus(200);
        $resToday->assertViewHas('period', '30days');
        $resToday->assertViewHas('invalidPeriodRejected', true);
        $resToday->assertSee("Filter 'Hari Ini' atau 'Shift' Tidak Didukung", false);

        // Request period 'shift' -> fallback ke 30days dan invalidPeriodRejected = true
        $resShift = $this->actingAs($this->userA)->get(route('copilot.intelligence.menu', ['period' => 'shift']));
        $resShift->assertStatus(200);
        $resShift->assertViewHas('period', '30days');
        $resShift->assertViewHas('invalidPeriodRejected', true);
        $resShift->assertSee("Filter 'Hari Ini' atau 'Shift' Tidak Didukung", false);
    }

    /**
     * 6. Notice edukatif minimal 7 hari data transaksi selalu tampil.
     */
    public function test_menu_detail_shows_notice_minimal_7_days(): void
    {
        $response = $this->actingAs($this->userA)->get(route('copilot.intelligence.menu'));

        $response->assertStatus(200);
        $response->assertSee('Analisis menu membutuhkan minimal 7 hari data transaksi.');
    }

    /**
     * 7. Data states: Insufficient data / empty state jika belum ada data transaksi.
     */
    public function test_insufficient_data_state_rendered_when_no_transactions(): void
    {
        $response = $this->actingAs($this->userA)->get(route('copilot.intelligence.menu'));

        $response->assertStatus(200);
        $response->assertSee('Data Transaksi Belum Cukup');
        $response->assertSee('Data belum cukup untuk menghasilkan analisis. Analisis menu membutuhkan minimal 7 hari data transaksi.');
    }

    /**
     * 8. BCG & SAW Data Rendering when transactions exist.
     */
    public function test_bcg_and_saw_rendered_with_transaction_data(): void
    {
        // Buat menu untuk userA dengan category
        $menu1 = Menu::create([
            'user_id' => $this->userA->id,
            'name' => 'Kopi Susu Gula Aren',
            'price' => 20000,
            'cost_price' => 8000,
            'category' => 'Minuman',
        ]);

        $menu2 = Menu::create([
            'user_id' => $this->userA->id,
            'name' => 'Roti Bakar Coklat',
            'price' => 15000,
            'cost_price' => 5000,
            'category' => 'Makanan',
        ]);

        // Buat transaksi valid dalam 30 hari terakhir
        $trx = Transaction::create([
            'app_uuid' => (string) Str::uuid(),
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA->id,
            'invoice_number' => 'INV-TEST-001',
            'total_amount' => 55000,
            'payment_method' => 'cash',
            'status' => 'selesai',
            'created_at_device' => Carbon::now('Asia/Jakarta')->subDays(2)->toDateTimeString(),
        ]);

        TransactionItem::create([
            'transaction_id' => $trx->id,
            'menu_id' => $menu1->id,
            'menu_name' => $menu1->name,
            'qty' => 2,
            'price' => 20000,
        ]);

        TransactionItem::create([
            'transaction_id' => $trx->id,
            'menu_id' => $menu2->id,
            'menu_name' => $menu2->name,
            'qty' => 1,
            'price' => 15000,
        ]);

        $response = $this->actingAs($this->userA)->get(route('copilot.intelligence.menu'));

        $response->assertStatus(200);
        $response->assertSee('BCG Matrix');
        $response->assertSee('SAW Recommendations');
        $response->assertSee('Kopi Susu Gula Aren');
        $response->assertSee('Roti Bakar Coklat');
    }

    /**
     * 9. Tenant Isolation: Menu dan transaksi User B TIDAK bocor ke User A.
     */
    public function test_tenant_isolation_guaranteed(): void
    {
        // Menu milik User B
        $menuSecretB = Menu::create([
            'user_id' => $this->userB->id,
            'name' => 'Menu Rahasia User B',
            'price' => 50000,
            'cost_price' => 20000,
            'category' => 'Makanan',
        ]);

        $trxB = Transaction::create([
            'app_uuid' => (string) Str::uuid(),
            'user_id' => $this->userB->id,
            'outlet_id' => $this->outletB->id,
            'invoice_number' => 'INV-USERB-001',
            'total_amount' => 50000,
            'payment_method' => 'cash',
            'status' => 'selesai',
            'created_at_device' => Carbon::now('Asia/Jakarta')->subDays(2)->toDateTimeString(),
        ]);

        TransactionItem::create([
            'transaction_id' => $trxB->id,
            'menu_id' => $menuSecretB->id,
            'menu_name' => $menuSecretB->name,
            'qty' => 1,
            'price' => 50000,
        ]);

        // Akses sebagai User A
        $responseA = $this->actingAs($this->userA)->get(route('copilot.intelligence.menu'));

        $responseA->assertStatus(200);
        // Pastikan nama menu User B TIDAK terlihat di User A
        $responseA->assertDontSee('Menu Rahasia User B');

        // Akses sebagai User B
        $responseB = $this->actingAs($this->userB)->get(route('copilot.intelligence.menu'));
        $responseB->assertStatus(200);
        $responseB->assertSee('Menu Rahasia User B');
    }

    /**
     * 10. Regression Test: Existing Reports pages tetap berfungsi normal.
     */
    public function test_existing_reports_pages_no_regression(): void
    {
        // Laba Rugi
        $resReports = $this->actingAs($this->userA)->get(route('reports.index'));
        $this->assertContains($resReports->status(), [200, 302]);

        // Analisis Menu (DSS) existing
        $resDss = $this->actingAs($this->userA)->get(route('reports.menu-analysis'));
        $this->assertContains($resDss->status(), [200, 302]);
    }
}
