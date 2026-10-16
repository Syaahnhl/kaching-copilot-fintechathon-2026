<?php

declare(strict_types=1);

namespace App\Services\Financial;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\Menu;
use App\Helpers\TimezoneContract;

/**
 * Class FinancialPerformanceIntelligenceService
 *
 * Deterministic Financial Performance Intelligence layer combining:
 * 1. MenuSalesVelocityService (Phase 3.9F)
 * 2. SalesAnomalyService (Phase 3.9G)
 * 3. ContributionMarginService (Phase 3.9H)
 *
 * GOVERNING PRINCIPLES:
 * 1. ESTIMATED MARGIN, NOT ACTUAL PROFIT:
 *    All margin metrics are labeled as "estimated contribution margin" or "estimated margin"
 *    because cost_price is resolved from item transaction snapshots or current menu master
 *    snapshots, not live dynamic batch FIFO/LIFO purchase ledgers.
 * 2. NO BLACK-BOX AI SCORING:
 *    States are produced via deterministic rules and explainable conditions.
 * 3. DATA SUFFICIENCY FIREWALL:
 *    If margin data is missing (<50% coverage), the engine marks INSUFFICIENT_MARGIN_EVIDENCE
 *    rather than guessing.
 * 4. OWNER-SAFE LANGUAGE:
 *    Explanations avoid causal speculation (e.g. attributing margin drops to raw material price hikes).
 * 5. NO_SALES IS NOT DEAD STOCK:
 *    Menus with 0 sales in the window are marked DORMANT_CATALOG_ITEM (not physical dead stock).
 * 6. ZERO MUTATION / READ-ONLY:
 *    Does not perform INSERT, UPDATE, DELETE, DB transactions, or external calls.
 */
class FinancialPerformanceIntelligenceService
{
    // Performance State Constants
    public const STATE_STRONG_PERFORMER           = 'STRONG_PERFORMER';
    public const STATE_HIGH_SALES_LOW_MARGIN       = 'HIGH_SALES_LOW_MARGIN';
    public const STATE_HIGH_MARGIN_LOW_VELOCITY   = 'HIGH_MARGIN_LOW_VELOCITY';
    public const STATE_LOW_MARGIN_LOW_VELOCITY    = 'LOW_MARGIN_LOW_VELOCITY';
    public const STATE_STABLE_CORE_PERFORMER      = 'STABLE_CORE_PERFORMER';
    public const STATE_PERFORMANCE_DETERIORATION  = 'PERFORMANCE_DETERIORATION';
    public const STATE_NEGATIVE_MARGIN_RISK       = 'NEGATIVE_MARGIN_RISK';
    public const STATE_ANOMALY_FLAGGED            = 'ANOMALY_FLAGGED';
    public const STATE_MIXED_SIGNAL               = 'MIXED_SIGNAL';
    public const STATE_DORMANT_CATALOG_ITEM       = 'DORMANT_CATALOG_ITEM';
    public const STATE_INSUFFICIENT_MARGIN_DATA   = 'INSUFFICIENT_MARGIN_EVIDENCE';
    public const STATE_INSUFFICIENT_EVIDENCE      = 'INSUFFICIENT_EVIDENCE';

    // Threshold Constants (INTERNAL KACHING RULE)
    public const THRESHOLD_MIN_COST_COVERAGE_PCT   = 50.0;
    public const THRESHOLD_HEALTHY_MARGIN_PCT      = 50.0;
    public const THRESHOLD_THIN_MARGIN_PCT         = 30.0;
    public const THRESHOLD_ANOMALY_FLAG_COUNT      = 1; // >=1 unusual quantity events to trigger item anomaly flag

    protected MenuSalesVelocityService $velocityService;
    protected ContributionMarginService $marginService;
    protected SalesAnomalyService $anomalyService;

    public function __construct(
        ?MenuSalesVelocityService $velocityService = null,
        ?ContributionMarginService $marginService = null,
        ?SalesAnomalyService $anomalyService = null
    ) {
        $this->velocityService = $velocityService ?? new MenuSalesVelocityService();
        $this->marginService = $marginService ?? new ContributionMarginService();
        $this->anomalyService = $anomalyService ?? new SalesAnomalyService();
    }

    /**
     * Primary entrypoint: Analyze multi-dimensional financial performance.
     *
     * @param int $userId Tenant/User ID (Authority Boundary)
     * @param int|null $outletId Branch/Outlet ID (optional)
     * @param string|int|null $param3 Anchor Date (Y-m-d) or Lookback Days
     * @param string|int|null $param4 Lookback Days or Anchor Date
     * @param array $options Additional options
     * @return array Standardized, explainable financial performance payload
     */
    public function analyze(
        int $userId,
        ?int $outletId = null,
        string|int|null $param3 = null,
        string|int|null $param4 = null,
        array $options = []
    ): array {
        // Flexible parameter resolution
        if (is_int($param3) || (is_numeric($param3) && !str_contains((string)$param3, '-') && !str_contains((string)$param3, '/'))) {
            $lookbackDays = (int)$param3;
            $anchorDate = is_string($param4) ? $param4 : null;
        } else {
            $anchorDate = is_string($param3) ? $param3 : null;
            $lookbackDays = is_numeric($param4) ? (int)$param4 : 30;
        }

        $lookbackDays = max(7, $lookbackDays);
        $tz = $this->resolveOutletTimezone($userId, $outletId);

        // 1. Run Underlying Intelligence Services (Phase 3.9F, 3.9G, 3.9H)
        $velocityResult = $this->velocityService->analyzeVelocity($userId, $outletId, $anchorDate, $lookbackDays, $options);
        $marginResult = $this->marginService->analyzeMargin($userId, $outletId, $anchorDate, $lookbackDays, $options);
        $anomalyResult = $this->anomalyService->analyze($userId, $outletId, $anchorDate, $lookbackDays, $options);

        // 2. Index Anomaly Signals by Normalized Menu Name
        $anomaliesByMenu = $this->indexAnomaliesByMenu($anomalyResult);

        // 3. Process and Fuse Performance Records
        $performanceRecords = [];
        $stateDistribution = [
            self::STATE_STRONG_PERFORMER          => 0,
            self::STATE_HIGH_SALES_LOW_MARGIN      => 0,
            self::STATE_HIGH_MARGIN_LOW_VELOCITY  => 0,
            self::STATE_LOW_MARGIN_LOW_VELOCITY   => 0,
            self::STATE_STABLE_CORE_PERFORMER     => 0,
            self::STATE_PERFORMANCE_DETERIORATION => 0,
            self::STATE_NEGATIVE_MARGIN_RISK      => 0,
            self::STATE_ANOMALY_FLAGGED           => 0,
            self::STATE_MIXED_SIGNAL              => 0,
            self::STATE_DORMANT_CATALOG_ITEM      => 0,
            self::STATE_INSUFFICIENT_MARGIN_DATA  => 0,
            self::STATE_INSUFFICIENT_EVIDENCE     => 0,
        ];

        $catalogMenus = $velocityResult['menus'] ?? [];
        $marginMenus = $marginResult['menus'] ?? [];

        foreach ($catalogMenus as $menuId => $vMenu) {
            $menuName = (string)($vMenu['menu_name'] ?? 'Unknown Menu');
            $normName = strtolower(trim($menuName));
            $mMenu = $marginMenus[$menuId] ?? null;
            $menuAnomalies = $anomaliesByMenu[$normName] ?? [];

            $record = $this->evaluateMenuPerformance(
                (int)$menuId,
                $menuName,
                $vMenu,
                $mMenu,
                $menuAnomalies,
                $lookbackDays
            );

            $performanceRecords[$menuId] = $record;
            $state = $record['performance_state'];
            if (isset($stateDistribution[$state])) {
                $stateDistribution[$state]++;
            } else {
                $stateDistribution[$state] = 1;
            }
        }

        // 4. Summarize Performance Overview
        $summary = $this->buildSummary(
            $performanceRecords,
            $stateDistribution,
            $velocityResult,
            $marginResult,
            $anomalyResult
        );

        $unresolvedList = $marginResult['unresolved_items']['items'] ?? [];
        $unresolvedRevenue = 0.0;
        foreach ($unresolvedList as $unItem) {
            $unresolvedRevenue += (float)($unItem['gross_revenue'] ?? 0.0);
        }

        return [
            'metadata' => [
                'user_id' => $userId,
                'outlet_id' => $outletId,
                'anchor_date' => $anchorDate ?? Carbon::now($tz)->toDateString(),
                'lookback_days' => $lookbackDays,
                'timezone' => $tz,
                'generated_at' => Carbon::now($tz)->toDateTimeString(),
                'evaluation_scope' => $outletId ? "Outlet {$outletId}" : 'All Tenant Outlets',
            ],
            'summary' => $summary,
            'state_distribution' => $stateDistribution,
            'performance_states' => $this->groupRecordsByState($performanceRecords),
            'menus' => $performanceRecords,
            'unresolved_items' => [
                'count' => count($unresolvedList),
                'total_revenue' => round($unresolvedRevenue, 2),
                'items' => $unresolvedList,
            ],
            'anomaly_overview' => $anomalyResult['summary'] ?? [],
            'limitations' => [
                'All margin metrics are strictly estimated contribution margins based on point-of-sale snapshots or current master catalog cost_price.',
                'Dynamic historical FIFO/LIFO purchase price ledgers are unavailable in current POS transaction storage.',
                'NO_SALES indicates zero sales in observation window, and does not evaluate physical stock expiration or physical availability.',
                'Operational anomaly flags indicate data/input spikes and do not constitute proof of cashier fraud.',
            ],
        ];
    }

    /**
     * Alias for analyze()
     */
    public function analyzePerformance(
        int $userId,
        ?int $outletId = null,
        string|int|null $param3 = null,
        string|int|null $param4 = null,
        array $options = []
    ): array {
        return $this->analyze($userId, $outletId, $param3, $param4, $options);
    }

    /**
     * Deterministic Menu Evaluation and Classification.
     */
    protected function evaluateMenuPerformance(
        int $menuId,
        string $menuName,
        array $vMenu,
        ?array $mMenu,
        array $anomalies,
        int $lookbackDays
    ): array {
        $qtySold = (int)($vMenu['total_quantity_sold'] ?? 0);
        $velocityCat = (string)($vMenu['category'] ?? MenuSalesVelocityService::CAT_NO_SALES);
        $salesTrend = (string)($vMenu['trend_direction'] ?? MenuSalesVelocityService::TREND_INSUFFICIENT_DATA);

        // Margin metrics resolution
        $hasTransactions = ($mMenu !== null && $qtySold > 0);
        $costCoveragePct = $hasTransactions ? (float)($mMenu['cost_coverage_pct'] ?? 0.0) : 0.0;
        $costDataStatus = $hasTransactions ? (string)($mMenu['cost_data_status'] ?? ContributionMarginService::COST_STATUS_MISSING) : ($qtySold === 0 ? 'NO_SALES' : ContributionMarginService::COST_STATUS_MISSING);
        $marginStatus = $hasTransactions ? (string)($mMenu['health_category'] ?? ContributionMarginService::HEALTH_INSUFFICIENT_COST) : ($qtySold === 0 ? 'NO_TRANSACTIONS' : ContributionMarginService::HEALTH_INSUFFICIENT_COST);
        $estimatedMarginPct = $hasTransactions ? (isset($mMenu['estimated_margin_pct']) ? (float)$mMenu['estimated_margin_pct'] : null) : null;
        $estimatedMargin = $hasTransactions ? (float)($mMenu['estimated_contribution_margin'] ?? 0.0) : 0.0;
        $coveredRev = $hasTransactions ? (float)($mMenu['covered_revenue'] ?? 0.0) : 0.0;
        $grossRev = $hasTransactions ? (float)($mMenu['gross_revenue'] ?? 0.0) : (float)($vMenu['total_revenue'] ?? 0.0);
        $marginTrend = (string)($mMenu['margin_trend']['direction'] ?? ContributionMarginService::TREND_INSUFFICIENT_DATA);

        // Sufficiency flags
        $hasSufficientCostData = ($hasTransactions && $costCoveragePct >= self::THRESHOLD_MIN_COST_COVERAGE_PCT && $marginStatus !== ContributionMarginService::HEALTH_INSUFFICIENT_COST);
        $hasVelocityData = ($lookbackDays >= 7);

        // Anomaly Signals
        $anomalySignals = [];
        foreach ($anomalies as $a) {
            $anomalySignals[] = [
                'type' => $a['anomaly_type'] ?? 'ANOMALY',
                'qty' => $a['qty'] ?? null,
                'description' => $a['description'] ?? 'Anomalous event detected',
            ];
        }

        // Deterministic Performance State Resolution
        $evidence = [];
        $limitations = [];
        $state = self::STATE_INSUFFICIENT_EVIDENCE;

        if ($qtySold === 0 || $velocityCat === MenuSalesVelocityService::CAT_NO_SALES) {
            $state = self::STATE_DORMANT_CATALOG_ITEM;
            $evidence[] = "No sales recorded during the {$lookbackDays}-day observation window.";
            $limitations[] = "No sales activity exists in the observation window to calculate contribution margin. Physical inventory status is unobserved.";
        } elseif ($hasSufficientCostData && ($marginStatus === ContributionMarginService::HEALTH_NEGATIVE_MARGIN || ($estimatedMarginPct !== null && $estimatedMarginPct < 0.0))) {
            $state = self::STATE_NEGATIVE_MARGIN_RISK;
            $evidence[] = "Estimated contribution margin is negative ({$estimatedMarginPct}%) with unit cost exceeding selling price based on available cost data.";
            $limitations[] = "Cost calculation uses available master/transaction cost snapshot; does not reflect dynamic supplier invoice fluctuations.";
        } elseif (count($anomalySignals) >= self::THRESHOLD_ANOMALY_FLAG_COUNT) {
            $state = self::STATE_ANOMALY_FLAGGED;
            $evidence[] = "Flagged with " . count($anomalySignals) . " operational quantity anomalies (e.g. orders exceeding unusual quantity threshold).";
            $limitations[] = "Indicates operational or cashier input deviation; does not prove intentional fraud without audit logs.";
        } elseif (!$hasSufficientCostData) {
            $state = self::STATE_INSUFFICIENT_MARGIN_DATA;
            $evidence[] = "Recorded {$qtySold} units sold, but cost data coverage is insufficient ({$costCoveragePct}% valid cost).";
            $limitations[] = "Margin health cannot be determined because cost price is missing or zero for >= 50% of transactions. Configure menu HPP in master catalog to enable margin analytics.";
        } elseif (($velocityCat === MenuSalesVelocityService::CAT_DECLINING_VELOCITY || $salesTrend === MenuSalesVelocityService::TREND_DOWNWARD) &&
                  ($marginTrend === ContributionMarginService::TREND_COMPRESSING || $marginStatus === ContributionMarginService::HEALTH_LOW_MARGIN)) {
            $state = self::STATE_PERFORMANCE_DETERIORATION;
            $evidence[] = "Sales velocity is declining ({$salesTrend}) alongside margin compression ({$marginTrend}, estimated margin: {$estimatedMarginPct}%).";
            $limitations[] = "Decline observed in transaction records. Cause cannot be attributed to raw material cost spikes without procurement invoices.";
        } elseif ($salesTrend === MenuSalesVelocityService::TREND_UPWARD && $marginTrend === ContributionMarginService::TREND_COMPRESSING) {
            $state = self::STATE_MIXED_SIGNAL;
            $evidence[] = "Sales volume trend is upward but estimated margin is compressing ({$estimatedMarginPct}%).";
            $limitations[] = "Divergent sales and margin trends warrant deeper inspection of discount usage or portion sizing.";
        } elseif ($velocityCat === MenuSalesVelocityService::CAT_HIGH_VELOCITY && $marginStatus === ContributionMarginService::HEALTH_HEALTHY_MARGIN) {
            $state = self::STATE_STRONG_PERFORMER;
            $evidence[] = "High sales velocity (avg " . round((float)$vMenu['average_daily_qty'], 2) . " units/day, " . round((float)$vMenu['sales_frequency'] * 100, 1) . "% frequency) combined with healthy estimated margin ({$estimatedMarginPct}%).";
            $limitations[] = "Estimated margin snapshot; verify recipe consistency to maintain healthy contribution.";
        } elseif ($velocityCat === MenuSalesVelocityService::CAT_HIGH_VELOCITY && in_array($marginStatus, [ContributionMarginService::HEALTH_THIN_MARGIN, ContributionMarginService::HEALTH_LOW_MARGIN], true)) {
            $state = self::STATE_HIGH_SALES_LOW_MARGIN;
            $evidence[] = "High sales velocity (avg " . round((float)$vMenu['average_daily_qty'], 2) . " units/day) but thin/low estimated margin ({$estimatedMarginPct}%).";
            $limitations[] = "High turnover generates operational load with lower margin cushion. Consider reviewing portion cost or selling price.";
        } elseif ($velocityCat === MenuSalesVelocityService::CAT_LOW_VELOCITY && $marginStatus === ContributionMarginService::HEALTH_HEALTHY_MARGIN) {
            $state = self::STATE_HIGH_MARGIN_LOW_VELOCITY;
            $evidence[] = "Low sales velocity (avg " . round((float)$vMenu['average_daily_qty'], 2) . " units/day, sold on " . round((float)$vMenu['sales_frequency'] * 100, 1) . "% of days) despite high estimated margin ({$estimatedMarginPct}%).";
            $limitations[] = "High theoretical margin yield with low customer demand. Evaluate catalog placement or promotional bundling.";
        } elseif ($velocityCat === MenuSalesVelocityService::CAT_LOW_VELOCITY && in_array($marginStatus, [ContributionMarginService::HEALTH_THIN_MARGIN, ContributionMarginService::HEALTH_LOW_MARGIN], true)) {
            $state = self::STATE_LOW_MARGIN_LOW_VELOCITY;
            $evidence[] = "Low sales velocity (avg " . round((float)$vMenu['average_daily_qty'], 2) . " units/day) combined with thin/low estimated margin ({$estimatedMarginPct}%).";
            $limitations[] = "Low turnover and low margin cushion. Candidate for menu rationalization or recipe rework.";
        } elseif ($velocityCat === MenuSalesVelocityService::CAT_STABLE || $salesTrend === MenuSalesVelocityService::TREND_STABLE) {
            $state = self::STATE_STABLE_CORE_PERFORMER;
            $evidence[] = "Consistent daily sales velocity (avg " . round((float)$vMenu['average_daily_qty'], 2) . " units/day) with acceptable margin cushion ({$estimatedMarginPct}%).";
            $limitations[] = "Stable baseline performer based on current catalog cost snapshot.";
        } else {
            $state = self::STATE_MIXED_SIGNAL;
            $evidence[] = "Multiple non-aligned operational signals detected (velocity: {$velocityCat}, margin: {$marginStatus}, sales trend: {$salesTrend}).";
            $limitations[] = "Requires granular daily inspection due to mixed performance indicators.";
        }

        return [
            'menu_id' => $menuId,
            'menu_name' => $menuName,
            'performance_state' => $state,
            'velocity_status' => $velocityCat,
            'velocity_metrics' => [
                'total_quantity_sold' => $qtySold,
                'average_daily_qty' => round((float)($vMenu['average_daily_qty'] ?? 0.0), 4),
                'sales_frequency' => round((float)($vMenu['sales_frequency'] ?? 0.0), 4),
                'active_selling_days' => (int)($vMenu['active_selling_days'] ?? 0),
                'zero_sales_days' => (int)($vMenu['zero_sales_days'] ?? 0),
                'velocity_7d' => round((float)($vMenu['velocity_7d'] ?? 0.0), 4),
                'velocity_14d' => round((float)($vMenu['velocity_14d'] ?? 0.0), 4),
                'velocity_30d' => round((float)($vMenu['velocity_30d'] ?? 0.0), 4),
                'velocity_trend_ratio' => round((float)($vMenu['velocity_trend_ratio'] ?? 1.0), 4),
            ],
            'margin_status' => $marginStatus,
            'estimated_margin_pct' => $estimatedMarginPct,
            'estimated_contribution_margin' => $estimatedMargin,
            'covered_revenue' => $coveredRev,
            'covered_gross_revenue' => $coveredRev,
            'gross_revenue' => $grossRev,
            'revenue_cost_coverage_pct' => $costCoveragePct,
            'sales_trend' => [
                'direction' => $salesTrend,
                'delta' => round((float)($vMenu['velocity_trend_delta'] ?? 0.0), 4),
            ],
            'margin_trend' => [
                'direction' => $marginTrend,
                'recent_margin_pct' => $mMenu['margin_trend']['recent_margin_pct'] ?? null,
                'prior_margin_pct' => $mMenu['margin_trend']['prior_margin_pct'] ?? null,
            ],
            'anomaly_signals' => $anomalySignals,
            'data_quality' => [
                'cost_data_status' => $costDataStatus,
                'cost_coverage_pct' => $costCoveragePct,
                'velocity_data_sufficient' => $hasVelocityData,
                'margin_data_sufficient' => $hasSufficientCostData,
            ],
            'evidence' => $evidence,
            'limitations' => $limitations,
        ];
    }

    /**
     * Group evaluated menu records by performance state.
     */
    protected function groupRecordsByState(array $records): array
    {
        $grouped = [];
        foreach ($records as $menuId => $rec) {
            $state = $rec['performance_state'];
            $grouped[$state][] = [
                'menu_id' => $menuId,
                'menu_name' => $rec['menu_name'],
                'velocity_status' => $rec['velocity_status'],
                'margin_status' => $rec['margin_status'],
                'estimated_margin_pct' => $rec['estimated_margin_pct'],
                'estimated_contribution_margin' => $rec['estimated_contribution_margin'],
                'gross_revenue' => $rec['gross_revenue'],
                'cost_coverage_pct' => $rec['revenue_cost_coverage_pct'],
                'evidence_summary' => $rec['evidence'][0] ?? '',
            ];
        }
        return $grouped;
    }

    /**
     * Build high-level performance summary.
     */
    protected function buildSummary(
        array $records,
        array $stateDist,
        array $velocityRes,
        array $marginRes,
        array $anomalyRes
    ): array {
        $totalCatalog = count($records);
        $activeEvaluated = 0;
        $dormantCount = $stateDist[self::STATE_DORMANT_CATALOG_ITEM] ?? 0;
        $sufficientEvidenceCount = 0;
        $insufficientMarginCount = $stateDist[self::STATE_INSUFFICIENT_MARGIN_DATA] ?? 0;
        $anomalyFlaggedCount = $stateDist[self::STATE_ANOMALY_FLAGGED] ?? 0;

        foreach ($records as $r) {
            if ($r['velocity_metrics']['total_quantity_sold'] > 0) {
                $activeEvaluated++;
            }
            if ($r['data_quality']['velocity_data_sufficient'] && $r['data_quality']['margin_data_sufficient']) {
                $sufficientEvidenceCount++;
            }
        }

        $marginSummary = $marginRes['summary'] ?? [];

        $unresolvedList = $marginRes['unresolved_items']['items'] ?? [];
        $unresolvedRevenue = 0.0;
        foreach ($unresolvedList as $unItem) {
            $unresolvedRevenue += (float)($unItem['gross_revenue'] ?? 0.0);
        }

        return [
            'total_catalog_menus' => $totalCatalog,
            'active_selling_menus' => $activeEvaluated,
            'dormant_catalog_menus' => $dormantCount,
            'sufficient_evidence_menus' => $sufficientEvidenceCount,
            'insufficient_margin_menus' => $insufficientMarginCount,
            'anomaly_flagged_menus' => $anomalyFlaggedCount,
            'financial_overview' => [
                'total_gross_revenue' => (float)($marginSummary['total_gross_revenue'] ?? 0.0),
                'covered_gross_revenue' => (float)($marginSummary['covered_gross_revenue'] ?? 0.0),
                'estimated_cogs' => (float)($marginSummary['estimated_cogs'] ?? 0.0),
                'estimated_contribution_margin' => (float)($marginSummary['estimated_contribution_margin'] ?? 0.0),
                'estimated_margin_pct' => (float)($marginSummary['estimated_margin_pct'] ?? 0.0),
                'revenue_cost_coverage_pct' => (float)($marginSummary['revenue_cost_coverage_pct'] ?? 0.0),
                'items_cost_coverage_pct' => (float)($marginSummary['items_cost_coverage_pct'] ?? 0.0),
            ],
            'primary_coverage_limitation' => 'Cost price is unconfigured or zero for ' . round(100.0 - (float)($marginSummary['revenue_cost_coverage_pct'] ?? 0.0), 2) . '% of gross revenue.',
        ];
    }

    /**
     * Map unusual quantity anomalies from SalesAnomalyService to menu names.
     */
    protected function indexAnomaliesByMenu(array $anomalyResult): array
    {
        $indexed = [];
        $unusual = $anomalyResult['sales_anomalies']['unusual_quantities'] ?? [];
        foreach ($unusual as $item) {
            $name = strtolower(trim((string)($item['menu_name'] ?? '')));
            if ($name !== '') {
                $indexed[$name][] = $item;
            }
        }
        return $indexed;
    }

    /**
     * Resolve outlet timezone safely.
     */
    protected function resolveOutletTimezone(int $userId, ?int $outletId): string
    {
        if ($outletId !== null && class_exists(TimezoneContract::class)) {
            return TimezoneContract::resolveOutletTimezone($outletId);
        }
        return 'Asia/Jakarta';
    }
}
