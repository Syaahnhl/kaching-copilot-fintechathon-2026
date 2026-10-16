<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Outlet;
use App\Models\User;
use App\Services\Financial\FinancingCapacitySimulatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FinancingCapacitySimulatorServiceTest
 *
 * Comprehensive unit test suite for Phase 5.16-B:
 * Deterministic SME Financing Capacity Simulator.
 */
class FinancingCapacitySimulatorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected FinancingCapacitySimulatorService $service;
    protected User $user;
    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FinancingCapacitySimulatorService::class);

        $this->user = User::factory()->create([
            'email' => 'merchant_financing@kaching.test',
        ]);

        $this->outlet = Outlet::create([
            'user_id'   => $this->user->id,
            'name'      => 'Financing Outlet',
            'device_id' => 'DEV-FINANCE-01',
        ]);
    }

    protected function seedHealthyOperatingCashflow(
        int $userId,
        int $outletId,
        string $anchorDate = '2026-04-01',
        float $monthlyRevenue = 60000000.0,
        float $monthlyCogs = 24000000.0,
        float $monthlyExpense = 6000000.0
    ): void {
        // Daily rev = 2,000,000 over 30 days
        $start = Carbon::parse($anchorDate)->subDays(30);
        for ($i = 0; $i < 30; $i++) {
            $date = $start->copy()->addDays($i)->format('Y-m-d') . ' 12:00:00';
            $dailyRev = $monthlyRevenue / 30.0;
            $dailyCogs = $monthlyCogs / 30.0;

            $trxId = DB::table('transactions')->insertGetId([
                'user_id'                 => $userId,
                'outlet_id'               => $outletId,
                'app_uuid'                => 'tx-fin-' . uniqid('', true),
                'official_invoice_number' => 'INV-F-' . uniqid(),
                'total_amount'            => $dailyRev,
                'pay_amount'              => $dailyRev,
                'payment_method'          => 'cash',
                'status'                  => 'Selesai',
                'created_at_device'       => $date,
                'created_at'              => $date,
                'updated_at'              => $date,
            ]);

            DB::table('transaction_items')->insert([
                'transaction_id' => $trxId,
                'menu_name'      => 'Menu Standard',
                'qty'            => 10,
                'price'          => $dailyRev / 10.0,
                'cost_price'     => $dailyCogs / 10.0,
                'created_at'     => $date,
                'updated_at'     => $date,
            ]);
        }

        // Monthly Expense
        $dailyExp = $monthlyExpense / 30.0;
        for ($i = 0; $i < 30; $i++) {
            $d = $start->copy()->addDays($i)->format('Y-m-d');
            DB::table('expenses')->insert([
                'user_id'    => $userId,
                'outlet_id'  => $outletId,
                'name'       => 'Sewa & Listrik',
                'date'       => $d,
                'category'   => 'Operasional',
                'amount'     => $dailyExp,
                'created_at' => $d . ' 12:00:00',
                'updated_at' => $d . ' 12:00:00',
            ]);
        }
    }

    public function testSimulate50Million12MonthsStandardHealthyCapacity(): void
    {
        $this->seedHealthyOperatingCashflow($this->user->id, $this->outlet->id);

        $result = $this->service->simulate($this->user->id, $this->outlet->id, [
            'amount'      => 50000000.0,
            'tenor'       => 12,
            'anchor_date' => '2026-04-01',
        ]);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals(50000000.0, $result['requested_financing']);
        $this->assertEquals(12, $result['tenor_months']);

        // Monthly payment for 50M at 9% p.a over 12 months:
        // Principal = 50M / 12 = 4,166,666.67
        // Interest = (50M * 0.09) / 12 = 375,000.00
        // Total = 4,541,666.67
        $this->assertEquals(4541666.67, $result['estimated_monthly_payment']);

        // Available cashflow ~ 30,000,000 (Rev 60M - COGS 24M - OPEX 6M)
        // Payment burden ~ 4.54M / 30M = 15.1%
        $this->assertLessThan(20.0, $result['payment_burden_pct']);
        $this->assertEquals(FinancingCapacitySimulatorService::ASSESSMENT_LOW_PRESSURE, $result['assessment']);

        // Multi-tenor comparison
        $this->assertCount(3, $result['tenor_comparison']);
        $this->assertEquals(12, $result['tenor_comparison'][0]['tenor_months']);
        $this->assertEquals(18, $result['tenor_comparison'][1]['tenor_months']);
        $this->assertEquals(24, $result['tenor_comparison'][2]['tenor_months']);

        // Format and disclaimers
        $formatted = $result['formatted_output'];
        $this->assertStringContainsString('REQUESTED FINANCING', $formatted);
        $this->assertStringContainsString('Rp 50.000.000', $formatted);
        $this->assertStringContainsString('ESTIMATED MONTHLY PAYMENT', $formatted);
        $this->assertStringContainsString('ESTIMATED AVAILABLE CASHFLOW', $formatted);
        $this->assertStringContainsString('PAYMENT BURDEN', $formatted);
        $this->assertStringContainsString('LOW_PRESSURE', $formatted);
        $this->assertStringContainsString('BUKAN persetujuan kredit', $result['disclaimer']);
    }

    public function testSimulateHighPressureAndSafetyWarningWhenPaymentExceedsCashflow(): void
    {
        // Thin operating cashflow: Rev 10M, COGS 6M, OPEX 3.5M -> Net cashflow only 500,000 IDR/month
        $this->seedHealthyOperatingCashflow(
            $this->user->id,
            $this->outlet->id,
            '2026-04-01',
            10000000.0,
            6000000.0,
            3500000.0
        );

        $result = $this->service->simulate($this->user->id, $this->outlet->id, [
            'amount'      => 50000000.0, // Payment 4.54M vs Net cashflow 500K
            'tenor'       => 12,
            'anchor_date' => '2026-04-01',
        ]);

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertEquals(FinancingCapacitySimulatorService::ASSESSMENT_HIGH_PRESSURE, $result['assessment']);
        $this->assertNotNull($result['safety_warning']);
        $this->assertStringContainsString('berpotensi melebihi kapasitas cashflow', $result['safety_warning']);
        $this->assertStringContainsString('PERINGATAN RISIKO', $result['formatted_output']);
    }

    public function testSimulateInsufficientDataFailsClosed(): void
    {
        // Zero transactions
        $result = $this->service->simulate($this->user->id, $this->outlet->id, [
            'amount'      => 50000000.0,
            'tenor'       => 12,
            'anchor_date' => '2026-04-01',
        ]);

        $this->assertEquals('INSUFFICIENT_DATA', $result['status']);
        $this->assertStringContainsString('Data arus kas operasional belum mencukupi', $result['message']);
        $this->assertStringContainsString('DATA TIDAK CUKUP', $result['formatted_output']);
    }

    public function testSimulateInvalidInputValidation(): void
    {
        // Negative amount
        $negResult = $this->service->simulate($this->user->id, $this->outlet->id, [
            'amount' => -1000000.0,
            'tenor'  => 12,
        ]);
        $this->assertEquals('ERROR', $negResult['status']);
        $this->assertEquals('INVALID_INPUT', $negResult['code']);

        // Extreme impossible amount > 1 Billion
        $hugeResult = $this->service->simulate($this->user->id, $this->outlet->id, [
            'amount' => 5000000000.0, // 5 Billion
            'tenor'  => 12,
        ]);
        $this->assertEquals('ERROR', $hugeResult['status']);
        $this->assertEquals('INVALID_INPUT', $hugeResult['code']);

        // Invalid tenor
        $tenorResult = $this->service->simulate($this->user->id, $this->outlet->id, [
            'amount' => 50000000.0,
            'tenor'  => 1, // < 3 months
        ]);
        $this->assertEquals('ERROR', $tenorResult['status']);
        $this->assertEquals('INVALID_INPUT', $tenorResult['code']);
    }

    public function testSimulateUnauthorizedOutletFailsClosed(): void
    {
        $otherUser = User::factory()->create();
        $otherOutlet = Outlet::create([
            'user_id'   => $otherUser->id,
            'name'      => 'Other Store',
            'device_id' => 'DEV-OTHER-02',
        ]);

        $result = $this->service->simulate($this->user->id, $otherOutlet->id, [
            'amount' => 50000000.0,
            'tenor'  => 12,
        ]);

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('UNAUTHORIZED_OUTLET', $result['code']);
    }
}
