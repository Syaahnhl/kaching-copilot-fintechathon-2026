<?php

namespace App\Services\Financial;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Helpers\TimezoneContract;
use Carbon\Carbon;

/**
 * Phase B.2: Deterministic Financial Warning Engine
 *
 * Implements the approved Phase B.1 canonical contract and rules.
 * Strictly deterministic — NO LLM, NO probabilistic scoring, NO raw SQL queries to AI.
 *
 * All financial formulas are delegated to Phase A FinancialCalculationService (SSOT).
 * Tenant isolation: every calculation is scoped by user_id + outlet_id.
 * Timezone: half-open UTC ranges via TimezoneContract.
 *
 * @see App\Services\Financial\FinancialCalculationService (Phase A SSOT)
 * @see App\Helpers\TimezoneContract (Timezone SSOT)
 */
class FinancialWarningService
{
    // Warning Codes
    public const CODE_REVENUE_DECLINE     = 'REVENUE_DECLINE';
    public const CODE_EXPENSE_SPIKE       = 'EXPENSE_SPIKE';
    public const CODE_MARGIN_COMPRESSION  = 'MARGIN_COMPRESSION';
    public const CODE_ABNORMAL_VOID_REFUND = 'ABNORMAL_VOID_REFUND';
    public const CODE_DRAWER_DISCREPANCY  = 'DRAWER_DISCREPANCY';
    public const CODE_CASH_DEPLETION      = 'CASH_DEPLETION';

    // Status Lifecycle
    public const STATUS_NO_WARNING        = 'NO_WARNING';
    public const STATUS_WARNING           = 'WARNING';
    public const STATUS_INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';
    public const STATUS_SUSPENDED         = 'SUSPENDED';
    public const STATUS_UNDEFINED         = 'UNDEFINED';

    // Severity Levels
    public const SEVERITY_NONE            = 'NONE';
    public const SEVERITY_WATCH           = 'WATCH';
    public const SEVERITY_WARNING         = 'WARNING';
    public const SEVERITY_CRITICAL        = 'CRITICAL';

    // Data Quality States
    public const DQ_SUFFICIENT            = 'SUFFICIENT';
    public const DQ_INSUFFICIENT_BASELINE = 'INSUFFICIENT_BASELINE';
    public const DQ_UNTRACKED_COGS        = 'UNTRACKED_COGS';
    public const DQ_INSUFFICIENT_VOLUME   = 'INSUFFICIENT_VOLUME';
    public const DQ_UNDEFINED             = 'UNDEFINED';
    public const DQ_SUSPENDED             = 'SUSPENDED';

    // Confidence Levels
    public const CONFIDENCE_HIGH          = 'HIGH';
    public const CONFIDENCE_MEDIUM        = 'MEDIUM';
    public const CONFIDENCE_LOW           = 'LOW';
    public const CONFIDENCE_INSUFFICIENT  = 'INSUFFICIENT';

    // Thresholds & Calibrated Parameters (from B.0 / B.1)
    public const REVENUE_DECLINE_WATCH_MIN_PCT        = 20.0;
    public const REVENUE_DECLINE_WATCH_MAX_PCT        = 25.0;
    public const REVENUE_DECLINE_WARNING_PCT          = 25.0;
    public const REVENUE_DECLINE_CRITICAL_PCT         = 50.0;
    public const REVENUE_DECLINE_MIN_BASELINE_IDR     = 500000;

    public const EXPENSE_SPIKE_WARNING_RATIO          = 1.5;   // >150%
    public const EXPENSE_SPIKE_WARNING_FLOOR_IDR      = 500000; // >= Rp500,000
    public const EXPENSE_SPIKE_CRITICAL_RATIO         = 3.0;   // >300%
    public const EXPENSE_SPIKE_CRITICAL_FLOOR_IDR     = 2000000; // >= Rp2,000,000

    public const MARGIN_COMPRESSION_WARNING_PP        = 5.0;   // >5 percentage points
    public const MARGIN_COMPRESSION_CRITICAL_PP       = 15.0;  // >15 percentage points
    public const MARGIN_MAX_ALLOWED_UNTRACKED_COGS_PCT = 15.0; // <= 15%

    public const VOID_REFUND_WARNING_PCT              = 5.0;   // >5%
    public const VOID_REFUND_CRITICAL_PCT             = 15.0;  // >15%
    public const VOID_REFUND_MIN_TRANSACTIONS         = 20;    // Minimum 20 trx

    public const DRAWER_DEFICIT_WARNING_NOMINAL_IDR   = 50000; // > Rp50,000
    public const DRAWER_DEFICIT_WARNING_PCT           = 2.0;   // >2% of cash sales
    public const DRAWER_DEFICIT_CRITICAL_NOMINAL_IDR  = 500000; // > Rp500,000
    public const DRAWER_DEFICIT_CRITICAL_PCT          = 10.0;  // >10% of cash sales

    protected FinancialCalculationService $calculationService;

    public function __construct(?FinancialCalculationService $calculationService = null)
    {
        $this->calculationService = $calculationService ?? new FinancialCalculationService();
    }

    // ────────────────────────────────────────────────────────────
    // PUBLIC API
    // ────────────────────────────────────────────────────────────

    /**
     * Evaluate all financial warnings for a given user/outlet.
     *
     * @param int         $userId
     * @param int|null    $outletId
     * @param string|null $referenceDate Business date Y-m-d (defaults to today WIB)
     * @param array       $options       Optional custom parameters
     * @return array<string, array> Keyed by warning code
     */
    public function evaluateAllWarnings(
        int $userId,
        ?int $outletId = null,
        ?string $referenceDate = null,
        array $options = []
    ): array {
        $refDate = $referenceDate ?? Carbon::now('Asia/Jakarta')->format('Y-m-d');

        return [
            self::CODE_REVENUE_DECLINE     => $this->evaluateRevenueDecline($userId, $outletId, $refDate, $options),
            self::CODE_EXPENSE_SPIKE       => $this->evaluateExpenseSpike($userId, $outletId, $refDate, $options),
            self::CODE_MARGIN_COMPRESSION  => $this->evaluateMarginCompression($userId, $outletId, $refDate, $options),
            self::CODE_ABNORMAL_VOID_REFUND => $this->evaluateAbnormalVoidRefund($userId, $outletId, $refDate, $options),
            self::CODE_DRAWER_DISCREPANCY  => $this->evaluateDrawerDiscrepancy($userId, $outletId, $options['shift_id'] ?? null, $options),
            self::CODE_CASH_DEPLETION      => $this->evaluateCashDepletion($userId, $outletId),
        ];
    }

    /**
     * Evaluate warnings with optional caching (per user + outlet).
     * TTL defaults to 30 minutes.
     */
    public function getCachedWarnings(
        int $userId,
        ?int $outletId = null,
        ?string $referenceDate = null,
        int $ttlMinutes = 30,
        array $options = []
    ): array {
        $refDate = $referenceDate ?? Carbon::now('Asia/Jakarta')->format('Y-m-d');
        $cacheKey = "fin_warn_v1_{$userId}_" . ($outletId ?? 'all') . "_{$refDate}";

        return Cache::remember($cacheKey, $ttlMinutes * 60, function () use ($userId, $outletId, $refDate, $options) {
            return $this->evaluateAllWarnings($userId, $outletId, $refDate, $options);
        });
    }

    /**
     * Generate deterministic fingerprint for deduplication.
     */
    public function generateFingerprint(
        int $userId,
        ?int $outletId,
        string $code,
        string $periodKey,
        string $severity
    ): string {
        $outletPart = $outletId !== null ? (string) $outletId : 'all';
        return hash('sha256', "{$userId}:{$outletPart}:{$code}:{$periodKey}:{$severity}");
    }

    // ────────────────────────────────────────────────────────────
    // 1. REVENUE_DECLINE RULE
    // ────────────────────────────────────────────────────────────

    /**
     * Rule 1: REVENUE_DECLINE
     *
     * Baseline: rolling 7-day net revenue vs previous 7-day period.
     * WATCH: decline 20%–25% (or single-window decline >25% pending confirmation)
     * WARNING: decline >25% AND confirmed across 2 consecutive observation windows.
     * CRITICAL: decline >50% AND confirmed across 2 consecutive observation windows.
     * Do NOT trigger WARNING from a single >25% observation.
     */
    public function evaluateRevenueDecline(
        int $userId,
        ?int $outletId,
        string $referenceDate,
        array $options = []
    ): array {
        $tz = $this->resolveTimezone($outletId);

        // Current 7-day window [ref - 6 days, ref]
        $curEnd   = $referenceDate;
        $curStart = Carbon::parse($referenceDate)->subDays(6)->format('Y-m-d');

        // Baseline 7-day window [curStart - 7 days, curStart - 1 day]
        $baseEnd   = Carbon::parse($curStart)->subDays(1)->format('Y-m-d');
        $baseStart = Carbon::parse($baseEnd)->subDays(6)->format('Y-m-d');

        $curRev  = $this->calculationService->calculateRevenue($userId, $outletId, $curStart, $curEnd, $tz);
        $baseRev = $this->calculationService->calculateRevenue($userId, $outletId, $baseStart, $baseEnd, $tz);

        $actualRevenue   = $curRev['value'];
        $baselineRevenue = $baseRev['value'];

        // Data Quality Gate: Baseline must be sufficient
        if ($baselineRevenue <= 0 || $baseRev['records_count'] === 0 || $baselineRevenue < self::REVENUE_DECLINE_MIN_BASELINE_IDR) {
            return $this->formatContract([
                'code'         => self::CODE_REVENUE_DECLINE,
                'status'       => self::STATUS_INSUFFICIENT_DATA,
                'severity'     => self::SEVERITY_NONE,
                'title'        => 'Data Baseline Pendapatan Tidak Memadai',
                'message'      => 'Pendapatan periode baseline tidak mencukupi untuk evaluasi (minimal Rp' . number_format(self::REVENUE_DECLINE_MIN_BASELINE_IDR, 0, ',', '.') . ').',
                'metric'       => 'net_revenue',
                'actual'       => $actualRevenue,
                'baseline'     => $baselineRevenue > 0 ? $baselineRevenue : null,
                'threshold'    => -self::REVENUE_DECLINE_WARNING_PCT,
                'unit'         => 'PERCENT',
                'period'       => [
                    'start'            => $curStart,
                    'end'              => $curEnd,
                    'comparison_start' => $baseStart,
                    'comparison_end'   => $baseEnd,
                    'type'             => 'rolling_7d_wow',
                ],
                'evidence'     => [
                    'current_revenue'     => $actualRevenue,
                    'baseline_revenue'    => $baselineRevenue,
                    'baseline_records'    => $baseRev['records_count'],
                    'min_required_amount' => self::REVENUE_DECLINE_MIN_BASELINE_IDR,
                ],
                'data_quality' => self::DQ_INSUFFICIENT_BASELINE,
                'confidence'   => self::CONFIDENCE_INSUFFICIENT,
                'limitations'  => ['Insufficient baseline revenue history prevents reliable WoW percentage calculation.'],
            ]);
        }

        // Percentage decline: ((current - baseline) / baseline) * 100
        $pctChange = round((($actualRevenue - $baselineRevenue) / $baselineRevenue) * 100, 2);
        $declinePct = $pctChange < 0 ? abs($pctChange) : 0.0;

        // Normal condition
        if ($declinePct < self::REVENUE_DECLINE_WATCH_MIN_PCT) {
            return $this->formatContract([
                'code'         => self::CODE_REVENUE_DECLINE,
                'status'       => self::STATUS_NO_WARNING,
                'severity'     => self::SEVERITY_NONE,
                'title'        => 'Pendapatan Berada Dalam Batas Normal',
                'message'      => 'Pendapatan 7 hari terakhir stabil dengan perubahan ' . $pctChange . '%.',
                'metric'       => 'net_revenue',
                'actual'       => $actualRevenue,
                'baseline'     => $baselineRevenue,
                'threshold'    => -self::REVENUE_DECLINE_WARNING_PCT,
                'unit'         => 'PERCENT',
                'period'       => [
                    'start'            => $curStart,
                    'end'              => $curEnd,
                    'comparison_start' => $baseStart,
                    'comparison_end'   => $baseEnd,
                    'type'             => 'rolling_7d_wow',
                ],
                'evidence'     => [
                    'current_revenue'   => $actualRevenue,
                    'baseline_revenue'  => $baselineRevenue,
                    'percentage_change' => $pctChange,
                    'nominal_drop'      => max(0.0, $baselineRevenue - $actualRevenue),
                    'confirmed'         => false,
                    'windows_observed'  => 1,
                ],
                'data_quality' => self::DQ_SUFFICIENT,
                'confidence'   => self::CONFIDENCE_HIGH,
                'limitations'  => [],
            ]);
        }

        // Check confirmation across 2 consecutive observation windows
        $isConfirmed = false;
        $priorWindowDecline = null;

        if (isset($options['confirmed'])) {
            $isConfirmed = (bool) $options['confirmed'];
            $priorWindowDecline = $options['prior_window_decline'] ?? null;
        } else {
            // Evaluasi observation window sebelumnya (D-1 rolling 7d vs D-1 baseline)
            $priorRefDate = Carbon::parse($referenceDate)->subDay()->format('Y-m-d');
            $priorCurStart = Carbon::parse($priorRefDate)->subDays(6)->format('Y-m-d');
            $priorBaseEnd = Carbon::parse($priorCurStart)->subDays(1)->format('Y-m-d');
            $priorBaseStart = Carbon::parse($priorBaseEnd)->subDays(6)->format('Y-m-d');

            $priorCurRev = $this->calculationService->calculateRevenue($userId, $outletId, $priorCurStart, $priorRefDate, $tz);
            $priorBaseRev = $this->calculationService->calculateRevenue($userId, $outletId, $priorBaseStart, $priorBaseEnd, $tz);

            if ($priorBaseRev['value'] >= self::REVENUE_DECLINE_MIN_BASELINE_IDR) {
                $priorPctChange = round((($priorCurRev['value'] - $priorBaseRev['value']) / $priorBaseRev['value']) * 100, 2);
                $priorWindowDecline = $priorPctChange < 0 ? abs($priorPctChange) : 0.0;
                $isConfirmed = ($priorWindowDecline >= self::REVENUE_DECLINE_WARNING_PCT);
            }
        }

        // Determine Status & Severity
        // Rule: Do NOT trigger WARNING from a single >25% observation
        if ($declinePct >= self::REVENUE_DECLINE_WATCH_MIN_PCT && $declinePct <= self::REVENUE_DECLINE_WATCH_MAX_PCT) {
            // WATCH: decline 20%–25%
            return $this->formatContract([
                'code'         => self::CODE_REVENUE_DECLINE,
                'status'       => self::STATUS_NO_WARNING,
                'severity'     => self::SEVERITY_WATCH,
                'title'        => 'Pemantauan Penurunan Pendapatan (Watch)',
                'message'      => "Pendapatan 7 hari terakhir turun {$declinePct}% (dalam zona pantau 20%–25%).",
                'metric'       => 'net_revenue',
                'actual'       => $actualRevenue,
                'baseline'     => $baselineRevenue,
                'threshold'    => -self::REVENUE_DECLINE_WARNING_PCT,
                'unit'         => 'PERCENT',
                'period'       => [
                    'start'            => $curStart,
                    'end'              => $curEnd,
                    'comparison_start' => $baseStart,
                    'comparison_end'   => $baseEnd,
                    'type'             => 'rolling_7d_wow',
                ],
                'evidence'     => [
                    'current_revenue'       => $actualRevenue,
                    'baseline_revenue'      => $baselineRevenue,
                    'percentage_change'     => $pctChange,
                    'nominal_drop'          => $baselineRevenue - $actualRevenue,
                    'confirmed'             => $isConfirmed,
                    'prior_window_decline'  => $priorWindowDecline,
                    'windows_observed'      => $isConfirmed ? 2 : 1,
                ],
                'data_quality' => self::DQ_SUFFICIENT,
                'confidence'   => self::CONFIDENCE_MEDIUM,
                'limitations'  => ['Decline is within moderate watch threshold, not yet actionable alarm.'],
            ]);
        }

        // Decline > 25% (or >50%)
        if (!$isConfirmed) {
            // Single observation window only: DO NOT trigger WARNING!
            return $this->formatContract([
                'code'         => self::CODE_REVENUE_DECLINE,
                'status'       => self::STATUS_NO_WARNING,
                'severity'     => self::SEVERITY_WATCH,
                'title'        => 'Pemantauan Penurunan Pendapatan (>25% Belum Terkonfirmasi)',
                'message'      => "Pendapatan 7 hari terakhir turun {$declinePct}%, namun baru teramati pada 1 jendela pengamatan (butuh konfirmasi 2 jendela berturut-turut).",
                'metric'       => 'net_revenue',
                'actual'       => $actualRevenue,
                'baseline'     => $baselineRevenue,
                'threshold'    => -self::REVENUE_DECLINE_WARNING_PCT,
                'unit'         => 'PERCENT',
                'period'       => [
                    'start'            => $curStart,
                    'end'              => $curEnd,
                    'comparison_start' => $baseStart,
                    'comparison_end'   => $baseEnd,
                    'type'             => 'rolling_7d_wow',
                ],
                'evidence'     => [
                    'current_revenue'      => $actualRevenue,
                    'baseline_revenue'     => $baselineRevenue,
                    'percentage_change'    => $pctChange,
                    'nominal_drop'         => $baselineRevenue - $actualRevenue,
                    'confirmed'            => false,
                    'prior_window_decline' => $priorWindowDecline,
                    'windows_observed'     => 1,
                ],
                'data_quality' => self::DQ_SUFFICIENT,
                'confidence'   => self::CONFIDENCE_MEDIUM,
                'limitations'  => ['Single-window drop may be caused by transient factors; waiting for consecutive window confirmation.'],
            ]);
        }

        // Confirmed across 2 consecutive observation windows:
        $severity = ($declinePct > self::REVENUE_DECLINE_CRITICAL_PCT)
            ? self::SEVERITY_CRITICAL
            : self::SEVERITY_WARNING;

        return $this->formatContract([
            'code'         => self::CODE_REVENUE_DECLINE,
            'status'       => self::STATUS_WARNING,
            'severity'     => $severity,
            'title'        => $severity === self::SEVERITY_CRITICAL ? 'Krisis Penurunan Pendapatan Ekstrem' : 'Peringatan Penurunan Pendapatan',
            'message'      => "Pendapatan 7 hari terakhir turun {$declinePct}% dan terkonfirmasi pada 2 jendela berturut-turut.",
            'metric'       => 'net_revenue',
            'actual'       => $actualRevenue,
            'baseline'     => $baselineRevenue,
            'threshold'    => -self::REVENUE_DECLINE_WARNING_PCT,
            'unit'         => 'PERCENT',
            'period'       => [
                'start'            => $curStart,
                'end'              => $curEnd,
                'comparison_start' => $baseStart,
                'comparison_end'   => $baseEnd,
                'type'             => 'rolling_7d_wow',
            ],
            'evidence'     => [
                'current_revenue'      => $actualRevenue,
                'baseline_revenue'     => $baselineRevenue,
                'percentage_change'    => $pctChange,
                'nominal_drop'         => $baselineRevenue - $actualRevenue,
                'confirmed'            => true,
                'prior_window_decline' => $priorWindowDecline,
                'windows_observed'     => 2,
            ],
            'data_quality' => self::DQ_SUFFICIENT,
            'confidence'   => self::CONFIDENCE_HIGH,
            'limitations'  => ['External external anomalies (severe weather, road blockages) not tracked.'],
        ]);
    }

    // ────────────────────────────────────────────────────────────
    // 2. EXPENSE_SPIKE RULE
    // ────────────────────────────────────────────────────────────

    /**
     * Rule 2: EXPENSE_SPIKE
     *
     * Current: 7-day expense total.
     * Baseline: 30-day historical expense baseline (normalized to weekly: (30d total / 30) * 7).
     * WARNING: current expense >150% baseline AND current expense >= Rp500,000.
     * CRITICAL: >300% AND current expense >= Rp2,000,000.
     * If baseline is zero/insufficient: INSUFFICIENT_DATA. Never divide by zero.
     */
    public function evaluateExpenseSpike(
        int $userId,
        ?int $outletId,
        string $referenceDate,
        array $options = []
    ): array {
        // Current 7-day window [ref - 6 days, ref]
        $curEnd   = $referenceDate;
        $curStart = Carbon::parse($referenceDate)->subDays(6)->format('Y-m-d');

        // Historical 30-day baseline before current window [curStart - 30 days, curStart - 1 day]
        $baseEnd   = Carbon::parse($curStart)->subDays(1)->format('Y-m-d');
        $baseStart = Carbon::parse($baseEnd)->subDays(29)->format('Y-m-d');

        $curExpResult  = $this->calculationService->calculateExpenses($userId, $outletId, $curStart, $curEnd);
        $baseExpResult = $this->calculationService->calculateExpenses($userId, $outletId, $baseStart, $baseEnd);

        $curExpense       = $curExpResult['value'];
        $base30dExpense   = $baseExpResult['value'];

        // Quality Gate: Baseline must not be zero or negative (Never divide by zero)
        if ($base30dExpense <= 0 || $baseExpResult['records_count'] === 0) {
            return $this->formatContract([
                'code'         => self::CODE_EXPENSE_SPIKE,
                'status'       => self::STATUS_INSUFFICIENT_DATA,
                'severity'     => self::SEVERITY_NONE,
                'title'        => 'Data Baseline Pengeluaran Tidak Memadai',
                'message'      => 'Tidak ada riwayat pengeluaran 30 hari sebelumnya untuk menghitung baseline rasio.',
                'metric'       => 'operational_expenses',
                'actual'       => $curExpense,
                'baseline'     => null,
                'threshold'    => self::EXPENSE_SPIKE_WARNING_RATIO * 100,
                'unit'         => 'PERCENT',
                'period'       => [
                    'start'            => $curStart,
                    'end'              => $curEnd,
                    'comparison_start' => $baseStart,
                    'comparison_end'   => $baseEnd,
                    'type'             => '7d_vs_30d_baseline',
                ],
                'evidence'     => [
                    'current_7d_expense'  => $curExpense,
                    'baseline_30d_total'  => $base30dExpense,
                    'baseline_records'    => $baseExpResult['records_count'],
                ],
                'data_quality' => self::DQ_INSUFFICIENT_BASELINE,
                'confidence'   => self::CONFIDENCE_INSUFFICIENT,
                'limitations'  => ['Zero historical expenses prevent deterministic spike ratio computation.'],
            ]);
        }

        // Weekly normalized baseline: (total 30d / 30) * 7
        $baselineWeekly = round(($base30dExpense / 30.0) * 7.0, 2);

        if ($baselineWeekly <= 0) {
            return $this->formatContract([
                'code'         => self::CODE_EXPENSE_SPIKE,
                'status'       => self::STATUS_INSUFFICIENT_DATA,
                'severity'     => self::SEVERITY_NONE,
                'title'        => 'Baseline Mingguan Pengeluaran Nol',
                'message'      => 'Rata-rata pengeluaran mingguan bernilai nol.',
                'metric'       => 'operational_expenses',
                'actual'       => $curExpense,
                'baseline'     => 0.0,
                'threshold'    => self::EXPENSE_SPIKE_WARNING_RATIO * 100,
                'unit'         => 'PERCENT',
                'period'       => [
                    'start'            => $curStart,
                    'end'              => $curEnd,
                    'comparison_start' => $baseStart,
                    'comparison_end'   => $baseEnd,
                    'type'             => '7d_vs_30d_baseline',
                ],
                'evidence'     => [
                    'current_7d_expense' => $curExpense,
                    'baseline_weekly'    => $baselineWeekly,
                ],
                'data_quality' => self::DQ_INSUFFICIENT_BASELINE,
                'confidence'   => self::CONFIDENCE_INSUFFICIENT,
                'limitations'  => ['Zero weekly baseline.'],
            ]);
        }

        $ratio = round($curExpense / $baselineWeekly, 2);
        $ratioPct = round($ratio * 100, 2);

        // Check CRITICAL: >300% AND current expense >= Rp2,000,000
        if ($ratio > self::EXPENSE_SPIKE_CRITICAL_RATIO && $curExpense >= self::EXPENSE_SPIKE_CRITICAL_FLOOR_IDR) {
            return $this->formatContract([
                'code'         => self::CODE_EXPENSE_SPIKE,
                'status'       => self::STATUS_WARNING,
                'severity'     => self::SEVERITY_CRITICAL,
                'title'        => 'Lonjakan Pengeluaran Kritis (>300%)',
                'message'      => "Pengeluaran 7 hari (Rp" . number_format($curExpense, 0, ',', '.') . ") melonjak {$ratioPct}% dibanding rata-rata mingguan.",
                'metric'       => 'operational_expenses',
                'actual'       => $curExpense,
                'baseline'     => $baselineWeekly,
                'threshold'    => self::EXPENSE_SPIKE_CRITICAL_RATIO * 100,
                'unit'         => 'PERCENT',
                'period'       => [
                    'start'            => $curStart,
                    'end'              => $curEnd,
                    'comparison_start' => $baseStart,
                    'comparison_end'   => $baseEnd,
                    'type'             => '7d_vs_30d_baseline',
                ],
                'evidence'     => [
                    'current_7d_expense'   => $curExpense,
                    'baseline_weekly'      => $baselineWeekly,
                    'ratio'                => $ratio,
                    'ratio_percentage'     => $ratioPct,
                    'nominal_increase'     => $curExpense - $baselineWeekly,
                    'floor_required'       => self::EXPENSE_SPIKE_CRITICAL_FLOOR_IDR,
                ],
                'data_quality' => self::DQ_SUFFICIENT,
                'confidence'   => self::CONFIDENCE_HIGH,
                'limitations'  => ['May include legitimate bulk inventory purchases or CapEx equipment.'],
            ]);
        }

        // Check WARNING: current expense >150% baseline AND current expense >= Rp500,000
        if ($ratio > self::EXPENSE_SPIKE_WARNING_RATIO && $curExpense >= self::EXPENSE_SPIKE_WARNING_FLOOR_IDR) {
            return $this->formatContract([
                'code'         => self::CODE_EXPENSE_SPIKE,
                'status'       => self::STATUS_WARNING,
                'severity'     => self::SEVERITY_WARNING,
                'title'        => 'Peringatan Lonjakan Pengeluaran (>150%)',
                'message'      => "Pengeluaran 7 hari (Rp" . number_format($curExpense, 0, ',', '.') . ") melonjak {$ratioPct}% dibanding rata-rata mingguan.",
                'metric'       => 'operational_expenses',
                'actual'       => $curExpense,
                'baseline'     => $baselineWeekly,
                'threshold'    => self::EXPENSE_SPIKE_WARNING_RATIO * 100,
                'unit'         => 'PERCENT',
                'period'       => [
                    'start'            => $curStart,
                    'end'              => $curEnd,
                    'comparison_start' => $baseStart,
                    'comparison_end'   => $baseEnd,
                    'type'             => '7d_vs_30d_baseline',
                ],
                'evidence'     => [
                    'current_7d_expense'   => $curExpense,
                    'baseline_weekly'      => $baselineWeekly,
                    'ratio'                => $ratio,
                    'ratio_percentage'     => $ratioPct,
                    'nominal_increase'     => $curExpense - $baselineWeekly,
                    'floor_required'       => self::EXPENSE_SPIKE_WARNING_FLOOR_IDR,
                ],
                'data_quality' => self::DQ_SUFFICIENT,
                'confidence'   => self::CONFIDENCE_HIGH,
                'limitations'  => ['May include legitimate periodic expenses (rent, utility bills).'],
            ]);
        }

        // Normal condition (ratio <= 150%, or current expense < Rp500,000)
        return $this->formatContract([
            'code'         => self::CODE_EXPENSE_SPIKE,
            'status'       => self::STATUS_NO_WARNING,
            'severity'     => self::SEVERITY_NONE,
            'title'        => 'Pengeluaran Berada Dalam Batas Wajar',
            'message'      => "Pengeluaran 7 hari (Rp" . number_format($curExpense, 0, ',', '.') . ") berada dalam batas wajar ({$ratioPct}% dari baseline mingguan).",
            'metric'       => 'operational_expenses',
            'actual'       => $curExpense,
            'baseline'     => $baselineWeekly,
            'threshold'    => self::EXPENSE_SPIKE_WARNING_RATIO * 100,
            'unit'         => 'PERCENT',
            'period'       => [
                'start'            => $curStart,
                'end'              => $curEnd,
                'comparison_start' => $baseStart,
                'comparison_end'   => $baseEnd,
                'type'             => '7d_vs_30d_baseline',
            ],
            'evidence'     => [
                'current_7d_expense' => $curExpense,
                'baseline_weekly'    => $baselineWeekly,
                'ratio'              => $ratio,
                'ratio_percentage'   => $ratioPct,
                'floor_required'     => self::EXPENSE_SPIKE_WARNING_FLOOR_IDR,
            ],
            'data_quality' => self::DQ_SUFFICIENT,
            'confidence'   => self::CONFIDENCE_HIGH,
            'limitations'  => [],
        ]);
    }

    // ────────────────────────────────────────────────────────────
    // 3. MARGIN_COMPRESSION RULE
    // ────────────────────────────────────────────────────────────

    /**
     * Rule 3: MARGIN_COMPRESSION
     *
     * Compare: 7-day gross margin vs previous 7-day gross margin.
     * WARNING: drop >5 percentage points.
     * CRITICAL: drop >15 percentage points.
     * Data quality: if untracked COGS ratio >15%: INSUFFICIENT_DATA.
     */
    public function evaluateMarginCompression(
        int $userId,
        ?int $outletId,
        string $referenceDate,
        array $options = []
    ): array {
        $tz = $this->resolveTimezone($outletId);

        // Current 7-day window [ref - 6 days, ref]
        $curEnd   = $referenceDate;
        $curStart = Carbon::parse($referenceDate)->subDays(6)->format('Y-m-d');

        // Baseline 7-day window [curStart - 7 days, curStart - 1 day]
        $baseEnd   = Carbon::parse($curStart)->subDays(1)->format('Y-m-d');
        $baseStart = Carbon::parse($baseEnd)->subDays(6)->format('Y-m-d');

        $curRev  = $this->calculationService->calculateRevenue($userId, $outletId, $curStart, $curEnd, $tz);
        $curCogs = $this->calculationService->calculateCogs($userId, $outletId, $curStart, $curEnd, $tz);

        // Quality Gate 1: Untracked COGS ratio must not exceed 15%
        $untrackedPct = $curCogs['untracked_items_percentage'];
        if ($untrackedPct > self::MARGIN_MAX_ALLOWED_UNTRACKED_COGS_PCT) {
            return $this->formatContract([
                'code'         => self::CODE_MARGIN_COMPRESSION,
                'status'       => self::STATUS_INSUFFICIENT_DATA,
                'severity'     => self::SEVERITY_NONE,
                'title'        => 'Kualitas Data HPP Tidak Memenuhi Syarat',
                'message'      => "Rasio item tanpa HPP ({$untrackedPct}%) melebihi batas toleransi maksimal " . self::MARGIN_MAX_ALLOWED_UNTRACKED_COGS_PCT . "%.",
                'metric'       => 'gross_margin',
                'actual'       => null,
                'baseline'     => null,
                'threshold'    => -self::MARGIN_COMPRESSION_WARNING_PP,
                'unit'         => 'PERCENTAGE_POINTS',
                'period'       => [
                    'start'            => $curStart,
                    'end'              => $curEnd,
                    'comparison_start' => $baseStart,
                    'comparison_end'   => $baseEnd,
                    'type'             => 'rolling_7d_wow',
                ],
                'evidence'     => [
                    'untracked_items_count'      => $curCogs['untracked_items_count'],
                    'total_items_count'          => $curCogs['total_items_count'],
                    'untracked_items_percentage' => $untrackedPct,
                    'max_allowed_percentage'     => self::MARGIN_MAX_ALLOWED_UNTRACKED_COGS_PCT,
                ],
                'data_quality' => self::DQ_UNTRACKED_COGS,
                'confidence'   => self::CONFIDENCE_INSUFFICIENT,
                'limitations'  => ['Merchant must input cost_price for sold menu items before margin compression can be computed.'],
            ]);
        }

        // Quality Gate 2: Revenue must be positive in both periods (avoid division by zero)
        if ($curRev['value'] <= 0) {
            return $this->formatContract([
                'code'         => self::CODE_MARGIN_COMPRESSION,
                'status'       => self::STATUS_INSUFFICIENT_DATA,
                'severity'     => self::SEVERITY_NONE,
                'title'        => 'Pendapatan Berjalan Nol',
                'message'      => 'Pendapatan periode berjalan nol; margin kotor tidak terdefinisi.',
                'metric'       => 'gross_margin',
                'actual'       => null,
                'baseline'     => null,
                'threshold'    => -self::MARGIN_COMPRESSION_WARNING_PP,
                'unit'         => 'PERCENTAGE_POINTS',
                'period'       => [
                    'start'            => $curStart,
                    'end'              => $curEnd,
                    'comparison_start' => $baseStart,
                    'comparison_end'   => $baseEnd,
                    'type'             => 'rolling_7d_wow',
                ],
                'evidence'     => [
                    'current_revenue' => $curRev['value'],
                ],
                'data_quality' => self::DQ_UNDEFINED,
                'confidence'   => self::CONFIDENCE_INSUFFICIENT,
                'limitations'  => ['Zero revenue prevents division for margin computation.'],
            ]);
        }

        $baseRev  = $this->calculationService->calculateRevenue($userId, $outletId, $baseStart, $baseEnd, $tz);
        $baseCogs = $this->calculationService->calculateCogs($userId, $outletId, $baseStart, $baseEnd, $tz);

        if ($baseRev['value'] <= 0) {
            return $this->formatContract([
                'code'         => self::CODE_MARGIN_COMPRESSION,
                'status'       => self::STATUS_INSUFFICIENT_DATA,
                'severity'     => self::SEVERITY_NONE,
                'title'        => 'Pendapatan Baseline Nol',
                'message'      => 'Pendapatan periode baseline nol; margin pembanding tidak terdefinisi.',
                'metric'       => 'gross_margin',
                'actual'       => null,
                'baseline'     => null,
                'threshold'    => -self::MARGIN_COMPRESSION_WARNING_PP,
                'unit'         => 'PERCENTAGE_POINTS',
                'period'       => [
                    'start'            => $curStart,
                    'end'              => $curEnd,
                    'comparison_start' => $baseStart,
                    'comparison_end'   => $baseEnd,
                    'type'             => 'rolling_7d_wow',
                ],
                'evidence'     => [
                    'baseline_revenue' => $baseRev['value'],
                ],
                'data_quality' => self::DQ_UNDEFINED,
                'confidence'   => self::CONFIDENCE_INSUFFICIENT,
                'limitations'  => ['Zero baseline revenue.'],
            ]);
        }

        // Calculate Gross Margins (in percentage: (Rev - COGS) / Rev * 100)
        $curMargin  = round((($curRev['value'] - $curCogs['value']) / $curRev['value']) * 100, 2);
        $baseMargin = round((($baseRev['value'] - $baseCogs['value']) / $baseRev['value']) * 100, 2);

        // Compression = drop in percentage points: baseline - current
        $dropPp = round($baseMargin - $curMargin, 2);

        // CRITICAL: drop > 15 percentage points
        if ($dropPp > self::MARGIN_COMPRESSION_CRITICAL_PP) {
            return $this->formatContract([
                'code'         => self::CODE_MARGIN_COMPRESSION,
                'status'       => self::STATUS_WARNING,
                'severity'     => self::SEVERITY_CRITICAL,
                'title'        => 'Kompresi Margin Kotor Kritis (>15 pp)',
                'message'      => "Margin kotor tergerus {$dropPp} poin persentase (dari {$baseMargin}% menjadi {$curMargin}%).",
                'metric'       => 'gross_margin',
                'actual'       => $curMargin,
                'baseline'     => $baseMargin,
                'threshold'    => -self::MARGIN_COMPRESSION_CRITICAL_PP,
                'unit'         => 'PERCENTAGE_POINTS',
                'period'       => [
                    'start'            => $curStart,
                    'end'              => $curEnd,
                    'comparison_start' => $baseStart,
                    'comparison_end'   => $baseEnd,
                    'type'             => 'rolling_7d_wow',
                ],
                'evidence'     => [
                    'current_margin'         => $curMargin,
                    'baseline_margin'        => $baseMargin,
                    'drop_percentage_points' => $dropPp,
                    'current_revenue'        => $curRev['value'],
                    'current_cogs'           => $curCogs['value'],
                    'untracked_cogs_ratio'   => $untrackedPct,
                ],
                'data_quality' => self::DQ_SUFFICIENT,
                'confidence'   => self::CONFIDENCE_HIGH,
                'limitations'  => ['Severe margin deterioration may indicate supplier price hike or portioning inconsistency.'],
            ]);
        }

        // WARNING: drop > 5 percentage points
        if ($dropPp > self::MARGIN_COMPRESSION_WARNING_PP) {
            return $this->formatContract([
                'code'         => self::CODE_MARGIN_COMPRESSION,
                'status'       => self::STATUS_WARNING,
                'severity'     => self::SEVERITY_WARNING,
                'title'        => 'Peringatan Penurunan Margin Kotor (>5 pp)',
                'message'      => "Margin kotor turun {$dropPp} poin persentase (dari {$baseMargin}% menjadi {$curMargin}%).",
                'metric'       => 'gross_margin',
                'actual'       => $curMargin,
                'baseline'     => $baseMargin,
                'threshold'    => -self::MARGIN_COMPRESSION_WARNING_PP,
                'unit'         => 'PERCENTAGE_POINTS',
                'period'       => [
                    'start'            => $curStart,
                    'end'              => $curEnd,
                    'comparison_start' => $baseStart,
                    'comparison_end'   => $baseEnd,
                    'type'             => 'rolling_7d_wow',
                ],
                'evidence'     => [
                    'current_margin'         => $curMargin,
                    'baseline_margin'        => $baseMargin,
                    'drop_percentage_points' => $dropPp,
                    'current_revenue'        => $curRev['value'],
                    'current_cogs'           => $curCogs['value'],
                    'untracked_cogs_ratio'   => $untrackedPct,
                ],
                'data_quality' => self::DQ_SUFFICIENT,
                'confidence'   => self::CONFIDENCE_HIGH,
                'limitations'  => ['May reflect product mix shift toward lower-margin menu categories.'],
            ]);
        }

        // Normal condition (drop <= 5 percentage points)
        return $this->formatContract([
            'code'         => self::CODE_MARGIN_COMPRESSION,
            'status'       => self::STATUS_NO_WARNING,
            'severity'     => self::SEVERITY_NONE,
            'title'        => 'Margin Kotor Stabil',
            'message'      => "Margin kotor berada dalam batas wajar ({$curMargin}% vs baseline {$baseMargin}%).",
            'metric'       => 'gross_margin',
            'actual'       => $curMargin,
            'baseline'     => $baseMargin,
            'threshold'    => -self::MARGIN_COMPRESSION_WARNING_PP,
            'unit'         => 'PERCENTAGE_POINTS',
            'period'       => [
                'start'            => $curStart,
                'end'              => $curEnd,
                'comparison_start' => $baseStart,
                'comparison_end'   => $baseEnd,
                'type'             => 'rolling_7d_wow',
            ],
            'evidence'     => [
                'current_margin'         => $curMargin,
                'baseline_margin'        => $baseMargin,
                'drop_percentage_points' => $dropPp,
                'untracked_cogs_ratio'   => $untrackedPct,
            ],
            'data_quality' => self::DQ_SUFFICIENT,
            'confidence'   => self::CONFIDENCE_HIGH,
            'limitations'  => [],
        ]);
    }

    // ────────────────────────────────────────────────────────────
    // 4. ABNORMAL_VOID_REFUND RULE
    // ────────────────────────────────────────────────────────────

    /**
     * Rule 4: ABNORMAL_VOID_REFUND
     *
     * Ratio: cancelled/void/refund transactions divided by total transactions.
     * WARNING: >5%.
     * CRITICAL: >15%.
     * Minimum: 20 transactions.
     * If <20: INSUFFICIENT_DATA.
     * Use Phase A cancellation semantics consistently.
     */
    public function evaluateAbnormalVoidRefund(
        int $userId,
        ?int $outletId,
        string $referenceDate,
        array $options = []
    ): array {
        $tz = $this->resolveTimezone($outletId);

        // Evaluation period (default: 7-day rolling window or single date if provided)
        $endDate   = $referenceDate;
        $startDate = $options['start_date'] ?? Carbon::parse($referenceDate)->subDays(6)->format('Y-m-d');

        [$utcStart, $utcEnd] = TimezoneContract::businessDateRangeToUtcRange($startDate, $endDate, $tz);

        $baseQuery = DB::table('transactions')
            ->where('user_id', $userId)
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->where('created_at_device', '>=', $utcStart)
            ->where('created_at_device', '<', $utcEnd);

        $totalCount = (clone $baseQuery)->count();

        // Quality Gate: Minimum 20 transactions
        if ($totalCount < self::VOID_REFUND_MIN_TRANSACTIONS) {
            return $this->formatContract([
                'code'         => self::CODE_ABNORMAL_VOID_REFUND,
                'status'       => self::STATUS_INSUFFICIENT_DATA,
                'severity'     => self::SEVERITY_NONE,
                'title'        => 'Volume Transaksi Kurang Dari Batas Minimal',
                'message'      => "Total transaksi ({$totalCount}) kurang dari batas minimal evaluasi (" . self::VOID_REFUND_MIN_TRANSACTIONS . " transaksi).",
                'metric'       => 'void_refund_ratio',
                'actual'       => null,
                'baseline'     => self::VOID_REFUND_WARNING_PCT,
                'threshold'    => self::VOID_REFUND_WARNING_PCT,
                'unit'         => 'PERCENT',
                'period'       => [
                    'start' => $startDate,
                    'end'   => $endDate,
                    'type'  => 'evaluation_period',
                ],
                'evidence'     => [
                    'total_transactions' => $totalCount,
                    'min_required'       => self::VOID_REFUND_MIN_TRANSACTIONS,
                ],
                'data_quality' => self::DQ_INSUFFICIENT_VOLUME,
                'confidence'   => self::CONFIDENCE_INSUFFICIENT,
                'limitations'  => ['Low transaction sample sizes cause false spike percentages from single accidental voids.'],
            ]);
        }

        // Count cancelled/void/refund transactions using Phase A SSOT CANCEL_STATUSES
        $voidCount = (clone $baseQuery)
            ->whereIn('status', FinancialCalculationService::CANCEL_STATUSES)
            ->count();

        $voidRatio = round(($voidCount / $totalCount) * 100, 2);

        // CRITICAL: >15%
        if ($voidRatio > self::VOID_REFUND_CRITICAL_PCT) {
            return $this->formatContract([
                'code'         => self::CODE_ABNORMAL_VOID_REFUND,
                'status'       => self::STATUS_WARNING,
                'severity'     => self::SEVERITY_CRITICAL,
                'title'        => 'Rasio Pembatalan/Void Kritis (>15%)',
                'message'      => "Rasio void/pembatalan mencapai {$voidRatio}% ({$voidCount} dari {$totalCount} transaksi).",
                'metric'       => 'void_refund_ratio',
                'actual'       => $voidRatio,
                'baseline'     => self::VOID_REFUND_WARNING_PCT,
                'threshold'    => self::VOID_REFUND_CRITICAL_PCT,
                'unit'         => 'PERCENT',
                'period'       => [
                    'start' => $startDate,
                    'end'   => $endDate,
                    'type'  => 'evaluation_period',
                ],
                'evidence'     => [
                    'total_transactions'  => $totalCount,
                    'void_count'          => $voidCount,
                    'void_ratio'          => $voidRatio,
                    'cancel_statuses'     => FinancialCalculationService::CANCEL_STATUSES,
                ],
                'data_quality' => self::DQ_SUFFICIENT,
                'confidence'   => self::CONFIDENCE_HIGH,
                'limitations'  => ['High void frequency may indicate cashier fraud, training deficit, or kitchen shortage.'],
            ]);
        }

        // WARNING: >5%
        if ($voidRatio > self::VOID_REFUND_WARNING_PCT) {
            return $this->formatContract([
                'code'         => self::CODE_ABNORMAL_VOID_REFUND,
                'status'       => self::STATUS_WARNING,
                'severity'     => self::SEVERITY_WARNING,
                'title'        => 'Peringatan Rasio Void/Pembatalan Tinggi (>5%)',
                'message'      => "Rasio void/pembatalan mencapai {$voidRatio}% ({$voidCount} dari {$totalCount} transaksi).",
                'metric'       => 'void_refund_ratio',
                'actual'       => $voidRatio,
                'baseline'     => self::VOID_REFUND_WARNING_PCT,
                'threshold'    => self::VOID_REFUND_WARNING_PCT,
                'unit'         => 'PERCENT',
                'period'       => [
                    'start' => $startDate,
                    'end'   => $endDate,
                    'type'  => 'evaluation_period',
                ],
                'evidence'     => [
                    'total_transactions' => $totalCount,
                    'void_count'         => $voidCount,
                    'void_ratio'         => $voidRatio,
                    'cancel_statuses'    => FinancialCalculationService::CANCEL_STATUSES,
                ],
                'data_quality' => self::DQ_SUFFICIENT,
                'confidence'   => self::CONFIDENCE_HIGH,
                'limitations'  => ['Review cashier audit logs to identify which staff member executed the void actions.'],
            ]);
        }

        // Normal condition (ratio <= 5.0%)
        return $this->formatContract([
            'code'         => self::CODE_ABNORMAL_VOID_REFUND,
            'status'       => self::STATUS_NO_WARNING,
            'severity'     => self::SEVERITY_NONE,
            'title'        => 'Rasio Void Dalam Batas Wajar',
            'message'      => "Rasio void berada dalam batas wajar ({$voidRatio}% dari {$totalCount} transaksi).",
            'metric'       => 'void_refund_ratio',
            'actual'       => $voidRatio,
            'baseline'     => self::VOID_REFUND_WARNING_PCT,
            'threshold'    => self::VOID_REFUND_WARNING_PCT,
            'unit'         => 'PERCENT',
            'period'       => [
                'start' => $startDate,
                'end'   => $endDate,
                'type'  => 'evaluation_period',
            ],
            'evidence'     => [
                'total_transactions' => $totalCount,
                'void_count'         => $voidCount,
                'void_ratio'         => $voidRatio,
            ],
            'data_quality' => self::DQ_SUFFICIENT,
            'confidence'   => self::CONFIDENCE_HIGH,
            'limitations'  => [],
        ]);
    }

    // ────────────────────────────────────────────────────────────
    // 5. DRAWER_DISCREPANCY RULE
    // ────────────────────────────────────────────────────────────

    /**
     * Rule 5: DRAWER_DISCREPANCY
     *
     * Only closed shifts.
     * Only DEFICIT: actual cash < expected cash.
     * WARNING: nominal deficit > Rp50,000 AND deficit >2% of relevant cash sales.
     * CRITICAL: nominal deficit > Rp500,000 AND deficit >10%.
     * Surplus must NOT become a financial-loss warning.
     */
    public function evaluateDrawerDiscrepancy(
        int $userId,
        ?int $outletId,
        ?int $shiftId = null,
        array $options = []
    ): array {
        // Fetch target shift: either specific ID or latest closed shift
        $query = DB::table('shifts')
            ->where('user_id', $userId)
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId));

        if ($shiftId !== null) {
            $shift = (clone $query)->where('id', $shiftId)->first();
        } else {
            $shift = (clone $query)
                ->where(function ($q) {
                    $q->where('status', 'closed')
                      ->orWhereNotNull('end_time');
                })
                ->orderByDesc('start_time')
                ->first();
        }

        // Quality Gate 1: Shift must exist
        if (!$shift) {
            return $this->formatContract([
                'code'         => self::CODE_DRAWER_DISCREPANCY,
                'status'       => self::STATUS_INSUFFICIENT_DATA,
                'severity'     => self::SEVERITY_NONE,
                'title'        => 'Data Shift Tidak Ditemukan',
                'message'      => 'Tidak ada data shift tertutup yang dapat dievaluasi.',
                'metric'       => 'cash_drawer_deficit',
                'actual'       => null,
                'baseline'     => 0.0,
                'threshold'    => self::DRAWER_DEFICIT_WARNING_NOMINAL_IDR,
                'unit'         => 'IDR',
                'period'       => ['type' => 'shift'],
                'evidence'     => ['shift_id' => $shiftId],
                'data_quality' => self::DQ_INSUFFICIENT_VOLUME,
                'confidence'   => self::CONFIDENCE_INSUFFICIENT,
                'limitations'  => ['No closed shifts recorded for this outlet.'],
            ]);
        }

        // Quality Gate 2: Shift must be closed
        $isClosed = (strtolower($shift->status ?? '') === 'closed') || ($shift->end_time !== null);
        if (!$isClosed) {
            return $this->formatContract([
                'code'         => self::CODE_DRAWER_DISCREPANCY,
                'status'       => self::STATUS_INSUFFICIENT_DATA,
                'severity'     => self::SEVERITY_NONE,
                'title'        => 'Shift Masih Aktif',
                'message'      => "Shift #{$shift->id} belum ditutup; selisih kas fisik hanya dapat dihitung setelah penutupan shift.",
                'metric'       => 'cash_drawer_deficit',
                'actual'       => null,
                'baseline'     => 0.0,
                'threshold'    => self::DRAWER_DEFICIT_WARNING_NOMINAL_IDR,
                'unit'         => 'IDR',
                'period'       => [
                    'shift_id'   => $shift->id,
                    'start_time' => $shift->start_time,
                    'type'       => 'shift',
                ],
                'evidence'     => [
                    'shift_id' => $shift->id,
                    'status'   => $shift->status,
                ],
                'data_quality' => self::DQ_UNDEFINED,
                'confidence'   => self::CONFIDENCE_INSUFFICIENT,
                'limitations'  => ['Active shifts have continuously changing expected cash and no verified physical count.'],
            ]);
        }

        $expectedCash = (float) ($shift->expected_cash ?? 0);
        $endCash      = (float) ($shift->end_cash ?? 0);
        $cashSales    = (float) ($shift->total_cash_sales ?? 0);

        // Invariant: Only DEFICIT (actual cash < expected cash)
        // If end_cash >= expected_cash, it is SURPLUS or EXACT MATCH
        if ($endCash >= $expectedCash) {
            $surplus = $endCash - $expectedCash;
            return $this->formatContract([
                'code'         => self::CODE_DRAWER_DISCREPANCY,
                'status'       => self::STATUS_NO_WARNING,
                'severity'     => self::SEVERITY_NONE,
                'title'        => 'Kas Fisik Sesuai atau Surplus',
                'message'      => $surplus > 0
                    ? "Kas fisik laci surplus Rp" . number_format($surplus, 0, ',', '.') . " di atas sistem (bukan kerugian kas)."
                    : "Kas fisik laci sesuai dengan perhitungan sistem.",
                'metric'       => 'cash_drawer_deficit',
                'actual'       => 0.0,
                'baseline'     => 0.0,
                'threshold'    => self::DRAWER_DEFICIT_WARNING_NOMINAL_IDR,
                'unit'         => 'IDR',
                'period'       => [
                    'shift_id'   => $shift->id,
                    'start_time' => $shift->start_time,
                    'end_time'   => $shift->end_time,
                    'type'       => 'shift',
                ],
                'evidence'     => [
                    'shift_id'         => $shift->id,
                    'cashier_name'     => $shift->cashier_name,
                    'expected_cash'    => $expectedCash,
                    'end_cash'         => $endCash,
                    'surplus'          => $surplus,
                    'deficit'          => 0.0,
                    'total_cash_sales' => $cashSales,
                ],
                'data_quality' => self::DQ_SUFFICIENT,
                'confidence'   => self::CONFIDENCE_HIGH,
                'limitations'  => [],
            ]);
        }

        // Deficit exists: expectedCash > endCash
        $nominalDeficit = round($expectedCash - $endCash, 2);

        // Quality Gate 3: Cash sales must be positive to compute percentage
        if ($cashSales <= 0) {
            return $this->formatContract([
                'code'         => self::CODE_DRAWER_DISCREPANCY,
                'status'       => self::STATUS_INSUFFICIENT_DATA,
                'severity'     => self::SEVERITY_NONE,
                'title'        => 'Penjualan Tunai Nol',
                'message'      => "Penjualan tunai shift #{$shift->id} bernilai nol; persentase defisit tidak dapat dihitung.",
                'metric'       => 'cash_drawer_deficit',
                'actual'       => $nominalDeficit,
                'baseline'     => 0.0,
                'threshold'    => self::DRAWER_DEFICIT_WARNING_NOMINAL_IDR,
                'unit'         => 'IDR',
                'period'       => [
                    'shift_id'   => $shift->id,
                    'start_time' => $shift->start_time,
                    'end_time'   => $shift->end_time,
                    'type'       => 'shift',
                ],
                'evidence'     => [
                    'shift_id'         => $shift->id,
                    'nominal_deficit'  => $nominalDeficit,
                    'total_cash_sales' => $cashSales,
                ],
                'data_quality' => self::DQ_UNDEFINED,
                'confidence'   => self::CONFIDENCE_INSUFFICIENT,
                'limitations'  => ['Zero cash sales prevents percentage ratio computation.'],
            ]);
        }

        $deficitPct = round(($nominalDeficit / $cashSales) * 100, 2);

        // CRITICAL: nominal deficit > Rp500,000 AND deficit >10%
        if ($nominalDeficit > self::DRAWER_DEFICIT_CRITICAL_NOMINAL_IDR && $deficitPct > self::DRAWER_DEFICIT_CRITICAL_PCT) {
            return $this->formatContract([
                'code'         => self::CODE_DRAWER_DISCREPANCY,
                'status'       => self::STATUS_WARNING,
                'severity'     => self::SEVERITY_CRITICAL,
                'title'        => 'Defisit Kas Fisik Laci Kritis',
                'message'      => "Uang fisik laci kasir kurang Rp" . number_format($nominalDeficit, 0, ',', '.') . " ({$deficitPct}% dari omzet tunai shift).",
                'metric'       => 'cash_drawer_deficit',
                'actual'       => $nominalDeficit,
                'baseline'     => 0.0,
                'threshold'    => self::DRAWER_DEFICIT_CRITICAL_NOMINAL_IDR,
                'unit'         => 'IDR',
                'period'       => [
                    'shift_id'   => $shift->id,
                    'start_time' => $shift->start_time,
                    'end_time'   => $shift->end_time,
                    'type'       => 'shift',
                ],
                'evidence'     => [
                    'shift_id'           => $shift->id,
                    'cashier_name'       => $shift->cashier_name,
                    'expected_cash'      => $expectedCash,
                    'end_cash'           => $endCash,
                    'nominal_deficit'    => $nominalDeficit,
                    'total_cash_sales'   => $cashSales,
                    'deficit_percentage' => $deficitPct,
                ],
                'data_quality' => self::DQ_SUFFICIENT,
                'confidence'   => self::CONFIDENCE_HIGH,
                'limitations'  => ['High cash shortage warrants physical count audit and investigation of unrecorded cash drops.'],
            ]);
        }

        // WARNING: nominal deficit > Rp50,000 AND deficit >2%
        if ($nominalDeficit > self::DRAWER_DEFICIT_WARNING_NOMINAL_IDR && $deficitPct > self::DRAWER_DEFICIT_WARNING_PCT) {
            return $this->formatContract([
                'code'         => self::CODE_DRAWER_DISCREPANCY,
                'status'       => self::STATUS_WARNING,
                'severity'     => self::SEVERITY_WARNING,
                'title'        => 'Peringatan Defisit Kas Fisik Laci',
                'message'      => "Uang fisik laci kasir kurang Rp" . number_format($nominalDeficit, 0, ',', '.') . " ({$deficitPct}% dari omzet tunai shift).",
                'metric'       => 'cash_drawer_deficit',
                'actual'       => $nominalDeficit,
                'baseline'     => 0.0,
                'threshold'    => self::DRAWER_DEFICIT_WARNING_NOMINAL_IDR,
                'unit'         => 'IDR',
                'period'       => [
                    'shift_id'   => $shift->id,
                    'start_time' => $shift->start_time,
                    'end_time'   => $shift->end_time,
                    'type'       => 'shift',
                ],
                'evidence'     => [
                    'shift_id'           => $shift->id,
                    'cashier_name'       => $shift->cashier_name,
                    'expected_cash'      => $expectedCash,
                    'end_cash'           => $endCash,
                    'nominal_deficit'    => $nominalDeficit,
                    'total_cash_sales'   => $cashSales,
                    'deficit_percentage' => $deficitPct,
                ],
                'data_quality' => self::DQ_SUFFICIENT,
                'confidence'   => self::CONFIDENCE_HIGH,
                'limitations'  => ['Check if cashier borrowed petty cash for change without inputting an expense entry.'],
            ]);
        }

        // Deficit below threshold (nominal <= Rp50k or percentage <= 2%) -> NO_WARNING
        return $this->formatContract([
            'code'         => self::CODE_DRAWER_DISCREPANCY,
            'status'       => self::STATUS_NO_WARNING,
            'severity'     => self::SEVERITY_NONE,
            'title'        => 'Selisih Kas Dalam Batas Toleransi',
            'message'      => "Selisih kas kurang Rp" . number_format($nominalDeficit, 0, ',', '.') . " ({$deficitPct}%) masih dalam batas toleransi wajar.",
            'metric'       => 'cash_drawer_deficit',
            'actual'       => $nominalDeficit,
            'baseline'     => 0.0,
            'threshold'    => self::DRAWER_DEFICIT_WARNING_NOMINAL_IDR,
            'unit'         => 'IDR',
            'period'       => [
                'shift_id'   => $shift->id,
                'start_time' => $shift->start_time,
                'end_time'   => $shift->end_time,
                'type'       => 'shift',
            ],
            'evidence'     => [
                'shift_id'           => $shift->id,
                'cashier_name'       => $shift->cashier_name,
                'expected_cash'      => $expectedCash,
                'end_cash'           => $endCash,
                'nominal_deficit'    => $nominalDeficit,
                'total_cash_sales'   => $cashSales,
                'deficit_percentage' => $deficitPct,
            ],
            'data_quality' => self::DQ_SUFFICIENT,
            'confidence'   => self::CONFIDENCE_HIGH,
            'limitations'  => [],
        ]);
    }

    // ────────────────────────────────────────────────────────────
    // 6. CASH_DEPLETION RULE (SUSPENDED)
    // ────────────────────────────────────────────────────────────

    /**
     * Rule 6: CASH_DEPLETION (DO NOT IMPLEMENT — SUSPENDED)
     *
     * POS cash drawer is not equivalent to business liquidity.
     * Always returns STATUS_SUSPENDED.
     */
    public function evaluateCashDepletion(int $userId, ?int $outletId): array
    {
        return $this->formatContract([
            'code'         => self::CODE_CASH_DEPLETION,
            'status'       => self::STATUS_SUSPENDED,
            'severity'     => self::SEVERITY_NONE,
            'title'        => 'Metrik Runway Kas Ditangguhkan',
            'message'      => 'Kalkulasi runway kas laci kasir ditangguhkan karena kas POS tidak merepresentasikan saldo rekening likuiditas usaha.',
            'metric'       => 'cash_runway',
            'actual'       => null,
            'baseline'     => null,
            'threshold'    => 7.0,
            'unit'         => 'DAYS',
            'period'       => ['type' => 'point_in_time'],
            'evidence'     => [
                'reason'                 => 'POS cash drawer balance is a transaction float and operational petty cash, not business bank liquidity.',
                'architectural_decision' => 'CASH_DEPLETION suspended from POS core engine pending bank integration module.',
            ],
            'data_quality' => self::DQ_SUSPENDED,
            'confidence'   => self::CONFIDENCE_INSUFFICIENT,
            'limitations'  => [
                'POS cash drawer is not equivalent to business liquidity.',
                'Bank balance and external liquidity sources are not tracked in POS cash drawer.',
            ],
        ]);
    }

    // ────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ────────────────────────────────────────────────────────────

    /**
     * Ensure the canonical contract format is strictly respected.
     */
    private function formatContract(array $data): array
    {
        return [
            'code'         => (string) $data['code'],
            'status'       => (string) $data['status'],
            'severity'     => (string) $data['severity'],
            'title'        => (string) $data['title'],
            'message'      => (string) $data['message'],
            'metric'       => (string) $data['metric'],
            'actual'       => $data['actual'] !== null ? (float) $data['actual'] : null,
            'baseline'     => $data['baseline'] !== null ? (float) $data['baseline'] : null,
            'threshold'    => $data['threshold'] !== null ? (float) $data['threshold'] : null,
            'unit'         => (string) $data['unit'],
            'period'       => (array) $data['period'],
            'evidence'     => (array) $data['evidence'],
            'data_quality' => (string) $data['data_quality'],
            'confidence'   => (string) $data['confidence'],
            'limitations'  => (array) ($data['limitations'] ?? []),
        ];
    }

    /**
     * Resolve timezone with Asia/Jakarta fallback.
     */
    private function resolveTimezone(?int $outletId): string
    {
        if ($outletId !== null) {
            return TimezoneContract::resolveOutletTimezone($outletId);
        }
        return 'Asia/Jakarta';
    }
}
