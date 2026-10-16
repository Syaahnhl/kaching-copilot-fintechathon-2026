<?php

namespace Tests\Unit;

use App\Models\Menu;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Financial\SalesAnomalyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesAnomalyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SalesAnomalyService $service;
    protected User $tenant1;
    protected User $tenant2;
    protected Outlet $outlet1;
    protected Outlet $outlet2;
    protected Outlet $tenant2Outlet;
    protected Menu $menuA;
    protected Menu $menuB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SalesAnomalyService::class);

        $this->tenant1 = User::factory()->create();
        $this->tenant2 = User::factory()->create();

        $this->outlet1 = Outlet::create([
            'user_id'  => $this->tenant1->id,
            'name'     => 'Outlet Utama',
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->outlet2 = Outlet::create([
            'user_id'  => $this->tenant1->id,
            'name'     => 'Outlet Cabang',
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->tenant2Outlet = Outlet::create([
            'user_id'  => $this->tenant2->id,
            'name'     => 'Tenant 2 Outlet',
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->menuA = Menu::create([
            'user_id'   => $this->tenant1->id,
            'outlet_id' => $this->outlet1->id,
            'name'      => 'Ayam Goreng',
            'category'  => 'Makanan',
            'price'     => 25000,
        ]);

        $this->menuB = Menu::create([
            'user_id'   => $this->tenant1->id,
            'outlet_id' => $this->outlet1->id,
            'name'      => 'Es Teh Manis',
            'category'  => 'Minuman',
            'price'     => 5000,
        ]);
    }

    protected function createTxWithItem(
        int $userId,
        int $outletId,
        string $businessDate,
        string $status,
        float $totalAmount,
        ?int $menuId = null,
        ?string $menuName = null,
        float $qty = 1.0,
        float $price = 0.0,
        ?string $cancelReason = null,
        ?string $updatedAt = null,
        ?string $cashierName = 'Kasir 1'
    ): int {
        $createdAtUtc = Carbon::createFromFormat('Y-m-d H:i:s', "{$businessDate} 12:00:00", 'Asia/Jakarta')
            ->setTimezone('UTC')
            ->format('Y-m-d H:i:s');

        $updatedAtUtc = $updatedAt ?? $createdAtUtc;

        $txId = DB::table('transactions')->insertGetId([
            'app_uuid'          => (string) Str::uuid(),
            'user_id'           => $userId,
            'outlet_id'         => $outletId,
            'cashier_name'      => $cashierName,
            'payment_method'    => 'Tunai',
            'total_amount'      => $totalAmount,
            'status'            => $status,
            'cancel_reason'     => $cancelReason,
            'created_at_device' => $createdAtUtc,
            'created_at'        => $createdAtUtc,
            'updated_at'        => $updatedAtUtc,
        ]);

        DB::table('transaction_items')->insert([
            'user_id'        => $userId,
            'transaction_id' => $txId,
            'menu_id'        => $menuId,
            'menu_name'      => $menuName ?? ($menuId ? Menu::find($menuId)?->name : 'Custom Item'),
            'qty'            => $qty,
            'price'          => $price > 0 ? $price : $totalAmount,
            'status'         => $status,
            'created_at'     => $createdAtUtc,
            'updated_at'     => $updatedAtUtc,
        ]);

        return $txId;
    }

    /**
     * 1. Test Void Transaction Detection and Rate Calculation.
     */
    public function test_void_transaction_detection_and_rate_calculation(): void
    {
        // 8 completed, 2 cancelled
        for ($i = 1; $i <= 8; $i++) {
            $this->createTxWithItem(
                $this->tenant1->id,
                $this->outlet1->id,
                '2026-09-10',
                'Selesai',
                50000,
                $this->menuA->id,
                $this->menuA->name,
                2,
                25000
            );
        }

        for ($i = 1; $i <= 2; $i++) {
            $this->createTxWithItem(
                $this->tenant1->id,
                $this->outlet1->id,
                '2026-09-10',
                'Batal',
                50000,
                $this->menuA->id,
                $this->menuA->name,
                2,
                25000,
                'Salah input meja'
            );
        }

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-15', 30);

        $this->assertEquals(10, $result['void_analysis']['total_transactions']);
        $this->assertEquals(8, $result['void_analysis']['valid_transactions']);
        $this->assertEquals(2, $result['void_analysis']['void_transactions']);
        $this->assertEquals(20.0, $result['void_analysis']['void_rate_pct']);
        $this->assertEquals(SalesAnomalyService::VOID_SEVERITY_CRITICAL, $result['void_analysis']['void_severity']);
        $this->assertNotEmpty($result['void_analysis']['cancel_reasons_breakdown']);
        $this->assertEquals('Salah input meja', $result['void_analysis']['cancel_reasons_breakdown'][0]['reason']);
        $this->assertEquals(2, $result['void_analysis']['cancel_reasons_breakdown'][0]['count']);
    }

    /**
     * 2. Test Non-Void Transaction Accumulation (0% Void Rate).
     */
    public function test_non_void_transaction_accumulation(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->createTxWithItem(
                $this->tenant1->id,
                $this->outlet1->id,
                '2026-09-10',
                'Selesai',
                30000,
                $this->menuB->id
            );
        }

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-15', 30);

        $this->assertEquals(15, $result['void_analysis']['total_transactions']);
        $this->assertEquals(15, $result['void_analysis']['valid_transactions']);
        $this->assertEquals(0, $result['void_analysis']['void_transactions']);
        $this->assertEquals(0.0, $result['void_analysis']['void_rate_pct']);
        $this->assertEquals(SalesAnomalyService::VOID_SEVERITY_LOW, $result['void_analysis']['void_severity']);
    }

    /**
     * 3. Test PIT Cutoff Excludes Post-Cutoff Transactions.
     */
    public function test_pit_cutoff_excludes_post_cutoff_transactions(): void
    {
        // Tx on Sept 10
        $this->createTxWithItem(
            $this->tenant1->id,
            $this->outlet1->id,
            '2026-09-10',
            'Selesai',
            25000,
            $this->menuA->id
        );

        // Tx on Sept 14
        $this->createTxWithItem(
            $this->tenant1->id,
            $this->outlet1->id,
            '2026-09-14',
            'Selesai',
            25000,
            $this->menuA->id
        );

        // Cutoff at Sept 12 23:59:59 UTC
        $cutoffUtc = '2026-09-12 23:59:59';
        $result = $this->service->analyze(
            $this->tenant1->id,
            $this->outlet1->id,
            '2026-09-15',
            30,
            ['cutoff_utc' => $cutoffUtc]
        );

        $this->assertEquals(1, $result['void_analysis']['total_transactions']);
        $this->assertEquals(1, $result['void_analysis']['valid_transactions']);
    }

    /**
     * 4. Test Future Cancellation Preserves Historical Valid Status at Cutoff.
     */
    public function test_future_cancellation_preserves_historical_valid_status_at_cutoff(): void
    {
        // Created on Sept 10, cancelled on Sept 14
        $cancelledLaterUtc = Carbon::createFromFormat('Y-m-d H:i:s', '2026-09-14 15:00:00', 'Asia/Jakarta')
            ->setTimezone('UTC')
            ->format('Y-m-d H:i:s');

        $this->createTxWithItem(
            $this->tenant1->id,
            $this->outlet1->id,
            '2026-09-10',
            'Batal',
            50000,
            $this->menuA->id,
            $this->menuA->name,
            2,
            25000,
            'Dibatalkan belakangan',
            $cancelledLaterUtc
        );

        // Evaluate at Cutoff Sept 12
        $cutoffUtc = Carbon::createFromFormat('Y-m-d H:i:s', '2026-09-12 23:59:59', 'Asia/Jakarta')
            ->setTimezone('UTC')
            ->format('Y-m-d H:i:s');

        $result = $this->service->analyze(
            $this->tenant1->id,
            $this->outlet1->id,
            '2026-09-15',
            30,
            ['cutoff_utc' => $cutoffUtc]
        );

        // At Sept 12 cutoff, it was still considered valid (cancellation happened in the future)
        $this->assertEquals(1, $result['void_analysis']['total_transactions']);
        $this->assertEquals(1, $result['void_analysis']['valid_transactions']);
        $this->assertEquals(0, $result['void_analysis']['void_transactions']);
    }

    /**
     * 5. Test Zero Transaction Edge Case (Clean No-Crash Return).
     */
    public function test_zero_transaction_edge_case(): void
    {
        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-15', 30);

        $this->assertEquals(0, $result['void_analysis']['total_transactions']);
        $this->assertEquals(0, $result['void_analysis']['valid_transactions']);
        $this->assertEquals(0.0, $result['void_analysis']['void_rate_pct']);
        $this->assertEquals(SalesAnomalyService::VOID_SEVERITY_LOW, $result['void_analysis']['void_severity']);
        $this->assertEquals(SalesAnomalyService::STATUS_NORMAL, $result['summary']['status']);
        $this->assertCount(30, $result['daily_time_series']);
    }

    /**
     * 6. Test Daily Sales Spike Detection.
     */
    public function test_daily_sales_spike_detection(): void
    {
        // 10 days with 5 tx per day
        for ($day = 1; $day <= 10; $day++) {
            $dStr = sprintf('2026-09-%02d', $day);
            for ($tx = 1; $tx <= 5; $tx++) {
                $this->createTxWithItem(
                    $this->tenant1->id,
                    $this->outlet1->id,
                    $dStr,
                    'Selesai',
                    20000,
                    $this->menuB->id
                );
            }
        }

        // On day 11, sudden surge with 35 transactions!
        for ($tx = 1; $tx <= 35; $tx++) {
            $this->createTxWithItem(
                $this->tenant1->id,
                $this->outlet1->id,
                '2026-09-11',
                'Selesai',
                20000,
                $this->menuB->id
            );
        }

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-15', 30);

        $spikeAnomalies = array_filter(
            $result['sales_anomalies']['daily_anomalies'],
            fn($a) => $a['anomaly_type'] === SalesAnomalyService::ANOMALY_SALES_SPIKE
        );

        $this->assertNotEmpty($spikeAnomalies);
        $spikeDates = array_column($spikeAnomalies, 'date');
        $this->assertContains('2026-09-11', $spikeDates);
    }

    /**
     * 7. Test Daily Sales Drop Detection.
     */
    public function test_daily_sales_drop_detection(): void
    {
        // 14 days of strong 25 tx per day
        for ($day = 1; $day <= 14; $day++) {
            $dStr = sprintf('2026-09-%02d', $day);
            for ($tx = 1; $tx <= 25; $tx++) {
                $this->createTxWithItem(
                    $this->tenant1->id,
                    $this->outlet1->id,
                    $dStr,
                    'Selesai',
                    20000,
                    $this->menuB->id
                );
            }
        }

        // On day 15, drops drastically to only 1 transaction
        $this->createTxWithItem(
            $this->tenant1->id,
            $this->outlet1->id,
            '2026-09-15',
            'Selesai',
            20000,
            $this->menuB->id
        );

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-15', 30);

        $dropAnomalies = array_filter(
            $result['sales_anomalies']['daily_anomalies'],
            fn($a) => $a['anomaly_type'] === SalesAnomalyService::ANOMALY_SALES_DROP
        );

        $this->assertNotEmpty($dropAnomalies);
        $dropDates = array_column($dropAnomalies, 'date');
        $this->assertContains('2026-09-15', $dropDates);
    }

    /**
     * 8. Test Daily Void Spike Detection.
     */
    public function test_daily_void_spike_detection(): void
    {
        // 5 valid transactions, 5 cancelled transactions on same day (50% void rate)
        for ($i = 1; $i <= 5; $i++) {
            $this->createTxWithItem(
                $this->tenant1->id,
                $this->outlet1->id,
                '2026-09-10',
                'Selesai',
                30000,
                $this->menuA->id
            );
        }

        for ($i = 1; $i <= 5; $i++) {
            $this->createTxWithItem(
                $this->tenant1->id,
                $this->outlet1->id,
                '2026-09-10',
                'Batal',
                30000,
                $this->menuA->id,
                $this->menuA->name,
                1,
                30000,
                'Kasir salah input order'
            );
        }

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-15', 30);

        $voidSpikes = array_filter(
            $result['sales_anomalies']['daily_anomalies'],
            fn($a) => $a['anomaly_type'] === SalesAnomalyService::ANOMALY_VOID_SPIKE
        );

        $this->assertNotEmpty($voidSpikes);
        $voidDates = array_column($voidSpikes, 'date');
        $this->assertContains('2026-09-10', $voidDates);
    }

    /**
     * 9. Test Strict Tenant Isolation.
     */
    public function test_tenant_isolation(): void
    {
        // Tenant 1 tx
        $this->createTxWithItem(
            $this->tenant1->id,
            $this->outlet1->id,
            '2026-09-10',
            'Selesai',
            100000,
            $this->menuA->id
        );

        // Tenant 2 tx
        $this->createTxWithItem(
            $this->tenant2->id,
            $this->tenant2Outlet->id,
            '2026-09-10',
            'Selesai',
            200000,
            null,
            'Tenant 2 Special Menu'
        );

        // Analyze Tenant 1
        $res1 = $this->service->analyze($this->tenant1->id, null, '2026-09-15', 30);
        $this->assertEquals(1, $res1['void_analysis']['total_transactions']);
        $this->assertEquals(100000.0, $res1['void_analysis']['total_nominal']);

        // Analyze Tenant 2
        $res2 = $this->service->analyze($this->tenant2->id, null, '2026-09-15', 30);
        $this->assertEquals(1, $res2['void_analysis']['total_transactions']);
        $this->assertEquals(200000.0, $res2['void_analysis']['total_nominal']);
    }

    /**
     * 10. Test Strict Outlet Isolation.
     */
    public function test_outlet_isolation(): void
    {
        // Outlet 1 tx
        $this->createTxWithItem(
            $this->tenant1->id,
            $this->outlet1->id,
            '2026-09-10',
            'Selesai',
            50000,
            $this->menuA->id
        );

        // Outlet 2 tx
        $this->createTxWithItem(
            $this->tenant1->id,
            $this->outlet2->id,
            '2026-09-10',
            'Selesai',
            75000,
            $this->menuB->id
        );

        // Analyze Outlet 1 only
        $resOutlet1 = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-15', 30);
        $this->assertEquals(1, $resOutlet1['void_analysis']['total_transactions']);
        $this->assertEquals(50000.0, $resOutlet1['void_analysis']['total_nominal']);

        // Analyze All Outlets
        $resAll = $this->service->analyze($this->tenant1->id, null, '2026-09-15', 30);
        $this->assertEquals(2, $resAll['void_analysis']['total_transactions']);
        $this->assertEquals(125000.0, $resAll['void_analysis']['total_nominal']);
    }

    /**
     * 11. Test Extreme Value and Unusual Quantity Detection.
     */
    public function test_extreme_value_and_unusual_quantity_detection(): void
    {
        // 20 normal transactions of Rp 20.000 (qty 1)
        for ($i = 1; $i <= 20; $i++) {
            $this->createTxWithItem(
                $this->tenant1->id,
                $this->outlet1->id,
                '2026-09-10',
                'Selesai',
                20000,
                $this->menuB->id,
                $this->menuB->name,
                1,
                20000
            );
        }

        // 1 extreme transaction of Rp 2.500.000 with huge quantity (100 units)
        $this->createTxWithItem(
            $this->tenant1->id,
            $this->outlet1->id,
            '2026-09-12',
            'Selesai',
            2500000,
            $this->menuA->id,
            $this->menuA->name,
            100,
            25000
        );

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-15', 30);

        $extremeTx = $result['sales_anomalies']['extreme_transactions'];
        $this->assertNotEmpty($extremeTx);
        $this->assertEquals(2500000.0, $extremeTx[0]['amount']);

        $unusualQty = $result['sales_anomalies']['unusual_quantities'];
        $this->assertNotEmpty($unusualQty);
        $this->assertEquals(100.0, $unusualQty[0]['qty']);
    }

    /**
     * 12. Test Ambiguous Menu Identity Fails Closed in Void Breakdown.
     */
    public function test_ambiguous_menu_identity_fails_closed_in_void_breakdown(): void
    {
        // Void transaction with unmapped custom item name
        $this->createTxWithItem(
            $this->tenant1->id,
            $this->outlet1->id,
            '2026-09-10',
            'Batal',
            30000,
            null,
            'Unregistered Mystery Item',
            1,
            30000,
            'Menu fiktif'
        );

        $result = $this->service->analyze($this->tenant1->id, $this->outlet1->id, '2026-09-15', 30);

        $this->assertEquals(1, $result['void_analysis']['unresolved_void_items']);
        $this->assertEmpty($result['void_analysis']['void_by_menu']);
    }
}
