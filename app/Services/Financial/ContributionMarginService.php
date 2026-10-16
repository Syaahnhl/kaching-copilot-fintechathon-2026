<?php

namespace App\Services\Financial;

use App\Helpers\TimezoneContract;
use App\Models\Menu;
use App\Models\Outlet;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ContributionMarginService
 *
 * Phase 3.9H: Contribution Margin & Margin Health Intelligence Engine.
 * 
 * Computes deterministic estimated gross revenue, estimated COGS, and contribution
 * margins using available transaction snapshot and menu master data under strict
 * Point-in-Time (PIT) and Tenant/Outlet isolation semantics.
 *
 * INVARIANTS & POLICIES:
 * 1. ESTIMATED, NOT ACTUAL PROFIT:
 *    Calculations are strictly labeled as "estimated gross margin" or "estimated
 *    contribution margin" because cost_price is resolved from item transaction
 *    snapshots or current menu master snapshots, not live dynamic batch FIFO/LIFO purchase ledgers.
 * 2. DETERMINISTIC CATEGORIZATION (INTERNAL KACHING RULE):
 *    - HEALTHY_MARGIN: Margin >= 50.0%
 *    - THIN_MARGIN: 30.0% <= Margin < 50.0%
 *    - LOW_MARGIN: 0.0% <= Margin < 30.0%
 *    - NEGATIVE_MARGIN: Margin < 0.0% (Cost > Selling Price)
 *    - INSUFFICIENT_COST_DATA: Missing/zero cost or coverage < 50.0%
 * 3. POINT-IN-TIME (PIT) AWARENESS:
 *    Transactions after cutoff are excluded. Transactions created before cutoff
 *    and cancelled after cutoff are treated as valid at the cutoff point.
 * 4. FAIL-CLOSED IDENTITY RESOLUTION:
 *    Unresolved items are quarantined in 'unresolved_items' without polluting valid menus.
 */
class ContributionMarginService
{
    // Margin Health Categories (INTERNAL KACHING RULES)
    public const HEALTH_HEALTHY_MARGIN     = 'HEALTHY_MARGIN';
    public const HEALTH_THIN_MARGIN        = 'THIN_MARGIN';
    public const HEALTH_LOW_MARGIN         = 'LOW_MARGIN';
    public const HEALTH_NEGATIVE_MARGIN    = 'NEGATIVE_MARGIN';
    public const HEALTH_INSUFFICIENT_COST  = 'INSUFFICIENT_COST_DATA';

    // Cost Quality Statuses
    public const COST_STATUS_VALID      = 'VALID_COST';
    public const COST_STATUS_ZERO       = 'ZERO_COST';
    public const COST_STATUS_MISSING    = 'MISSING_COST';
    public const COST_STATUS_ANOMALOUS  = 'ANOMALOUS_COST';
    public const COST_STATUS_INVALID    = 'INVALID_VALUE';
    public const COST_STATUS_MIXED      = 'MIXED_COST';

    // Margin Trend Directions
    public const TREND_EXPANDING          = 'EXPANDING';
    public const TREND_COMPRESSING        = 'COMPRESSING';
    public const TREND_STABLE             = 'STABLE';
    public const TREND_INSUFFICIENT_DATA  = 'INSUFFICIENT_DATA';

    // Thresholds (INTERNAL KACHING RULES)
    public const THRESHOLD_HEALTHY_MIN_PCT  = 50.0;
    public const THRESHOLD_THIN_MIN_PCT     = 30.0;
    public const THRESHOLD_LOW_MIN_PCT      = 0.0;
    public const THRESHOLD_MIN_COVERAGE_PCT = 50.0;

    // Standard Canonical Cancellation Statuses
    public const CANCEL_STATUSES = [
        'cancelled', 'canceled', 'batal', 'void', 'refund', 'refunded'
    ];

    /**
     * Preloaded menu master cache indexed by menu_id and lowercase name.
     */
    protected array $menuCache = [];
    protected array $nameToMenuId = [];

    /**
     * Preloaded outlet timezone cache.
     */
    protected array $outletTimezones = [];

    /**
     * Execute deterministic Contribution Margin & Margin Health Analysis.
     * Flexible signature accepts ($userId, $outletId, $asOfDate, $lookbackDays) OR ($userId, $outletId, $lookbackDays, $asOfDate)
     */
    public function analyze(
        int $userId,
        ?int $outletId = null,
        string|int|null $param3 = null,
        string|int|null $param4 = null,
        array $options = []
    ): array {
        if (is_int($param3) || (is_numeric($param3) && !str_contains((string)$param3, '-') && !str_contains((string)$param3, '/'))) {
            $lookbackDays = (int)$param3;
            $anchorDate = is_string($param4) ? $param4 : null;
        } else {
            $anchorDate = is_string($param3) ? $param3 : null;
            $lookbackDays = is_numeric($param4) ? (int)$param4 : 30;
        }

        return $this->executeAnalysis($userId, $outletId, $anchorDate, $lookbackDays, $options);
    }

    /**
     * Alias for analyze().
     */
    public function analyzeMargin(
        int $userId,
        ?int $outletId = null,
        string|int|null $param3 = null,
        string|int|null $param4 = null,
        array $options = []
    ): array {
        return $this->analyze($userId, $outletId, $param3, $param4, $options);
    }

    /**
     * Internal execution of margin analysis.
     */
    protected function executeAnalysis(
        int $userId,
        ?int $outletId = null,
        ?string $anchorDate = null,
        int $lookbackDays = 30,
        array $options = []
    ): array {
        $includeZeroSales = $options['include_zero_sales'] ?? false;

        // 1. Resolve Timezone & PIT Bounds
        $tz = $this->resolveOutletTimezone($userId, $outletId);
        $asOfCarbon = $anchorDate ? Carbon::parse($anchorDate, $tz) : Carbon::now($tz);
        $cutoffUtc = $asOfCarbon->copy()->setTimezone('UTC')->toDateTimeString();

        $windowStartLocal = $asOfCarbon->copy()->subDays($lookbackDays)->startOfDay();
        $windowStartUtc = $windowStartLocal->copy()->setTimezone('UTC')->toDateTimeString();

        // Sub-windows for 14-day recent vs prior trend comparison
        $recentStartLocal = $asOfCarbon->copy()->subDays(14)->startOfDay();
        $recentStartUtc = $recentStartLocal->copy()->setTimezone('UTC')->toDateTimeString();

        $priorStartLocal = $asOfCarbon->copy()->subDays(28)->startOfDay();
        $priorStartUtc = $priorStartLocal->copy()->setTimezone('UTC')->toDateTimeString();

        // 2. Preload Master Menu & Outlet Data
        $this->preloadMenuCache($userId, $outletId);

        // 3. Fetch Eligible Transaction Items under PIT & Isolation Semantics
        $rawItems = $this->fetchEligibleTransactionItems(
            $userId,
            $outletId,
            $windowStartUtc,
            $cutoffUtc
        );

        if (empty($rawItems)) {
            return $this->buildEmptyResponse($userId, $outletId, $tz, $asOfCarbon->toDateString(), $lookbackDays);
        }

        // 4. Process Item Aggregates & Margins
        $processed = $this->processTransactionItems($rawItems, $recentStartUtc, $priorStartUtc);

        // 5. Compile Final Intelligence Report
        return $this->compileReport(
            $userId,
            $outletId,
            $tz,
            $asOfCarbon->toDateString(),
            $lookbackDays,
            $cutoffUtc,
            $processed,
            $includeZeroSales
        );
    }

    /**
     * Fetch raw items joined with transactions respecting PIT & isolation.
     */
    protected function fetchEligibleTransactionItems(
        int $userId,
        ?int $outletId,
        string $windowStartUtc,
        string $cutoffUtc
    ): array {
        $query = DB::table('transaction_items as ti')
            ->join('transactions as t', 'ti.transaction_id', '=', 't.id')
            ->leftJoin('menus as m', 'ti.menu_id', '=', 'm.id')
            ->where('t.user_id', $userId)
            ->where('t.created_at', '>=', $windowStartUtc)
            ->where('t.created_at', '<=', $cutoffUtc);

        if ($outletId !== null) {
            $query->where('t.outlet_id', $outletId);
        }

        if (Schema::hasColumn('transactions', 'is_training')) {
            $query->where('t.is_training', 0);
        }

        $items = $query->select([
            'ti.id as item_id',
            'ti.transaction_id',
            'ti.menu_id',
            'ti.menu_name',
            'ti.qty',
            'ti.price as item_price',
            'ti.cost_price as ti_cost_price',
            't.status as transaction_status',
            't.outlet_id',
            't.user_id',
            't.created_at as tx_created_at',
            't.updated_at as tx_updated_at',
            'm.id as master_menu_id',
            'm.name as master_menu_name',
            'm.price as master_menu_price',
            'm.cost_price as menu_cost_price',
            'm.category as menu_category',
        ])->orderBy('t.created_at', 'asc')->get();

        // Apply PIT Cancellation Filtering in PHP
        $eligible = [];
        $cancelStatuses = array_map('strtolower', self::CANCEL_STATUSES);

        foreach ($items as $item) {
            $status = strtolower(trim((string)$item->transaction_status));
            $isCancelled = in_array($status, $cancelStatuses, true);

            if ($isCancelled) {
                $updatedAtUtc = (string)$item->tx_updated_at;
                // If cancelled AFTER cutoff, it was still valid at cutoff point (PIT invariant)
                if (!empty($updatedAtUtc) && $updatedAtUtc > $cutoffUtc) {
                    $eligible[] = $item;
                }
                // Otherwise if cancelled before/at cutoff, strictly exclude
            } else {
                $eligible[] = $item;
            }
        }

        return $eligible;
    }

    /**
     * Process raw items into menu aggregates, sub-windows, and unresolved buckets.
     */
    protected function processTransactionItems(
        array $items,
        string $recentStartUtc,
        string $priorStartUtc
    ): array {
        $menuAggs = [];
        $outletAggs = [];
        $unresolvedItems = [];
        $anomalousItems = [];
        $distinctTxIds = [];

        $totalItemsCount = 0;
        $validCostCount = 0;
        $zeroCostCount = 0;
        $missingCostCount = 0;
        $anomalousCostCount = 0;
        $invalidValueCount = 0;

        $totalGrossRevenue = 0.0;
        $coveredGrossRevenue = 0.0;
        $totalEstimatedCogs = 0.0;

        foreach ($items as $item) {
            $distinctTxIds[$item->transaction_id] = true;
            $totalItemsCount++;

            $qty = (float)$item->qty;
            if ($qty <= 0) {
                $invalidValueCount++;
                continue;
            }

            // 1. Resolve Selling Price
            $price = $item->item_price !== null ? (float)$item->item_price : ($item->master_menu_price !== null ? (float)$item->master_menu_price : null);
            if ($price === null || $price < 0) {
                $invalidValueCount++;
                continue;
            }

            $lineRevenue = round($qty * $price, 2);
            $totalGrossRevenue += $lineRevenue;

            // 2. Resolve Cost Price
            // Precedence: Item snapshot on transaction_items > Menu master snapshot
            $rawCost = null;
            if ($item->ti_cost_price !== null && (float)$item->ti_cost_price > 0) {
                $rawCost = (float)$item->ti_cost_price;
            } elseif ($item->menu_cost_price !== null && (float)$item->menu_cost_price > 0) {
                $rawCost = (float)$item->menu_cost_price;
            } elseif ($item->ti_cost_price !== null && (float)$item->ti_cost_price == 0) {
                $rawCost = 0.0;
            } elseif ($item->menu_cost_price !== null && (float)$item->menu_cost_price == 0) {
                $rawCost = 0.0;
            }

            // 3. Classify Cost Status
            $costStatus = self::COST_STATUS_MISSING;
            $lineCogs = 0.0;
            $isCostCovered = false;

            if ($rawCost === null) {
                $costStatus = self::COST_STATUS_MISSING;
                $missingCostCount++;
            } elseif ($rawCost < 0) {
                $costStatus = self::COST_STATUS_INVALID;
                $invalidValueCount++;
            } elseif ($rawCost == 0.0) {
                $costStatus = self::COST_STATUS_ZERO;
                $zeroCostCount++;
            } elseif ($rawCost > $price) {
                $costStatus = self::COST_STATUS_ANOMALOUS;
                $anomalousCostCount++;
                $isCostCovered = true;
                $lineCogs = round($qty * $rawCost, 2);
                $totalEstimatedCogs += $lineCogs;
                $coveredGrossRevenue += $lineRevenue;
                $anomalousItems[] = [
                    'type'              => 'COST_EXCEEDS_PRICE',
                    'item_id'           => $item->item_id,
                    'transaction_id'    => $item->transaction_id,
                    'menu_id'           => $item->menu_id,
                    'menu_name'         => $item->menu_name,
                    'selling_price'     => $price,
                    'cost_price'        => $rawCost,
                    'qty'               => $qty,
                    'loss_nominal'      => round(($rawCost - $price) * $qty, 2),
                ];
            } else {
                $costStatus = self::COST_STATUS_VALID;
                $validCostCount++;
                $isCostCovered = true;
                $lineCogs = round($qty * $rawCost, 2);
                $totalEstimatedCogs += $lineCogs;
                $coveredGrossRevenue += $lineRevenue;
            }

            // 4. Resolve Menu Identity (Fail-Closed)
            $resolvedMenuId = $this->resolveMenuId($item);

            if ($resolvedMenuId === null) {
                $unresolvedItems[] = [
                    'item_id'           => $item->item_id,
                    'transaction_id'    => $item->transaction_id,
                    'raw_menu_id'       => $item->menu_id,
                    'raw_menu_name'     => $item->menu_name,
                    'qty'               => $qty,
                    'price'             => $price,
                    'cost_price'        => $rawCost,
                    'gross_revenue'     => $lineRevenue,
                    'estimated_cogs'    => $lineCogs,
                    'cost_status'       => $costStatus,
                ];
                continue;
            }

            // Initialize Menu Aggregate
            if (!isset($menuAggs[$resolvedMenuId])) {
                $master = $this->menuCache[$resolvedMenuId] ?? null;
                $menuAggs[$resolvedMenuId] = [
                    'menu_id'                   => $resolvedMenuId,
                    'menu_name'                 => $master['name'] ?? ($item->menu_name ?: 'Menu #' . $resolvedMenuId),
                    'category'                  => $master['category'] ?? ($item->menu_category ?: 'Uncategorized'),
                    'outlet_id'                 => (int)($master['outlet_id'] ?? $item->outlet_id),
                    'master_price'              => $master['price'] ?? $price,
                    'master_cost'               => $master['cost_price'] ?? $rawCost,
                    'quantity_sold'             => 0.0,
                    'gross_revenue'             => 0.0,
                    'covered_revenue'           => 0.0,
                    'estimated_cogs'            => 0.0,
                    'valid_cost_items_count'    => 0,
                    'zero_cost_items_count'     => 0,
                    'missing_cost_items_count'  => 0,
                    'anomalous_cost_items_count'=> 0,
                    'invalid_value_items_count' => 0,
                    'selling_prices'            => [],
                    'cost_prices'               => [],
                    'recent_revenue'            => 0.0,
                    'recent_cogs'               => 0.0,
                    'recent_covered_revenue'    => 0.0,
                    'prior_revenue'             => 0.0,
                    'prior_cogs'                => 0.0,
                    'prior_covered_revenue'     => 0.0,
                ];
            }

            $menuAggs[$resolvedMenuId]['quantity_sold'] += $qty;
            $menuAggs[$resolvedMenuId]['gross_revenue'] += $lineRevenue;
            $menuAggs[$resolvedMenuId]['selling_prices'][] = ['price' => $price, 'qty' => $qty];

            if ($costStatus === self::COST_STATUS_VALID) {
                $menuAggs[$resolvedMenuId]['valid_cost_items_count']++;
            } elseif ($costStatus === self::COST_STATUS_ZERO) {
                $menuAggs[$resolvedMenuId]['zero_cost_items_count']++;
            } elseif ($costStatus === self::COST_STATUS_MISSING) {
                $menuAggs[$resolvedMenuId]['missing_cost_items_count']++;
            } elseif ($costStatus === self::COST_STATUS_ANOMALOUS) {
                $menuAggs[$resolvedMenuId]['anomalous_cost_items_count']++;
            } else {
                $menuAggs[$resolvedMenuId]['invalid_value_items_count']++;
            }

            if ($isCostCovered) {
                $menuAggs[$resolvedMenuId]['covered_revenue'] += $lineRevenue;
                $menuAggs[$resolvedMenuId]['estimated_cogs'] += $lineCogs;
                $menuAggs[$resolvedMenuId]['cost_prices'][] = ['cost' => $rawCost, 'qty' => $qty];
            }

            // Sub-window tracking for trend analysis
            $txCreatedAtUtc = (string)$item->tx_created_at;
            if ($txCreatedAtUtc >= $recentStartUtc) {
                $menuAggs[$resolvedMenuId]['recent_revenue'] += $lineRevenue;
                if ($isCostCovered) {
                    $menuAggs[$resolvedMenuId]['recent_covered_revenue'] += $lineRevenue;
                    $menuAggs[$resolvedMenuId]['recent_cogs'] += $lineCogs;
                }
            } elseif ($txCreatedAtUtc >= $priorStartUtc) {
                $menuAggs[$resolvedMenuId]['prior_revenue'] += $lineRevenue;
                if ($isCostCovered) {
                    $menuAggs[$resolvedMenuId]['prior_covered_revenue'] += $lineRevenue;
                    $menuAggs[$resolvedMenuId]['prior_cogs'] += $lineCogs;
                }
            }

            // Outlet level aggregation
            $oId = (int)$item->outlet_id;
            if (!isset($outletAggs[$oId])) {
                $outletAggs[$oId] = [
                    'outlet_id'         => $oId,
                    'gross_revenue'     => 0.0,
                    'covered_revenue'   => 0.0,
                    'estimated_cogs'    => 0.0,
                    'total_items'       => 0,
                    'covered_items'     => 0,
                    'transactions'      => [],
                ];
            }
            $outletAggs[$oId]['gross_revenue'] += $lineRevenue;
            $outletAggs[$oId]['total_items'] += (int)$qty;
            $outletAggs[$oId]['transactions'][$item->transaction_id] = true;
            if ($isCostCovered) {
                $outletAggs[$oId]['covered_revenue'] += $lineRevenue;
                $outletAggs[$oId]['estimated_cogs'] += $lineCogs;
                $outletAggs[$oId]['covered_items'] += (int)$qty;
            }
        }

        return [
            'total_transactions_count'  => count($distinctTxIds),
            'total_items_count'         => $totalItemsCount,
            'valid_cost_count'          => $validCostCount,
            'zero_cost_count'           => $zeroCostCount,
            'missing_cost_count'        => $missingCostCount,
            'anomalous_cost_count'      => $anomalousCostCount,
            'invalid_value_count'       => $invalidValueCount,
            'total_gross_revenue'       => round($totalGrossRevenue, 2),
            'covered_gross_revenue'     => round($coveredGrossRevenue, 2),
            'total_estimated_cogs'      => round($totalEstimatedCogs, 2),
            'menu_aggregates'           => $menuAggs,
            'outlet_aggregates'         => $outletAggs,
            'unresolved_items'          => $unresolvedItems,
            'anomalous_items'           => $anomalousItems,
        ];
    }

    /**
     * Compile final structured report with health classifications and statistics.
     */
    protected function compileReport(
        int $userId,
        ?int $outletId,
        string $timezone,
        string $asOfDate,
        int $lookbackDays,
        string $cutoffUtc,
        array $processed,
        bool $includeZeroSales
    ): array {
        $totalGrossRevenue = $processed['total_gross_revenue'];
        $coveredGrossRevenue = $processed['covered_gross_revenue'];
        $totalEstimatedCogs = $processed['total_estimated_cogs'];
        $totalEstimatedMargin = round($coveredGrossRevenue - $totalEstimatedCogs, 2);

        $overallMarginPct = $coveredGrossRevenue > 0
            ? round(($totalEstimatedMargin / $coveredGrossRevenue) * 100.0, 2)
            : 0.0;

        $revCoverage = $totalGrossRevenue > 0
            ? round(($coveredGrossRevenue / $totalGrossRevenue) * 100.0, 2)
            : 0.0;

        $totalItems = $processed['total_items_count'];
        $coveredItems = $processed['valid_cost_count'] + $processed['anomalous_cost_count'];
        $itemsCoverage = $totalItems > 0
            ? round(($coveredItems / $totalItems) * 100.0, 2)
            : 0.0;

        // Process Menus Analysis
        $menusAnalysis = [];
        $healthDistribution = [
            self::HEALTH_HEALTHY_MARGIN     => 0,
            self::HEALTH_THIN_MARGIN        => 0,
            self::HEALTH_LOW_MARGIN         => 0,
            self::HEALTH_NEGATIVE_MARGIN    => 0,
            self::HEALTH_INSUFFICIENT_COST  => 0,
        ];

        foreach ($processed['menu_aggregates'] as $menuId => $menuData) {
            $grossRev = round($menuData['gross_revenue'], 2);
            $covRev = round($menuData['covered_revenue'], 2);
            $cogs = round($menuData['estimated_cogs'], 2);
            $qtySold = round($menuData['quantity_sold'], 2);

            $margin = round($covRev - $cogs, 2);
            $marginPct = $covRev > 0 ? round(($margin / $covRev) * 100.0, 2) : null;

            $revContributionPct = $totalGrossRevenue > 0 ? round(($grossRev / $totalGrossRevenue) * 100.0, 2) : 0.0;
            $marginContributionPct = ($totalEstimatedMargin > 0 && $margin > 0)
                ? round(($margin / $totalEstimatedMargin) * 100.0, 2)
                : 0.0;

            // Weighted Average Unit Price
            $totPricedUnits = 0.0;
            $sumWeightedPrice = 0.0;
            foreach ($menuData['selling_prices'] as $sp) {
                $totPricedUnits += $sp['qty'];
                $sumWeightedPrice += ($sp['price'] * $sp['qty']);
            }
            $avgSellingPrice = $totPricedUnits > 0 ? round($sumWeightedPrice / $totPricedUnits, 2) : round($grossRev / max(1.0, $qtySold), 2);

            // Weighted Average Unit Cost
            $totCostedUnits = 0.0;
            $sumWeightedCost = 0.0;
            foreach ($menuData['cost_prices'] as $cp) {
                $totCostedUnits += $cp['qty'];
                $sumWeightedCost += ($cp['cost'] * $cp['qty']);
            }
            $avgCostPrice = $totCostedUnits > 0 ? round($sumWeightedCost / $totCostedUnits, 2) : null;

            // Cost Data Status
            $costCoveragePct = $grossRev > 0 ? round(($covRev / $grossRev) * 100.0, 2) : 0.0;
            $costStatus = $this->determineMenuCostStatus($menuData, $costCoveragePct);

            // Margin Health Category (INTERNAL KACHING RULE)
            $healthCategory = $this->classifyMarginHealth($marginPct, $costCoveragePct, $costStatus);

            // Recent Margin Trend
            $recentMarginPct = $menuData['recent_covered_revenue'] > 0
                ? round((($menuData['recent_covered_revenue'] - $menuData['recent_cogs']) / $menuData['recent_covered_revenue']) * 100.0, 2)
                : null;

            $priorMarginPct = $menuData['prior_covered_revenue'] > 0
                ? round((($menuData['prior_covered_revenue'] - $menuData['prior_cogs']) / $menuData['prior_covered_revenue']) * 100.0, 2)
                : null;

            $trendDirection = self::TREND_INSUFFICIENT_DATA;
            if ($recentMarginPct !== null && $priorMarginPct !== null) {
                $diff = round($recentMarginPct - $priorMarginPct, 2);
                if ($diff >= 3.0) {
                    $trendDirection = self::TREND_EXPANDING;
                } elseif ($diff <= -3.0) {
                    $trendDirection = self::TREND_COMPRESSING;
                } else {
                    $trendDirection = self::TREND_STABLE;
                }
            }

            $menusAnalysis[] = [
                'menu_id'                   => $menuId,
                'menu_name'                 => $menuData['menu_name'],
                'category'                  => $menuData['category'],
                'outlet_id'                 => $menuData['outlet_id'],
                'quantity_sold'             => $qtySold,
                'gross_revenue'             => $grossRev,
                'total_revenue'             => $grossRev,
                'covered_revenue'           => $covRev,
                'estimated_cogs'            => $cogs,
                'estimated_margin'          => $margin,
                'estimated_contribution_margin' => $margin,
                'estimated_margin_pct'      => $marginPct,
                'avg_selling_price'         => $avgSellingPrice,
                'unit_price'                => $avgSellingPrice,
                'avg_cost_price'            => $avgCostPrice,
                'unit_cost'                 => $avgCostPrice,
                'revenue_contribution_pct'  => $revContributionPct,
                'margin_contribution_pct'   => $marginContributionPct,
                'cost_status'               => $costStatus,
                'cost_data_status'          => $costStatus,
                'cost_coverage_pct'         => $costCoveragePct,
                'health_category'           => $healthCategory,
                'margin_trend'              => [
                    'recent_margin_pct' => $recentMarginPct,
                    'prior_margin_pct'  => $priorMarginPct,
                    'direction'         => $trendDirection,
                ],
            ];

            $healthDistribution[$healthCategory]++;
        }

        // Optional: Include zero-sales catalog menus
        if ($includeZeroSales) {
            foreach ($this->menuCache as $mId => $master) {
                if (isset($processed['menu_aggregates'][$mId])) {
                    continue;
                }
                $masterCost = $master['cost_price'] !== null ? (float)$master['cost_price'] : null;
                $costQuality = self::COST_STATUS_MISSING;
                if ($masterCost !== null && $masterCost > 0) {
                    $costQuality = self::COST_STATUS_VALID;
                } elseif ($masterCost !== null && $masterCost == 0) {
                    $costQuality = self::COST_STATUS_ZERO;
                }

                $menusAnalysis[] = [
                    'menu_id'                   => $mId,
                    'menu_name'                 => $master['name'],
                    'category'                  => $master['category'] ?? 'Uncategorized',
                    'outlet_id'                 => (int)($master['outlet_id'] ?? 0),
                    'quantity_sold'             => 0.0,
                    'gross_revenue'             => 0.0,
                    'total_revenue'             => 0.0,
                    'covered_revenue'           => 0.0,
                    'estimated_cogs'            => 0.0,
                    'estimated_margin'          => 0.0,
                    'estimated_contribution_margin' => 0.0,
                    'estimated_margin_pct'      => null,
                    'avg_selling_price'         => (float)($master['price'] ?? 0),
                    'unit_price'                => (float)($master['price'] ?? 0),
                    'avg_cost_price'            => $masterCost,
                    'unit_cost'                 => $masterCost,
                    'revenue_contribution_pct'  => 0.0,
                    'margin_contribution_pct'   => 0.0,
                    'cost_status'               => $costQuality,
                    'cost_data_status'          => $costQuality,
                    'cost_coverage_pct'         => 0.0,
                    'health_category'           => self::HEALTH_INSUFFICIENT_COST,
                    'margin_trend'              => [
                        'recent_margin_pct' => null,
                        'prior_margin_pct'  => null,
                        'direction'         => self::TREND_INSUFFICIENT_DATA,
                    ],
                ];

                $healthDistribution[self::HEALTH_INSUFFICIENT_COST]++;
            }
        }

        // Sort menus by revenue descending
        usort($menusAnalysis, fn($a, $b) => $b['gross_revenue'] <=> $a['gross_revenue']);

        $menusMap = [];
        $insufficientCostMenuIds = [];
        $anomalies = [];

        foreach ($menusAnalysis as $m) {
            $menusMap[$m['menu_id']] = $m;
            if ($m['health_category'] === self::HEALTH_INSUFFICIENT_COST) {
                $insufficientCostMenuIds[] = $m['menu_id'];
            }
            if ($m['health_category'] === self::HEALTH_NEGATIVE_MARGIN || $m['cost_status'] === self::COST_STATUS_ANOMALOUS) {
                $anomalies[] = [
                    'type'          => 'NEGATIVE_MARGIN_MENU',
                    'menu_id'       => $m['menu_id'],
                    'menu_name'     => $m['menu_name'],
                    'selling_price' => $m['avg_selling_price'],
                    'cost_price'    => $m['avg_cost_price'],
                    'margin_pct'    => $m['estimated_margin_pct'],
                    'message'       => "Menu {$m['menu_name']} has estimated cost ({$m['avg_cost_price']}) exceeding selling price ({$m['avg_selling_price']}).",
                ];
            }
        }

        // Append item-level anomalies
        foreach ($processed['anomalous_items'] as $anomItem) {
            $anomalies[] = $anomItem;
        }

        // Process Outlets Analysis
        $outletsReport = [];
        foreach ($processed['outlet_aggregates'] as $oId => $oData) {
            $oGross = round($oData['gross_revenue'], 2);
            $oCov = round($oData['covered_revenue'], 2);
            $oCogs = round($oData['estimated_cogs'], 2);
            $oMargin = round($oCov - $oCogs, 2);
            $oMarginPct = $oCov > 0 ? round(($oMargin / $oCov) * 100.0, 2) : 0.0;
            $oRevCoverage = $oGross > 0 ? round(($oCov / $oGross) * 100.0, 2) : 0.0;

            $outletsReport[] = [
                'outlet_id'                 => $oId,
                'eligible_transactions_count' => count($oData['transactions']),
                'total_items_sold'          => $oData['total_items'],
                'gross_revenue'             => $oGross,
                'covered_revenue'           => $oCov,
                'estimated_cogs'            => $oCogs,
                'estimated_contribution_margin' => $oMargin,
                'estimated_margin_pct'      => $oMarginPct,
                'revenue_coverage_pct'      => $oRevCoverage,
                'items_cost_coverage_pct'   => $oData['total_items'] > 0 ? round(($oData['covered_items'] / $oData['total_items']) * 100.0, 2) : 0.0,
            ];
        }

        // Unresolved Items Summary
        $unresolvedGross = 0.0;
        $unresolvedCov = 0.0;
        $unresolvedCogs = 0.0;
        $unresolvedQty = 0.0;
        foreach ($processed['unresolved_items'] as $unres) {
            $unresolvedGross += $unres['gross_revenue'];
            $unresolvedQty += $unres['qty'];
            if ($unres['cost_status'] === self::COST_STATUS_VALID || $unres['cost_status'] === self::COST_STATUS_ANOMALOUS) {
                $unresolvedCov += $unres['gross_revenue'];
                $unresolvedCogs += $unres['estimated_cogs'];
            }
        }

        $eligibleTxCount = $processed['total_transactions_count'];
        $uncostedRev = round($totalGrossRevenue - $coveredGrossRevenue, 2);

        return [
            'meta' => [
                'engine'                => 'ContributionMarginService',
                'phase'                 => '3.9H',
                'nature'                => 'DETERMINISTIC_ESTIMATED_MARGIN',
                'as_of_date'            => $asOfDate,
                'timezone'              => $timezone,
                'lookback_days'         => $lookbackDays,
                'pit_cutoff_utc'        => $cutoffUtc,
                'tenant_user_id'        => $userId,
                'scoped_outlet_id'      => $outletId,
                'internal_rule_note'    => 'Margin thresholds and health categories are defined by INTERNAL KACHING RULES, not industry standards. Cost prices reflect item or master snapshots, not live dynamic batch purchase ledgers.',
            ],
            'summary' => [
                'total_eligible_transactions'       => $eligibleTxCount,
                'eligible_transactions_count'       => $eligibleTxCount,
                'total_eligible_items'              => $totalItems,
                'eligible_items_count'              => $totalItems,
                'valid_cost_items_count'            => $processed['valid_cost_count'],
                'zero_cost_items_count'             => $processed['zero_cost_count'],
                'missing_cost_items_count'          => $processed['missing_cost_count'],
                'anomalous_cost_items_count'        => $processed['anomalous_cost_count'],
                'invalid_value_items_count'         => $processed['invalid_value_count'],
                'total_gross_revenue'               => $totalGrossRevenue,
                'covered_gross_revenue'             => $coveredGrossRevenue,
                'covered_revenue'                   => $coveredGrossRevenue,
                'uncosted_revenue'                  => $uncostedRev,
                'total_estimated_cogs'              => $totalEstimatedCogs,
                'estimated_cogs'                    => $totalEstimatedCogs,
                'total_estimated_contribution_margin'=> $totalEstimatedMargin,
                'estimated_contribution_margin'     => $totalEstimatedMargin,
                'estimated_margin_pct'              => $overallMarginPct,
                'revenue_coverage_pct'              => $revCoverage,
                'revenue_cost_coverage_pct'         => $revCoverage,
                'items_cost_coverage_pct'           => $itemsCoverage,
                'health_distribution'               => $healthDistribution,
                'menus_with_insufficient_cost'      => count($insufficientCostMenuIds),
                'insufficient_cost_menu_ids'        => $insufficientCostMenuIds,
            ],
            'menus'             => $menusMap,
            'menus_analysis'    => $menusAnalysis,
            'outlets_analysis'  => $outletsReport,
            'anomalies'         => $anomalies,
            'unresolved_items'  => [
                'count'             => count($processed['unresolved_items']),
                'total_unresolved'  => count($processed['unresolved_items']),
                'total_qty'         => round($unresolvedQty, 2),
                'gross_revenue'     => round($unresolvedGross, 2),
                'revenue'           => round($unresolvedGross, 2),
                'covered_revenue'   => round($unresolvedCov, 2),
                'estimated_cogs'    => round($unresolvedCogs, 2),
                'items'             => $processed['unresolved_items'],
            ],
        ];
    }

    /**
     * Determine menu-level aggregated cost status.
     */
    protected function determineMenuCostStatus(array $menuData, float $costCoveragePct): string
    {
        $valid = $menuData['valid_cost_items_count'];
        $zero = $menuData['zero_cost_items_count'];
        $missing = $menuData['missing_cost_items_count'];
        $anom = $menuData['anomalous_cost_items_count'];

        if ($anom > 0 && ($valid + $zero + $missing) === 0) {
            return self::COST_STATUS_ANOMALOUS;
        }
        if ($valid > 0 && ($zero + $missing + $anom) === 0) {
            return self::COST_STATUS_VALID;
        }
        if ($zero > 0 && ($valid + $missing + $anom) === 0) {
            return self::COST_STATUS_ZERO;
        }
        if ($missing > 0 && ($valid + $zero + $anom) === 0) {
            return self::COST_STATUS_MISSING;
        }
        if ($anom > 0) {
            return self::COST_STATUS_ANOMALOUS;
        }

        return self::COST_STATUS_MIXED;
    }

    /**
     * Classify margin health according to INTERNAL KACHING RULES.
     */
    protected function classifyMarginHealth(?float $marginPct, float $costCoveragePct, string $costStatus): string
    {
        if ($costStatus === self::COST_STATUS_ZERO || $costStatus === self::COST_STATUS_MISSING) {
            return self::HEALTH_INSUFFICIENT_COST;
        }

        if ($costCoveragePct < self::THRESHOLD_MIN_COVERAGE_PCT || $marginPct === null) {
            return self::HEALTH_INSUFFICIENT_COST;
        }

        if ($marginPct < 0.0) {
            return self::HEALTH_NEGATIVE_MARGIN;
        }

        if ($marginPct >= self::THRESHOLD_HEALTHY_MIN_PCT) {
            return self::HEALTH_HEALTHY_MARGIN;
        }

        if ($marginPct >= self::THRESHOLD_THIN_MIN_PCT) {
            return self::HEALTH_THIN_MARGIN;
        }

        return self::HEALTH_LOW_MARGIN;
    }

    /**
     * Resolve menu_id fail-closed.
     */
    protected function resolveMenuId(object $item): ?int
    {
        if ($item->menu_id !== null && isset($this->menuCache[$item->menu_id])) {
            return (int)$item->menu_id;
        }

        $rawName = trim((string)$item->menu_name);
        if ($rawName !== '') {
            $lower = strtolower($rawName);
            if (isset($this->nameToMenuId[$lower])) {
                return (int)$this->nameToMenuId[$lower];
            }
        }

        return null;
    }

    /**
     * Build an empty response when no data exists.
     */
    protected function buildEmptyResponse(
        int $userId,
        ?int $outletId,
        string $timezone,
        string $asOfDate,
        int $lookbackDays
    ): array {
        return [
            'meta' => [
                'engine'                => 'ContributionMarginService',
                'phase'                 => '3.9H',
                'nature'                => 'DETERMINISTIC_ESTIMATED_MARGIN',
                'as_of_date'            => $asOfDate,
                'timezone'              => $timezone,
                'lookback_days'         => $lookbackDays,
                'pit_cutoff_utc'        => null,
                'tenant_user_id'        => $userId,
                'scoped_outlet_id'      => $outletId,
                'internal_rule_note'    => 'Margin thresholds and health categories are defined by INTERNAL KACHING RULES.',
            ],
            'summary' => [
                'total_eligible_transactions'       => 0,
                'eligible_transactions_count'       => 0,
                'total_eligible_items'              => 0,
                'eligible_items_count'              => 0,
                'valid_cost_items_count'            => 0,
                'zero_cost_items_count'             => 0,
                'missing_cost_items_count'          => 0,
                'anomalous_cost_items_count'        => 0,
                'invalid_value_items_count'         => 0,
                'total_gross_revenue'               => 0.0,
                'covered_gross_revenue'             => 0.0,
                'covered_revenue'                   => 0.0,
                'uncosted_revenue'                  => 0.0,
                'total_estimated_cogs'              => 0.0,
                'estimated_cogs'                    => 0.0,
                'total_estimated_contribution_margin'=> 0.0,
                'estimated_contribution_margin'     => 0.0,
                'estimated_margin_pct'              => 0.0,
                'revenue_coverage_pct'              => 0.0,
                'revenue_cost_coverage_pct'         => 0.0,
                'items_cost_coverage_pct'           => 0.0,
                'health_distribution'               => [
                    self::HEALTH_HEALTHY_MARGIN     => 0,
                    self::HEALTH_THIN_MARGIN        => 0,
                    self::HEALTH_LOW_MARGIN         => 0,
                    self::HEALTH_NEGATIVE_MARGIN    => 0,
                    self::HEALTH_INSUFFICIENT_COST  => 0,
                ],
                'menus_with_insufficient_cost'      => 0,
                'insufficient_cost_menu_ids'        => [],
            ],
            'menus'             => [],
            'menus_analysis'    => [],
            'outlets_analysis'  => [],
            'anomalies'         => [],
            'unresolved_items'  => [
                'count'             => 0,
                'total_unresolved'  => 0,
                'total_qty'         => 0.0,
                'gross_revenue'     => 0.0,
                'revenue'           => 0.0,
                'covered_revenue'   => 0.0,
                'estimated_cogs'    => 0.0,
                'items'             => [],
            ],
        ];
    }

    /**
     * Preload menu master cache for tenant/outlet.
     */
    protected function preloadMenuCache(int $userId, ?int $outletId): void
    {
        $this->menuCache = [];
        $this->nameToMenuId = [];

        $query = Menu::where('user_id', $userId);
        if ($outletId !== null) {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)
                  ->orWhereNull('outlet_id')
                  ->orWhere('outlet_id', 0);
            });
        }

        $menus = $query->get(['id', 'name', 'price', 'cost_price', 'category', 'outlet_id']);
        foreach ($menus as $m) {
            $this->menuCache[$m->id] = [
                'id'         => $m->id,
                'name'       => $m->name,
                'price'      => $m->price !== null ? (float)$m->price : null,
                'cost_price' => $m->cost_price !== null ? (float)$m->cost_price : null,
                'category'   => $m->category,
                'outlet_id'  => $m->outlet_id,
            ];
            $lowerName = strtolower(trim((string)$m->name));
            if (!isset($this->nameToMenuId[$lowerName])) {
                $this->nameToMenuId[$lowerName] = $m->id;
            }
        }
    }

    /**
     * Resolve timezone with fail-safe fallback.
     */
    protected function resolveOutletTimezone(int $userId, ?int $outletId): string
    {
        if ($outletId !== null && isset($this->outletTimezones[$outletId])) {
            return $this->outletTimezones[$outletId];
        }

        if ($outletId !== null) {
            $tz = TimezoneContract::resolveOutletTimezone($outletId);
            $this->outletTimezones[$outletId] = $tz;
            return $tz;
        }

        return 'Asia/Jakarta';
    }
}
