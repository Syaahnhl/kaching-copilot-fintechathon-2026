<?php

namespace Tests\Unit;

use App\Services\Financial\FinancialEvidenceService;
use PHPUnit\Framework\TestCase;

class FinancialEvidenceServiceTest extends TestCase
{
    private FinancialEvidenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FinancialEvidenceService();
    }

    /**
     * 1. Warning mapping: REVENUE_DECLINE
     */
    public function test_warning_mapping_revenue_decline(): void
    {
        $payload = [
            'code'         => 'REVENUE_DECLINE',
            'status'       => 'ACTIVE',
            'severity'     => 'CRITICAL',
            'title'        => 'Penurunan Omzet Signifikan',
            'message'      => 'Omzet 7 hari terakhir turun 30%',
            'metric'       => 'net_revenue',
            'actual'       => 70000000.0,
            'baseline'     => 100000000.0,
            'threshold'    => -20.0,
            'unit'         => 'IDR',
            'period'       => [
                'start_date' => '2026-09-18',
                'end_date'   => '2026-09-24',
                'type'       => 'rolling_7d',
            ],
            'evidence'     => [
                'percentage_change'     => -30.0,
                'nominal_drop'          => 30000000.0,
                'confirmed'             => true,
                'windows_observed'      => 2,
                'prior_window_decline'  => true,
            ],
            'data_quality' => 'DQ_SUFFICIENT',
            'confidence'   => 'HIGH',
            'limitations'  => ['Faktor cuaca atau jalan ditutup belum diperhitungkan.'],
        ];

        $wrapped = $this->service->wrapWarning($payload);

        $this->assertSame('1.0', $wrapped['evidence_version']);
        $this->assertSame('FinancialWarningService', $wrapped['engine']);
        $this->assertSame('EMPIRICAL_HEURISTIC', $wrapped['calculation_type']);
        $this->assertSame('REVENUE_DECLINE', $wrapped['subject']);
        $this->assertSame($payload['period'], $wrapped['period']);
        $this->assertSame(100000000.0, $wrapped['baseline']['value']);
        $this->assertSame('IDR', $wrapped['baseline']['unit']);
        $this->assertSame(70000000.0, $wrapped['observed_or_projected']['value']);
        $this->assertSame('CRITICAL', $wrapped['observed_or_projected']['severity']);
        $this->assertSame('ACTIVE', $wrapped['observed_or_projected']['status']);
        $this->assertSame(-20.0, $wrapped['delta_or_threshold']['threshold_applied']);
        $this->assertSame(-30.0, $wrapped['delta_or_threshold']['percentage_change']);
        $this->assertSame(30000000.0, $wrapped['delta_or_threshold']['nominal_drop']);
        $this->assertSame('DQ_SUFFICIENT', $wrapped['data_quality']['state']);
        $this->assertTrue($wrapped['data_quality']['is_reliable']);
        $this->assertSame('HIGH', $wrapped['confidence_or_evaluation']['level']);
        $this->assertSame($payload['limitations'], $wrapped['limitations']);
    }

    /**
     * 2. Warning mapping: EXPENSE_SPIKE
     */
    public function test_warning_mapping_expense_spike(): void
    {
        $payload = [
            'code'         => 'EXPENSE_SPIKE',
            'status'       => 'ACTIVE',
            'severity'     => 'CRITICAL',
            'title'        => 'Lonjakan Pengeluaran Operasional',
            'message'      => 'Pengeluaran naik drastis',
            'metric'       => 'operational_expense',
            'actual'       => 15000000.0,
            'baseline'     => 4000000.0,
            'threshold'    => 3.0,
            'unit'         => 'IDR',
            'period'       => ['start_date' => '2026-09-18', 'end_date' => '2026-09-24'],
            'evidence'     => [
                'ratio'            => 3.75,
                'ratio_percentage' => 375.0,
                'nominal_increase' => 11000000.0,
                'floor_required'   => 2000000.0,
            ],
            'data_quality' => 'DQ_SUFFICIENT',
            'confidence'   => 'HIGH',
            'limitations'  => ['Mungkin terdapat belanja stok grosir berkala.'],
        ];

        $wrapped = $this->service->wrapWarning($payload);

        $this->assertSame('EXPENSE_SPIKE', $wrapped['subject']);
        $this->assertSame(15000000.0, $wrapped['observed_or_projected']['value']);
        $this->assertSame(3.75, $wrapped['delta_or_threshold']['ratio']);
        $this->assertSame(11000000.0, $wrapped['delta_or_threshold']['nominal_increase']);
        $this->assertSame(3.0, $wrapped['delta_or_threshold']['threshold_applied']);
    }

    /**
     * 3. Warning mapping: MARGIN_COMPRESSION
     */
    public function test_warning_mapping_margin_compression(): void
    {
        $payload = [
            'code'         => 'MARGIN_COMPRESSION',
            'status'       => 'ACTIVE',
            'severity'     => 'WARNING',
            'title'        => 'Tekanan Margin Kotor',
            'message'      => 'Margin kotor tertekan',
            'metric'       => 'gross_margin_percentage',
            'actual'       => 42.0,
            'baseline'     => 55.0,
            'threshold'    => 5.0,
            'unit'         => 'PERCENT',
            'period'       => ['start_date' => '2026-09-18', 'end_date' => '2026-09-24'],
            'evidence'     => [
                'drop_percentage_points' => 13.0,
                'current_revenue'        => 50000000.0,
                'current_cogs'           => 29000000.0,
                'untracked_cogs_ratio'   => 0.05,
            ],
            'data_quality' => 'DQ_SUFFICIENT',
            'confidence'   => 'MEDIUM',
            'limitations'  => ['Kenaikan harga bahan baku supplier.'],
        ];

        $wrapped = $this->service->wrapWarning($payload);

        $this->assertSame('MARGIN_COMPRESSION', $wrapped['subject']);
        $this->assertSame(42.0, $wrapped['observed_or_projected']['value']);
        $this->assertSame(55.0, $wrapped['baseline']['value']);
        $this->assertSame(13.0, $wrapped['delta_or_threshold']['drop_percentage_points']);
        $this->assertSame(5.0, $wrapped['delta_or_threshold']['threshold_applied']);
        $this->assertSame('MEDIUM', $wrapped['confidence_or_evaluation']['level']);
    }

    /**
     * 4. Warning mapping: ABNORMAL_VOID_REFUND
     */
    public function test_warning_mapping_abnormal_void_refund(): void
    {
        $payload = [
            'code'         => 'ABNORMAL_VOID_REFUND',
            'status'       => 'ACTIVE',
            'severity'     => 'CRITICAL',
            'title'        => 'Tingkat Void / Refund Abnormal',
            'message'      => 'Banyak pembatalan',
            'metric'       => 'void_ratio_percentage',
            'actual'       => 18.5,
            'baseline'     => 0.0,
            'threshold'    => 15.0,
            'unit'         => 'PERCENT',
            'period'       => ['start_date' => '2026-09-18', 'end_date' => '2026-09-24'],
            'evidence'     => [
                'total_transactions' => 100,
                'void_count'         => 18,
                'void_ratio'         => 18.0,
                'cancel_statuses'    => ['Batal', 'Cancelled', 'refund', 'void'],
            ],
            'data_quality' => 'DQ_SUFFICIENT',
            'confidence'   => 'HIGH',
            'limitations'  => ['Verifikasi log kasir untuk memastikan kejujuran staf.'],
        ];

        $wrapped = $this->service->wrapWarning($payload);

        $this->assertSame('ABNORMAL_VOID_REFUND', $wrapped['subject']);
        $this->assertSame(18.5, $wrapped['observed_or_projected']['value']);
        $this->assertSame(15.0, $wrapped['delta_or_threshold']['threshold_applied']);
    }

    /**
     * 5. Warning mapping: DRAWER_DISCREPANCY
     */
    public function test_warning_mapping_drawer_discrepancy(): void
    {
        $payload = [
            'code'         => 'DRAWER_DISCREPANCY',
            'status'       => 'ACTIVE',
            'severity'     => 'CRITICAL',
            'title'        => 'Selisih Kas Laci Signifikan',
            'message'      => 'Selisih kas fisik',
            'metric'       => 'cash_drawer_deficit',
            'actual'       => 2800000.0,
            'baseline'     => 3000000.0,
            'threshold'    => 50000.0,
            'unit'         => 'IDR',
            'period'       => ['shift_id' => 42, 'date' => '2026-09-24'],
            'evidence'     => [
                'shift_id'           => 42,
                'cashier_name'       => 'Budi',
                'expected_cash'      => 3000000.0,
                'end_cash'           => 2800000.0,
                'nominal_deficit'    => 200000.0,
                'total_cash_sales'   => 2500000.0,
                'deficit_percentage' => 8.0,
            ],
            'data_quality' => 'DQ_SUFFICIENT',
            'confidence'   => 'HIGH',
            'limitations'  => ['Hanya mengevaluasi shift yang sudah ditutup resmi.'],
        ];

        $wrapped = $this->service->wrapWarning($payload);

        $this->assertSame('DRAWER_DISCREPANCY', $wrapped['subject']);
        $this->assertSame(2800000.0, $wrapped['observed_or_projected']['value']);
        $this->assertSame(3000000.0, $wrapped['baseline']['value']);
        $this->assertSame(50000.0, $wrapped['delta_or_threshold']['threshold_applied']);
    }

    /**
     * 6. Warning mapping: CASH_DEPLETION (strictly suspended)
     */
    public function test_warning_mapping_cash_depletion_suspended(): void
    {
        $payload = [
            'code'         => 'CASH_DEPLETION',
            'status'       => 'SUSPENDED',
            'severity'     => 'INFO',
            'title'        => 'Peringatan Ketahanan Kas Ditangguhkan',
            'message'      => 'Fitur ditangguhkan demi akurasi finansial.',
            'metric'       => 'cash_runway_days',
            'actual'       => null,
            'baseline'     => null,
            'threshold'    => null,
            'unit'         => 'DAYS',
            'period'       => null,
            'evidence'     => [
                'reason'                 => 'CASH_DRAWER_NOT_BANK_BALANCE',
                'architectural_decision' => 'ADR-004',
            ],
            'data_quality' => 'DQ_SUSPENDED',
            'confidence'   => 'NOT_APPLICABLE_DETERMINISTIC',
            'limitations'  => ['Kas POS hanya mencatat kas fisik laci, bukan rekening bank.'],
        ];

        $wrapped = $this->service->wrapWarning($payload);

        $this->assertSame('CASH_DEPLETION', $wrapped['subject']);
        $this->assertNull($wrapped['observed_or_projected']['value']);
        $this->assertNull($wrapped['baseline']['value']);
        $this->assertSame('SUSPENDED', $wrapped['observed_or_projected']['status']);
        $this->assertSame('DQ_SUSPENDED', $wrapped['data_quality']['state']);
        $this->assertFalse($wrapped['data_quality']['is_reliable']);
    }

    /**
     * 7. wrapAllWarnings with container array
     */
    public function test_wrap_all_warnings_with_container(): void
    {
        $container = [
            'status'            => 'HAS_WARNINGS',
            'warnings'          => [
                [
                    'code'         => 'REVENUE_DECLINE',
                    'status'       => 'ACTIVE',
                    'severity'     => 'WARNING',
                    'actual'       => 80000000.0,
                    'baseline'     => 100000000.0,
                    'threshold'    => -20.0,
                    'unit'         => 'IDR',
                    'data_quality' => 'DQ_SUFFICIENT',
                    'confidence'   => 'MEDIUM',
                ],
            ],
            'insufficient_data' => [
                [
                    'code'         => 'MARGIN_COMPRESSION',
                    'data_quality' => 'DQ_UNTRACKED_COGS',
                    'limitations'  => ['COGS tidak lengkap > 15%'],
                ],
            ],
        ];

        $wrapped = $this->service->wrapAllWarnings($container);

        $this->assertCount(1, $wrapped['active_warnings']);
        $this->assertCount(1, $wrapped['insufficient_data']);
        $this->assertSame('REVENUE_DECLINE', $wrapped['active_warnings'][0]['subject']);
        $this->assertSame('MARGIN_COMPRESSION', $wrapped['insufficient_data'][0]['subject']);
        $this->assertSame('INSUFFICIENT', $wrapped['insufficient_data'][0]['confidence_or_evaluation']['level']);
    }

    /**
     * 8. Scenario mapping: SUCCESS
     */
    public function test_scenario_mapping_success(): void
    {
        $payload = [
            'status'   => 'SUCCESS',
            'scenario' => [
                'type'       => 'PRICE_CHANGE',
                'parameters' => ['percent' => 10.0],
            ],
            'baseline' => [
                'revenue'      => 50000000.0,
                'cogs'         => 20000000.0,
                'gross_profit' => 30000000.0,
                'expenses'     => 10000000.0,
                'net_profit'   => 20000000.0,
                'gross_margin' => 60.0,
                'net_margin'   => 40.0,
                'period'       => [
                    'start_date' => '2026-08-25',
                    'end_date'   => '2026-09-24',
                    'timezone'   => 'Asia/Jakarta',
                    'type'       => '30d_rolling',
                ],
                'data_quality' => [
                    'has_untracked_cogs'         => false,
                    'untracked_items_percentage' => 0.0,
                    'status'                     => 'HEALTHY',
                ],
            ],
            'result'   => [
                'revenue'      => 55000000.0,
                'cogs'         => 20000000.0,
                'gross_profit' => 35000000.0,
                'expenses'     => 10000000.0,
                'net_profit'   => 25000000.0,
                'gross_margin' => 63.64,
                'net_margin'   => 45.45,
            ],
            'delta'    => [
                'revenue'              => 5000000.0,
                'revenue_percent'      => 10.0,
                'cogs'                 => 0.0,
                'cogs_percent'         => 0.0,
                'gross_profit'         => 5000000.0,
                'gross_profit_percent' => 16.67,
                'expenses'             => 0.0,
                'expenses_percent'     => 0.0,
                'net_profit'           => 5000000.0,
                'net_profit_percent'   => 25.0,
                'gross_margin_pp'      => 3.64,
                'net_margin_pp'        => 5.45,
            ],
            'assumptions'  => ['Elastisitas permintaan nol (volume konstan).'],
            'limitations'  => ['Kenaikan harga berpotensi menurunkan volume pelanggan.'],
            'data_quality' => 'HEALTHY',
        ];

        $wrapped = $this->service->wrapScenario($payload);

        $this->assertSame('1.0', $wrapped['evidence_version']);
        $this->assertSame('FinancialScenarioService', $wrapped['engine']);
        $this->assertSame('DETERMINISTIC_SIMULATION', $wrapped['calculation_type']);
        $this->assertSame('PRICE_CHANGE', $wrapped['subject']);
        $this->assertSame(50000000.0, $wrapped['baseline']['revenue']);
        $this->assertSame(55000000.0, $wrapped['observed_or_projected']['revenue']);
        $this->assertSame(5000000.0, $wrapped['delta_or_threshold']['revenue_delta']);
        $this->assertSame(10.0, $wrapped['delta_or_threshold']['revenue_percent']);
        $this->assertSame(3.64, $wrapped['delta_or_threshold']['gross_margin_pp']);
        $this->assertSame('NOT_APPLICABLE_DETERMINISTIC', $wrapped['confidence_or_evaluation']['level']);
        $this->assertSame('HEALTHY', $wrapped['data_quality']['state']);
        $this->assertTrue($wrapped['data_quality']['is_reliable']);
        $this->assertFalse($wrapped['data_quality']['has_untracked_cogs']);
        $this->assertSame($payload['assumptions'], $wrapped['assumptions']);
        $this->assertSame($payload['limitations'], $wrapped['limitations']);
    }

    /**
     * 9. Scenario mapping: FAIL-CLOSED ERROR
     */
    public function test_scenario_mapping_fail_closed_error(): void
    {
        $payload = [
            'status'  => 'ERROR',
            'code'    => 'INVALID_SCENARIO_TYPE',
            'message' => 'Tipe skenario tidak valid.',
        ];

        $wrapped = $this->service->wrapScenario($payload);

        $this->assertSame('FinancialScenarioService', $wrapped['engine']);
        $this->assertSame('DETERMINISTIC_SIMULATION', $wrapped['calculation_type']);
        $this->assertNull($wrapped['observed_or_projected']);
        $this->assertNull($wrapped['baseline']);
        $this->assertSame('INVALID_SCENARIO_TYPE', $wrapped['data_quality']['state']);
        $this->assertFalse($wrapped['data_quality']['is_reliable']);
        $this->assertSame(['Tipe skenario tidak valid.'], $wrapped['limitations']);
    }

    /**
     * 10. Forecast mapping: SUCCESS
     */
    public function test_forecast_mapping_success(): void
    {
        $payload = [
            'status'          => 'SUCCESS',
            'forecast_period' => [
                'start_date'   => '2026-09-26',
                'end_date'     => '2026-10-02',
                'horizon_days' => 7,
                'anchor_date'  => '2026-09-25',
                'timezone'     => 'Asia/Jakarta',
            ],
            'model'                             => 'DOW_AVERAGE',
            'data_quality'                      => 'SUFFICIENT_HISTORY',
            'data_quality_details'              => [
                'state'                     => 'SUFFICIENT_HISTORY',
                'total_history_days'        => 28,
                'baseline_active_days'      => 26,
                'baseline_total_inflow'     => 56000000.0,
                'baseline_total_expense'    => 14000000.0,
                'expense_records_count'     => 12,
                'regularly_closed_weekdays' => [1],
            ],
            'regularly_closed_weekdays'         => [1],
            'projected_inflow'                  => 14000000.0,
            'projected_outflow'                 => 3500000.0,
            'projected_net_operating_cash_flow' => 10500000.0,
            'daily_forecast'                    => [
                [
                    'date'              => '2026-09-26',
                    'dow'               => 6,
                    'dow_name'          => 'Saturday',
                    'projected_inflow'  => 2500000.0,
                    'projected_outflow' => 500000.0,
                    'projected_net'     => 2000000.0,
                    'model_used'        => 'DOW_AVERAGE',
                    'is_closed_day'     => false,
                    'notes'             => 'Standard DOW projected flow',
                ],
            ],
            'backtest' => [
                'wape'                  => 8.5,
                'mae'                   => 170000.0,
                'bias_direction'        => 'BALANCED',
                'accuracy_score'        => 91.5,
                'data_points_evaluated' => 21,
            ],
            'assumptions' => ['Kas masuk berasumsi tren 4 minggu konsisten.'],
            'limitations' => ['Proyeksi kas operasional != saldo rekening bank.'],
        ];

        $wrapped = $this->service->wrapForecast($payload);

        $this->assertSame('1.0', $wrapped['evidence_version']);
        $this->assertSame('CashFlowForecastService', $wrapped['engine']);
        $this->assertSame('HISTORICAL_PROXY_FORECAST', $wrapped['calculation_type']);
        $this->assertSame('OPERATING_CASH_FLOW_PROXY_FORECAST_7D', $wrapped['subject']);
        $this->assertSame(14000000.0, $wrapped['observed_or_projected']['projected_inflow']);
        $this->assertSame(3500000.0, $wrapped['observed_or_projected']['projected_outflow']);
        $this->assertSame(10500000.0, $wrapped['observed_or_projected']['projected_net_operating_cash_flow']);
        $this->assertSame('DOW_AVERAGE', $wrapped['delta_or_threshold']['model_selected']);
        $this->assertSame(7, $wrapped['delta_or_threshold']['horizon_days']);
        $this->assertSame('SUFFICIENT_HISTORY', $wrapped['data_quality']['state']);
        $this->assertTrue($wrapped['data_quality']['is_reliable']);
        $this->assertSame('EVALUATED_BACKTEST', $wrapped['confidence_or_evaluation']['level']);
        $this->assertSame(91.5, $wrapped['confidence_or_evaluation']['accuracy_metrics']['accuracy_score']);
        $this->assertStringContainsString('!= bank balance', $wrapped['observed_or_projected']['product_boundary_notice']);
    }

    /**
     * 11. Forecast mapping: FAIL-CLOSED INSUFFICIENT HISTORY (critical: preserve null!)
     */
    public function test_forecast_mapping_fail_closed_preserves_null(): void
    {
        $payload = [
            'status'          => 'INSUFFICIENT_HISTORY',
            'forecast_period' => [
                'start_date'   => '2026-09-26',
                'end_date'     => '2026-10-02',
                'horizon_days' => 7,
                'anchor_date'  => '2026-09-25',
                'timezone'     => 'Asia/Jakarta',
            ],
            'model'                             => 'NONE',
            'data_quality'                      => 'INSUFFICIENT_HISTORY',
            'data_quality_details'              => [
                'state'              => 'INSUFFICIENT_HISTORY',
                'total_history_days' => 6,
            ],
            'regularly_closed_weekdays'         => [],
            'projected_inflow'                  => null,
            'projected_outflow'                 => null,
            'projected_net_operating_cash_flow' => null,
            'daily_forecast'                    => [],
            'backtest'                          => null,
            'assumptions'                       => [],
            'limitations'                       => ['Dibutuhkan minimal 14 hari histori transaksi.'],
        ];

        $wrapped = $this->service->wrapForecast($payload);

        $this->assertSame('INSUFFICIENT_HISTORY', $wrapped['data_quality']['state']);
        $this->assertFalse($wrapped['data_quality']['is_reliable']);
        // MUST NOT CONVERT NULL TO ZERO
        $this->assertNull($wrapped['observed_or_projected']['projected_inflow']);
        $this->assertNull($wrapped['observed_or_projected']['projected_outflow']);
        $this->assertNull($wrapped['observed_or_projected']['projected_net_operating_cash_flow']);
        $this->assertNotSame(0, $wrapped['observed_or_projected']['projected_inflow']);
        $this->assertNotSame(0.0, $wrapped['observed_or_projected']['projected_inflow']);
        $this->assertSame('INSUFFICIENT', $wrapped['confidence_or_evaluation']['level']);
        $this->assertNull($wrapped['confidence_or_evaluation']['accuracy_metrics']);
    }

    /**
     * 12. wrapSales: Today sales canonical evidence
     */
    public function test_sales_mapping(): void
    {
        $amount = 1500000.0;
        $date = '2026-09-25';
        $timezone = 'Asia/Jakarta';

        $wrapped = $this->service->wrapSales($amount, $date, $timezone);

        $this->assertSame('1.0', $wrapped['evidence_version']);
        $this->assertSame('FinancialCalculationService', $wrapped['engine']);
        $this->assertSame('DETERMINISTIC_AGGREGATION', $wrapped['calculation_type']);
        $this->assertSame('TODAY_SALES', $wrapped['subject']);
        $this->assertSame($date, $wrapped['period']['start']);
        $this->assertSame($date, $wrapped['period']['end']);
        $this->assertSame($timezone, $wrapped['period']['timezone']);
        $this->assertSame($amount, $wrapped['observed_or_projected']['value']);
        $this->assertSame('completed', $wrapped['observed_or_projected']['status']);
        $this->assertSame('SUFFICIENT', $wrapped['data_quality']['state']);
        $this->assertTrue($wrapped['data_quality']['is_reliable']);
        $this->assertSame('NOT_APPLICABLE_DETERMINISTIC', $wrapped['confidence_or_evaluation']['level']);
        $this->assertCount(2, $wrapped['assumptions']);
        $this->assertCount(2, $wrapped['limitations']);
    }

    /**
     * 13. Null preservation and zero recalculation test
     */
    public function test_null_preservation_and_zero_recalculation(): void
    {
        $sparsePayload = [
            'code'     => 'REVENUE_DECLINE',
            'status'   => 'ACTIVE',
            'severity' => 'WARNING',
            'actual'   => null,
            'baseline' => null,
        ];

        $wrapped = $this->service->wrapWarning($sparsePayload);

        $this->assertNull($wrapped['observed_or_projected']['value']);
        $this->assertNull($wrapped['baseline']['value']);
        $this->assertNull($wrapped['delta_or_threshold']['percentage_change']);
        $this->assertSame('NOT_APPLICABLE_DETERMINISTIC', $wrapped['confidence_or_evaluation']['level']);
    }

    /**
     * 14. wrapFinancingReadiness canonical envelope test
     */
    public function test_wrap_financing_readiness_canonical_envelope(): void
    {
        $payload = [
            'status' => 'SUCCESS',
            'score'  => 72,
            'band'   => 'MODERATE_READINESS',
            'evaluation_period' => [
                'start_date' => '2026-09-01',
                'end_date'   => '2026-09-30',
                'days'       => 30,
            ],
            'pillars' => [
                'revenue_stability' => ['score' => 45, 'weight' => 0.25],
            ],
            'evidence_summary' => [
                [
                    'key'          => 'sales_history',
                    'label'        => 'Riwayat Penjualan (Sales History)',
                    'status'       => 'AVAILABLE',
                    'source'       => 'Tabel transaksi kasir POS (transactions)',
                    'period'       => '2026-09-01 s/d 2026-09-30 (30 Hari)',
                    'record_count' => 50,
                    'coverage'     => '15 dari 30 hari aktif berdagang',
                    'summary'      => 'Total omzet Rp 586.000 dari 50 transaksi tersimpan.',
                    'explainability' => 'Dicatat secara otomatis oleh sistem POS saat kasir menyelesaikan pembayaran.',
                ],
            ],
            'disclaimer'  => 'Financing Readiness Index mengukur kelengkapan data operasional...',
            'limitations' => ['Hanya 78% menu ber-HPP'],
        ];

        $wrapped = $this->service->wrapFinancingReadiness($payload);

        $this->assertSame('financing_readiness', $wrapped['type']);
        $this->assertSame('SUCCESS', $wrapped['status']);
        $this->assertSame(72, $wrapped['score']);
        $this->assertSame('MODERATE_READINESS', $wrapped['band']);
        $this->assertSame('2026-09-01', $wrapped['period']['start_date']);
        $this->assertSame('2026-09-30', $wrapped['period']['end_date']);
        $this->assertCount(1, $wrapped['evidence_coverage']);
        $this->assertSame('sales_history', $wrapped['evidence_coverage'][0]['key']);
        $this->assertSame('Tabel transaksi kasir POS (transactions)', $wrapped['evidence_coverage'][0]['source']);
        $this->assertSame('Financing Readiness Index mengukur kelengkapan data operasional...', $wrapped['disclaimer']);
        $this->assertNotEmpty($wrapped['generated_at']);
    }
}
