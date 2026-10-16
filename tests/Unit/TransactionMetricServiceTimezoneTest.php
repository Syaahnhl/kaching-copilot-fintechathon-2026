<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\Transaction;
use App\Services\TransactionMetricService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class TransactionMetricServiceTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => 'owner',
            'account_status' => 'active'
        ]);

        $this->outlet = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Outlet Bukit Padangan',
            'address' => 'Jl. Bukit No. 1',
            'phone' => '08123456789',
            'is_main' => true,
            'timezone' => 'Asia/Jakarta'
        ]);
    }

    /**
     * Test Case A & D: Shift boundary in UTC matches created_at_device in UTC
     * Shift 559 boundary: 04:52:02 UTC (11:52:02 WIB)
     * - Trx sebelum boundary (04:50:00 UTC) -> EXCLUDED
     * - Trx 05:29:48 UTC (12:29 WIB) -> INCLUDED
     * - Trx 11:51:29 UTC (18:51 WIB) -> INCLUDED
     * - Trx 11:53:54 UTC (18:53 WIB) -> INCLUDED
     */
    public function test_shift_boundary_includes_utc_transactions_across_wib_day()
    {
        // Shift dibuka jam 11:52:02 WIB = 04:52:02 UTC
        $shiftStartTime = '2026-09-10 04:52:02';
        $shift = Shift::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'cashier_name' => 'Mila',
            'start_time' => $shiftStartTime,
            'start_cash' => 100000,
            'status' => 'open'
        ]);

        // Case C: Transaksi sebelum shift (04:50:00 UTC = 11:50 WIB) -> Harus EXCLUDED
        Transaction::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => null, // legacy transaction with null shift_id
            'app_uuid' => 'trx-before-shift',
            'total_amount' => 10000,
            'pay_amount' => 10000,
            'payment_method' => 'Cash',
            'status' => 'Selesai',
            'created_at_device' => '2026-09-10 04:50:00',
            'created_at' => '2026-09-10 04:50:00',
        ]);

        // Case A1: Trx 05:29:48 UTC (12:29 WIB) -> INCLUDED
        Transaction::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => null,
            'app_uuid' => 'trx-10272',
            'total_amount' => 50000,
            'pay_amount' => 50000,
            'payment_method' => 'Cash',
            'status' => 'Selesai',
            'created_at_device' => '2026-09-10 05:29:48',
            'created_at' => '2026-09-10 05:29:48',
        ]);

        // Case A2: Trx 11:51:29 UTC (18:51 WIB) -> INCLUDED (sebelumnya drop karena < 11:52:02 WIB string)
        Transaction::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => null,
            'app_uuid' => 'trx-10309',
            'total_amount' => 30000,
            'pay_amount' => 30000,
            'payment_method' => 'Cash',
            'status' => 'Selesai',
            'created_at_device' => '2026-09-10 11:51:29',
            'created_at' => '2026-09-10 11:51:29',
        ]);

        // Case A3 & B: Trx 11:53:54 UTC (18:53 WIB) -> INCLUDED
        Transaction::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => null,
            'app_uuid' => 'trx-10310',
            'total_amount' => 5000,
            'pay_amount' => 5000,
            'payment_method' => 'Cash',
            'status' => 'Selesai',
            'created_at_device' => '2026-09-10 11:53:54',
            'created_at' => '2026-09-10 11:53:54',
        ]);

        // Case B2: Trx 12:02:57 UTC (19:02 WIB) -> INCLUDED (Piutang / Bayar Nanti)
        Transaction::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => null,
            'app_uuid' => 'trx-10312',
            'total_amount' => 42000,
            'pay_amount' => 0,
            'payment_method' => 'Bayar Nanti',
            'status' => 'Proses',
            'created_at_device' => '2026-09-10 12:02:57',
            'created_at' => '2026-09-10 12:02:57',
        ]);

        // Panggil TransactionMetricService
        $metrics = TransactionMetricService::getShiftMetrics($this->user->id, $shift->id, $this->outlet->id);

        // 4 transaksi valid dalam shift: 50.000 + 30.000 + 5.000 + 42.000 = Gross 127.000
        // Paid amount (cash basis): 50.000 + 30.000 + 5.000 = 85.000
        // Outstanding (piutang): 42.000
        $this->assertEquals(4, $metrics['total_count'], 'Total transaksi harus 4 (trx sebelum boundary ter-exclude)');
        $this->assertEquals(127000, $metrics['gross_sales'], 'Gross sales harus 127.000');
        $this->assertEquals(85000, $metrics['total_omset'], 'Total omset cash basis harus 85.000');
        $this->assertEquals(85000, $metrics['paid_amount'], 'Paid amount harus 85.000');
        $this->assertEquals(42000, $metrics['total_piutang'], 'Total piutang harus 42.000');
    }

    /**
     * Test Case C: Closed shift boundary strictly excludes transactions outside [start_time, end_time]
     */
    public function test_closed_shift_strictly_bounds_utc_window()
    {
        $shift = Shift::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'cashier_name' => 'Mila',
            'start_time' => '2026-09-10 04:00:00',
            'end_time' => '2026-09-10 10:00:00',
            'start_cash' => 100000,
            'status' => 'closed'
        ]);

        // Inside window (06:00:00 UTC)
        Transaction::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => null,
            'app_uuid' => 'trx-inside-window',
            'total_amount' => 25000,
            'pay_amount' => 25000,
            'payment_method' => 'Cash',
            'status' => 'Selesai',
            'created_at_device' => '2026-09-10 06:00:00',
            'created_at' => '2026-09-10 06:00:00',
        ]);

        // After window (10:30:00 UTC) -> EXCLUDED
        Transaction::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => null,
            'app_uuid' => 'trx-after-window',
            'total_amount' => 25000,
            'pay_amount' => 25000,
            'payment_method' => 'Cash',
            'status' => 'Selesai',
            'created_at_device' => '2026-09-10 10:30:00',
            'created_at' => '2026-09-10 10:30:00',
        ]);

        $metrics = TransactionMetricService::getShiftMetrics($this->user->id, $shift->id, $this->outlet->id);

        $this->assertEquals(1, $metrics['total_count']);
        $this->assertEquals(25000, $metrics['gross_sales']);
        $this->assertEquals(25000, $metrics['total_omset']);
    }
}
