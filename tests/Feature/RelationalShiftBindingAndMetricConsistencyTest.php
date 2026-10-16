<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\Menu;
use App\Models\Transaction;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

class RelationalShiftBindingAndMetricConsistencyTest extends TestCase
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
            'name' => 'Outlet Utama',
            'address' => 'Jl. Bisnis No. 1',
            'phone' => '08123456789',
            'is_main' => true,
            'timezone' => 'Asia/Jakarta'
        ]);
    }

    /**
     * Test 1: Transaksi store menyertakan shift_id yang valid
     */
    public function test_transaction_store_binds_valid_shift_id()
    {
        $shift = Shift::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'cashier_name' => 'Kasir Relasional',
            'start_time' => Carbon::now()->subHours(2),
            'start_cash' => 100000,
            'status' => 'open'
        ]);

        $menu = Menu::create([
            'user_id' => $this->user->id,
            'name' => 'Kopi Relasional',
            'category' => 'Minuman',
            'price' => 25000,
            'stock' => 100,
            'is_active' => true
        ]);

        $payload = [
            'app_uuid' => 'trx-relational-valid-001',
            'outlet_id' => $this->outlet->id,
            'shift_id' => $shift->id,
            'total_amount' => 50000,
            'pay_amount' => 50000,
            'payment_method' => 'Cash',
            'status' => 'Selesai',
            'created_at_device' => Carbon::now()->format('Y-m-d H:i:s'),
            'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'items' => [
                [
                    'menu_id' => $menu->id,
                    'name' => 'Kopi Relasional',
                    'qty' => 2,
                    'price' => 25000
                ]
            ]
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/transactions', $payload);

        $response->assertStatus(201);

        $this->assertDatabaseHas('transactions', [
            'app_uuid' => 'trx-relational-valid-001',
            'shift_id' => $shift->id,
            'outlet_id' => $this->outlet->id,
            'total_amount' => 50000,
            'pay_amount' => 50000
        ]);
    }

    /**
     * Test 2: Shift ID tidak ditemukan (SHIFT_NOT_FOUND)
     * Transaksi tetap tersimpan dengan shift_id NULL dan dicatat di AuditLog
     */
    public function test_transaction_with_non_existent_shift_id_sets_null_and_logs_audit()
    {
        $menu = Menu::create([
            'user_id' => $this->user->id,
            'name' => 'Kopi Relasional',
            'category' => 'Minuman',
            'price' => 25000,
            'stock' => 100,
            'is_active' => true
        ]);

        $payload = [
            'app_uuid' => 'trx-invalid-shift-001',
            'outlet_id' => $this->outlet->id,
            'shift_id' => 999999, // Non-existent
            'total_amount' => 25000,
            'pay_amount' => 25000,
            'payment_method' => 'Cash',
            'status' => 'Selesai',
            'created_at_device' => Carbon::now()->format('Y-m-d H:i:s'),
            'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'items' => [
                [
                    'menu_id' => $menu->id,
                    'name' => 'Kopi Relasional',
                    'qty' => 1,
                    'price' => 25000
                ]
            ]
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/transactions', $payload);

        $response->assertStatus(201);

        $this->assertDatabaseHas('transactions', [
            'app_uuid' => 'trx-invalid-shift-001',
            'shift_id' => null,
            'outlet_id' => $this->outlet->id
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'TRANSACTION_SHIFT_VALIDATION_FAILED',
            'target_type' => Transaction::class,
            'user_id' => $this->user->id
        ]);
    }

    /**
     * Test 3: Cross-User Shift ID (USER_MISMATCH)
     * Transaksi tetap tersimpan dengan shift_id NULL dan dicatat di AuditLog
     */
    public function test_transaction_with_cross_user_shift_id_sets_null_and_logs_audit()
    {
        $otherUser = User::factory()->create();
        $otherOutlet = Outlet::create([
            'user_id' => $otherUser->id,
            'name' => 'Outlet Other User',
            'address' => 'Jl. Lain',
            'phone' => '081111111',
            'is_main' => true
        ]);

        $otherShift = Shift::create([
            'user_id' => $otherUser->id,
            'outlet_id' => $otherOutlet->id,
            'cashier_name' => 'Kasir Other',
            'start_time' => Carbon::now()->subHours(1),
            'start_cash' => 50000,
            'status' => 'open'
        ]);

        $menu = Menu::create([
            'user_id' => $this->user->id,
            'name' => 'Kopi Relasional',
            'category' => 'Minuman',
            'price' => 25000,
            'stock' => 100,
            'is_active' => true
        ]);

        $payload = [
            'app_uuid' => 'trx-cross-user-shift-001',
            'outlet_id' => $this->outlet->id,
            'shift_id' => $otherShift->id, // Milik user lain
            'total_amount' => 25000,
            'pay_amount' => 25000,
            'payment_method' => 'Cash',
            'status' => 'Selesai',
            'created_at_device' => Carbon::now()->format('Y-m-d H:i:s'),
            'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'items' => [
                [
                    'menu_id' => $menu->id,
                    'name' => 'Kopi Relasional',
                    'qty' => 1,
                    'price' => 25000
                ]
            ]
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/transactions', $payload);

        $response->assertStatus(201);

        $this->assertDatabaseHas('transactions', [
            'app_uuid' => 'trx-cross-user-shift-001',
            'shift_id' => null,
            'outlet_id' => $this->outlet->id
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'TRANSACTION_SHIFT_VALIDATION_FAILED',
            'target_type' => Transaction::class,
            'user_id' => $this->user->id
        ]);
    }

    /**
     * Test 4: Outlet Mismatch Shift ID (OUTLET_MISMATCH)
     * Transaksi tetap tersimpan dengan shift_id NULL dan dicatat di AuditLog
     */
    public function test_transaction_with_outlet_mismatch_shift_id_sets_null_and_logs_audit()
    {
        $outlet2 = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Outlet Kedua',
            'address' => 'Jl. Cabang 2',
            'phone' => '082222222',
            'is_main' => false
        ]);

        $shiftOutlet2 = Shift::create([
            'user_id' => $this->user->id,
            'outlet_id' => $outlet2->id,
            'cashier_name' => 'Kasir Cabang 2',
            'start_time' => Carbon::now()->subHours(1),
            'start_cash' => 50000,
            'status' => 'open'
        ]);

        $menu = Menu::create([
            'user_id' => $this->user->id,
            'name' => 'Kopi Relasional',
            'category' => 'Minuman',
            'price' => 20000,
            'stock' => 100,
            'is_active' => true
        ]);

        $payload = [
            'app_uuid' => 'trx-outlet-mismatch-001',
            'outlet_id' => $this->outlet->id, // Transaksi di outlet 1
            'shift_id' => $shiftOutlet2->id,   // Tapi shift di outlet 2
            'total_amount' => 20000,
            'pay_amount' => 20000,
            'payment_method' => 'Cash',
            'status' => 'Selesai',
            'created_at_device' => Carbon::now()->format('Y-m-d H:i:s'),
            'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'items' => [
                [
                    'menu_id' => $menu->id,
                    'name' => 'Kopi Relasional',
                    'qty' => 1,
                    'price' => 20000
                ]
            ]
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/transactions', $payload);

        $response->assertStatus(201);

        $this->assertDatabaseHas('transactions', [
            'app_uuid' => 'trx-outlet-mismatch-001',
            'shift_id' => null,
            'outlet_id' => $this->outlet->id
        ]);
    }

    /**
     * Test 5: Bulk Sync offline transactions bind shift_id correctly and idempotently
     */
    public function test_bulk_sync_binds_shift_id_and_preserves_idempotency()
    {
        $shift = Shift::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'cashier_name' => 'Kasir Offline',
            'start_time' => Carbon::now()->subHours(5),
            'start_cash' => 100000,
            'status' => 'open'
        ]);

        $menu = Menu::create([
            'user_id' => $this->user->id,
            'name' => 'Offline Tea',
            'category' => 'Minuman',
            'price' => 15000,
            'stock' => 100,
            'is_active' => true
        ]);

        $job = new \App\Jobs\ProcessBulkTransactions([
            [
                'app_uuid' => 'bulk-trx-shift-001',
                'outlet_id' => $this->outlet->id,
                'shift_id' => $shift->id,
                'total_amount' => 30000,
                'pay_amount' => 30000,
                'payment_method' => 'Cash',
                'status' => 'Selesai',
                'created_at_device' => Carbon::now()->subHours(4)->toIso8601String(),
                'items' => [
                    [
                        'menu_id' => $menu->id,
                        'name' => 'Offline Tea',
                        'qty' => 2,
                        'price' => 15000
                    ]
                ]
            ]
        ], $this->user->id, '1.4.0');

        $job->handle();

        $this->assertDatabaseHas('transactions', [
            'app_uuid' => 'bulk-trx-shift-001',
            'shift_id' => $shift->id,
            'outlet_id' => $this->outlet->id
        ]);
    }

    /**
     * Test 6: Cross-day offline transaction isolation by created_at_device
     */
    public function test_cross_day_offline_transaction_isolated_by_created_at_device()
    {
        $yesterday = Carbon::yesterday();

        $shiftYesterday = Shift::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'cashier_name' => 'Kasir Kemarin',
            'start_time' => $yesterday->copy()->setTime(8, 0, 0),
            'end_time' => $yesterday->copy()->setTime(16, 0, 0),
            'start_cash' => 100000,
            'status' => 'closed'
        ]);

        $menu = Menu::create([
            'user_id' => $this->user->id,
            'name' => 'Menu Kemarin',
            'category' => 'Makanan',
            'price' => 50000,
            'stock' => 100,
            'is_active' => true
        ]);

        // Transaksi offline kemarin tapi baru di-sync HARI INI
        $job = new \App\Jobs\ProcessBulkTransactions([
            [
                'app_uuid' => 'offline-yesterday-synced-today-001',
                'outlet_id' => $this->outlet->id,
                'shift_id' => $shiftYesterday->id,
                'total_amount' => 50000,
                'pay_amount' => 50000,
                'payment_method' => 'Cash',
                'status' => 'Selesai',
                'created_at_device' => $yesterday->copy()->setTime(15, 30, 0)->format('Y-m-d H:i:s'),
                'items' => [
                    [
                        'menu_id' => $menu->id,
                        'name' => 'Menu Kemarin',
                        'qty' => 1,
                        'price' => 50000
                    ]
                ]
            ]
        ], $this->user->id, '1.4.0');

        $job->handle();

        // Cek dashboardMetrics tanpa open shift hari ini
        $responseToday = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/transactions/dashboard-metrics?outlet_id={$this->outlet->id}&period=today");

        $responseToday->assertStatus(200);
        $dataToday = $responseToday->json('data');

        // Hari ini omzet gross_sales HARUS 0 (tidak ada open shift hari ini)
        $this->assertEquals(0, $dataToday['gross_sales'] ?? $dataToday['total_omset'] ?? 0);
        $this->assertEquals(0, $dataToday['total_count'] ?? 0);
    }
}
