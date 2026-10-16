<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Outlet;
use App\Models\Menu;
use App\Models\Item;
use App\Models\Transaction;
use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RawMaterialIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected User $testingUser;
    protected User $regularUser;
    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Akun testing eksplisit
        $this->testingUser = User::factory()->create([
            'email' => 'testing@gmail.com',
            'role' => 'owner',
            'subscription_tier' => 'pro',
        ]);

        // 2. Akun merchant reguler (bukan testing)
        $this->regularUser = User::factory()->create([
            'email' => 'warung_berkah@gmail.com',
            'role' => 'owner',
            'subscription_tier' => 'pro',
        ]);

        $this->outlet = Outlet::create([
            'user_id' => $this->testingUser->id,
            'name' => 'Outlet Uji Rasa',
            'address' => 'Jl. Pengujian No. 1',
        ]);
    }

    /**
     * Akun testing terotorisasi dapat mengakses halaman RMI (HTTP 200).
     */
    public function test_testing_account_by_email_can_access_page(): void
    {
        $response = $this->actingAs($this->testingUser)
            ->get(route('copilot.raw-material-intelligence'));

        $response->assertStatus(200);
        $response->assertViewIs('backoffice.raw_material_intelligence');
        $response->assertViewHasAll([
            'commodities',
            'trendData',
            'marketSignals',
            'forecastPlaceholder',
            'businessImpact'
        ]);

        // Verifikasi keberadaan komponen inti UI & penanda transparansi data demo
        $response->assertSee('TESTING PREVIEW');
        $response->assertSee('Kaching Raw Material Intelligence');
        $response->assertSee('Cabai Rawit Merah');
        $response->assertSee('Bawang Merah Brebes');
        $response->assertSee('Daging Ayam Broiler');
        $response->assertSee('Pergerakan Harga Pasar');
        $response->assertSee('Forecast engine belum terhubung');
        $response->assertSee('Sinyal Pasar Terkini');
        $response->assertSee('Dampak ke Bisnis');
        $response->assertSee('Analisis Dampak Lebih Lanjut dengan Kachi');
    }

    /**
     * Akun testing via email ber-pola 'test' atau 'testing' diizinkan masuk.
     */
    public function test_testing_account_with_test_in_email_pattern_allowed(): void
    {
        $qaUser = User::factory()->create([
            'email' => 'fintech-test-auditor@example.com',
            'role' => 'owner',
        ]);

        $response = $this->actingAs($qaUser)
            ->get(route('copilot.raw-material-intelligence'));

        $response->assertStatus(200);
    }

    /**
     * Akun yang ditunjuk melalui env RMI_DEMO_USER_ID diizinkan masuk.
     */
    public function test_testing_account_by_env_demo_user_id_allowed(): void
    {
        $specialDemoUser = User::factory()->create([
            'email' => 'special_vip_merchant@gmail.com',
            'role' => 'owner',
        ]);

        putenv("RMI_DEMO_USER_ID={$specialDemoUser->id}");
        $_ENV['RMI_DEMO_USER_ID'] = (string)$specialDemoUser->id;

        try {
            $response = $this->actingAs($specialDemoUser)
                ->get(route('copilot.raw-material-intelligence'));

            $response->assertStatus(200);
        } finally {
            putenv('RMI_DEMO_USER_ID');
            unset($_ENV['RMI_DEMO_USER_ID']);
        }
    }

    /**
     * Akun non-testing dilarang mengakses halaman (HTTP 403 Forbidden).
     */
    public function test_non_testing_account_is_forbidden(): void
    {
        $response = $this->actingAs($this->regularUser)
            ->get(route('copilot.raw-material-intelligence'));

        $response->assertStatus(403);
    }

    /**
     * Tamu tanpa autentikasi dialihkan ke halaman login (HTTP 302).
     */
    public function test_unauthenticated_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('copilot.raw-material-intelligence'));

        $response->assertRedirect(route('login'));
    }

    /**
     * Memastikan akses halaman murni READ-ONLY dan tidak memutasi database.
     */
    public function test_page_access_does_not_mutate_financial_or_menu_data(): void
    {
        $menu = Menu::create([
            'user_id' => $this->testingUser->id,
            'name' => 'Ayam Geprek Cabai Super',
            'category' => 'Makanan Utama',
            'price' => 25000,
            'cost_price' => 14000,
        ]);

        $item = Item::create([
            'outlet_id' => $this->outlet->id,
            'name' => 'Cabai Rawit Merah Segar',
            'type' => 'raw_material',
            'base_uom' => 'kg',
            'cost_price' => 60000,
            'sales_price' => 0,
            'is_active' => true,
        ]);

        $initialMenuCount = Menu::count();
        $initialItemCount = Item::count();
        $initialTransactionCount = Transaction::count();
        $initialExpenseCount = Expense::count();

        // Akses halaman RMI
        $response = $this->actingAs($this->testingUser)
            ->get(route('copilot.raw-material-intelligence'));

        $response->assertStatus(200);

        // Verifikasi tidak ada perubahan hitungan baris pada DB
        $this->assertSame($initialMenuCount, Menu::count(), 'Menu table count must remain unmodified.');
        $this->assertSame($initialItemCount, Item::count(), 'Item table count must remain unmodified.');
        $this->assertSame($initialTransactionCount, Transaction::count(), 'Transaction count must remain unmodified.');
        $this->assertSame($initialExpenseCount, Expense::count(), 'Expense count must remain unmodified.');

        // Verifikasi HPP dan harga jual menu tidak terdistorsi
        $freshMenu = $menu->fresh();
        $this->assertEquals(25000, $freshMenu->price);
        $this->assertEquals(14000, $freshMenu->cost_price);
    }
}
