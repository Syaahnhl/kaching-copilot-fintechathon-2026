<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMetricsOwnerCalendarFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Outlet $outletA;
    protected Outlet $outletB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => 'owner',
            'account_status' => 'active',
        ]);

        $this->outletA = Outlet::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Outlet Jakarta (WIB)',
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->outletB = Outlet::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Outlet Bali (WITA)',
            'timezone' => 'Asia/Makassar',
        ]);
    }

    /**
     * Bukti 1: Tidak ada shift sama sekali -> Omzet kalender tetap muncul.
     */
    public function test_no_active_shift_returns_calendar_day_omset()
    {
        // Buat transaksi hari ini tanpa shift
        Transaction::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outletA->id,
            'app_uuid' => 'tx-no-shift-01',
            'total_amount' => 50000,
            'pay_amount' => 50000,
            'status' => 'Selesai',
            'order_type' => 'Dine In',
            'payment_method' => 'cash',
            'cashier_name' => 'Kasir Offline',
            'created_at_device' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
            'created_at' => Carbon::now('UTC'),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('Outlet-ID', $this->outletA->id)
            ->getJson('/api/transactions/dashboard-metrics');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'total_omset' => 50000,
                    'gross_sales' => 50000,
                    'paid_amount' => 50000,
                    'total_count' => 1,
                    'scope' => 'calendar_day',
                    'shift_id' => null,
                ]
            ]);
    }

    /**
     * Bukti 2: Semua shift sudah CLOSED (malam hari) -> Omzet kalender tetap muncul.
     */
    public function test_all_shifts_closed_returns_calendar_day_omset()
    {
        // Buat shift yang sudah ditutup 1 jam lalu
        $closedShift = new Shift();
        $shiftData = [
            'user_id' => $this->user->id,
            'outlet_id' => $this->outletA->id,
            'app_uuid' => 'shift-closed-uuid',
            'cashier_name' => 'Kasir Pagi',
            'start_time' => Carbon::now('Asia/Jakarta')->subHours(8)->format('Y-m-d H:i:s'),
            'end_time' => Carbon::now('Asia/Jakarta')->subHours(1)->format('Y-m-d H:i:s'),
            'status' => 'closed',
        ];
        foreach ($shiftData as $key => $val) {
            $closedShift->{$key} = $val;
        }
        $closedShift->save();

        Transaction::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outletA->id,
            'app_uuid' => 'tx-closed-shift-01',
            'total_amount' => 75000,
            'pay_amount' => 100000, // Pay 100k for 75k bill
            'status' => 'Selesai',
            'order_type' => 'Dine In',
            'payment_method' => 'cash',
            'cashier_name' => 'Kasir Pagi',
            'created_at_device' => Carbon::now('Asia/Jakarta')->subHours(4)->format('Y-m-d H:i:s'),
            'created_at' => Carbon::now('UTC')->subHours(4),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('Outlet-ID', $this->outletA->id)
            ->getJson('/api/transactions/dashboard-metrics');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'total_omset' => 75000, // Clamped ke 75k, uang kembalian 25k tidak masuk omzet
                    'gross_sales' => 75000,
                    'paid_amount' => 75000,
                    'total_count' => 1,
                    'scope' => 'calendar_day',
                ]
            ]);
    }

    /**
     * Bukti 3: Isolasi Outlet -> Omzet Outlet A tidak bocor ke Outlet B.
     */
    public function test_outlet_isolation_strictly_enforced()
    {
        // Transaksi di Outlet A
        Transaction::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outletA->id,
            'app_uuid' => 'tx-outlet-a',
            'total_amount' => 120000,
            'pay_amount' => 120000,
            'status' => 'Selesai',
            'order_type' => 'Take Away',
            'payment_method' => 'cash',
            'created_at_device' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
            'created_at' => Carbon::now('UTC'),
        ]);

        // Transaksi di Outlet B
        Transaction::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outletB->id,
            'app_uuid' => 'tx-outlet-b',
            'total_amount' => 45000,
            'pay_amount' => 45000,
            'status' => 'Selesai',
            'order_type' => 'Dine In',
            'payment_method' => 'cash',
            'created_at_device' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
            'created_at' => Carbon::now('UTC'),
        ]);

        // Query Outlet A harus HANYA 120.000
        $responseA = $this->actingAs($this->user, 'sanctum')
            ->withHeader('Outlet-ID', $this->outletA->id)
            ->getJson('/api/transactions/dashboard-metrics');

        $responseA->assertStatus(200)
            ->assertJson([
                'data' => [
                    'total_omset' => 120000,
                    'total_count' => 1,
                ]
            ]);

        // Query Outlet B harus HANYA 45.000
        $responseB = $this->actingAs($this->user, 'sanctum')
            ->withHeader('Outlet-ID', $this->outletB->id)
            ->getJson('/api/transactions/dashboard-metrics');

        $responseB->assertStatus(200)
            ->assertJson([
                'data' => [
                    'total_omset' => 45000,
                    'total_count' => 1,
                ]
            ]);
    }

    /**
     * Bukti 4: Timezone boundary benar (WITA vs UTC).
     */
    public function test_timezone_boundary_uses_outlet_timezone()
    {
        // Transaksi kemarin di Bali (WITA): 23:30 WITA kemarin = 15:30 UTC kemarin
        $yesterdayWita = Carbon::yesterday('Asia/Makassar')->setHour(23)->setMinute(30);
        Transaction::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outletB->id,
            'app_uuid' => 'tx-yesterday-wita',
            'total_amount' => 99000,
            'pay_amount' => 99000,
            'status' => 'Selesai',
            'order_type' => 'Dine In',
            'payment_method' => 'cash',
            'created_at_device' => $yesterdayWita->copy()->timezone('UTC')->format('Y-m-d H:i:s'),
            'created_at' => $yesterdayWita->copy()->timezone('UTC'),
        ]);

        // Transaksi hari ini di Bali (WITA): 00:30 WITA hari ini = 16:30 UTC kemarin
        $todayEarlyWita = Carbon::today('Asia/Makassar')->setHour(0)->setMinute(30);
        Transaction::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outletB->id,
            'app_uuid' => 'tx-today-early-wita',
            'total_amount' => 35000,
            'pay_amount' => 35000,
            'status' => 'Selesai',
            'order_type' => 'Dine In',
            'payment_method' => 'cash',
            'created_at_device' => $todayEarlyWita->copy()->timezone('UTC')->format('Y-m-d H:i:s'),
            'created_at' => $todayEarlyWita->copy()->timezone('UTC'),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('Outlet-ID', $this->outletB->id)
            ->getJson('/api/transactions/dashboard-metrics');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'total_omset' => 35000, // Hanya transaksi 00:30 WITA hari ini yang terhitung
                    'total_count' => 1,
                    'timezone' => 'Asia/Makassar',
                ]
            ]);
    }
}
