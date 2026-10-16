<?php

namespace Tests\Unit;

use App\Models\Menu;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Financial\MenuDemandForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MenuDemandForecastServiceTest extends TestCase
{
    use RefreshDatabase;

    protected MenuDemandForecastService $service;
    protected User $tenantA;
    protected User $tenantB;
    protected Outlet $outletA1;
    protected Outlet $outletA2;
    protected Outlet $outletB1;
    protected Menu $menuA1;
    protected Menu $menuA2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MenuDemandForecastService();

        $this->tenantA = User::factory()->create([
            'name'  => 'Tenant A Owner',
            'email' => 'tenanta@kaching.id',
        ]);

        $this->tenantB = User::factory()->create([
            'name'  => 'Tenant B Owner',
            'email' => 'tenantb@kaching.id',
        ]);

        $this->outletA1 = Outlet::create([
            'user_id'   => $this->tenantA->id,
            'name'      => 'Outlet A-1 Menteng',
            'timezone'  => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $this->outletA2 = Outlet::create([
            'user_id'   => $this->tenantA->id,
            'name'      => 'Outlet A-2 Kemang',
            'timezone'  => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $this->outletB1 = Outlet::create([
            'user_id'   => $this->tenantB->id,
            'name'      => 'Outlet B-1 Kuta',
            'timezone'  => 'Asia/Makassar',
            'is_active' => true,
        ]);

        $this->menuA1 = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA1->id,
            'name'       => 'Es Teh Manis Segar',
            'category'   => 'Minuman',
            'price'      => 5000,
            'cost_price' => 1500,
            'stock'      => -1,
        ]);

        $this->menuA2 = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA1->id,
            'name'       => 'Nasi Goreng Spesial',
            'category'   => 'Makanan',
            'price'      => 25000,
            'cost_price' => 12000,
            'stock'      => 50,
        ]);
    }

    /**
     * Helper to create a transaction with an item row
     */
    protected function createTxWithItem(
        User $user,
        Outlet $outlet,
        string $businessDate,
        string $businessTime,
        ?int $menuId,
        string $menuName,
        float $qty,
        float $price,
        string $txStatus = 'Selesai',
        ?string $txUpdatedAt = null,
        string $itemStatus = 'Selesai',
        ?string $itemUpdatedAt = null
    ): array {
        $tz = $outlet->timezone ?: 'Asia/Jakarta';
        $utcCreated = Carbon::parse("{$businessDate} {$businessTime}", $tz)->setTimezone('UTC')->format('Y-m-d H:i:s');
        $txUpdated = $txUpdatedAt ?? $utcCreated;
        $itemUpdated = $itemUpdatedAt ?? $txUpdated;

        $txId = DB::table('transactions')->insertGetId([
            'app_uuid'                => (string) \Illuminate\Support\Str::uuid(),
            'user_id'                 => $user->id,
            'outlet_id'               => $outlet->id,
            'official_invoice_number' => 'INV-' . uniqid(),
            'total_amount'            => $qty * $price,
            'pay_amount'              => $qty * $price,
            'payment_method'          => 'Tunai',
            'status'                  => $txStatus,
            'created_at_device'       => $utcCreated,
            'created_at'              => $utcCreated,
            'updated_at'              => $txUpdated,
        ]);

        $itemId = DB::table('transaction_items')->insertGetId([
            'transaction_id' => $txId,
            'menu_id'        => $menuId,
            'menu_name'      => $menuName,
            'qty'            => $qty,
            'price'          => $price,
            'status'         => $itemStatus,
            'created_at'     => $utcCreated,
            'updated_at'     => $itemUpdated,
        ]);

        return ['transaction_id' => $txId, 'item_id' => $itemId, 'created_at_device' => $utcCreated];
    }

    // =========================================================================
    // 1. DIRECT MENU_ID RESOLUTION
    // =========================================================================
    public function test_1_existing_menu_id_resolution(): void
    {
        $itemRow = [
            'menu_id'   => $this->menuA1->id,
            'menu_name' => 'Random Name Here',
        ];

        $res = $this->service->resolveMenuIdentity($this->tenantA->id, $this->outletA1->id, $itemRow);

        $this->assertSame('RESOLVED', $res['status']);
        $this->assertSame($this->menuA1->id, $res['resolved_menu_id']);
        $this->assertSame(MenuDemandForecastService::RES_DIRECT_MENU_ID, $res['resolution_source']);
        $this->assertEquals(1.0, $res['confidence']);
    }

    // =========================================================================
    // 2. NULL MENU_ID EXACT NAME RESOLUTION
    // =========================================================================
    public function test_2_null_menu_id_exact_name_resolution(): void
    {
        $itemRow = [
            'menu_id'   => null,
            'menu_name' => '  es teh manis segar  ', // case-insensitive + trim
        ];

        $res = $this->service->resolveMenuIdentity($this->tenantA->id, $this->outletA1->id, $itemRow);

        $this->assertSame('RESOLVED', $res['status']);
        $this->assertSame($this->menuA1->id, $res['resolved_menu_id']);
        $this->assertSame(MenuDemandForecastService::RES_EXACT_TENANT_NAME, $res['resolution_source']);
        $this->assertEquals(0.95, $res['confidence']);
    }

    // =========================================================================
    // 3. CROSS-TENANT NAME COLLISION DEFENSE
    // =========================================================================
    public function test_3_cross_tenant_name_collision(): void
    {
        // Tenant B creates a menu with the exact same name
        $menuB = Menu::create([
            'user_id'   => $this->tenantB->id,
            'outlet_id' => $this->outletB1->id,
            'name'      => 'Kopi Susu Senja',
            'category'  => 'Minuman',
            'price'     => 18000,
            'stock'     => -1,
        ]);

        $itemRow = [
            'menu_id'   => null,
            'menu_name' => 'Kopi Susu Senja',
        ];

        // Resolving for Tenant A must NOT match Tenant B's menu!
        $resA = $this->service->resolveMenuIdentity($this->tenantA->id, $this->outletA1->id, $itemRow);
        $this->assertSame('UNRESOLVED', $resA['status']);
        $this->assertNull($resA['resolved_menu_id']);
        $this->assertSame(MenuDemandForecastService::RES_UNRESOLVED, $resA['resolution_source']);

        // But resolving for Tenant B must resolve correctly
        $resB = $this->service->resolveMenuIdentity($this->tenantB->id, $this->outletB1->id, $itemRow);
        $this->assertSame('RESOLVED', $resB['status']);
        $this->assertSame($menuB->id, $resB['resolved_menu_id']);
    }

    // =========================================================================
    // 4. UNKNOWN MENU RESOLUTION
    // =========================================================================
    public function test_4_unknown_menu_resolution(): void
    {
        $itemRow = [
            'menu_id'   => null,
            'menu_name' => 'Menu Siluman Belum Ada',
        ];

        $res = $this->service->resolveMenuIdentity($this->tenantA->id, $this->outletA1->id, $itemRow);

        $this->assertSame('UNRESOLVED', $res['status']);
        $this->assertNull($res['resolved_menu_id']);
        $this->assertSame(MenuDemandForecastService::RES_UNRESOLVED, $res['resolution_source']);
        $this->assertEquals(0.0, $res['confidence']);
    }

    // =========================================================================
    // 5. DUPLICATE MENU NAME DEFENSE (FAIL-CLOSED)
    // =========================================================================
    public function test_5_duplicate_menu_name_defense(): void
    {
        // Tenant A accidentally creates two menus with identical names in outlet
        $duplicateMenu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA1->id,
            'name'      => 'Es Teh Manis Segar', // duplicate name with menuA1!
            'category'  => 'Minuman',
            'price'     => 6000,
            'stock'     => -1,
        ]);

        $itemRow = [
            'menu_id'   => null,
            'menu_name' => 'Es Teh Manis Segar',
        ];

        // Must fail-closed to UNRESOLVED because of ambiguity
        $res = $this->service->resolveMenuIdentity($this->tenantA->id, $this->outletA1->id, $itemRow);
        $this->assertSame('UNRESOLVED', $res['status']);
        $this->assertNull($res['resolved_menu_id']);
        $this->assertStringContainsString('Duplikasi nama menu', $res['reason']);
    }

    // =========================================================================
    // 6. POINT-IN-TIME CUTOFF INCLUSION
    // =========================================================================
    public function test_6_cutoff_inclusion(): void
    {
        // Transaction created on 2026-09-15 10:00:00 WIB
        $this->createTxWithItem(
            $this->tenantA,
            $this->outletA1,
            '2026-09-15',
            '10:00:00',
            $this->menuA1->id,
            $this->menuA1->name,
            5.0,
            5000
        );

        // Cutoff is 2026-09-20 00:00:00 UTC (after tx was created)
        $cutoffUtc = '2026-09-20 00:00:00';

        $demand = $this->service->buildDailyDemand(
            $this->tenantA->id,
            $this->outletA1->id,
            '2026-09-01',
            '2026-09-20',
            $cutoffUtc,
            [$this->menuA1->id]
        );

        $day15 = collect($demand['records'])->firstWhere('date', '2026-09-15');
        $this->assertNotNull($day15);
        $this->assertEquals(5.0, $day15['qty']);
    }

    // =========================================================================
    // 7. FUTURE TRANSACTION EXCLUSION (T > CUTOFF)
    // =========================================================================
    public function test_7_future_transaction_exclusion(): void
    {
        // Transaction created on 2026-09-25 10:00:00 WIB (future relative to cutoff)
        $this->createTxWithItem(
            $this->tenantA,
            $this->outletA1,
            '2026-09-25',
            '10:00:00',
            $this->menuA1->id,
            $this->menuA1->name,
            8.0,
            5000
        );

        // Cutoff is 2026-09-20 00:00:00 UTC
        $cutoffUtc = '2026-09-20 00:00:00';

        $demand = $this->service->buildDailyDemand(
            $this->tenantA->id,
            $this->outletA1->id,
            '2026-09-01',
            '2026-09-30',
            $cutoffUtc,
            [$this->menuA1->id]
        );

        // Day 25 must have qty 0.0 because tx was created AFTER cutoff!
        $day25 = collect($demand['records'])->firstWhere('date', '2026-09-25');
        $this->assertNotNull($day25);
        $this->assertEquals(0.0, $day25['qty']);
    }

    // =========================================================================
    // 8. CRITICAL FORENSIC TEST: HISTORICAL STATUS MUTATION DEFENSE (ANTI-LOOKAHEAD LEAKAGE)
    // =========================================================================
    public function test_8_future_cancellation_leakage_defense(): void
    {
        $cutoffUtc = '2026-09-20 00:00:00';

        // Case A: Created on 2026-09-10 (before cutoff). Cancelled on 2026-09-25 (AFTER cutoff).
        // At cutoff T0 (2026-09-20), this cancellation was unknown.
        // MUST STILL CONTRIBUTE TO DEMAND!
        $this->createTxWithItem(
            $this->tenantA,
            $this->outletA1,
            '2026-09-10',
            '10:00:00',
            $this->menuA1->id,
            $this->menuA1->name,
            12.0,
            5000,
            'Batal', // Current status is Batal
            '2026-09-25 14:00:00' // but updated to Batal on Sept 25 (> cutoff)
        );

        // Case B: Created on 2026-09-12 (before cutoff). Cancelled on 2026-09-15 (BEFORE cutoff).
        // At cutoff T0 (2026-09-20), this cancellation was ALREADY KNOWN.
        // MUST NOT CONTRIBUTE TO DEMAND!
        $this->createTxWithItem(
            $this->tenantA,
            $this->outletA1,
            '2026-09-12',
            '10:00:00',
            $this->menuA1->id,
            $this->menuA1->name,
            20.0,
            5000,
            'Batal', // Status is Batal
            '2026-09-15 12:00:00' // updated to Batal on Sept 15 (<= cutoff)
        );

        $demand = $this->service->buildDailyDemand(
            $this->tenantA->id,
            $this->outletA1->id,
            '2026-09-01',
            '2026-09-20',
            $cutoffUtc,
            [$this->menuA1->id]
        );

        // Day 10 (cancelled AFTER cutoff) MUST have qty 12.0
        $day10 = collect($demand['records'])->firstWhere('date', '2026-09-10');
        $this->assertNotNull($day10);
        $this->assertEquals(12.0, $day10['qty'], 'Tx cancelled after cutoff must contribute to historical point-in-time demand.');

        // Day 12 (cancelled BEFORE cutoff) MUST have qty 0.0
        $day12 = collect($demand['records'])->firstWhere('date', '2026-09-12');
        $this->assertNotNull($day12);
        $this->assertEquals(0.0, $day12['qty'], 'Tx cancelled before cutoff must NOT contribute to demand.');
    }

    // =========================================================================
    // 9. DAILY AGGREGATION SUM
    // =========================================================================
    public function test_9_daily_aggregation_sum(): void
    {
        // 3 separate transactions on 2026-09-14
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-14', '08:00:00', $this->menuA1->id, $this->menuA1->name, 3.0, 5000);
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-14', '12:30:00', $this->menuA1->id, $this->menuA1->name, 5.0, 5000);
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-14', '19:15:00', $this->menuA1->id, $this->menuA1->name, 2.0, 5000);

        $demand = $this->service->buildDailyDemand(
            $this->tenantA->id,
            $this->outletA1->id,
            '2026-09-14',
            '2026-09-14',
            null,
            [$this->menuA1->id]
        );

        $day14 = collect($demand['records'])->firstWhere('date', '2026-09-14');
        $this->assertEquals(10.0, $day14['qty']);
    }

    // =========================================================================
    // 10. ZERO-SALES DAYS PRESERVATION
    // =========================================================================
    public function test_10_zero_sales_days_preservation(): void
    {
        // Only 1 transaction on day 2026-09-02 in a 7-day window
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-02', '12:00:00', $this->menuA1->id, $this->menuA1->name, 4.0, 5000);

        $demand = $this->service->buildDailyDemand(
            $this->tenantA->id,
            $this->outletA1->id,
            '2026-09-01',
            '2026-09-07',
            null,
            [$this->menuA1->id]
        );

        $records = collect($demand['records']);
        $this->assertCount(7, $records, 'Calendar vector must contain all 7 days.');

        $zeroDay = $records->firstWhere('date', '2026-09-01');
        $this->assertSame(0.0, $zeroDay['qty']);

        $activeDay = $records->firstWhere('date', '2026-09-02');
        $this->assertEquals(4.0, $activeDay['qty']);
    }

    // =========================================================================
    // 11. CLASSIFY STRONG HISTORY
    // =========================================================================
    public function test_11_classify_strong_history(): void
    {
        // 16 active days out of 28 days
        $vector = [];
        for ($i = 1; $i <= 28; $i++) {
            $date = sprintf('2026-09-%02d', $i);
            $vector[$date] = ($i <= 16) ? 10.0 : 0.0;
        }

        $res = $this->service->classifyHistory($vector, 28);
        $this->assertSame(MenuDemandForecastService::TIER_STRONG_HISTORY, $res['tier']);
        $this->assertSame(16, $res['active_sales_days']);
        $this->assertSame(12, $res['zero_sales_days']);
    }

    // =========================================================================
    // 12. CLASSIFY INTERMITTENT HISTORY
    // =========================================================================
    public function test_12_classify_intermittent_history(): void
    {
        // 9 active days out of 28 days (between 7 and 13)
        $vector = [];
        for ($i = 1; $i <= 28; $i++) {
            $date = sprintf('2026-09-%02d', $i);
            $vector[$date] = ($i <= 9) ? 5.0 : 0.0;
        }

        $res = $this->service->classifyHistory($vector, 28);
        $this->assertSame(MenuDemandForecastService::TIER_INTERMITTENT_HISTORY, $res['tier']);
        $this->assertSame(9, $res['active_sales_days']);
    }

    // =========================================================================
    // 13. CLASSIFY INSUFFICIENT HISTORY
    // =========================================================================
    public function test_13_classify_insufficient_history(): void
    {
        // Only 4 active days (< 7)
        $vector = [];
        for ($i = 1; $i <= 28; $i++) {
            $date = sprintf('2026-09-%02d', $i);
            $vector[$date] = ($i <= 4) ? 2.0 : 0.0;
        }

        $res = $this->service->classifyHistory($vector, 28);
        $this->assertSame(MenuDemandForecastService::TIER_INSUFFICIENT_HISTORY, $res['tier']);
        $this->assertSame(4, $res['active_sales_days']);
    }

    // =========================================================================
    // 14. DOW MEDIAN FORECAST CALCULATION
    // =========================================================================
    public function test_14_dow_median_forecast_calculation(): void
    {
        // Target date: 2026-09-29 is a Tuesday.
        // 4 Tuesdays in the 28-day window prior to 2026-09-29:
        // 2026-09-01, 2026-09-08, 2026-09-15, 2026-09-22
        $dailyVector = [];
        // Fill window with active days to qualify for STRONG_HISTORY
        for ($i = 1; $i <= 28; $i++) {
            $d = sprintf('2026-09-%02d', $i);
            $dailyVector[$d] = 1.0;
        }

        // Set specific quantities on the 4 Tuesdays: 10, 14, 18, 22
        $dailyVector['2026-09-01'] = 10.0;
        $dailyVector['2026-09-08'] = 14.0;
        $dailyVector['2026-09-15'] = 18.0;
        $dailyVector['2026-09-22'] = 22.0;

        $targetDate = '2026-09-29';
        $dowRes = $this->service->calculateDowMedian($dailyVector, $targetDate, 'Asia/Jakarta');

        $this->assertFalse($dowRes['fallback_needed']);
        $this->assertSame(4, $dowRes['active_observations']);
        // Median of [10, 14, 18, 22] = (14 + 18) / 2 = 16.0
        $this->assertEquals(16.0, $dowRes['median_qty']);
    }

    // =========================================================================
    // 15. DOW FALLBACK TO WMA_14
    // =========================================================================
    public function test_15_dow_fallback_to_wma14(): void
    {
        // Target date: Tuesday 2026-09-29
        $dailyVector = [];
        for ($i = 1; $i <= 28; $i++) {
            $d = sprintf('2026-09-%02d', $i);
            $dailyVector[$d] = 5.0;
        }

        // Make Tuesdays have only 1 active observation (< 2 observations)
        $dailyVector['2026-09-01'] = 0.0;
        $dailyVector['2026-09-08'] = 0.0;
        $dailyVector['2026-09-15'] = 0.0;
        $dailyVector['2026-09-22'] = 8.0; // only 1 Tuesday active

        $targetDate = '2026-09-29';
        $dowRes = $this->service->calculateDowMedian($dailyVector, $targetDate, 'Asia/Jakarta');

        $this->assertTrue($dowRes['fallback_needed']);
        $this->assertSame(1, $dowRes['active_observations']);
    }

    // =========================================================================
    // 16. PAYDAY MULTIPLIER CALCULATION
    // =========================================================================
    public function test_16_payday_multiplier_calculation(): void
    {
        // Create 60 historical days
        $vector = [];
        $anchorDate = '2026-09-27'; // In payday window
        $targetDate = '2026-09-28'; // Also in payday window

        $start = Carbon::parse($anchorDate)->subDays(60);
        $curr = $start->copy();
        while ($curr->lt(Carbon::parse($anchorDate))) {
            $d = $curr->format('Y-m-d');
            $dom = (int) $curr->day;
            if ($dom >= 25) {
                $vector[$d] = 25.0; // Payday demand higher
            } else {
                $vector[$d] = 20.0; // Regular demand
            }
            $curr->addDay();
        }

        $res = $this->service->calculatePaydayMultiplier($vector, $anchorDate, $targetDate, 'Asia/Jakarta');

        $this->assertSame('APPLIED_PAYDAY_ADJUSTMENT', $res['status']);
        $this->assertTrue($res['is_target_payday']);
        $this->assertGreaterThanOrEqual(7, $res['payday_observations']);
        // Ratio = 25 / 20 = 1.25
        $this->assertEquals(1.25, $res['raw_multiplier']);
        $this->assertEquals(1.25, $res['applied_multiplier']);
    }

    // =========================================================================
    // 17. PAYDAY INSUFFICIENT EVIDENCE (FAIL-CLOSED)
    // =========================================================================
    public function test_17_payday_insufficient_evidence(): void
    {
        $vector = [];
        $anchorDate = '2026-09-27';
        $targetDate = '2026-09-28';

        // Only 3 payday days recorded (< 7 required)
        $vector['2026-08-25'] = 30.0;
        $vector['2026-08-26'] = 30.0;
        $vector['2026-08-27'] = 30.0;
        for ($i = 1; $i <= 20; $i++) {
            $vector[sprintf('2026-09-%02d', $i)] = 15.0;
        }

        $res = $this->service->calculatePaydayMultiplier($vector, $anchorDate, $targetDate, 'Asia/Jakarta');

        $this->assertSame('INSUFFICIENT_PAYDAY_EVIDENCE', $res['status']);
        $this->assertEquals(1.0, $res['applied_multiplier']);
        $this->assertNull($res['calculated_multiplier']);
    }

    // =========================================================================
    // 18. INVALID PRICE FINANCIAL IMPACT
    // =========================================================================
    public function test_18_invalid_price(): void
    {
        $zeroPriceMenu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA1->id,
            'name'      => 'Promo Tester Gratis',
            'category'  => 'Promo',
            'price'     => 0, // invalid price
            'stock'     => -1,
        ]);

        $res = $this->service->forecastMenu($this->tenantA->id, $zeroPriceMenu->id, $this->outletA1->id, '2026-09-30');

        $this->assertSame('INSUFFICIENT_DATA', $res['financial_impact']['status']);
        $this->assertNull($res['financial_impact']['potential_forecast_revenue']);
        $this->assertSame('potensi pendapatan berdasarkan forecast', $res['financial_impact']['label']);
    }

    // =========================================================================
    // 19. FINANCIAL IMPACT REVENUE CALCULATION
    // =========================================================================
    public function test_19_financial_impact_revenue_calculation(): void
    {
        // Populate 28 days of transactions for menuA1 (price: 5,000)
        for ($i = 1; $i <= 28; $i++) {
            $d = sprintf('2026-09-%02d', $i);
            $this->createTxWithItem($this->tenantA, $this->outletA1, $d, '12:00:00', $this->menuA1->id, $this->menuA1->name, 10.0, 5000);
        }

        $res = $this->service->forecastMenu($this->tenantA->id, $this->menuA1->id, $this->outletA1->id, '2026-09-29');

        $this->assertSame('SUCCESS', $res['status']);
        $this->assertEquals(10.0, $res['forecast']['forecast_qty']);
        $this->assertSame('CALCULATED', $res['financial_impact']['status']);
        $this->assertEquals(5000.0, $res['financial_impact']['unit_price']);
        // 10 * 5000 = 50,000
        $this->assertEquals(50000.0, $res['financial_impact']['potential_forecast_revenue']);
        $this->assertSame('potensi pendapatan berdasarkan forecast', $res['financial_impact']['label']);
    }

    // =========================================================================
    // 20. TENANT ISOLATION BOUNDARY
    // =========================================================================
    public function test_20_tenant_isolation(): void
    {
        // Menu for Tenant B with identical name and price
        $menuB = Menu::create([
            'user_id'   => $this->tenantB->id,
            'outlet_id' => $this->outletB1->id,
            'name'      => $this->menuA1->name,
            'category'  => 'Minuman',
            'price'     => 5000,
            'stock'     => -1,
        ]);

        // Tenant A sells 10 qty on 2026-09-15
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-15', '12:00:00', $this->menuA1->id, $this->menuA1->name, 10.0, 5000);

        // Tenant B sells 100 qty on 2026-09-15
        $this->createTxWithItem($this->tenantB, $this->outletB1, '2026-09-15', '12:00:00', $menuB->id, $menuB->name, 100.0, 5000);

        $demandA = $this->service->buildDailyDemand(
            $this->tenantA->id,
            $this->outletA1->id,
            '2026-09-15',
            '2026-09-15',
            null,
            [$this->menuA1->id]
        );

        $dayA = collect($demandA['records'])->firstWhere('date', '2026-09-15');
        // Tenant A must only see 10.0 qty, never contaminated by Tenant B's 100.0!
        $this->assertEquals(10.0, $dayA['qty']);
    }

    // =========================================================================
    // 21. OUTLET ISOLATION BOUNDARY
    // =========================================================================
    public function test_21_outlet_isolation(): void
    {
        // Menu in Outlet A-1 sells 5 qty
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-16', '12:00:00', $this->menuA1->id, $this->menuA1->name, 5.0, 5000);

        // Menu in Outlet A-2 sells 50 qty
        $this->createTxWithItem($this->tenantA, $this->outletA2, '2026-09-16', '12:00:00', $this->menuA1->id, $this->menuA1->name, 50.0, 5000);

        $demandOutlet1 = $this->service->buildDailyDemand(
            $this->tenantA->id,
            $this->outletA1->id,
            '2026-09-16',
            '2026-09-16',
            null,
            [$this->menuA1->id]
        );

        $dayOutlet1 = collect($demandOutlet1['records'])->firstWhere('date', '2026-09-16');
        // Outlet 1 query must strictly isolate to Outlet 1's 5.0 qty!
        $this->assertEquals(5.0, $dayOutlet1['qty']);
    }

    // =========================================================================
    // 22. ZERO DATABASE MUTATION VERIFICATION
    // =========================================================================
    public function test_22_zero_database_mutation(): void
    {
        // Populate sample data
        for ($i = 1; $i <= 28; $i++) {
            $d = sprintf('2026-09-%02d', $i);
            $this->createTxWithItem($this->tenantA, $this->outletA1, $d, '12:00:00', $this->menuA1->id, $this->menuA1->name, 5.0, 5000);
        }

        // Count rows before forecasting
        $txCountBefore = DB::table('transactions')->count();
        $itemCountBefore = DB::table('transaction_items')->count();
        $menuCountBefore = DB::table('menus')->count();
        $outletCountBefore = DB::table('outlets')->count();
        $userCountBefore = DB::table('users')->count();

        // Enable query log
        DB::enableQueryLog();

        // Run forecastMenu and forecastAllMenus
        $this->service->forecastMenu($this->tenantA->id, $this->menuA1->id, $this->outletA1->id, '2026-09-29');
        $this->service->forecastAllMenus($this->tenantA->id, $this->outletA1->id, '2026-09-29');

        $executedQueries = DB::getQueryLog();
        DB::disableQueryLog();

        // Check each executed query - NONE should be INSERT, UPDATE, DELETE, ALTER, DROP
        foreach ($executedQueries as $queryLog) {
            $sql = strtoupper(trim($queryLog['query']));
            $this->assertFalse(str_starts_with($sql, 'INSERT'), "Mutation detected: {$sql}");
            $this->assertFalse(str_starts_with($sql, 'UPDATE'), "Mutation detected: {$sql}");
            $this->assertFalse(str_starts_with($sql, 'DELETE'), "Mutation detected: {$sql}");
            $this->assertFalse(str_starts_with($sql, 'DROP'), "Mutation detected: {$sql}");
            $this->assertFalse(str_starts_with($sql, 'ALTER'), "Mutation detected: {$sql}");
        }

        // Count rows after forecasting
        $this->assertSame($txCountBefore, DB::table('transactions')->count());
        $this->assertSame($itemCountBefore, DB::table('transaction_items')->count());
        $this->assertSame($menuCountBefore, DB::table('menus')->count());
        $this->assertSame($outletCountBefore, DB::table('outlets')->count());
        $this->assertSame($userCountBefore, DB::table('users')->count());
    }

    // =========================================================================
    // 23. STOCK MONITORING BOUNDARIES
    // =========================================================================
    public function test_23_stock_monitoring_boundaries(): void
    {
        // 1. Untracked (-1)
        $menuUntracked = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA1->id,
            'name'      => 'Kopi Untracked',
            'category'  => 'Minuman',
            'price'     => 10000,
            'stock'     => -1,
        ]);
        $resUntracked = $this->service->forecastMenu($this->tenantA->id, $menuUntracked->id, $this->outletA1->id, '2026-09-29');
        $this->assertSame('STOCK_UNTRACKED', $resUntracked['stock_monitoring']['status']);
        $this->assertEquals(-1, $resUntracked['stock_monitoring']['recorded_stock']);

        // 2. Anomalous (> 1000)
        $menuAnomalous = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA1->id,
            'name'      => 'Kopi Anomalous',
            'category'  => 'Minuman',
            'price'     => 10000,
            'stock'     => 9999,
        ]);
        $resAnomalous = $this->service->forecastMenu($this->tenantA->id, $menuAnomalous->id, $this->outletA1->id, '2026-09-29');
        $this->assertSame('STOCK_ANOMALOUS', $resAnomalous['stock_monitoring']['status']);
        $this->assertEquals(9999.0, $resAnomalous['stock_monitoring']['recorded_stock']);

        // 3. Tracked (0 <= stock <= 1000)
        $menuTracked = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA1->id,
            'name'      => 'Kopi Tracked',
            'category'  => 'Minuman',
            'price'     => 10000,
            'stock'     => 50,
        ]);
        $resTracked = $this->service->forecastMenu($this->tenantA->id, $menuTracked->id, $this->outletA1->id, '2026-09-29');
        $this->assertSame('STOCK_TRACKED', $resTracked['stock_monitoring']['status']);
        $this->assertEquals(50.0, $resTracked['stock_monitoring']['recorded_stock']);
    }

    // =========================================================================
    // 24. TWO-QUERY BATCH ARCHITECTURE (ZERO N+1)
    // =========================================================================
    public function test_24_two_query_batch_architecture_zero_n_plus_one(): void
    {
        // Tenant A has multiple menus
        for ($m = 1; $m <= 5; $m++) {
            $menu = Menu::create([
                'user_id'   => $this->tenantA->id,
                'outlet_id' => $this->outletA1->id,
                'name'      => "Batch Menu {$m}",
                'category'  => 'Minuman',
                'price'     => 10000,
                'stock'     => 20,
            ]);

            // Add transactions for each menu
            for ($d = 1; $d <= 15; $d++) {
                $dateStr = sprintf('2026-09-%02d', $d);
                $this->createTxWithItem($this->tenantA, $this->outletA1, $dateStr, '12:00:00', $menu->id, $menu->name, 2.0, 10000);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $batchResult = $this->service->forecastAllMenus($this->tenantA->id, $this->outletA1->id, '2026-09-29', ['timezone' => 'Asia/Jakarta']);

        $executedQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame('SUCCESS', $batchResult['status']);
        $this->assertGreaterThanOrEqual(5, count($batchResult['menus']));

        // Verify exactly 2 DB queries were executed:
        // Query 1: preload master menus
        // Query 2: retrieve transaction ledger for window
        $this->assertCount(2, $executedQueries, 'Batch forecast must execute exactly 2 queries (zero N+1).');
    }

    // =========================================================================
    // 25. EXACT CUTOFF CANCELLATION BOUNDARY (AT CUTOFF VS 1 SEC AFTER)
    // =========================================================================
    public function test_25_point_in_time_exact_cutoff_cancellation_boundary(): void
    {
        $cutoffUtc = '2026-09-20 12:00:00';

        // Tx A: Cancelled EXACTLY at cutoff (updated_at == cutoffUtc) -> MUST BE EXCLUDED
        $this->createTxWithItem(
            $this->tenantA,
            $this->outletA1,
            '2026-09-18',
            '10:00:00',
            $this->menuA1->id,
            $this->menuA1->name,
            5.0,
            5000,
            'Batal',
            $cutoffUtc
        );

        // Tx B: Cancelled 1 SECOND AFTER cutoff (updated_at == cutoffUtc + 1s) -> MUST BE INCLUDED
        $afterCutoff = '2026-09-20 12:00:01';
        $this->createTxWithItem(
            $this->tenantA,
            $this->outletA1,
            '2026-09-18',
            '11:00:00',
            $this->menuA1->id,
            $this->menuA1->name,
            7.0,
            5000,
            'Batal',
            $afterCutoff
        );

        $result = $this->service->buildDailyDemand(
            $this->tenantA->id,
            $this->outletA1->id,
            '2026-09-18',
            '2026-09-18',
            $cutoffUtc,
            ['timezone' => 'Asia/Jakarta']
        );

        // Demand on 2026-09-18 must be exactly 7.0 (Tx B included, Tx A excluded)
        $this->assertEquals(7.0, $result['by_menu'][$this->menuA1->id]['2026-09-18']);
    }

    // =========================================================================
    // 26. PAYDAY EXACT BOUNDARY (6 VS 7 OBSERVATIONS)
    // =========================================================================
    public function test_26_payday_exact_boundary_6_vs_7_observations(): void
    {
        $vector6 = [];
        $vector7 = [];

        // Build continuous 60-day calendar from 2026-07-31 to 2026-09-28
        $cur = Carbon::parse('2026-07-31');
        $end = Carbon::parse('2026-09-28');
        while ($cur->lte($end)) {
            $d = $cur->format('Y-m-d');
            $vector6[$d] = 0.0;
            $vector7[$d] = 0.0;
            $cur->addDay();
        }

        // For vector6, first active date is 2026-08-30
        // Payday dates in window [2026-08-30, 2026-09-29):
        // 2026-08-30, 2026-08-31 (2) + 2026-09-25, 26, 27, 28 (4) = 6 observations
        $cur = Carbon::parse('2026-08-30');
        while ($cur->lte($end)) {
            $d = $cur->format('Y-m-d');
            $vector6[$d] = 10.0;
            $cur->addDay();
        }

        // For vector7, first active date is 2026-08-29
        // Payday dates in window [2026-08-29, 2026-09-29):
        // 2026-08-29, 30, 31 (3) + 2026-09-25, 26, 27, 28 (4) = 7 observations
        $cur = Carbon::parse('2026-08-29');
        while ($cur->lte($end)) {
            $d = $cur->format('Y-m-d');
            $vector7[$d] = 10.0;
            $cur->addDay();
        }

        $res6 = $this->service->calculatePaydayMultiplier($vector6, '2026-09-29', '2026-09-30', 'Asia/Jakarta');
        $res7 = $this->service->calculatePaydayMultiplier($vector7, '2026-09-29', '2026-09-30', 'Asia/Jakarta');

        $this->assertSame('INSUFFICIENT_PAYDAY_EVIDENCE', $res6['status']);
        $this->assertEquals(1.0, $res6['applied_multiplier']);
        $this->assertEquals(6, $res6['payday_observations']);

        $this->assertSame('APPLIED_PAYDAY_ADJUSTMENT', $res7['status']);
        $this->assertEquals(1.0, $res7['applied_multiplier']); // regular 10.0 == payday 10.0
        $this->assertEquals(7, $res7['payday_observations']);
    }

    // =========================================================================
    // 27. EXHAUSTIVE STOCK BOUNDARY (-1 / 0 / 1000 / 1000.01 / ZERO FORECAST)
    // =========================================================================
    public function test_27_stock_boundary_exhaustive(): void
    {
        $mUntracked = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA1->id,
            'name'      => 'Menu Untracked',
            'category'  => 'Test',
            'price'     => 10000,
            'stock'     => -1.0,
        ]);

        $mTrackedZero = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA1->id,
            'name'      => 'Menu Tracked Zero',
            'category'  => 'Test',
            'price'     => 10000,
            'stock'     => 0.0,
        ]);

        $mTracked1000 = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA1->id,
            'name'      => 'Menu Tracked 1000',
            'category'  => 'Test',
            'price'     => 10000,
            'stock'     => 1000.0,
        ]);

        $mAnomalous = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => $this->outletA1->id,
            'name'      => 'Menu Anomalous',
            'category'  => 'Test',
            'price'     => 10000,
            'stock'     => 1000.01,
        ]);

        for ($d = 1; $d <= 15; $d++) {
            $dateStr = sprintf('2026-09-%02d', $d);
            $this->createTxWithItem($this->tenantA, $this->outletA1, $dateStr, '12:00:00', $mTracked1000->id, $mTracked1000->name, 10.0, 10000);
        }

        $resUntracked = $this->service->forecastMenu($this->tenantA->id, $mUntracked->id, $this->outletA1->id, '2026-09-29');
        $this->assertSame('STOCK_UNTRACKED', $resUntracked['stock_monitoring']['status']);
        $this->assertNull($resUntracked['stock_monitoring']['run_rate_days']);

        $resTrackedZero = $this->service->forecastMenu($this->tenantA->id, $mTrackedZero->id, $this->outletA1->id, '2026-09-29');
        $this->assertSame('STOCK_TRACKED', $resTrackedZero['stock_monitoring']['status']);
        $this->assertNull($resTrackedZero['stock_monitoring']['run_rate_days']);

        $resTracked1000 = $this->service->forecastMenu($this->tenantA->id, $mTracked1000->id, $this->outletA1->id, '2026-09-29');
        $this->assertSame('STOCK_TRACKED', $resTracked1000['stock_monitoring']['status']);
        $this->assertNotNull($resTracked1000['stock_monitoring']['run_rate_days']);
        $this->assertEquals((int) floor(1000.0 / $resTracked1000['forecast']['forecast_qty']), $resTracked1000['stock_monitoring']['run_rate_days']);

        $resAnomalous = $this->service->forecastMenu($this->tenantA->id, $mAnomalous->id, $this->outletA1->id, '2026-09-29');
        $this->assertSame('STOCK_ANOMALOUS', $resAnomalous['stock_monitoring']['status']);
        $this->assertNull($resAnomalous['stock_monitoring']['run_rate_days']);
    }

    // =========================================================================
    // 28. GLOBAL MENU OUTLET_ID NULL / 0 RESOLUTION
    // =========================================================================
    public function test_28_global_menu_outlet_id_null_resolution(): void
    {
        $globalMenu = Menu::create([
            'user_id'   => $this->tenantA->id,
            'outlet_id' => 0, // Global menu (tenant level) across all outlets of tenant A
            'name'      => 'Kopi Susu Global',
            'category'  => 'Minuman',
            'price'     => 15000,
            'stock'     => 100,
        ]);

        $this->createTxWithItem(
            $this->tenantA,
            $this->outletA1,
            '2026-09-15',
            '10:00:00',
            $globalMenu->id,
            $globalMenu->name,
            4.0,
            15000
        );

        $demand = $this->service->buildDailyDemand(
            $this->tenantA->id,
            $this->outletA1->id,
            '2026-09-15',
            '2026-09-15',
            null,
            ['timezone' => 'Asia/Jakarta']
        );

        $this->assertEquals(4.0, $demand['by_menu'][$globalMenu->id]['2026-09-15']);

        $forecast = $this->service->forecastMenu(
            $this->tenantA->id,
            $globalMenu->id,
            $this->outletA1->id,
            '2026-09-29'
        );

        $this->assertSame('Kopi Susu Global', $forecast['menu_name']);
        $this->assertSame('STOCK_TRACKED', $forecast['stock_monitoring']['status']);
    }

    // =========================================================================
    // 29. MULTIPLE TRANSACTION ITEMS ON SAME TRANSACTION
    // =========================================================================
    public function test_29_multiple_items_same_transaction_same_day(): void
    {
        $utcCreated = '2026-09-15 05:00:00';
        $txId = DB::table('transactions')->insertGetId([
            'app_uuid'                => (string) \Illuminate\Support\Str::uuid(),
            'user_id'                 => $this->tenantA->id,
            'outlet_id'               => $this->outletA1->id,
            'official_invoice_number' => 'INV-MULTI-ITEM',
            'total_amount'            => 40000,
            'pay_amount'              => 40000,
            'payment_method'          => 'Tunai',
            'status'                  => 'Selesai',
            'created_at_device'       => $utcCreated,
            'created_at'              => $utcCreated,
            'updated_at'              => $utcCreated,
        ]);

        DB::table('transaction_items')->insert([
            'transaction_id' => $txId,
            'menu_id'        => $this->menuA1->id,
            'menu_name'      => $this->menuA1->name,
            'qty'            => 3.0,
            'price'          => 5000,
            'status'         => 'Selesai',
            'created_at'     => $utcCreated,
            'updated_at'     => $utcCreated,
        ]);

        DB::table('transaction_items')->insert([
            'transaction_id' => $txId,
            'menu_id'        => $this->menuA1->id,
            'menu_name'      => $this->menuA1->name,
            'qty'            => 5.0,
            'price'          => 5000,
            'status'         => 'Selesai',
            'created_at'     => $utcCreated,
            'updated_at'     => $utcCreated,
        ]);

        $demand = $this->service->buildDailyDemand(
            $this->tenantA->id,
            $this->outletA1->id,
            '2026-09-15',
            '2026-09-15',
            null,
            ['timezone' => 'Asia/Jakarta']
        );

        $this->assertEquals(8.0, $demand['by_menu'][$this->menuA1->id]['2026-09-15']);
    }

    // =========================================================================
    // 30. TIMEZONE MIDNIGHT BOUNDARY (23:59:59 WIB VS 00:00:00 WIB NEXT DAY)
    // =========================================================================
    public function test_30_timezone_midnight_boundary_235959_vs_000000(): void
    {
        $this->createTxWithItem(
            $this->tenantA,
            $this->outletA1,
            '2026-09-15',
            '23:59:59',
            $this->menuA1->id,
            $this->menuA1->name,
            4.0,
            5000
        );

        $this->createTxWithItem(
            $this->tenantA,
            $this->outletA1,
            '2026-09-16',
            '00:00:00',
            $this->menuA1->id,
            $this->menuA1->name,
            6.0,
            5000
        );

        $demand15 = $this->service->buildDailyDemand(
            $this->tenantA->id,
            $this->outletA1->id,
            '2026-09-15',
            '2026-09-15',
            null,
            ['timezone' => 'Asia/Jakarta']
        );

        $demand16 = $this->service->buildDailyDemand(
            $this->tenantA->id,
            $this->outletA1->id,
            '2026-09-16',
            '2026-09-16',
            null,
            ['timezone' => 'Asia/Jakarta']
        );

        $this->assertEquals(4.0, $demand15['by_menu'][$this->menuA1->id]['2026-09-15']);
        $this->assertEquals(6.0, $demand16['by_menu'][$this->menuA1->id]['2026-09-16']);
    }

    // =========================================================================
    // 31. ZERO-SALES DOW MEDIAN PRESERVATION (EVEN COUNT)
    // =========================================================================
    public function test_31_zero_sales_dow_median_preservation(): void
    {
        $vector = [
            '2026-09-01' => 0.0,
            '2026-09-08' => 10.0,
            '2026-09-15' => 14.0,
            '2026-09-22' => 20.0,
        ];

        // 2026-09-29 is a Tuesday
        $dowResult = $this->service->calculateDowMedian($vector, '2026-09-29', 'Asia/Jakarta');

        $this->assertFalse($dowResult['fallback_needed']);
        $this->assertEquals(4, $dowResult['dow_observations_count']);
        $this->assertEquals(12.0, $dowResult['median_qty']);
    }

    // =========================================================================
    // 32. DETERMINISTIC REPEATED EXECUTION (ZERO STATE LEAKAGE)
    // =========================================================================
    public function test_32_deterministic_repeated_execution(): void
    {
        for ($d = 1; $d <= 15; $d++) {
            $dateStr = sprintf('2026-09-%02d', $d);
            $this->createTxWithItem($this->tenantA, $this->outletA1, $dateStr, '12:00:00', $this->menuA1->id, $this->menuA1->name, 5.0, 5000);
            $this->createTxWithItem($this->tenantA, $this->outletA1, $dateStr, '12:00:00', $this->menuA2->id, $this->menuA2->name, 3.0, 25000);
        }

        $res1 = $this->service->forecastAllMenus($this->tenantA->id, $this->outletA1->id, '2026-09-29', ['timezone' => 'Asia/Jakarta']);
        $res2 = $this->service->forecastAllMenus($this->tenantA->id, $this->outletA1->id, '2026-09-29', ['timezone' => 'Asia/Jakarta']);

        $this->assertSame($res1['status'], $res2['status']);
        $this->assertSame($res1['total_menus'], $res2['total_menus']);
        $this->assertEquals($res1['summary'], $res2['summary']);
        $this->assertEquals($res1['menus'], $res2['menus']);
    }

    // =========================================================================
    // 33. WHITESPACE AND CASE INSENSITIVE NAME RESOLUTION
    // =========================================================================
    public function test_33_whitespace_and_case_insensitive_name_resolution(): void
    {
        $resolved = $this->service->resolveMenuIdentity(
            $this->tenantA->id,
            $this->outletA1->id,
            ['menu_id' => null, 'menu_name' => "   es   TEH   manis   segar   \n"]
        );

        $this->assertSame($this->menuA1->id, $resolved['resolved_menu_id']);
        $this->assertSame('RESOLVED', $resolved['status']);
        $this->assertSame(MenuDemandForecastService::RES_EXACT_TENANT_NAME, $resolved['resolution_source']);
        $this->assertEquals(0.95, $resolved['confidence']);
    }

    // =========================================================================
    // 34. CALCULATE EVALUATION METRICS DETERMINISM & EDGE CASES (Phase 3.4C)
    // =========================================================================
    public function test_34_calculate_evaluation_metrics_determinism_and_edge_cases(): void
    {
        // Case A: Normal vectors with varied errors
        $forecasts = [10.0, 20.0, 30.0];
        $actuals   = [12.0, 18.0, 30.0];
        $resA = $this->service->calculateEvaluationMetrics($forecasts, $actuals);

        $this->assertSame(MenuDemandForecastService::BACKTEST_STATUS_SUCCESS, $resA['status']);
        $this->assertEquals(1.3333, $resA['mae']);
        $this->assertEquals(6.67, $resA['wape']);
        $this->assertEquals(0.0, $resA['bias']);
        $this->assertEquals(60.0, $resA['actual_total']);
        $this->assertEquals(60.0, $resA['forecast_total']);

        // Case B: Severe Underprediction Bias
        $resB = $this->service->calculateEvaluationMetrics([10.0, 10.0], [20.0, 20.0]);
        $this->assertSame(MenuDemandForecastService::BACKTEST_STATUS_SUCCESS, $resB['status']);
        $this->assertEquals(10.0, $resB['mae']);
        $this->assertEquals(50.0, $resB['wape']);
        $this->assertEquals(-50.0, $resB['bias']);

        // Case C: Actual sales zero with non-zero forecast (Division by zero safe)
        $resC = $this->service->calculateEvaluationMetrics([5.0, 5.0], [0.0, 0.0]);
        $this->assertSame(MenuDemandForecastService::BACKTEST_STATUS_NO_ACTUAL_SALES, $resC['status']);
        $this->assertNull($resC['wape']);
        $this->assertNull($resC['bias']);
        $this->assertEquals(5.0, $resC['mae']);

        // Case D: Actual and forecast both zero
        $resD = $this->service->calculateEvaluationMetrics([0.0, 0.0], [0.0, 0.0]);
        $this->assertSame(MenuDemandForecastService::BACKTEST_STATUS_NO_ACTUAL_SALES, $resD['status']);
        $this->assertNull($resD['wape']);
        $this->assertNull($resD['bias']);
        $this->assertEquals(0.0, $resD['mae']);

        // Case E: Vector length mismatch
        $resE = $this->service->calculateEvaluationMetrics([10.0], [10.0, 20.0]);
        $this->assertSame('INVALID_VECTORS', $resE['status']);
        $this->assertNull($resE['wape']);
    }

    // =========================================================================
    // 35. EMPIRICAL BACKTEST POINT-IN-TIME & MULTI-MODEL METRICS (Phase 3.4C)
    // =========================================================================
    public function test_35_empirical_backtest_point_in_time_and_metrics(): void
    {
        // 1. Seed 28 days of history prior to anchor date 2026-09-20
        for ($d = 1; $d <= 20; $d++) {
            $dateStr = sprintf('2026-09-%02d', $d);
            $this->createTxWithItem($this->tenantA, $this->outletA1, $dateStr, '12:00:00', $this->menuA1->id, $this->menuA1->name, 10.0, 5000);
        }

        // 2. Seed 7 days of actual realization in evaluation window (2026-09-21 .. 2026-09-27)
        for ($d = 21; $d <= 27; $d++) {
            $dateStr = sprintf('2026-09-%02d', $d);
            $this->createTxWithItem($this->tenantA, $this->outletA1, $dateStr, '12:00:00', $this->menuA1->id, $this->menuA1->name, 12.0, 5000);
        }

        $res = $this->service->backtest(
            $this->tenantA->id,
            $this->outletA1->id,
            '2026-09-20',
            [
                'horizon_days' => 7,
                'menu_id'      => $this->menuA1->id,
                'timezone'     => 'Asia/Jakarta',
            ]
        );

        $this->assertSame(MenuDemandForecastService::BACKTEST_STATUS_SUCCESS, $res['status']);
        $this->assertSame('2026-09-20', $res['anchor_date']);
        $this->assertSame('2026-09-21', $res['eval_window']['start_date']);
        $this->assertSame('2026-09-27', $res['eval_window']['end_date']);
        $this->assertSame(7, $res['eval_window']['horizon_days']);

        // Check models comparison structure
        $this->assertArrayHasKey('engine', $res['models_comparison']);
        $this->assertArrayHasKey('naive_last_week', $res['models_comparison']);
        $this->assertArrayHasKey('sma_14', $res['models_comparison']);
        $this->assertArrayHasKey('historical_median', $res['models_comparison']);

        // Check segment breakdown structure
        $this->assertArrayHasKey('strong_history', $res['segment_breakdown']);
        $this->assertArrayHasKey('intermittent_history', $res['segment_breakdown']);
        $this->assertArrayHasKey('insufficient_history', $res['segment_breakdown']);
        $this->assertArrayHasKey('zero_demand_days', $res['segment_breakdown']);
        $this->assertArrayHasKey('payday_days', $res['segment_breakdown']);
        $this->assertArrayHasKey('dow_performance', $res['segment_breakdown']);

        // Check point-in-time guard
        $this->assertSame('STRICTLY_BLOCKED', $res['point_in_time_guard']['future_leakage']);
        $this->assertNotEmpty($res['point_in_time_guard']['anchor_cutoff_utc']);
    }

    // =========================================================================
    // 36. EMPIRICAL BACKTEST FUTURE LEAKAGE DEFENSE (Phase 3.4C)
    // =========================================================================
    public function test_36_empirical_backtest_future_leakage_defense(): void
    {
        // Seed 14 days of baseline prior to anchor date 2026-09-14
        for ($d = 1; $d <= 14; $d++) {
            $dateStr = sprintf('2026-09-%02d', $d);
            $this->createTxWithItem($this->tenantA, $this->outletA1, $dateStr, '12:00:00', $this->menuA1->id, $this->menuA1->name, 10.0, 5000);
        }

        // Run backtest without any future anomalies
        $resBaseline = $this->service->backtest(
            $this->tenantA->id,
            $this->outletA1->id,
            '2026-09-14',
            ['horizon_days' => 7, 'menu_id' => $this->menuA1->id, 'timezone' => 'Asia/Jakarta']
        );
        $forecastBeforeFutureTx = $resBaseline['models_comparison']['engine']['forecast_total'];

        // Now insert massive transaction in the future evaluation window (2026-09-16)
        $this->createTxWithItem($this->tenantA, $this->outletA1, '2026-09-16', '12:00:00', $this->menuA1->id, $this->menuA1->name, 9999.0, 5000);

        // Re-run backtest anchored at the same cutoff 2026-09-14
        $resAfter = $this->service->backtest(
            $this->tenantA->id,
            $this->outletA1->id,
            '2026-09-14',
            ['horizon_days' => 7, 'menu_id' => $this->menuA1->id, 'timezone' => 'Asia/Jakarta']
        );
        $forecastAfterFutureTx = $resAfter['models_comparison']['engine']['forecast_total'];

        // The engine's forecast total MUST be completely identical because the cutoff blocked the future transaction!
        $this->assertEquals($forecastBeforeFutureTx, $forecastAfterFutureTx);

        // But actual realization MUST reflect the future transaction
        $this->assertGreaterThan($resBaseline['models_comparison']['engine']['actual_total'], $resAfter['models_comparison']['engine']['actual_total']);
    }

    // =========================================================================
    // 37. EMPIRICAL BACKTEST SUPERIORITY OVER SMA AND NAIVE ON OUTLIER RESILIENCE (Phase 3.4C)
    // =========================================================================
    public function test_37_empirical_backtest_superiority_over_sma_on_strong_dow_pattern(): void
    {
        // Anchor: 2026-09-12 (Saturday, mid-month, no payday distortion)
        // 4 weeks history: 2026-08-16 (Sun) to 2026-09-12 (Sat)
        // Normal Saturday = 100, weekdays = 10
        // BUT last Saturday (2026-09-12) had an outlier: outage, sales dropped to 20
        $cur = \Carbon\Carbon::parse('2026-08-16', 'Asia/Jakarta');
        $anchor = \Carbon\Carbon::parse('2026-09-12', 'Asia/Jakarta');

        while ($cur->lte($anchor)) {
            $isLastSat = $cur->format('Y-m-d') === '2026-09-12';
            $qty = $isLastSat ? 20.0 : ($cur->isSaturday() ? 100.0 : 10.0);
            $this->createTxWithItem($this->tenantA, $this->outletA1, $cur->format('Y-m-d'), '12:00:00', $this->menuA1->id, $this->menuA1->name, $qty, 5000);
            $cur->addDay();
        }

        // Evaluation horizon: next 7 days 2026-09-13 (Sun) to 2026-09-19 (Sat)
        // Realized sales return to normal (Saturday = 100, weekdays = 10)
        $evalEnd = \Carbon\Carbon::parse('2026-09-19', 'Asia/Jakarta');
        $curEval = \Carbon\Carbon::parse('2026-09-13', 'Asia/Jakarta');
        while ($curEval->lte($evalEnd)) {
            $qty = $curEval->isSaturday() ? 100.0 : 10.0;
            $this->createTxWithItem($this->tenantA, $this->outletA1, $curEval->format('Y-m-d'), '12:00:00', $this->menuA1->id, $this->menuA1->name, $qty, 5000);
            $curEval->addDay();
        }

        $res = $this->service->backtest(
            $this->tenantA->id,
            $this->outletA1->id,
            '2026-09-12',
            ['horizon_days' => 7, 'menu_id' => $this->menuA1->id, 'timezone' => 'Asia/Jakarta']
        );

        $engineWape = $res['models_comparison']['engine']['wape'];
        $smaWape    = $res['models_comparison']['sma_14']['wape'];
        $naiveWape  = $res['models_comparison']['naive_last_week']['wape'];

        // Naive last week took the outlier (20 instead of 100), SMA-14 smeared it.
        // Deterministic DOW median filtered the outlier out and forecasted 100!
        $this->assertLessThan($smaWape, $engineWape);
        $this->assertLessThan($naiveWape, $engineWape);
        $this->assertSame(MenuDemandForecastService::BACKTEST_VERDICT_SUPERIOR, $res['verdict']);
    }
}
