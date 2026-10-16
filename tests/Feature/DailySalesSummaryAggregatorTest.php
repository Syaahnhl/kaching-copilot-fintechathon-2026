<?php

namespace Tests\Feature;

use App\Models\DailyMenuSalesSummary;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailySalesSummaryAggregatorTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;
    protected User $userB;
    protected Outlet $outletA1;
    protected Outlet $outletA2;
    protected Outlet $outletB1;
    protected Menu $menuA1;
    protected Menu $menuA2;
    protected Menu $menuB1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create(['name' => 'Owner A', 'email' => 'owner.a@example.com']);
        $this->userB = User::factory()->create(['name' => 'Owner B', 'email' => 'owner.b@example.com']);

        $this->outletA1 = Outlet::create([
            'user_id' => $this->userA->id,
            'name' => 'Outlet A-1 Jakarta',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);

        $this->outletA2 = Outlet::create([
            'user_id' => $this->userA->id,
            'name' => 'Outlet A-2 Bandung',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);

        $this->outletB1 = Outlet::create([
            'user_id' => $this->userB->id,
            'name' => 'Outlet B-1 Surabaya',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);

        $this->menuA1 = Menu::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'name' => 'Nasi Goreng Spesial',
            'category' => 'Makanan Utama',
            'price' => 25000.0,
            'cost_price' => 12000.0,
            'unit' => 'Porsi',
        ]);

        $this->menuA2 = Menu::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'name' => 'Es Teh Manis',
            'category' => 'Minuman',
            'price' => 6000.0,
            'cost_price' => 2000.0,
            'unit' => 'Porsi',
        ]);

        $this->menuB1 = Menu::create([
            'user_id' => $this->userB->id,
            'outlet_id' => $this->outletB1->id,
            'name' => 'Kopi Tubruk',
            'category' => 'Minuman',
            'price' => 10000.0,
            'cost_price' => 3500.0,
            'unit' => 'Cangkir',
        ]);
    }

    protected function createTx(
        User $user,
        Outlet $outlet,
        string $status = 'Selesai',
        string $createdWib = '2026-10-01 12:00:00',
        float $totalAmount = 50000.0,
        array $items = []
    ): Transaction {
        $utcTimestamp = Carbon::createFromFormat('Y-m-d H:i:s', $createdWib, 'Asia/Jakarta')
            ->setTimezone('UTC');

        $tx = new Transaction();
        $tx->timestamps = false;
        $tx->forceFill([
            'app_uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'outlet_id' => $outlet->id,
            'customer_name' => 'Pelanggan Uji',
            'total_amount' => $totalAmount,
            'pay_amount' => $totalAmount,
            'payment_method' => 'CASH',
            'status' => $status,
            'created_at' => $utcTimestamp,
            'updated_at' => $utcTimestamp,
        ]);
        $tx->saveQuietly();

        foreach ($items as $item) {
            $itemModel = new TransactionItem();
            $itemModel->timestamps = false;
            $itemModel->forceFill([
                'transaction_id' => $tx->id,
                'menu_id' => $item['menu_id'] ?? null,
                'menu_name' => $item['menu_name'] ?? 'Item Tanpa Nama',
                'qty' => $item['qty'] ?? 1.0,
                'price' => $item['price'] ?? 10000.0,
                'cost_price' => $item['cost_price'] ?? 0.0,
                'status' => $item['status'] ?? 'SELESAI',
                'created_at' => $utcTimestamp,
                'updated_at' => $utcTimestamp,
            ]);
            $itemModel->saveQuietly();
        }

        return $tx;
    }

    /**
     * TEST 1: Transaksi eligible dan non-eligible
     */
    public function test_eligible_and_non_eligible_transactions(): void
    {
        $this->createTx($this->userA, $this->outletA1, 'Selesai', '2026-10-01 10:00:00', 125000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 5.0, 'price' => 25000.0, 'cost_price' => 12000.0],
        ]);

        $this->createTx($this->userA, $this->outletA1, 'completed', '2026-10-01 11:00:00', 50000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 2.0, 'price' => 25000.0, 'cost_price' => 12000.0],
        ]);

        $this->createTx($this->userA, $this->outletA1, 'pending', '2026-10-01 12:00:00', 250000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 10.0, 'price' => 25000.0],
        ]);

        $this->createTx($this->userA, $this->outletA1, 'draft', '2026-10-01 13:00:00', 75000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 3.0, 'price' => 25000.0],
        ]);

        $this->artisan('kaching:summarize-daily-sales', ['--date' => '2026-10-01'])
            ->assertExitCode(0);

        $summary = DailyMenuSalesSummary::where('outlet_id', $this->outletA1->id)
            ->where('menu_id', $this->menuA1->id)
            ->where('business_date', '2026-10-01')
            ->first();

        $this->assertNotNull($summary);
        $this->assertEquals(7.0, $summary->total_qty);
        $this->assertEquals(175000.0, $summary->total_revenue);
        $this->assertEquals(84000.0, $summary->total_cost);
        $this->assertEquals(2, $summary->transaction_count);
    }

    /**
     * TEST 2: Transaksi Void / Refund / Cancel wajib dieksklusikan
     */
    public function test_void_refund_and_cancelled_transactions_excluded(): void
    {
        $this->createTx($this->userA, $this->outletA1, 'Selesai', '2026-10-01 10:00:00', 75000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 3.0, 'price' => 25000.0],
        ]);

        $this->createTx($this->userA, $this->outletA1, 'batal', '2026-10-01 11:00:00', 100000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 4.0, 'price' => 25000.0],
        ]);

        $this->createTx($this->userA, $this->outletA1, 'cancelled', '2026-10-01 12:00:00', 50000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 2.0, 'price' => 25000.0],
        ]);

        $this->createTx($this->userA, $this->outletA1, 'refund', '2026-10-01 13:00:00', 25000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 1.0, 'price' => 25000.0],
        ]);

        $this->createTx($this->userA, $this->outletA1, 'Selesai', '2026-10-01 14:00:00', 50000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 2.0, 'price' => 25000.0, 'status' => 'BATAL'],
        ]);

        $this->artisan('kaching:summarize-daily-sales', ['--date' => '2026-10-01'])
            ->assertExitCode(0);

        $summary = DailyMenuSalesSummary::where('outlet_id', $this->outletA1->id)
            ->where('menu_id', $this->menuA1->id)
            ->where('business_date', '2026-10-01')
            ->first();

        $this->assertNotNull($summary);
        $this->assertEquals(3.0, $summary->total_qty);
        $this->assertEquals(75000.0, $summary->total_revenue);
        $this->assertEquals(1, $summary->transaction_count);
    }

    /**
     * TEST 3: Isolasi tenant dan outlet
     */
    public function test_tenant_and_outlet_isolation(): void
    {
        $this->createTx($this->userA, $this->outletA1, 'Selesai', '2026-10-01 10:00:00', 100000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 4.0, 'price' => 25000.0],
        ]);

        $menuA2Outlet = Menu::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA2->id,
            'name' => 'Nasi Goreng Spesial',
            'category' => 'Makanan Utama',
            'price' => 25000.0,
            'cost_price' => 12000.0,
            'unit' => 'Porsi',
        ]);

        $this->createTx($this->userA, $this->outletA2, 'Selesai', '2026-10-01 11:00:00', 50000.0, [
            ['menu_id' => $menuA2Outlet->id, 'menu_name' => $menuA2Outlet->name, 'qty' => 2.0, 'price' => 25000.0],
        ]);

        $this->createTx($this->userB, $this->outletB1, 'Selesai', '2026-10-01 12:00:00', 80000.0, [
            ['menu_id' => $this->menuB1->id, 'menu_name' => $this->menuB1->name, 'qty' => 8.0, 'price' => 10000.0],
        ]);

        $this->artisan('kaching:summarize-daily-sales', ['--date' => '2026-10-01'])
            ->assertExitCode(0);

        $this->assertEquals(3, DailyMenuSalesSummary::where('business_date', '2026-10-01')->count());

        $sumA1 = DailyMenuSalesSummary::where('outlet_id', $this->outletA1->id)->first();
        $this->assertEquals($this->userA->id, $sumA1->user_id);
        $this->assertEquals(4.0, $sumA1->total_qty);

        $sumA2 = DailyMenuSalesSummary::where('outlet_id', $this->outletA2->id)->first();
        $this->assertEquals($this->userA->id, $sumA2->user_id);
        $this->assertEquals(2.0, $sumA2->total_qty);

        $sumB1 = DailyMenuSalesSummary::where('outlet_id', $this->outletB1->id)->first();
        $this->assertEquals($this->userB->id, $sumB1->user_id);
        $this->assertEquals(8.0, $sumB1->total_qty);
    }

    /**
     * TEST 4: Pemisahan UOM (Dimensional Firewall)
     */
    public function test_uom_dimensional_firewall_separation(): void
    {
        $menuGram = Menu::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'name' => 'Daging Sapi Wagyu',
            'category' => 'Bahan Olahan',
            'price' => 500.0,
            'cost_price' => 300.0,
            'unit' => 'Gram',
        ]);

        $this->createTx($this->userA, $this->outletA1, 'Selesai', '2026-10-01 12:00:00', 125000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 5.0, 'price' => 25000.0],
            ['menu_id' => $menuGram->id, 'menu_name' => $menuGram->name, 'qty' => 250.0, 'price' => 500.0],
        ]);

        $this->artisan('kaching:summarize-daily-sales', ['--date' => '2026-10-01'])
            ->assertExitCode(0);

        $summaryPorsi = DailyMenuSalesSummary::where('menu_id', $this->menuA1->id)->first();
        $this->assertNotNull($summaryPorsi);
        $this->assertEquals('Porsi', $summaryPorsi->uom);
        $this->assertEquals(5.0, $summaryPorsi->total_qty);

        $summaryGram = DailyMenuSalesSummary::where('menu_id', $menuGram->id)->first();
        $this->assertNotNull($summaryGram);
        $this->assertEquals('Gram', $summaryGram->uom);
        $this->assertEquals(250.0, $summaryGram->total_qty);
    }

    /**
     * TEST 5: Business-date boundary dan timezone Asia/Jakarta
     */
    public function test_business_date_boundary_and_timezone(): void
    {
        // 2026-10-01 23:45:00 WIB (16:45:00 UTC) -> Hari Bisnis: 2026-10-01
        $this->createTx($this->userA, $this->outletA1, 'Selesai', '2026-10-01 23:45:00', 75000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 3.0, 'price' => 25000.0],
        ]);

        // 2026-10-02 00:15:00 WIB (17:15:00 UTC tanggal 1 Okt) -> Hari Bisnis: 2026-10-02
        $this->createTx($this->userA, $this->outletA1, 'Selesai', '2026-10-02 00:15:00', 50000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 2.0, 'price' => 25000.0],
        ]);

        $this->artisan('kaching:summarize-daily-sales', ['--date' => '2026-10-01'])
            ->assertExitCode(0);

        $this->artisan('kaching:summarize-daily-sales', ['--date' => '2026-10-02'])
            ->assertExitCode(0);

        $sumDay1 = DailyMenuSalesSummary::where('outlet_id', $this->outletA1->id)
            ->where('menu_id', $this->menuA1->id)
            ->where('business_date', '2026-10-01')
            ->first();

        $sumDay2 = DailyMenuSalesSummary::where('outlet_id', $this->outletA1->id)
            ->where('menu_id', $this->menuA1->id)
            ->where('business_date', '2026-10-02')
            ->first();

        $this->assertNotNull($sumDay1);
        $this->assertEquals(3.0, $sumDay1->total_qty);

        $this->assertNotNull($sumDay2);
        $this->assertEquals(2.0, $sumDay2->total_qty);
    }

    /**
     * TEST 6: Menu tanpa ID atau mapping ambigu
     */
    public function test_unresolved_menu_without_id_or_ambiguous_name(): void
    {
        // Item dengan nama cocok Menu A2 (Es Teh Manis) tapi tanpa menu_id
        // Item dengan nama fiktif tak terdaftar di master menu
        $this->createTx($this->userA, $this->outletA1, 'Selesai', '2026-10-01 12:00:00', 446000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 4.0, 'price' => 25000.0],
            ['menu_id' => null, 'menu_name' => 'Es Teh Manis', 'qty' => 6.0, 'price' => 6000.0],
            ['menu_id' => null, 'menu_name' => 'Menu Misterius Tidak Ada', 'qty' => 10.0, 'price' => 31000.0],
        ]);

        $this->artisan('kaching:summarize-daily-sales', ['--date' => '2026-10-01'])
            ->assertExitCode(0);

        // Menu A1 teragregasi
        $sumA1 = DailyMenuSalesSummary::where('menu_id', $this->menuA1->id)->first();
        $this->assertNotNull($sumA1);
        $this->assertEquals(4.0, $sumA1->total_qty);

        // Menu A2 teresolusi lewat nama
        $sumA2 = DailyMenuSalesSummary::where('menu_id', $this->menuA2->id)->first();
        $this->assertNotNull($sumA2);
        $this->assertEquals(6.0, $sumA2->total_qty);
    }

    /**
     * TEST 7: Idempotency saat command dijalankan dua kali
     */
    public function test_idempotency_when_command_run_twice(): void
    {
        $this->createTx($this->userA, $this->outletA1, 'Selesai', '2026-10-01 10:00:00', 125000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 5.0, 'price' => 25000.0],
        ]);

        // Run 1
        $this->artisan('kaching:summarize-daily-sales', ['--date' => '2026-10-01'])
            ->assertExitCode(0);
        $countFirstRun = DailyMenuSalesSummary::where('business_date', '2026-10-01')->count();

        // Run 2
        $this->artisan('kaching:summarize-daily-sales', ['--date' => '2026-10-01'])
            ->assertExitCode(0);
        $countSecondRun = DailyMenuSalesSummary::where('business_date', '2026-10-01')->count();

        $this->assertEquals($countFirstRun, $countSecondRun);
        $this->assertEquals(1, $countSecondRun);

        $summary = DailyMenuSalesSummary::where('business_date', '2026-10-01')->first();
        $this->assertNotNull($summary);
        $this->assertEquals(5.0, $summary->total_qty);
        $this->assertEquals(125000.0, $summary->total_revenue);
    }

    /**
     * TEST 8: Late-arriving transaction dan rerun
     */
    public function test_late_arriving_transaction_and_rerun(): void
    {
        $this->createTx($this->userA, $this->outletA1, 'Selesai', '2026-10-01 10:00:00', 75000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 3.0, 'price' => 25000.0],
        ]);

        $this->artisan('kaching:summarize-daily-sales', ['--date' => '2026-10-01'])
            ->assertExitCode(0);

        $initialSummary = DailyMenuSalesSummary::where('business_date', '2026-10-01')->first();
        $this->assertNotNull($initialSummary);
        $this->assertEquals(3.0, $initialSummary->total_qty);
        $this->assertEquals(1, $initialSummary->transaction_count);

        // Transaksi terlambat masuk (misal sync offline POS selesai siang hari berikutnya)
        $this->createTx($this->userA, $this->outletA1, 'Selesai', '2026-10-01 21:00:00', 50000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 2.0, 'price' => 25000.0],
        ]);

        // Re-run
        $this->artisan('kaching:summarize-daily-sales', ['--date' => '2026-10-01'])
            ->assertExitCode(0);

        $updatedSummary = DailyMenuSalesSummary::where('business_date', '2026-10-01')->first();
        $this->assertNotNull($updatedSummary);
        $this->assertEquals(5.0, $updatedSummary->total_qty);
        $this->assertEquals(125000.0, $updatedSummary->total_revenue);
        $this->assertEquals(2, $updatedSummary->transaction_count);
    }

    /**
     * TEST 9: Empty date range
     */
    public function test_empty_date_range_graceful_handling(): void
    {
        $this->artisan('kaching:summarize-daily-sales', ['--date' => '2026-09-01'])
            ->assertExitCode(0);

        $this->assertEquals(0, DailyMenuSalesSummary::where('business_date', '2026-09-01')->count());
    }

    /**
     * TEST 10: Periode backfill terbatas
     */
    public function test_limited_backfill_period(): void
    {
        $this->createTx($this->userA, $this->outletA1, 'Selesai', '2026-10-01 12:00:00', 50000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 2.0, 'price' => 25000.0],
        ]);

        $this->createTx($this->userA, $this->outletA1, 'Selesai', '2026-10-02 12:00:00', 75000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 3.0, 'price' => 25000.0],
        ]);

        $this->createTx($this->userA, $this->outletA1, 'Selesai', '2026-10-03 12:00:00', 100000.0, [
            ['menu_id' => $this->menuA1->id, 'menu_name' => $this->menuA1->name, 'qty' => 4.0, 'price' => 25000.0],
        ]);

        $this->artisan('kaching:summarize-daily-sales', [
            '--start-date' => '2026-10-01',
            '--end-date' => '2026-10-03',
        ])->assertExitCode(0);

        $this->assertEquals(3, DailyMenuSalesSummary::whereBetween('business_date', ['2026-10-01', '2026-10-03'])->count());
    }
}
