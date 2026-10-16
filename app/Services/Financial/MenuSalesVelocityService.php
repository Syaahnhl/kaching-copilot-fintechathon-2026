<?php

namespace App\Services\Financial;

use App\Models\Menu;
use App\Models\Outlet;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MenuSalesVelocityService — Phase 3.9F
 *
 * Menu Sales Velocity & Dead Stock Intelligence Engine
 * Analyzes:
 *  1. Sales velocity per menu (daily average, frequency, multi-window 7/14/30d)
 *  2. Active but rarely sold menus (LOW_VELOCITY)
 *  3. Registered menus with zero sales (NO_SALES / Dead Stock candidates)
 *  4. Menus with declining sales velocity (DECLINING_VELOCITY)
 *  5. High velocity & high revenue contribution menus (HIGH_VELOCITY / STABLE)
 *
 * Invariants:
 *  - Strict Tenant & Outlet Isolation
 *  - Point-in-Time (PIT) Semantics identical to MenuDemandForecastService
 *  - Preserves Zero-Sales Days (true daily velocity = total qty / calendar days)
 *  - Idempotent aggregation (no transaction double counting)
 *  - Status-gated: excludes 'Batal' and 'Refund' (unless cancelled post-cutoff)
 *  - Fail-closed menu identity resolution (ambiguous names rejected)
 *  - Zero database mutations
 */
class MenuSalesVelocityService
{
    // Category Classifications (Explicit & Non-absolute)
    public const CAT_NO_SALES           = 'NO_SALES';
    public const CAT_LOW_VELOCITY       = 'LOW_VELOCITY';
    public const CAT_DECLINING_VELOCITY = 'DECLINING_VELOCITY';
    public const CAT_STABLE             = 'STABLE';
    public const CAT_HIGH_VELOCITY      = 'HIGH_VELOCITY';

    // Velocity Trend Directions
    public const TREND_UPWARD            = 'UPWARD';
    public const TREND_DOWNWARD          = 'DOWNWARD';
    public const TREND_STABLE            = 'STABLE';
    public const TREND_INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';

    // Default Window Configuration
    public const DEFAULT_LOOKBACK_DAYS      = 30;
    public const DEFAULT_RECENT_WINDOW_DAYS = 14;
    public const DEFAULT_PRIOR_WINDOW_DAYS  = 14;

    // Deterministic Threshold Defaults
    public const THRESHOLD_LOW_VELOCITY_FREQ      = 0.15; // Sold on <15% of calendar days
    public const THRESHOLD_LOW_VELOCITY_DAILY_QTY = 0.20; // Average <0.20 units/day (<6 units in 30d)
    public const THRESHOLD_HIGH_VELOCITY_FREQ     = 0.70; // Sold on >=70% of calendar days
    public const THRESHOLD_HIGH_VELOCITY_DAILY_QTY = 2.00; // Average >=2.00 units/day (>=60 units in 30d)
    public const THRESHOLD_DECLINING_RATIO        = 0.60; // Recent velocity <= 60% of prior velocity
    public const THRESHOLD_DECLINING_MIN_PRIOR_QTY = 0.50; // Prior velocity was at least 0.50 units/day
    public const THRESHOLD_UPWARD_RATIO           = 1.25; // Recent velocity >= 125% of prior velocity
    public const THRESHOLD_UPWARD_MIN_DELTA       = 0.25; // Recent velocity increased by at least 0.25 units/day

    protected MenuDemandForecastService $forecastService;

    public function __construct(?MenuDemandForecastService $forecastService = null)
    {
        $this->forecastService = $forecastService ?? app(MenuDemandForecastService::class);
    }

    protected array $timezoneCache = [];

    /**
     * Resolve primary timezone for tenant/outlet.
     */
    public function resolvePrimaryTimezone(int $userId, ?int $outletId): string
    {
        $cacheKey = "{$userId}:" . ($outletId ?? 'all');
        if (isset($this->timezoneCache[$cacheKey])) {
            return $this->timezoneCache[$cacheKey];
        }

        if ($outletId !== null) {
            $outlet = DB::table('outlets')->where('id', $outletId)->where('user_id', $userId)->first();
            if ($outlet && !empty($outlet->timezone)) {
                return $this->timezoneCache[$cacheKey] = $outlet->timezone;
            }
        }

        $firstOutlet = DB::table('outlets')->where('user_id', $userId)->whereNotNull('timezone')->first();
        return $this->timezoneCache[$cacheKey] = ($firstOutlet->timezone ?? 'Asia/Jakarta');
    }

    /**
     * Analyze sales velocity, trends, dead stock candidates, and revenue contributions for a tenant.
     *
     * @param int $userId Tenant user ID (mandatory)
     * @param int|null $outletId Specific outlet ID (optional, null = all tenant outlets)
     * @param string|null $anchorDate Point-in-time business date 'Y-m-d' (null = today)
     * @param int|null $lookbackDays Total evaluation window in calendar days (default: 30)
     * @param array $options Configuration and filtering overrides
     * @return array Analytical dossier with item-level metrics and aggregate summaries
     */
    public function analyzeVelocity(
        int $userId,
        ?int $outletId = null,
        ?string $anchorDate = null,
        ?int $lookbackDays = null,
        array $options = []
    ): array {
        $lookbackDays = $lookbackDays ?? self::DEFAULT_LOOKBACK_DAYS;
        if ($lookbackDays < 1) {
            $lookbackDays = self::DEFAULT_LOOKBACK_DAYS;
        }

        // 1. Resolve Timezone & Date Boundaries
        $primaryTz = $this->resolvePrimaryTimezone($userId, $outletId);
        $anchor = $anchorDate ? Carbon::createFromFormat('Y-m-d', $anchorDate, $primaryTz) : Carbon::now($primaryTz);
        $endDateStr = $anchor->format('Y-m-d');
        $startDateStr = $anchor->copy()->subDays($lookbackDays - 1)->format('Y-m-d');

        // Resolve UTC Timestamp boundaries for SQL filtering via TimezoneContract SSOT
        [$utcStart, $utcEnd] = \App\Helpers\TimezoneContract::businessDateRangeToUtcRange($startDateStr, $endDateStr, $primaryTz);

        $cutoffUtc = $options['cutoff_utc'] ?? null;
        if ($cutoffUtc instanceof Carbon) {
            $cutoffUtc = $cutoffUtc->toDateTimeString();
        }

        // 2. Preload Menus & Initialize Zero-Sales Calendar Matrix
        $includeInactive = $options['include_inactive_menus'] ?? true;
        $menuCache = $this->forecastService->preloadMenuCache($userId, $outletId, $includeInactive);
        $targetMenus = $menuCache['byId'];

        // If specific menu_ids filter requested
        if (!empty($options['menu_ids']) && is_array($options['menu_ids'])) {
            $allowedIds = array_flip($options['menu_ids']);
            $targetMenus = array_filter($targetMenus, fn($m) => isset($allowedIds[$m->id]));
        }

        // Generate full list of calendar dates (Y-m-d) in order
        $calendarDates = [];
        $curr = Carbon::createFromFormat('Y-m-d', $startDateStr, $primaryTz);
        $endCarbon = Carbon::createFromFormat('Y-m-d', $endDateStr, $primaryTz);
        while ($curr->lte($endCarbon)) {
            $calendarDates[] = $curr->format('Y-m-d');
            $curr->addDay();
        }
        $totalCalendarDays = count($calendarDates);

        // Initialize zero-sales matrix for each target menu
        $dailyQtyMatrix = [];
        $dailyRevMatrix = [];
        foreach ($targetMenus as $menuId => $menuObj) {
            $dailyQtyMatrix[$menuId] = array_fill_keys($calendarDates, 0.0);
            $dailyRevMatrix[$menuId] = array_fill_keys($calendarDates, 0.0);
        }

        // 3. Query Transactions & Transaction Items
        $hasCreatedAtDevice = Schema::hasColumn('transactions', 'created_at_device');
        $hasIsTraining = Schema::hasColumn('transactions', 'is_training');
        $hasItemIsTraining = Schema::hasColumn('transaction_items', 'is_training');

        $query = DB::table('transactions as t')
            ->join('transaction_items as ti', 'ti.transaction_id', '=', 't.id')
            ->where('t.user_id', $userId);

        if ($outletId !== null && (int)$outletId > 0) {
            $query->where('t.outlet_id', $outletId);
        }

        if ($hasIsTraining) {
            $query->where('t.is_training', 0);
        }
        if ($hasItemIsTraining) {
            $query->where('ti.is_training', 0);
        }

        // Date range filtering
        if ($hasCreatedAtDevice) {
            $query->where(function ($q) use ($utcStart, $utcEnd) {
                $q->where(function ($sub) use ($utcStart, $utcEnd) {
                    $sub->whereNotNull('t.created_at_device')
                        ->where('t.created_at_device', '>=', $utcStart)
                        ->where('t.created_at_device', '<', $utcEnd);
                })->orWhere(function ($sub) use ($utcStart, $utcEnd) {
                    $sub->whereNull('t.created_at_device')
                        ->where('t.created_at', '>=', $utcStart)
                        ->where('t.created_at', '<', $utcEnd);
                });
            });
        } else {
            $query->where('t.created_at', '>=', $utcStart)
                  ->where('t.created_at', '<', $utcEnd);
        }

        // Point-in-Time cutoff filtering
        if ($cutoffUtc !== null) {
            if ($hasCreatedAtDevice) {
                $query->where(function ($q) use ($cutoffUtc) {
                    $q->where(function ($sub) use ($cutoffUtc) {
                        $sub->whereNotNull('t.created_at_device')
                            ->where('t.created_at_device', '<=', $cutoffUtc);
                    })->orWhere(function ($sub) use ($cutoffUtc) {
                        $sub->whereNull('t.created_at_device')
                            ->where('t.created_at', '<=', $cutoffUtc);
                    });
                });
            } else {
                $query->where('t.created_at', '<=', $cutoffUtc);
            }

            // PIT cancellation defense:
            // Exclude Batal/Refund ONLY IF cancellation happened on or before cutoff
            $query->where(function ($q) use ($cutoffUtc) {
                $q->whereNotIn('t.status', ['Batal', 'Refund'])
                  ->orWhere(function ($sub) use ($cutoffUtc) {
                      $sub->whereIn('t.status', ['Batal', 'Refund'])
                          ->where('t.updated_at', '>', $cutoffUtc);
                  });
            });
            $query->where(function ($q) use ($cutoffUtc) {
                $q->whereNotIn('ti.status', ['Batal', 'Refund'])
                  ->orWhereNull('ti.status')
                  ->orWhere(function ($sub) use ($cutoffUtc) {
                      $sub->whereIn('ti.status', ['Batal', 'Refund'])
                          ->where('ti.updated_at', '>', $cutoffUtc);
                  });
            });
        } else {
            // Live / Standard Non-Cancelled filter
            $query->whereNotIn('t.status', ['Batal', 'Refund']);
            $query->where(function ($q) {
                $q->whereNotIn('ti.status', ['Batal', 'Refund'])->orWhereNull('ti.status');
            });
        }

        $selectCols = [
            't.id as transaction_id',
            't.user_id',
            't.outlet_id',
            't.status as transaction_status',
            'ti.id as transaction_item_id',
            'ti.menu_id',
            'ti.menu_name',
            'ti.qty',
            'ti.price',
            't.created_at',
        ];
        if ($hasCreatedAtDevice) {
            $selectCols[] = 't.created_at_device';
        }

        $rawRows = $query->select($selectCols)->get();

        // 4. Process Rows, Resolve Identity, and Populate Daily Aggregation
        $unresolvedCount = 0;
        $unresolvedItems = [];
        $distinctTxIds = [];
        $outletTotalRevenue = 0.0;
        $outletTotalQty = 0.0;

        foreach ($rawRows as $row) {
            $distinctTxIds[$row->transaction_id] = true;

            $resolution = $this->forecastService->resolveMenuIdentity(
                (int)$row->user_id,
                $row->outlet_id !== null ? (int)$row->outlet_id : null,
                [
                    'menu_id'   => $row->menu_id,
                    'menu_name' => $row->menu_name,
                ],
                $cutoffUtc,
                $menuCache
            );

            if ($resolution['resolved_menu_id'] === null) {
                $unresolvedCount++;
                if (count($unresolvedItems) < 50) {
                    $unresolvedItems[] = [
                        'transaction_id' => $row->transaction_id,
                        'raw_menu_id'    => $row->menu_id,
                        'raw_menu_name'  => $row->menu_name,
                        'reason'         => $resolution['reason'],
                    ];
                }
                continue;
            }

            $resolvedMenuId = (int)$resolution['resolved_menu_id'];

            // Skip if target menu is filtered out
            if (!isset($dailyQtyMatrix[$resolvedMenuId])) {
                continue;
            }

            // Convert transaction wire timestamp to local business date
            $ts = $row->created_at_device ?? $row->created_at;
            $localBusinessDate = Carbon::parse($ts, 'UTC')->setTimezone($primaryTz)->format('Y-m-d');

            if (!isset($dailyQtyMatrix[$resolvedMenuId][$localBusinessDate])) {
                continue;
            }

            $itemQty = (float)$row->qty;
            if ($itemQty <= 0) {
                continue;
            }

            $itemPrice = $row->price !== null ? (float)$row->price : (float)($targetMenus[$resolvedMenuId]->price ?? 0.0);
            $itemRevenue = $itemQty * $itemPrice;

            $dailyQtyMatrix[$resolvedMenuId][$localBusinessDate] += $itemQty;
            $dailyRevMatrix[$resolvedMenuId][$localBusinessDate] += $itemRevenue;

            $outletTotalRevenue += $itemRevenue;
            $outletTotalQty += $itemQty;
        }

        // 5. Compute Metrics and Deterministic Categories
        $recentDaysCount = $options['recent_days'] ?? self::DEFAULT_RECENT_WINDOW_DAYS;
        $priorDaysCount = $options['prior_days'] ?? self::DEFAULT_PRIOR_WINDOW_DAYS;

        // Partition recent and prior dates
        $recentSlice = array_slice($calendarDates, -$recentDaysCount);
        $priorSlice = ($totalCalendarDays >= ($recentDaysCount + $priorDaysCount))
            ? array_slice($calendarDates, -($recentDaysCount + $priorDaysCount), $priorDaysCount)
            : [];

        // Threshold overrides
        $thresholds = array_merge([
            'low_velocity_freq'      => self::THRESHOLD_LOW_VELOCITY_FREQ,
            'low_velocity_daily_qty' => self::THRESHOLD_LOW_VELOCITY_DAILY_QTY,
            'high_velocity_freq'     => self::THRESHOLD_HIGH_VELOCITY_FREQ,
            'high_velocity_daily_qty' => self::THRESHOLD_HIGH_VELOCITY_DAILY_QTY,
            'declining_ratio'        => self::THRESHOLD_DECLINING_RATIO,
            'declining_min_prior_qty' => self::THRESHOLD_DECLINING_MIN_PRIOR_QTY,
            'upward_ratio'           => self::THRESHOLD_UPWARD_RATIO,
            'upward_min_delta'       => self::THRESHOLD_UPWARD_MIN_DELTA,
        ], $options['thresholds'] ?? []);

        $menuEvaluations = [];
        $categoryCounts = [
            self::CAT_NO_SALES           => 0,
            self::CAT_LOW_VELOCITY       => 0,
            self::CAT_DECLINING_VELOCITY => 0,
            self::CAT_STABLE             => 0,
            self::CAT_HIGH_VELOCITY      => 0,
        ];

        foreach ($targetMenus as $menuId => $menuObj) {
            $qtySeries = $dailyQtyMatrix[$menuId];
            $revSeries = $dailyRevMatrix[$menuId];

            $totQty = (float)array_sum($qtySeries);
            $totRev = (float)array_sum($revSeries);

            $activeDays = 0;
            foreach ($qtySeries as $dQty) {
                if ($dQty > 0.0001) {
                    $activeDays++;
                }
            }
            $zeroSalesDays = $totalCalendarDays - $activeDays;
            $salesFrequency = $totalCalendarDays > 0 ? round($activeDays / $totalCalendarDays, 4) : 0.0;
            $avgDailyQty = $totalCalendarDays > 0 ? round($totQty / $totalCalendarDays, 4) : 0.0;
            $activeDayAvgQty = $activeDays > 0 ? round($totQty / $activeDays, 4) : 0.0;

            // Consecutive zero-sales days ending on anchor date
            $consecutiveZeroDays = 0;
            for ($i = $totalCalendarDays - 1; $i >= 0; $i--) {
                $d = $calendarDates[$i];
                if ($qtySeries[$d] <= 0.0001) {
                    $consecutiveZeroDays++;
                } else {
                    break;
                }
            }

            // Multi-window velocity: 7d, 14d, 30d
            $v7Dates = array_slice($calendarDates, -7);
            $v7Qty = 0.0;
            foreach ($v7Dates as $d) {
                $v7Qty += $qtySeries[$d];
            }
            $velocity7d = count($v7Dates) > 0 ? round($v7Qty / count($v7Dates), 4) : 0.0;

            $v14Dates = array_slice($calendarDates, -14);
            $v14Qty = 0.0;
            foreach ($v14Dates as $d) {
                $v14Qty += $qtySeries[$d];
            }
            $velocity14d = count($v14Dates) > 0 ? round($v14Qty / count($v14Dates), 4) : 0.0;

            $velocity30d = $avgDailyQty;

            // Trend calculation: recent vs prior
            $recentQtySum = 0.0;
            foreach ($recentSlice as $d) {
                $recentQtySum += $qtySeries[$d];
            }
            $recentAvgQty = count($recentSlice) > 0 ? round($recentQtySum / count($recentSlice), 4) : 0.0;

            $priorQtySum = 0.0;
            foreach ($priorSlice as $d) {
                $priorQtySum += $qtySeries[$d];
            }
            $priorAvgQty = count($priorSlice) > 0 ? round($priorQtySum / count($priorSlice), 4) : 0.0;

            if (empty($priorSlice)) {
                $trendDirection = self::TREND_INSUFFICIENT_DATA;
                $velocityTrendRatio = 1.0;
                $velocityTrendDelta = 0.0;
            } else {
                $velocityTrendDelta = round($recentAvgQty - $priorAvgQty, 4);
                if ($priorAvgQty > 0.0001) {
                    $velocityTrendRatio = round($recentAvgQty / $priorAvgQty, 4);
                } else {
                    $velocityTrendRatio = $recentAvgQty > 0.0001 ? 999.0 : 1.0;
                }

                if ($priorAvgQty >= $thresholds['declining_min_prior_qty']
                    && $velocityTrendRatio <= $thresholds['declining_ratio']
                    && $velocityTrendDelta <= -0.20
                ) {
                    $trendDirection = self::TREND_DOWNWARD;
                } elseif ($velocityTrendRatio >= $thresholds['upward_ratio']
                    && $velocityTrendDelta >= $thresholds['upward_min_delta']
                ) {
                    $trendDirection = self::TREND_UPWARD;
                } else {
                    $trendDirection = self::TREND_STABLE;
                }
            }

            // Contribution percentages
            $revContribPct = $outletTotalRevenue > 0.0001 ? round(($totRev / $outletTotalRevenue) * 100, 2) : 0.0;
            $qtyContribPct = $outletTotalQty > 0.0001 ? round(($totQty / $outletTotalQty) * 100, 2) : 0.0;

            // Deterministic Category Assignment
            if ($totQty <= 0.0001) {
                $category = self::CAT_NO_SALES;
                $reason = "Menu had 0 units sold across all {$totalCalendarDays} calendar days in window.";
            } elseif ($salesFrequency < $thresholds['low_velocity_freq'] || $avgDailyQty < $thresholds['low_velocity_daily_qty']) {
                $category = self::CAT_LOW_VELOCITY;
                $reason = "Low sales activity (frequency: " . ($salesFrequency * 100) . "%, avg: {$avgDailyQty} units/day).";
            } elseif ($trendDirection === self::TREND_DOWNWARD && $priorAvgQty >= $thresholds['declining_min_prior_qty']) {
                $category = self::CAT_DECLINING_VELOCITY;
                $dropPct = round((1 - ($recentAvgQty / $priorAvgQty)) * 100, 1);
                $reason = "Sales velocity dropped by {$dropPct}% (from {$priorAvgQty} to {$recentAvgQty} units/day).";
            } elseif ($salesFrequency >= $thresholds['high_velocity_freq'] && $avgDailyQty >= $thresholds['high_velocity_daily_qty']) {
                $category = self::CAT_HIGH_VELOCITY;
                $reason = "High sales velocity (frequency: " . ($salesFrequency * 100) . "%, avg: {$avgDailyQty} units/day).";
            } else {
                $category = self::CAT_STABLE;
                $reason = "Regular sales pattern with stable velocity.";
            }

            $categoryCounts[$category]++;

            $menuEvaluations[$menuId] = [
                'menu_id'                     => $menuId,
                'menu_name'                   => $menuObj->name,
                'category'                    => $category,
                'category_reason'             => $reason,
                'master_price'                => (float)$menuObj->price,
                'total_quantity_sold'         => $totQty,
                'total_revenue'               => $totRev,
                'total_calendar_days'         => $totalCalendarDays,
                'active_selling_days'         => $activeDays,
                'zero_sales_days'             => $zeroSalesDays,
                'consecutive_zero_sales_days' => $consecutiveZeroDays,
                'sales_frequency'             => $salesFrequency,
                'average_daily_qty'           => $avgDailyQty,
                'active_day_avg_qty'          => $activeDayAvgQty,
                'velocity_7d'                 => $velocity7d,
                'velocity_14d'                => $velocity14d,
                'velocity_30d'                => $velocity30d,
                'recent_avg_qty'              => $recentAvgQty,
                'prior_avg_qty'               => $priorAvgQty,
                'velocity_trend_delta'        => $velocityTrendDelta,
                'velocity_trend_ratio'        => $velocityTrendRatio,
                'trend_direction'             => $trendDirection,
                'revenue_contribution_pct'    => $revContribPct,
                'quantity_contribution_pct'   => $qtyContribPct,
            ];
        }

        // 6. Ranked Segments for Copilot
        $sortedByRev = $menuEvaluations;
        uasort($sortedByRev, fn($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);

        $sortedByVelocity = $menuEvaluations;
        uasort($sortedByVelocity, fn($a, $b) => $b['average_daily_qty'] <=> $a['average_daily_qty']);

        $deadStockCandidates = array_filter($menuEvaluations, fn($m) => $m['category'] === self::CAT_NO_SALES);
        uasort($deadStockCandidates, fn($a, $b) => $b['master_price'] <=> $a['master_price']);

        $decliningMenus = array_filter($menuEvaluations, fn($m) => $m['category'] === self::CAT_DECLINING_VELOCITY);
        uasort($decliningMenus, fn($a, $b) => $b['prior_avg_qty'] <=> $a['prior_avg_qty']);

        return [
            'metadata' => [
                'user_id'                      => $userId,
                'outlet_id'                    => $outletId,
                'primary_timezone'             => $primaryTz,
                'anchor_date'                  => $endDateStr,
                'start_date'                   => $startDateStr,
                'total_calendar_days'          => $totalCalendarDays,
                'cutoff_utc'                   => $cutoffUtc,
                'total_menus_evaluated'        => count($menuEvaluations),
                'total_transactions_evaluated' => count($distinctTxIds),
                'unresolved_items_count'       => $unresolvedCount,
                'outlet_total_revenue'         => $outletTotalRevenue,
                'outlet_total_quantity'        => $outletTotalQty,
                'thresholds_applied'           => $thresholds,
            ],
            'category_distribution'   => $categoryCounts,
            'top_revenue_contributors' => array_values(array_slice($sortedByRev, 0, 10)),
            'top_velocity_menus'      => array_values(array_slice($sortedByVelocity, 0, 10)),
            'dead_stock_candidates'   => array_values($deadStockCandidates),
            'declining_velocity_menus'=> array_values($decliningMenus),
            'menus'                   => $menuEvaluations,
        ];
    }
}
