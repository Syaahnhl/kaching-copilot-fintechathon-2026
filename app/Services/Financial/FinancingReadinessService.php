<?php

namespace App\Services\Financial;

use App\Helpers\TimezoneContract;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FinancingReadinessService
 *
 * Deterministic Financial & Operational Readiness Assessment Engine for Kaching Copilot (Topic E).
 *
 * CRITICAL COMPETITION INVARIANTS:
 * 1. DETERMINISTIC & VERIFIABLE — 100% computed from real database records, zero LLM guesswork.
 * 2. READ-ONLY — zero mutations, zero writes to database.
 * 3. NOT A CREDIT SCORE — evaluates SME data readiness & operational hygiene, NOT loan approvals.
 * 4. STRICT DISCLAIMER — explicitly states no credit guarantee or underwriting decision.
 * 5. HONEST LIMITATIONS — incomplete historical data reduces score and is reported transparently.
 *
 * PILLARS & WEIGHTS:
 * 1. Revenue Stability (25%): 30-day active selling days, daily sales consistency, volatility CV.
 * 2. Margin Health (20%): Gross margin %, net margin %, COGS tracking coverage.
 * 3. Cash Discipline (25%): Closed shift reconciliation, cash drawer discrepancy rate & nominal.
 * 4. Operational Integrity (15%): Low cancellation, void, and refund rates (<2% is optimal).
 * 5. Data Completeness (15%): Catalog completeness (HPP filled), category coverage, payment methods.
 */
class FinancingReadinessService
{
    public const WEIGHT_REVENUE_STABILITY = 0.25;
    public const WEIGHT_MARGIN_HEALTH = 0.20;
    public const WEIGHT_CASH_DISCIPLINE = 0.25;
    public const WEIGHT_OPERATIONAL_INTEGRITY = 0.15;
    public const WEIGHT_DATA_COMPLETENESS = 0.15;

    public const BAND_HIGH = 'HIGH_READINESS';
    public const BAND_MODERATE = 'MODERATE_READINESS';
    public const BAND_EARLY = 'EARLY_STAGE_READINESS';
    public const BAND_NEEDS_IMPROVEMENT = 'NEEDS_OPERATIONAL_IMPROVEMENT';

    protected FinancialCalculationService $calcService;

    public function __construct(?FinancialCalculationService $calcService = null)
    {
        $this->calcService = $calcService ?? app(FinancialCalculationService::class);
    }

    /**
     * Calculate Financing Readiness Index (0-100).
     *
     * @param int $userId Authenticated tenant ID (SSOT)
     * @param int|null $outletId Validated outlet ID
     * @param string|null $referenceDate Optional business date (defaults to today)
     * @return array Structured readiness report
     */
    public function calculate(int $userId, ?int $outletId = null, ?string $referenceDate = null): array
    {
        // 1. Tenant Authentication Gate
        if ($userId <= 0) {
            return [
                'status' => 'ERROR',
                'code' => 'UNAUTHENTICATED',
                'message' => 'Konteks autentikasi tenant tidak valid.',
            ];
        }

        // 2. Outlet Ownership Gate
        if ($outletId !== null) {
            $outletExists = Outlet::where('user_id', $userId)->where('id', $outletId)->exists();
            if (!$outletExists) {
                return [
                    'status' => 'ERROR',
                    'code' => 'UNAUTHORIZED_OUTLET',
                    'message' => 'Outlet tidak ditemukan atau bukan milik akun ini.',
                ];
            }
        }

        $tz = 'Asia/Jakarta';
        $today = $referenceDate
            ? Carbon::createFromFormat('Y-m-d', $referenceDate, $tz)->startOfDay()
            : Carbon::today($tz);

        // 30-day evaluation window
        $startDate = $today->copy()->subDays(29)->toDateString();
        $endDate = $today->toDateString();

        $allLimitations = [];

        // Pillar 1: Revenue Stability (25%)
        $revenueStability = $this->evaluateRevenueStability($userId, $outletId, $startDate, $endDate, $tz);
        foreach ($revenueStability['limitations'] as $lim) {
            $allLimitations[] = "[Revenue Stability] $lim";
        }

        // Pillar 2: Margin Health (20%)
        $marginHealth = $this->evaluateMarginHealth($userId, $outletId, $startDate, $endDate, $tz);
        foreach ($marginHealth['limitations'] as $lim) {
            $allLimitations[] = "[Margin Health] $lim";
        }

        // Pillar 3: Cash Discipline (25%)
        $cashDiscipline = $this->evaluateCashDiscipline($userId, $outletId, $startDate, $endDate, $tz);
        foreach ($cashDiscipline['limitations'] as $lim) {
            $allLimitations[] = "[Cash Discipline] $lim";
        }

        // Pillar 4: Operational Integrity (15%)
        $operationalIntegrity = $this->evaluateOperationalIntegrity($userId, $outletId, $startDate, $endDate, $tz);
        foreach ($operationalIntegrity['limitations'] as $lim) {
            $allLimitations[] = "[Operational Integrity] $lim";
        }

        // Pillar 5: Data Completeness (15%)
        $dataCompleteness = $this->evaluateDataCompleteness($userId, $outletId, $startDate, $endDate, $tz);
        foreach ($dataCompleteness['limitations'] as $lim) {
            $allLimitations[] = "[Data Completeness] $lim";
        }

        // Aggregate Weighted Score
        $totalScoreFloat = (
            $revenueStability['weighted_contribution'] +
            $marginHealth['weighted_contribution'] +
            $cashDiscipline['weighted_contribution'] +
            $operationalIntegrity['weighted_contribution'] +
            $dataCompleteness['weighted_contribution']
        );

        $finalScore = (int) round(max(0.0, min(100.0, $totalScoreFloat)));

        // Readiness Band Classification
        if ($finalScore >= 80) {
            $band = self::BAND_HIGH;
        } elseif ($finalScore >= 60) {
            $band = self::BAND_MODERATE;
        } elseif ($finalScore >= 40) {
            $band = self::BAND_EARLY;
        } else {
            $band = self::BAND_NEEDS_IMPROVEMENT;
        }

        $pillarsMap = [
            'revenue_stability' => $revenueStability,
            'margin_health' => $marginHealth,
            'cash_discipline' => $cashDiscipline,
            'operational_integrity' => $operationalIntegrity,
            'data_completeness' => $dataCompleteness,
        ];

        $evidenceSummary = $this->buildEvidenceSummary($pillarsMap, $startDate, $endDate, $userId, $outletId);

        return [
            'status' => 'SUCCESS',
            'score' => $finalScore,
            'band' => $band,
            'evaluation_period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => 30,
            ],
            'pillars' => $pillarsMap,
            'evidence_summary' => $evidenceSummary,
            'disclaimer' => 'Financing Readiness Index mengukur kelengkapan data operasional dan kedisiplinan pembukuan UMKM, bukan persetujuan kredit (credit score) dan tidak menjamin penerimaan pinjaman oleh lembaga keuangan.',
            'limitations' => $allLimitations,
        ];
    }

    /**
     * Pillar 1: Revenue Stability (Weight: 25%)
     */
    public function evaluateRevenueStability(
        int $userId,
        ?int $outletId,
        string $startDate,
        string $endDate,
        string $tz
    ): array {
        [$rangeStart, $rangeEnd] = TimezoneContract::businessDateRangeToUtcRange($startDate, $endDate, $tz);

        // Fetch non-cancelled transactions
        $transactions = DB::table('transactions')
            ->where('user_id', $userId)
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->where('created_at_device', '>=', $rangeStart)
            ->where('created_at_device', '<', $rangeEnd)
            ->whereNotIn('status', FinancialCalculationService::CANCEL_STATUSES)
            ->select('created_at_device', DB::raw(Transaction::getActualPaidSql() . ' as actual_paid'))
            ->get();

        $limitations = [];
        $totalRevenue = 0.0;
        $dailySums = [];

        foreach ($transactions as $tx) {
            $paid = (float) $tx->actual_paid;
            $totalRevenue += $paid;
            $bDate = Carbon::parse($tx->created_at_device, 'UTC')->timezone($tz)->toDateString();
            $dailySums[$bDate] = ($dailySums[$bDate] ?? 0.0) + $paid;
        }

        $activeDays = count($dailySums);

        if ($activeDays === 0) {
            $limitations[] = 'Tidak ada transaksi penjualan non-batal dalam 30 hari terakhir.';
            return [
                'score' => 20,
                'weight' => self::WEIGHT_REVENUE_STABILITY,
                'weighted_contribution' => round(20 * self::WEIGHT_REVENUE_STABILITY, 2),
                'evidence' => [
                    'active_days' => 0,
                    'total_revenue' => 0.0,
                    'avg_daily_revenue' => 0.0,
                    'cv_volatility' => 0.0,
                ],
                'limitations' => $limitations,
            ];
        }

        if ($activeDays < 7) {
            $limitations[] = "Riwayat penjualan aktif hanya $activeDays hari (kurang dari batas minimum 7 hari untuk estimasi stabilitas andal).";
        }

        $avgDaily = $totalRevenue / $activeDays;

        // Volatility: Coefficient of Variation (CV = stdDev / mean)
        $sumSquareDiff = 0.0;
        foreach ($dailySums as $dRev) {
            $sumSquareDiff += pow($dRev - $avgDaily, 2);
        }
        $variance = $sumSquareDiff / $activeDays;
        $stdDev = sqrt($variance);
        $cv = $avgDaily > 0 ? round($stdDev / $avgDaily, 4) : 1.0;

        // Active days score (out of 100)
        if ($activeDays >= 24) {
            $activeScore = 100;
        } elseif ($activeDays >= 18) {
            $activeScore = 85;
        } elseif ($activeDays >= 12) {
            $activeScore = 70;
        } elseif ($activeDays >= 6) {
            $activeScore = 50;
        } else {
            $activeScore = max(10, $activeDays * 8);
        }

        // Consistency score (out of 100) based on CV
        if ($activeDays < 3) {
            $consistencyScore = 30;
        } elseif ($cv <= 0.35) {
            $consistencyScore = 100;
        } elseif ($cv <= 0.65) {
            $consistencyScore = 85;
        } elseif ($cv <= 1.00) {
            $consistencyScore = 65;
        } else {
            $consistencyScore = (int) max(20, round(100 - ($cv * 40)));
        }

        $rawScore = (int) round((0.40 * $activeScore) + (0.60 * $consistencyScore));
        $score = (int) max(10, min(100, $rawScore));

        return [
            'score' => $score,
            'weight' => self::WEIGHT_REVENUE_STABILITY,
            'weighted_contribution' => round($score * self::WEIGHT_REVENUE_STABILITY, 2),
            'evidence' => [
                'active_days' => $activeDays,
                'total_revenue' => round($totalRevenue, 2),
                'avg_daily_revenue' => round($avgDaily, 2),
                'cv_volatility' => $cv,
            ],
            'limitations' => $limitations,
        ];
    }

    /**
     * Pillar 2: Margin Health (Weight: 20%)
     */
    public function evaluateMarginHealth(
        int $userId,
        ?int $outletId,
        string $startDate,
        string $endDate,
        string $tz
    ): array {
        $revData = $this->calcService->calculateRevenue($userId, $outletId, $startDate, $endDate, $tz);
        $cogsData = $this->calcService->calculateCogs($userId, $outletId, $startDate, $endDate, $tz);
        $expData = $this->calcService->calculateExpenses($userId, $outletId, $startDate, $endDate, $tz);

        $revenue = (float) ($revData['value'] ?? 0.0);
        $cogs = (float) ($cogsData['value'] ?? 0.0);
        $expenses = (float) ($expData['value'] ?? 0.0);

        $limitations = [];

        if ($revenue <= 0) {
            $limitations[] = 'Tidak ada pendapatan pada periode ini untuk menghitung margin laba.';
            return [
                'score' => 20,
                'weight' => self::WEIGHT_MARGIN_HEALTH,
                'weighted_contribution' => round(20 * self::WEIGHT_MARGIN_HEALTH, 2),
                'evidence' => [
                    'revenue' => 0.0,
                    'cogs' => $cogs,
                    'gross_profit' => 0.0,
                    'gross_margin_percent' => 0.0,
                    'net_margin_percent' => 0.0,
                    'untracked_cogs_percentage' => (float) ($cogsData['untracked_items_percentage'] ?? 0.0),
                ],
                'limitations' => $limitations,
            ];
        }

        $grossProfit = $revenue - $cogs;
        $netProfit = $grossProfit - $expenses;
        $grossMarginPct = round(($grossProfit / $revenue) * 100, 2);
        $netMarginPct = round(($netProfit / $revenue) * 100, 2);

        $untrackedPct = (float) ($cogsData['untracked_items_percentage'] ?? 0.0);
        if ($untrackedPct > 20.0) {
            $limitations[] = "Sebanyak {$untrackedPct}% menu terjual belum memiliki data HPP/modal, sehingga margin adalah estimasi parsial.";
        }

        // Base score from gross margin
        if ($grossMarginPct >= 60.0) {
            $baseScore = 100;
        } elseif ($grossMarginPct >= 50.0) {
            $baseScore = 90;
        } elseif ($grossMarginPct >= 40.0) {
            $baseScore = 75;
        } elseif ($grossMarginPct >= 25.0) {
            $baseScore = 55;
        } elseif ($grossMarginPct >= 10.0) {
            $baseScore = 35;
        } elseif ($grossMarginPct >= 0.0) {
            $baseScore = 20;
        } else {
            $baseScore = 10;
        }

        // Adjustment for net margin
        $adjustment = 0;
        if ($netMarginPct >= 15.0) {
            $adjustment += 5;
        } elseif ($netMarginPct < 0.0) {
            $adjustment -= 15;
        }

        // Penalty for untracked COGS
        if ($untrackedPct > 50.0) {
            $adjustment -= 15;
        } elseif ($untrackedPct > 20.0) {
            $adjustment -= 5;
        }

        $finalScore = (int) max(10, min(100, $baseScore + $adjustment));

        return [
            'score' => $finalScore,
            'weight' => self::WEIGHT_MARGIN_HEALTH,
            'weighted_contribution' => round($finalScore * self::WEIGHT_MARGIN_HEALTH, 2),
            'evidence' => [
                'revenue' => round($revenue, 2),
                'cogs' => round($cogs, 2),
                'gross_profit' => round($grossProfit, 2),
                'gross_margin_percent' => $grossMarginPct,
                'net_margin_percent' => $netMarginPct,
                'untracked_cogs_percentage' => $untrackedPct,
            ],
            'limitations' => $limitations,
        ];
    }

    /**
     * Pillar 3: Cash Discipline (Weight: 25%)
     */
    public function evaluateCashDiscipline(
        int $userId,
        ?int $outletId,
        string $startDate,
        string $endDate,
        string $tz
    ): array {
        $shifts = DB::table('shifts')
            ->where('user_id', $userId)
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->where('created_at', '>=', $startDate . ' 00:00:00')
            ->where(function ($q) {
                $q->where(DB::raw('LOWER(status)'), 'closed')
                  ->orWhereNotNull('end_time');
            })
            ->get();

        $totalShifts = $shifts->count();
        $limitations = [];

        if ($totalShifts === 0) {
            $limitations[] = 'Belum ada penutupan shift kasir pada 30 hari terakhir untuk evaluasi rekonsiliasi laci kasir.';
            return [
                'score' => 50,
                'weight' => self::WEIGHT_CASH_DISCIPLINE,
                'weighted_contribution' => round(50 * self::WEIGHT_CASH_DISCIPLINE, 2),
                'evidence' => [
                    'total_closed_shifts' => 0,
                    'balanced_shifts' => 0,
                    'discrepancy_shifts' => 0,
                    'total_variance_nominal' => 0.0,
                    'reconciliation_rate_percent' => 0.0,
                ],
                'limitations' => $limitations,
            ];
        }

        $balancedShifts = 0;
        $discrepantShifts = 0;
        $totalVariance = 0.0;

        foreach ($shifts as $shift) {
            $expected = (float) ($shift->expected_cash ?? 0.0);
            $actual = (float) ($shift->end_cash ?? 0.0);
            $diff = abs($actual - $expected);
            $totalVariance += $diff;

            // Tolerance of Rp 5.000 for coin discrepancies
            if ($diff <= 5000.0) {
                $balancedShifts++;
            }
            if ($diff > 10000.0) {
                $discrepantShifts++;
            }
        }

        $balancedRatio = $balancedShifts / $totalShifts;
        $rawScore = (int) round($balancedRatio * 100);

        // Penalties for large discrepancies
        if ($discrepantShifts / $totalShifts > 0.30) {
            $rawScore = min($rawScore, 60);
        }
        if ($totalVariance > 500000.0) {
            $rawScore -= 10;
        }

        $score = (int) max(10, min(100, $rawScore));

        if ($discrepantShifts > 0) {
            $limitations[] = "Terdapat $discrepantShifts dari $totalShifts shift dengan selisih kas fisik di atas Rp 10.000.";
        }

        return [
            'score' => $score,
            'weight' => self::WEIGHT_CASH_DISCIPLINE,
            'weighted_contribution' => round($score * self::WEIGHT_CASH_DISCIPLINE, 2),
            'evidence' => [
                'total_closed_shifts' => $totalShifts,
                'balanced_shifts' => $balancedShifts,
                'discrepant_shifts' => $discrepantShifts,
                'total_variance_nominal' => round($totalVariance, 2),
                'reconciliation_rate_percent' => round($balancedRatio * 100, 2),
            ],
            'limitations' => $limitations,
        ];
    }

    /**
     * Pillar 4: Operational Integrity (Weight: 15%)
     */
    public function evaluateOperationalIntegrity(
        int $userId,
        ?int $outletId,
        string $startDate,
        string $endDate,
        string $tz
    ): array {
        [$rangeStart, $rangeEnd] = TimezoneContract::businessDateRangeToUtcRange($startDate, $endDate, $tz);

        $query = DB::table('transactions')
            ->where('user_id', $userId)
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->where('created_at_device', '>=', $rangeStart)
            ->where('created_at_device', '<', $rangeEnd);

        $totalTransactions = (clone $query)->count();
        $limitations = [];

        if ($totalTransactions === 0) {
            $limitations[] = 'Belum ada transaksi tercatat dalam 30 hari terakhir.';
            return [
                'score' => 50,
                'weight' => self::WEIGHT_OPERATIONAL_INTEGRITY,
                'weighted_contribution' => round(50 * self::WEIGHT_OPERATIONAL_INTEGRITY, 2),
                'evidence' => [
                    'total_transactions' => 0,
                    'void_refund_count' => 0,
                    'void_refund_rate_percent' => 0.0,
                ],
                'limitations' => $limitations,
            ];
        }

        $voidRefundCount = (clone $query)
            ->whereIn('status', FinancialCalculationService::CANCEL_STATUSES)
            ->count();

        $voidRate = round(($voidRefundCount / $totalTransactions) * 100, 2);

        if ($voidRate <= 1.0) {
            $score = 100;
        } elseif ($voidRate <= 2.0) {
            $score = 90;
        } elseif ($voidRate <= 3.5) {
            $score = 80;
        } elseif ($voidRate <= 5.0) {
            $score = 65;
        } elseif ($voidRate <= 10.0) {
            $score = 40;
        } else {
            $score = 20;
        }

        if ($voidRate > 3.0) {
            $limitations[] = "Tingkat pembatalan/void/refund mencapai {$voidRate}% (di atas ambang wajar operasional 2%).";
        }

        return [
            'score' => $score,
            'weight' => self::WEIGHT_OPERATIONAL_INTEGRITY,
            'weighted_contribution' => round($score * self::WEIGHT_OPERATIONAL_INTEGRITY, 2),
            'evidence' => [
                'total_transactions' => $totalTransactions,
                'void_refund_count' => $voidRefundCount,
                'void_refund_rate_percent' => $voidRate,
            ],
            'limitations' => $limitations,
        ];
    }

    /**
     * Pillar 5: Data Completeness (Weight: 15%)
     */
    public function evaluateDataCompleteness(
        int $userId,
        ?int $outletId,
        string $startDate = '',
        string $endDate = '',
        string $tz = 'Asia/Jakarta'
    ): array {
        $menus = Menu::where('user_id', $userId)
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->get();

        $totalMenus = $menus->count();
        $limitations = [];

        $menusWithCost = 0;
        $menusWithCategory = 0;

        foreach ($menus as $m) {
            if ($m->cost_price !== null && (float) $m->cost_price > 0) {
                $menusWithCost++;
            }
            if (!empty($m->category)) {
                $menusWithCategory++;
            }
        }

        $costCompleteness = $totalMenus > 0 ? round(($menusWithCost / $totalMenus) * 100, 2) : 0.0;
        $categoryCompleteness = $totalMenus > 0 ? round(($menusWithCategory / $totalMenus) * 100, 2) : 0.0;

        // Payment method diversity check (past 30 days)
        $paymentMethodsCount = 0;
        if (!empty($startDate) && !empty($endDate)) {
            [$rangeStart, $rangeEnd] = TimezoneContract::businessDateRangeToUtcRange($startDate, $endDate, $tz);
            $paymentMethodsCount = DB::table('transactions')
                ->where('user_id', $userId)
                ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
                ->where('created_at_device', '>=', $rangeStart)
                ->where('created_at_device', '<', $rangeEnd)
                ->whereNotIn('status', FinancialCalculationService::CANCEL_STATUSES)
                ->whereNotNull('payment_method')
                ->distinct()
                ->count('payment_method');
        }

        $payDiversityScore = $paymentMethodsCount >= 2 ? 100 : ($paymentMethodsCount === 1 ? 60 : 30);
        $costScore = $totalMenus > 0 ? (int) round($costCompleteness) : 30;
        $catScore = $totalMenus > 0 ? (int) round($categoryCompleteness) : 30;

        $rawScore = (int) round((0.50 * $costScore) + (0.25 * $catScore) + (0.25 * $payDiversityScore));
        $score = (int) max(10, min(100, $rawScore));

        if ($totalMenus === 0) {
            $limitations[] = 'Katalog menu belum didaftarkan di sistem POS.';
        } elseif ($costCompleteness < 50.0) {
            $limitations[] = "Hanya {$costCompleteness}% menu yang memiliki data modal/HPP.";
        }

        return [
            'score' => $score,
            'weight' => self::WEIGHT_DATA_COMPLETENESS,
            'weighted_contribution' => round($score * self::WEIGHT_DATA_COMPLETENESS, 2),
            'evidence' => [
                'total_menus' => $totalMenus,
                'menus_with_cost' => $menusWithCost,
                'cost_completeness_percent' => $costCompleteness,
                'category_completeness_percent' => $categoryCompleteness,
                'payment_methods_distinct' => $paymentMethodsCount,
            ],
            'limitations' => $limitations,
        ];
    }

    /**
     * Membangun ringkasan bukti finansial terstruktur (Financial Evidence Coverage).
     * Berorientasi pada ketersediaan data, transparansi sumber, periode, dan explainability.
     * FinTechathon 2026 SSOT.
     *
     * @param  array  $pillars
     * @param  string $startDate
     * @param  string $endDate
     * @param  int    $userId
     * @param  int|null $outletId
     * @return array
     */
    public function buildEvidenceSummary(
        array $pillars,
        string $startDate,
        string $endDate,
        int $userId,
        ?int $outletId
    ): array {
        $p1 = $pillars['revenue_stability']['evidence'] ?? [];
        $p3 = $pillars['cash_discipline']['evidence'] ?? [];
        $p4 = $pillars['operational_integrity']['evidence'] ?? [];
        $p5 = $pillars['data_completeness']['evidence'] ?? [];

        $totalTransactions = (int) ($p4['total_transactions'] ?? 0);
        $totalRevenue = (float) ($p1['total_revenue'] ?? 0.0);
        $activeDays = (int) ($p1['active_days'] ?? 0);
        $totalClosedShifts = (int) ($p3['total_closed_shifts'] ?? 0);
        $balancedShifts = (int) ($p3['balanced_shifts'] ?? 0);
        $discrepantShifts = (int) ($p3['discrepant_shifts'] ?? 0);
        $reconciliationRate = (float) ($p3['reconciliation_rate_percent'] ?? 0.0);
        $totalMenus = (int) ($p5['total_menus'] ?? 0);
        $menusWithCost = (int) ($p5['menus_with_cost'] ?? 0);
        $costCompleteness = (float) ($p5['cost_completeness_percent'] ?? 0.0);
        $paymentMethodsCount = (int) ($p5['payment_methods_distinct'] ?? 0);
        $voidRate = (float) ($p4['void_refund_rate_percent'] ?? 0.0);

        // Query expense records count & supplier count safely
        $expenseQuery = DB::table('expenses')
            ->where('user_id', $userId)
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->whereBetween('date', [$startDate, $endDate]);

        $expenseRecordsCount = (int) (clone $expenseQuery)->count();
        $expenseTotalAmount = (float) (clone $expenseQuery)->sum('amount');
        $supplierRecordsCount = (int) (clone $expenseQuery)
            ->whereNotNull('supplier')
            ->where('supplier', '!=', '')
            ->count();

        $periodLabel = "{$startDate} s/d {$endDate} (30 Hari)";

        return [
            [
                'key'            => 'sales_history',
                'label'          => 'Riwayat Penjualan (Sales History)',
                'status'         => $totalTransactions > 0 ? 'AVAILABLE' : 'INSUFFICIENT',
                'source'         => 'Tabel transaksi kasir POS (transactions)',
                'period'         => $periodLabel,
                'record_count'   => $totalTransactions,
                'coverage'       => "{$activeDays} dari 30 hari aktif berdagang",
                'summary'        => "Total omzet Rp " . number_format($totalRevenue, 0, ',', '.') . " dari {$totalTransactions} transaksi tersimpan.",
                'explainability' => 'Dicatat secara otomatis oleh sistem POS saat kasir menyelesaikan pembayaran pelanggan, mengecualikan pesanan dibatalkan/void.',
            ],
            [
                'key'            => 'transaction_integrity',
                'label'          => 'Integritas Transaksi & Pembayaran',
                'status'         => $totalTransactions > 0 ? 'AVAILABLE' : 'INSUFFICIENT',
                'source'         => 'Tabel transaksi kasir POS (transactions)',
                'period'         => $periodLabel,
                'record_count'   => $totalTransactions,
                'coverage'       => "Tingkat void {$voidRate}%, {$paymentMethodsCount} kanal pembayaran",
                'summary'        => "Tercatat {$paymentMethodsCount} kanal pembayaran aktif dengan rasio pembatalan/void sebesar {$voidRate}%.",
                'explainability' => 'Mengukur kepatuhan kasir dan keterlacakan kanal pembayaran digital (QRIS, Transfer, EDC) versus uang tunai.',
            ],
            [
                'key'            => 'cash_reconciliation',
                'label'          => 'Rekonsiliasi Kas (Cash Reconciliation)',
                'status'         => $totalClosedShifts > 0 ? 'AVAILABLE' : 'INSUFFICIENT',
                'source'         => 'Tabel sesi kerja & tutup shift (shifts)',
                'period'         => $periodLabel,
                'record_count'   => $totalClosedShifts,
                'coverage'       => "{$balancedShifts} dari {$totalClosedShifts} shift seimbang ({$reconciliationRate}%)",
                'summary'        => "{$totalClosedShifts} shift kasir tercatat tutup buku; {$balancedShifts} klop, {$discrepantShifts} tercatat selisih fisik.",
                'explainability' => 'Dihasilkan dari rekonsiliasi uang fisik di laci kasir saat pergantian shift dan penutupan hari kerja.',
            ],
            [
                'key'            => 'expense_records',
                'label'          => 'Pencatatan Biaya (Expense Records)',
                'status'         => $expenseRecordsCount > 0 ? 'AVAILABLE' : 'INSUFFICIENT',
                'source'         => 'Tabel pengeluaran operasional (expenses)',
                'period'         => $periodLabel,
                'record_count'   => $expenseRecordsCount,
                'coverage'       => "{$expenseRecordsCount} transaksi tercatat",
                'summary'        => "Total pengeluaran tercatat Rp " . number_format($expenseTotalAmount, 0, ',', '.') . " dari {$expenseRecordsCount} transaksi beban.",
                'explainability' => 'Pengeluaran operasional harian dan pembelian barang yang diinput ke modul pembukuan kas kecil.',
            ],
            [
                'key'            => 'inventory_cost_records',
                'label'          => 'Kelengkapan HPP (Inventory & Cost Records)',
                'status'         => $totalMenus > 0 && $costCompleteness >= 50.0 ? 'AVAILABLE' : 'PARTIAL',
                'source'         => 'Katalog menu dan harga pokok (menus)',
                'period'         => 'Katalog aktif saat ini',
                'record_count'   => $totalMenus,
                'coverage'       => "{$menusWithCost} dari {$totalMenus} menu ber-HPP ({$costCompleteness}%)",
                'summary'        => "{$menusWithCost} menu telah ditentukan biaya modalnya dari total {$totalMenus} menu aktif.",
                'explainability' => 'Dasar penghitungan marjin laba kotor bisnis untuk mengetahui struktur profitabilitas produk.',
            ],
            [
                'key'            => 'supplier_history',
                'label'          => 'Riwayat Supplier & Pembelian',
                'status'         => $supplierRecordsCount > 0 ? 'PARTIAL' : 'NOT_AVAILABLE',
                'source'         => 'Pencatatan nama supplier pada beban (expenses.supplier)',
                'period'         => $periodLabel,
                'record_count'   => $supplierRecordsCount,
                'coverage'       => $supplierRecordsCount > 0 ? "{$supplierRecordsCount} biaya mencatat supplier" : 'Belum tercatat',
                'summary'        => $supplierRecordsCount > 0
                    ? "Tercatat {$supplierRecordsCount} transaksi pembelian operasional yang mencantumkan identitas supplier."
                    : "Belum ada pencatatan identitas supplier pada pengeluaran operasional.",
                'explainability' => 'Data berasal dari catatan pengeluaran kas. Belum terhubung dengan modul Purchase Order (PO) atau faktur utang usaha formal.',
            ],
            [
                'key'            => 'financial_consistency',
                'label'          => 'Konsistensi Pembukuan Finansial',
                'status'         => $activeDays >= 15 && $totalClosedShifts >= 10 ? 'AVAILABLE' : 'PARTIAL',
                'source'         => 'Agregasi lintas entitas (transactions, shifts, expenses)',
                'period'         => $periodLabel,
                'record_count'   => null,
                'coverage'       => "Aktivitas 30 hari trailing",
                'summary'        => "Keteraturan pencatatan dinilai dari frekuensi transaksi aktif dan kedisiplinan tutup laci kas.",
                'explainability' => 'Mengonfirmasi bahwa data keuangan terbentuk secara organik dari operasional harian yang berkelanjutan.',
            ],
        ];
    }
}
