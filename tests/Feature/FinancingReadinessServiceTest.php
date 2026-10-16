<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Financial\FinancialCalculationService;
use App\Services\Financial\FinancingReadinessService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancingReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    protected FinancingReadinessService $service;
    protected User $user;
    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->outlet = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Main Outlet',
            'address' => 'Jl. Sudirman No. 1',
        ]);

        $this->service = app(FinancingReadinessService::class);
    }

    public function test_unauthenticated_user_returns_error(): void
    {
        $result = $this->service->calculate(0);

        $this->assertSame('ERROR', $result['status']);
        $this->assertSame('UNAUTHENTICATED', $result['code']);
    }

    public function test_unauthorized_outlet_returns_error(): void
    {
        $otherUser = User::factory()->create();
        $otherOutlet = Outlet::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Outlet',
        ]);

        $result = $this->service->calculate($this->user->id, $otherOutlet->id);

        $this->assertSame('ERROR', $result['status']);
        $this->assertSame('UNAUTHORIZED_OUTLET', $result['code']);
    }

    public function test_structure_weights_and_pillars(): void
    {
        $this->assertSame(0.25, FinancingReadinessService::WEIGHT_REVENUE_STABILITY);
        $this->assertSame(0.20, FinancingReadinessService::WEIGHT_MARGIN_HEALTH);
        $this->assertSame(0.25, FinancingReadinessService::WEIGHT_CASH_DISCIPLINE);
        $this->assertSame(0.15, FinancingReadinessService::WEIGHT_OPERATIONAL_INTEGRITY);
        $this->assertSame(0.15, FinancingReadinessService::WEIGHT_DATA_COMPLETENESS);

        $totalWeight = FinancingReadinessService::WEIGHT_REVENUE_STABILITY
            + FinancingReadinessService::WEIGHT_MARGIN_HEALTH
            + FinancingReadinessService::WEIGHT_CASH_DISCIPLINE
            + FinancingReadinessService::WEIGHT_OPERATIONAL_INTEGRITY
            + FinancingReadinessService::WEIGHT_DATA_COMPLETENESS;

        $this->assertEqualsWithDelta(1.0, $totalWeight, 0.0001);
    }

    public function test_disclaimer_and_no_credit_claim(): void
    {
        $result = $this->service->calculate($this->user->id, $this->outlet->id, '2026-09-27');

        $this->assertSame('SUCCESS', $result['status']);
        $this->assertIsInt($result['score']);
        $this->assertArrayHasKey('band', $result);
        $this->assertArrayHasKey('pillars', $result);

        foreach ($result['pillars'] as $pName => $pData) {
            $this->assertArrayHasKey('score', $pData, "Pillar $pName missing score");
            $this->assertArrayHasKey('weight', $pData, "Pillar $pName missing weight");
            $this->assertArrayHasKey('weighted_contribution', $pData, "Pillar $pName missing weighted_contribution");
            $this->assertArrayHasKey('evidence', $pData, "Pillar $pName missing evidence");
            $this->assertArrayHasKey('limitations', $pData, "Pillar $pName missing limitations");
        }

        // Strict FinTechathon Compliance Check
        $this->assertStringContainsString('bukan persetujuan kredit', strtolower($result['disclaimer']));
        $this->assertStringNotContainsString('loan approved', strtolower(json_encode($result)));
        $this->assertStringNotContainsString('credit score', strtolower(json_encode($result['band'])));

        // Financial Evidence Summary Coverage Check
        $this->assertArrayHasKey('evidence_summary', $result);
        $this->assertIsArray($result['evidence_summary']);
        $this->assertGreaterThanOrEqual(5, count($result['evidence_summary']));

        foreach ($result['evidence_summary'] as $evidence) {
            $this->assertArrayHasKey('key', $evidence);
            $this->assertArrayHasKey('label', $evidence);
            $this->assertArrayHasKey('status', $evidence);
            $this->assertArrayHasKey('source', $evidence);
            $this->assertArrayHasKey('period', $evidence);
            $this->assertArrayHasKey('summary', $evidence);
            $this->assertArrayHasKey('explainability', $evidence);
        }
    }

    public function test_full_calculation_with_healthy_data_produces_high_readiness(): void
    {
        // 1. Create healthy menus with cost price
        for ($i = 1; $i <= 5; $i++) {
            Menu::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'name' => "Menu $i",
                'price' => 30000,
                'cost_price' => 12000, // 60% gross margin
                'category' => 'Food',
                'is_available' => true,
            ]);
        }

        // 2. Create 25 days of active sales across 30 days
        $refDate = Carbon::createFromFormat('Y-m-d', '2026-09-27', 'Asia/Jakarta');
        for ($day = 0; $day < 25; $day++) {
            $txTime = $refDate->copy()->subDays($day)->setTime(12, 0, 0)->toDateTimeString();
            Transaction::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'app_uuid' => \Illuminate\Support\Str::uuid()->toString(),
                'receipt_number' => "REC-$day",
                'status' => 'Selesai',
                'total_amount' => 1000000,
                'pay_amount' => 1000000,
                'payment_method' => $day % 2 === 0 ? 'cash' : 'qris',
                'created_at_device' => $txTime,
                'created_at' => $txTime,
            ]);
        }

        // 3. Create balanced closed shifts
        for ($s = 0; $s < 10; $s++) {
            Shift::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'cashier_name' => 'Budi',
                'status' => 'closed',
                'start_cash' => 500000,
                'expected_cash' => 1500000,
                'end_cash' => 1500000, // 0 discrepancy
                'cash_difference' => 0,
                'created_at' => $refDate->copy()->subDays($s * 2)->setTime(8, 0, 0)->toDateTimeString(),
                'end_time' => $refDate->copy()->subDays($s * 2)->setTime(22, 0, 0)->toDateTimeString(),
            ]);
        }

        $result = $this->service->calculate($this->user->id, $this->outlet->id, '2026-09-27');

        $this->assertSame('SUCCESS', $result['status']);
        $this->assertGreaterThanOrEqual(75, $result['score']);
        $this->assertContains($result['band'], [
            FinancingReadinessService::BAND_HIGH,
            FinancingReadinessService::BAND_MODERATE,
        ]);

        $this->assertGreaterThanOrEqual(80, $result['pillars']['revenue_stability']['score']);
        $this->assertGreaterThanOrEqual(80, $result['pillars']['cash_discipline']['score']);
        $this->assertGreaterThanOrEqual(80, $result['pillars']['operational_integrity']['score']);
    }

    public function test_poor_discipline_and_high_voids_reduces_readiness_score(): void
    {
        // 1. Menus with 0 cost price
        for ($i = 1; $i <= 3; $i++) {
            Menu::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'name' => "Menu $i",
                'price' => 20000,
                'cost_price' => 0, // No HPP tracking
                'category' => 'General',
                'is_available' => true,
            ]);
        }

        // 2. High void rate transactions (> 20% void)
        $refDate = Carbon::createFromFormat('Y-m-d', '2026-09-27', 'Asia/Jakarta');
        for ($tx = 0; $tx < 10; $tx++) {
            $isVoid = $tx < 4; // 40% void rate!
            $txTime = $refDate->copy()->subDays($tx)->setTime(14, 0, 0)->toDateTimeString();
            Transaction::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'app_uuid' => \Illuminate\Support\Str::uuid()->toString(),
                'receipt_number' => "REC-VOID-$tx",
                'status' => $isVoid ? 'Batal' : 'Selesai',
                'total_amount' => 500000,
                'pay_amount' => 500000,
                'payment_method' => 'cash',
                'created_at_device' => $txTime,
                'created_at' => $txTime,
            ]);
        }

        // 3. Shifts with large cash deficits
        for ($s = 0; $s < 3; $s++) {
            Shift::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'cashier_name' => 'Doni',
                'status' => 'closed',
                'start_cash' => 200000,
                'expected_cash' => 1000000,
                'end_cash' => 600000, // Deficit Rp 400.000!
                'cash_difference' => -400000,
                'created_at' => $refDate->copy()->subDays($s)->setTime(8, 0, 0)->toDateTimeString(),
                'end_time' => $refDate->copy()->subDays($s)->setTime(22, 0, 0)->toDateTimeString(),
            ]);
        }

        $result = $this->service->calculate($this->user->id, $this->outlet->id, '2026-09-27');

        $this->assertSame('SUCCESS', $result['status']);
        // Operational integrity should be penalized (< 50)
        $this->assertLessThan(60, $result['pillars']['operational_integrity']['score']);
        // Cash discipline should be penalized (< 60)
        $this->assertLessThan(60, $result['pillars']['cash_discipline']['score']);
        // Score should be much lower than healthy
        $this->assertLessThan(65, $result['score']);
        $this->assertNotEmpty($result['limitations']);
    }
}
