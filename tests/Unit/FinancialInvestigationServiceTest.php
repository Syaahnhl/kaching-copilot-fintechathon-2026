<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Outlet;
use App\Models\User;
use App\Services\Financial\FinancialCalculationService;
use App\Services\Financial\FinancialInvestigationService;
use App\Services\Financial\FinancialWarningService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FinancialInvestigationServiceTest
 *
 * Comprehensive unit and feature test suite for Phase 5.16-A:
 * Autonomous SME Financial Investigation Engine.
 */
class FinancialInvestigationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected FinancialInvestigationService $service;
    protected User $user;
    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FinancialInvestigationService::class);

        $this->user = User::factory()->create([
            'email' => 'merchant_investigation@kaching.test',
        ]);

        $this->outlet = Outlet::create([
            'user_id'   => $this->user->id,
            'name'      => 'Investigation Outlet',
            'device_id' => 'DEV-INVESTIGATE-01',
        ]);
    }

    /**
     * Helper to seed standard baseline transactions.
     */
    protected function seedPeriodTransactions(
        int $userId,
        int $outletId,
        string $startDate,
        int $days,
        float $dailyRevenue,
        float $cogsRatio = 0.4
    ): void {
        for ($i = 0; $i < $days; $i++) {
            $date = Carbon::parse($startDate)->addDays($i)->format('Y-m-d') . ' 12:00:00';
            $trxId = DB::table('transactions')->insertGetId([
                'user_id'                 => $userId,
                'outlet_id'               => $outletId,
                'app_uuid'                => 'tx-uuid-' . uniqid('', true),
                'official_invoice_number' => 'INV-' . uniqid(),
                'total_amount'            => $dailyRevenue,
                'pay_amount'              => $dailyRevenue,
                'payment_method'          => 'cash',
                'status'                  => 'Selesai',
                'created_at_device'       => $date,
                'created_at'              => $date,
                'updated_at'              => $date,
            ]);

            DB::table('transaction_items')->insert([
                'transaction_id' => $trxId,
                'menu_name'      => 'Sample Menu',
                'qty'            => 10,
                'price'          => $dailyRevenue / 10.0,
                'cost_price'     => ($dailyRevenue / 10.0) * $cogsRatio,
                'created_at'     => $date,
                'updated_at'     => $date,
            ]);
        }
    }

    /**
     * Helper to seed expenses.
     */
    protected function seedPeriodExpenses(
        int $userId,
        int $outletId,
        string $startDate,
        int $days,
        float $dailyExpense
    ): void {
        for ($i = 0; $i < $days; $i++) {
            $date = Carbon::parse($startDate)->addDays($i)->format('Y-m-d');
            DB::table('expenses')->insert([
                'user_id'    => $userId,
                'outlet_id'  => $outletId,
                'name'       => 'Biaya Operasional',
                'date'       => $date,
                'category'   => 'Operasional',
                'amount'     => $dailyExpense,
                'created_at' => $date . ' 12:00:00',
                'updated_at' => $date . ' 12:00:00',
            ]);
        }
    }

    public function testClassifyInvestigationTypesCorrectly(): void
    {
        $this->assertEquals(
            FinancialInvestigationService::TYPE_PROFIT_DECLINE,
            $this->service->classifyInvestigationType('Kenapa profit saya turun?')
        );
        $this->assertEquals(
            FinancialInvestigationService::TYPE_PROFIT_DECLINE,
            $this->service->classifyInvestigationType('Kenapa laba saya turun drastis?')
        );
        $this->assertEquals(
            FinancialInvestigationService::TYPE_REVENUE_DECLINE,
            $this->service->classifyInvestigationType('Kenapa omzet turun minggu ini?')
        );
        $this->assertEquals(
            FinancialInvestigationService::TYPE_REVENUE_DECLINE,
            $this->service->classifyInvestigationType('Kenapa penjualan sepi?')
        );
        $this->assertEquals(
            FinancialInvestigationService::TYPE_EXPENSE_PRESSURE,
            $this->service->classifyInvestigationType('Apakah masalah saya dari biaya?')
        );
        $this->assertEquals(
            FinancialInvestigationService::TYPE_CASH_DRAIN,
            $this->service->classifyInvestigationType('Kenapa cash saya menipis?')
        );
        $this->assertEquals(
            FinancialInvestigationService::TYPE_STOCK_IMPACT,
            $this->service->classifyInvestigationType('Apakah stok berpengaruh?')
        );
    }

    public function testInvestigateProfitDeclineDrivenByRevenueDrop(): void
    {
        // Anchor date: 2026-04-01
        // Prev period: 60 days to 31 days ago (higher revenue)
        // Curr period: 30 days to 0 days ago (lower revenue: -30%)
        $anchor = Carbon::parse('2026-04-01', 'Asia/Jakarta');

        $prevStart = $anchor->copy()->subDays(61)->format('Y-m-d');
        $this->seedPeriodTransactions($this->user->id, $this->outlet->id, $prevStart, 30, 1000000.0, 0.4); // 30M rev, 12M cogs
        $this->seedPeriodExpenses($this->user->id, $this->outlet->id, $prevStart, 30, 200000.0); // 6M exp

        $currStart = $anchor->copy()->subDays(30)->format('Y-m-d');
        $this->seedPeriodTransactions($this->user->id, $this->outlet->id, $currStart, 30, 700000.0, 0.4); // 21M rev (-30%), 8.4M cogs
        $this->seedPeriodExpenses($this->user->id, $this->outlet->id, $currStart, 30, 200000.0); // 6M exp

        $result = $this->service->investigate(
            $this->user->id,
            $this->outlet->id,
            'Kenapa profit saya turun?',
            ['anchor_date' => '2026-04-01', 'days' => 30]
        );

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals(FinancialInvestigationService::TYPE_PROFIT_DECLINE, $result['investigation_type']);
        $this->assertEquals(FinancialInvestigationService::CONFIDENCE_HIGH, $result['confidence']);
        $this->assertLessThan(-20.0, $result['metrics']['change']['revenue_pct']);

        // Check canonical output formatting
        $formatted = $result['formatted_output'];
        $this->assertStringContainsString('FINANCIAL HEALTH', $formatted);
        $this->assertStringContainsString('CHANGE', $formatted);
        $this->assertStringContainsString('ROOT CAUSE', $formatted);
        $this->assertStringContainsString('CONCLUSION', $formatted);
        $this->assertStringContainsString('EVIDENCE', $formatted);
        $this->assertStringContainsString('RECOMMENDATION', $formatted);
        $this->assertStringContainsString('CONFIDENCE', $formatted);
        $this->assertStringContainsString('HIGH', $formatted);
        $this->assertStringContainsString('Tekanan laba terutama berasal dari penurunan penjualan', $result['conclusion']);
    }

    public function testInvestigateProfitDeclineDrivenByMarginCompression(): void
    {
        $anchor = Carbon::parse('2026-04-01', 'Asia/Jakarta');

        $prevStart = $anchor->copy()->subDays(61)->format('Y-m-d');
        $this->seedPeriodTransactions($this->user->id, $this->outlet->id, $prevStart, 30, 1000000.0, 0.35); // Margin 65%

        $currStart = $anchor->copy()->subDays(30)->format('Y-m-d');
        $this->seedPeriodTransactions($this->user->id, $this->outlet->id, $currStart, 30, 1000000.0, 0.55); // Margin 45% (-20pp compression)

        $result = $this->service->investigate(
            $this->user->id,
            $this->outlet->id,
            'Kenapa laba saya turun?',
            ['anchor_date' => '2026-04-01', 'days' => 30]
        );

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertLessThan(-5.0, $result['metrics']['change']['gross_margin_pp']);
        $this->assertStringContainsString('kompresi margin kotor', $result['conclusion']);
    }

    public function testInvestigateInsufficientEvidenceReturnsLowConfidence(): void
    {
        // Zero transactions in both periods
        $result = $this->service->investigate(
            $this->user->id,
            $this->outlet->id,
            'Kenapa profit saya turun?',
            ['anchor_date' => '2026-04-01', 'days' => 30]
        );

        $this->assertEquals('INSUFFICIENT_EVIDENCE', $result['status']);
        $this->assertEquals(FinancialInvestigationService::CONFIDENCE_LOW, $result['confidence']);
        $this->assertStringContainsString('Data historis transaksi belum mencukupi', $result['message']);
        $this->assertStringContainsString('LOW', $result['formatted_output']);
    }

    public function testInvestigateUnauthorizedOutletFailsClosed(): void
    {
        $otherUser = User::factory()->create();
        $otherOutlet = Outlet::create([
            'user_id'   => $otherUser->id,
            'name'      => 'Other Merchant Outlet',
            'device_id' => 'DEV-OTHER-01',
        ]);

        $result = $this->service->investigate(
            $this->user->id,
            $otherOutlet->id, // Tenant mismatch probe
            'Kenapa profit saya turun?'
        );

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('UNAUTHORIZED_OUTLET', $result['code']);
    }
}
