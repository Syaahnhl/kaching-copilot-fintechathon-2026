<?php

namespace App\Services\Financial;

use App\Helpers\TimezoneContract;
use App\Models\Menu;
use App\Models\Outlet;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SalesAnomalyService
 *
 * Phase 3.9G: Deterministic Sales Anomaly & Void Intelligence Engine.
 *
 * Capabilities:
 *  1. Void/Cancel Rate & Trend Analysis (overall, per outlet, per cashier/staff, per menu, cancel reasons).
 *  2. Point-in-Time (PIT) Semantics with post-cutoff cancellation defense.
 *  3. Deterministic Statistical Anomaly Detection:
 *     - Daily transaction volume spikes & drops (IQR / Tukey's fence & moving baseline).
 *     - Daily void rate spikes (critical threshold & moving delta).
 *     - Transaction amount extremes (Tukey's extreme upper fence Q3 + 3.0 * IQR).
 *     - Item quantity anomalies (unusual large batch orders).
 *  4. Strict Tenant & Outlet Isolation.
 *  5. Fail-Closed Ambiguity Guard for menus and staff.
 *
 * NOTE: Anomaly != Fraud. All findings are labeled with neutral operational descriptors
 * (ANOMALY, UNUSUAL, VOID_SPIKE, SALES_SPIKE, SALES_DROP).
 */
class SalesAnomalyService
{
    // Anomaly Type Identifiers
    public const ANOMALY_SALES_SPIKE       = 'SALES_SPIKE';
    public const ANOMALY_SALES_DROP        = 'SALES_DROP';
    public const ANOMALY_VOID_SPIKE        = 'VOID_SPIKE';
    public const ANOMALY_EXTREME_AMOUNT    = 'EXTREME_VALUE';
    public const ANOMALY_UNUSUAL_QUANTITY  = 'UNUSUAL_QUANTITY';

    // Summary Status
    public const STATUS_NORMAL             = 'NORMAL';
    public const STATUS_ELEVATED           = 'ELEVATED';
    public const STATUS_ANOMALY            = 'ANOMALY';

    // Void Severity Tiers
    public const VOID_SEVERITY_LOW         = 'LOW';        // <= 2.0%
    public const VOID_SEVERITY_MODERATE    = 'MODERATE';   // 2.01% - 5.0%
    public const VOID_SEVERITY_ELEVATED    = 'ELEVATED';   // 5.01% - 15.0%
    public const VOID_SEVERITY_CRITICAL    = 'CRITICAL';   // > 15.0%

    // Canonical Cancel/Void Statuses (SSOT from FinancialCalculationService)
    public const CANCEL_STATUSES = [
        'Batal', 'Refund', 'batal', 'refund',
        'Canceled', 'Cancel', 'Void',
        'batal ', 'refund ',
    ];

    public const DEFAULT_LOOKBACK_DAYS = 30;

    protected ?MenuDemandForecastService $forecastService;
    protected array $timezoneCache = [];

    public function __construct(?MenuDemandForecastService $forecastService = null)
    {
        $this->forecastService = $forecastService;
    }

    /**
     * Get or lazy-load MenuDemandForecastService.
     */
    protected function getForecastService(): MenuDemandForecastService
    {
        if ($this->forecastService === null) {
            $this->forecastService = app(MenuDemandForecastService::class);
        }
        return $this->forecastService;
    }

    /**
     * Analyze sales anomalies and void intelligence.
     *
     * @param int $userId Tenant ID (mandatory)
     * @param int|null $outletId Optional Outlet ID filter
     * @param string|null $anchorDate Local business date Y-m-d (anchor for lookback window)
     * @param int|null $lookbackDays Number of days to analyze (default 30)
     * @param array $options Additional options (cutoff_utc, custom thresholds, etc.)
     * @return array
     */
    public function analyze(
        int $userId,
        ?int $outletId = null,
        ?string $anchorDate = null,
        ?int $lookbackDays = null,
        array $options = []
    ): array {
        $lookbackDays = max(7, $lookbackDays ?? self::DEFAULT_LOOKBACK_DAYS);
        $primaryTz = $this->resolvePrimaryTimezone($userId, $outletId);

        // Resolve Anchor Date
        if ($anchorDate === null) {
            $anchorDate = Carbon::now($primaryTz)->format('Y-m-d');
        }

        $endDateObj = Carbon::createFromFormat('Y-m-d', $anchorDate, $primaryTz);
        $startDateObj = (clone $endDateObj)->subDays($lookbackDays - 1);
        $startDateStr = $startDateObj->format('Y-m-d');
        $endDateStr = $endDateObj->format('Y-m-d');

        [$utcStart, $utcEnd] = TimezoneContract::businessDateRangeToUtcRange($startDateStr, $endDateStr, $primaryTz);
        $cutoffUtc = $options['cutoff_utc'] ?? null;

        // Preload Menu Cache for menu void attribution
        $menuCache = $this->getForecastService()->preloadMenuCache($userId, $outletId, true);

        // Fetch Transactions
        $txRecords = $this->fetchTransactions($userId, $outletId, $utcStart, $utcEnd, $cutoffUtc);

        // Build Continuous Date Matrix
        $dateMatrix = [];
        $cursor = clone $startDateObj;
        while ($cursor->lte($endDateObj)) {
            $dStr = $cursor->format('Y-m-d');
            $dateMatrix[$dStr] = [
                'date'             => $dStr,
                'total_tx'         => 0,
                'valid_tx'         => 0,
                'void_tx'          => 0,
                'total_nominal'    => 0.0,
                'valid_nominal'    => 0.0,
                'void_nominal'     => 0.0,
                'total_qty'        => 0.0,
                'transactions'     => [],
            ];
            $cursor->addDay();
        }

        $allAmounts = [];
        $voidTransactionsList = [];
        $cashierStats = [];
        $outletStats = [];
        $cancelReasons = [];
        $txIds = [];

        foreach ($txRecords as $tx) {
            $txIds[] = (int)$tx->id;

            // Determine Local Business Date
            $deviceDate = !empty($tx->created_at_device) ? $tx->created_at_device : $tx->created_at;
            $localDate = Carbon::parse($deviceDate, 'UTC')->setTimezone($primaryTz)->format('Y-m-d');

            // Evaluate PIT Void Status
            $isVoid = $this->isTransactionVoidAtCutoff($tx, $cutoffUtc);
            $amount = (float)$tx->total_amount;

            $outId = (int)$tx->outlet_id;
            $cashierKey = !empty($tx->cashier_name) ? trim($tx->cashier_name) : (!empty($tx->staff_id) ? "Staff #{$tx->staff_id}" : '[Kasir Tidak Tertera]');

            // Accumulate Outlet Stats
            if (!isset($outletStats[$outId])) {
                $outletStats[$outId] = [
                    'outlet_id'     => $outId,
                    'total_tx'      => 0,
                    'valid_tx'      => 0,
                    'void_tx'       => 0,
                    'total_nominal' => 0.0,
                    'void_nominal'  => 0.0,
                ];
            }
            $outletStats[$outId]['total_tx']++;
            $outletStats[$outId]['total_nominal'] += $amount;

            // Accumulate Cashier Stats
            if (!isset($cashierStats[$cashierKey])) {
                $cashierStats[$cashierKey] = [
                    'cashier_name'  => $cashierKey,
                    'staff_id'      => $tx->staff_id !== null ? (int)$tx->staff_id : null,
                    'total_tx'      => 0,
                    'valid_tx'      => 0,
                    'void_tx'       => 0,
                    'total_nominal' => 0.0,
                    'void_nominal'  => 0.0,
                ];
            }
            $cashierStats[$cashierKey]['total_tx']++;
            $cashierStats[$cashierKey]['total_nominal'] += $amount;

            if ($isVoid) {
                $outletStats[$outId]['void_tx']++;
                $outletStats[$outId]['void_nominal'] += $amount;
                $cashierStats[$cashierKey]['void_tx']++;
                $cashierStats[$cashierKey]['void_nominal'] += $amount;

                $reason = !empty($tx->cancel_reason) ? trim($tx->cancel_reason) : '[Tanpa Alasan]';
                $cancelReasons[$reason] = ($cancelReasons[$reason] ?? 0) + 1;

                $voidTransactionsList[] = [
                    'transaction_id' => (int)$tx->id,
                    'outlet_id'      => $outId,
                    'cashier'        => $cashierKey,
                    'total_amount'   => $amount,
                    'date'           => $localDate,
                    'cancel_reason'  => $reason,
                ];
            } else {
                $outletStats[$outId]['valid_tx']++;
                $cashierStats[$cashierKey]['valid_tx']++;
                $allAmounts[] = $amount;
            }

            if (isset($dateMatrix[$localDate])) {
                $dateMatrix[$localDate]['total_tx']++;
                $dateMatrix[$localDate]['total_nominal'] += $amount;
                if ($isVoid) {
                    $dateMatrix[$localDate]['void_tx']++;
                    $dateMatrix[$localDate]['void_nominal'] += $amount;
                } else {
                    $dateMatrix[$localDate]['valid_tx']++;
                    $dateMatrix[$localDate]['valid_nominal'] += $amount;
                }
                $dateMatrix[$localDate]['transactions'][] = $tx;
            }
        }

        // Fetch and Analyze Transaction Items
        $itemsData = $this->fetchAndAnalyzeItems($txIds, $userId, $cutoffUtc, $menuCache);

        // Attach item quantities to dateMatrix
        foreach ($itemsData['daily_qty_map'] as $dStr => $qtySum) {
            if (isset($dateMatrix[$dStr])) {
                $dateMatrix[$dStr]['total_qty'] += $qtySum;
            }
        }

        // Compute Overall Void Metrics
        $totalTxCount = count($txRecords);
        $totalVoidCount = count($voidTransactionsList);
        $totalValidCount = $totalTxCount - $totalVoidCount;
        $voidRatePct = $totalTxCount > 0 ? round(($totalVoidCount / $totalTxCount) * 100, 2) : 0.0;

        $totalNominal = array_sum(array_column($txRecords->all(), 'total_amount'));
        $voidNominal = array_sum(array_column($voidTransactionsList, 'total_amount'));
        $voidNominalRatePct = $totalNominal > 0 ? round(($voidNominal / $totalNominal) * 100, 2) : 0.0;

        $voidSeverity = $this->determineVoidSeverity($voidRatePct, $totalTxCount);

        // Compute Void Trend (Recent 14d vs Prior 14d)
        $voidTrend = $this->calculateVoidTrend($dateMatrix, $lookbackDays);

        // Statistical Baselines for Daily Volume & Extremes
        $dailyTxCounts = array_column($dateMatrix, 'valid_tx');
        $volumeBaseline = $this->calculateDistributionStats($dailyTxCounts);
        $amountBaseline = $this->calculateDistributionStats($allAmounts);
        $itemQtyBaseline = $this->calculateDistributionStats($itemsData['all_valid_qtys']);

        // Detect Daily Anomalies (Spikes, Drops, Void Spikes)
        $dailyAnomalies = $this->detectDailyAnomalies($dateMatrix, $volumeBaseline);

        // Detect Extreme Transaction Amounts
        $amountAnomalies = $this->detectExtremeTransactions($txRecords, $amountBaseline, $cutoffUtc);

        // Detect Unusual Item Quantities
        $quantityAnomalies = $this->detectUnusualItemQuantities($itemsData['raw_items'], $itemQtyBaseline, $cutoffUtc);

        // Format Cashier and Outlet Void Breakdowns
        $formattedOutletStats = [];
        foreach ($outletStats as $o) {
            $o['void_rate_pct'] = $o['total_tx'] > 0 ? round(($o['void_tx'] / $o['total_tx']) * 100, 2) : 0.0;
            $formattedOutletStats[] = $o;
        }
        usort($formattedOutletStats, fn($a, $b) => $b['void_tx'] <=> $a['void_tx']);

        $formattedCashierStats = [];
        foreach ($cashierStats as $c) {
            $c['void_rate_pct'] = $c['total_tx'] > 0 ? round(($c['void_tx'] / $c['total_tx']) * 100, 2) : 0.0;
            $formattedCashierStats[] = $c;
        }
        usort($formattedCashierStats, fn($a, $b) => $b['void_tx'] <=> $a['void_tx']);

        $formattedCancelReasons = [];
        foreach ($cancelReasons as $r => $cnt) {
            $formattedCancelReasons[] = [
                'reason' => $r,
                'count'  => $cnt,
                'pct'    => $totalVoidCount > 0 ? round(($cnt / $totalVoidCount) * 100, 2) : 0.0,
            ];
        }
        usort($formattedCancelReasons, fn($a, $b) => $b['count'] <=> $a['count']);

        // Overall Summary Status
        $totalAnomalies = count($dailyAnomalies) + count($amountAnomalies) + count($quantityAnomalies);
        $summaryStatus = self::STATUS_NORMAL;
        if ($voidSeverity === self::VOID_SEVERITY_CRITICAL || $totalAnomalies >= 5) {
            $summaryStatus = self::STATUS_ANOMALY;
        } elseif ($voidSeverity === self::VOID_SEVERITY_ELEVATED || $totalAnomalies >= 1) {
            $summaryStatus = self::STATUS_ELEVATED;
        }

        return [
            'metadata' => [
                'tenant_id'          => $userId,
                'outlet_id'          => $outletId,
                'anchor_date'        => $anchorDate,
                'start_date'         => $startDateStr,
                'end_date'           => $endDateStr,
                'lookback_days'      => $lookbackDays,
                'timezone'           => $primaryTz,
                'utc_start'          => $utcStart,
                'utc_end'            => $utcEnd,
                'cutoff_utc'         => $cutoffUtc,
            ],
            'summary' => [
                'status'                    => $summaryStatus,
                'total_anomalies_detected'  => $totalAnomalies,
                'daily_anomalies_count'     => count($dailyAnomalies),
                'amount_anomalies_count'    => count($amountAnomalies),
                'quantity_anomalies_count'  => count($quantityAnomalies),
                'void_severity'             => $voidSeverity,
            ],
            'void_analysis' => [
                'total_transactions'        => $totalTxCount,
                'valid_transactions'        => $totalValidCount,
                'void_transactions'         => $totalVoidCount,
                'void_rate_pct'             => $voidRatePct,
                'total_nominal'             => round($totalNominal, 2),
                'void_nominal'              => round($voidNominal, 2),
                'void_nominal_rate_pct'     => $voidNominalRatePct,
                'void_severity'             => $voidSeverity,
                'void_trend'                => $voidTrend,
                'void_by_outlet'            => $formattedOutletStats,
                'void_by_cashier'           => $formattedCashierStats,
                'void_by_menu'              => $itemsData['void_by_menu'],
                'unresolved_void_items'     => $itemsData['unresolved_void_items'],
                'cancel_reasons_breakdown'  => $formattedCancelReasons,
            ],
            'sales_anomalies' => [
                'daily_anomalies'           => $dailyAnomalies,
                'extreme_transactions'      => $amountAnomalies,
                'unusual_quantities'        => $quantityAnomalies,
            ],
            'baselines' => [
                'daily_volume' => [
                    'median'    => $volumeBaseline['median'],
                    'mean'      => $volumeBaseline['mean'],
                    'stddev'    => $volumeBaseline['stddev'],
                    'p75'       => $volumeBaseline['p75'],
                    'iqr'       => $volumeBaseline['iqr'],
                    'spike_threshold' => $volumeBaseline['p75'] + (1.5 * $volumeBaseline['iqr']),
                ],
                'transaction_amount' => [
                    'p25'       => $amountBaseline['p25'],
                    'median'    => $amountBaseline['median'],
                    'p75'       => $amountBaseline['p75'],
                    'iqr'       => $amountBaseline['iqr'],
                    'extreme_threshold' => $amountBaseline['p75'] + (3.0 * $amountBaseline['iqr']),
                ],
                'item_quantity' => [
                    'median'    => $itemQtyBaseline['median'],
                    'p75'       => $itemQtyBaseline['p75'],
                    'iqr'       => $itemQtyBaseline['iqr'],
                    'unusual_threshold' => max(10.0, $itemQtyBaseline['p75'] + (3.0 * $itemQtyBaseline['iqr'])),
                ],
            ],
            'daily_time_series' => array_values(array_map(function($d) {
                return [
                    'date'          => $d['date'],
                    'total_tx'      => $d['total_tx'],
                    'valid_tx'      => $d['valid_tx'],
                    'void_tx'       => $d['void_tx'],
                    'void_rate_pct' => $d['total_tx'] > 0 ? round(($d['void_tx'] / $d['total_tx']) * 100, 2) : 0.0,
                    'valid_nominal' => round($d['valid_nominal'], 2),
                    'void_nominal'  => round($d['void_nominal'], 2),
                    'total_qty'     => round($d['total_qty'], 2),
                ];
            }, $dateMatrix)),
        ];
    }

    /**
     * Fetch transactions within UTC window.
     */
    protected function fetchTransactions(
        int $userId,
        ?int $outletId,
        string $utcStart,
        string $utcEnd,
        ?string $cutoffUtc
    ) {
        $query = DB::table('transactions as t')
            ->where('t.user_id', $userId)
            ->select([
                't.id',
                't.user_id',
                't.outlet_id',
                't.staff_id',
                't.cashier_name',
                't.total_amount',
                't.status',
                't.cancel_reason',
                't.created_at_device',
                't.created_at',
                't.updated_at',
            ]);

        if ($outletId !== null) {
            $query->where('t.outlet_id', $outletId);
        }

        if (Schema::hasColumn('transactions', 'is_training')) {
            $query->where('t.is_training', 0);
        }

        // Half-open date range on created_at_device (fallback created_at)
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

        // PIT Cutoff Filter
        if ($cutoffUtc !== null) {
            $query->where(function ($q) use ($cutoffUtc) {
                $q->where(function ($sub) use ($cutoffUtc) {
                    $sub->whereNotNull('t.created_at_device')
                        ->where('t.created_at_device', '<=', $cutoffUtc);
                })->orWhere(function ($sub) use ($cutoffUtc) {
                    $sub->whereNull('t.created_at_device')
                        ->where('t.created_at', '<=', $cutoffUtc);
                });
            });
        }

        return $query->orderBy('t.created_at_device', 'asc')->get();
    }

    /**
     * Check if transaction is void at the PIT cutoff point.
     */
    public function isTransactionVoidAtCutoff($tx, ?string $cutoffUtc): bool
    {
        $status = (string)$tx->status;
        $isCancelledStatus = in_array($status, self::CANCEL_STATUSES, true);

        if (!$isCancelledStatus) {
            return false;
        }

        // If cutoff is specified, check if cancellation happened after cutoff
        if ($cutoffUtc !== null && !empty($tx->updated_at)) {
            $updatedAtUtc = Carbon::parse($tx->updated_at, 'UTC')->format('Y-m-d H:i:s');
            $cutoffParsed = Carbon::parse($cutoffUtc, 'UTC')->format('Y-m-d H:i:s');
            if ($updatedAtUtc > $cutoffParsed) {
                // Future cancellation! At cutoff time, it was NOT cancelled yet.
                return false;
            }
        }

        return true;
    }

    /**
     * Fetch and analyze transaction items for quantity extremes and menu void attribution.
     */
    protected function fetchAndAnalyzeItems(
        array $txIds,
        int $userId,
        ?string $cutoffUtc,
        array $menuCache
    ): array {
        if (empty($txIds)) {
            return [
                'raw_items'              => [],
                'all_valid_qtys'         => [],
                'daily_qty_map'          => [],
                'void_by_menu'           => [],
                'unresolved_void_items'  => 0,
            ];
        }

        $query = DB::table('transaction_items as ti')
            ->join('transactions as t', 'ti.transaction_id', '=', 't.id')
            ->whereIn('ti.transaction_id', $txIds)
            ->where('ti.user_id', $userId)
            ->select([
                'ti.id as item_id',
                'ti.transaction_id',
                'ti.menu_id',
                'ti.menu_name',
                'ti.qty',
                'ti.price',
                'ti.status as item_status',
                'ti.created_at as item_created_at',
                'ti.updated_at as item_updated_at',
                't.outlet_id',
                't.status as tx_status',
                't.updated_at as tx_updated_at',
                't.created_at_device',
                't.created_at as tx_created_at',
            ]);

        if (Schema::hasColumn('transaction_items', 'is_training')) {
            $query->where('ti.is_training', 0);
        }

        $rows = $query->get();

        $allValidQtys = [];
        $dailyQtyMap = [];
        $voidByMenu = [];
        $unresolvedVoidItems = 0;
        $primaryTz = $this->resolvePrimaryTimezone($userId, null);

        foreach ($rows as $row) {
            $deviceDate = !empty($row->created_at_device) ? $row->created_at_device : $row->tx_created_at;
            $localDate = Carbon::parse($deviceDate, 'UTC')->setTimezone($primaryTz)->format('Y-m-d');

            // Evaluate PIT void status for item
            $isTxVoid = in_array((string)$row->tx_status, self::CANCEL_STATUSES, true);
            if ($isTxVoid && $cutoffUtc !== null && !empty($row->tx_updated_at)) {
                if (Carbon::parse($row->tx_updated_at, 'UTC')->format('Y-m-d H:i:s') > Carbon::parse($cutoffUtc, 'UTC')->format('Y-m-d H:i:s')) {
                    $isTxVoid = false;
                }
            }

            $isItemVoid = in_array((string)$row->item_status, self::CANCEL_STATUSES, true);
            if ($isItemVoid && $cutoffUtc !== null && !empty($row->item_updated_at)) {
                if (Carbon::parse($row->item_updated_at, 'UTC')->format('Y-m-d H:i:s') > Carbon::parse($cutoffUtc, 'UTC')->format('Y-m-d H:i:s')) {
                    $isItemVoid = false;
                }
            }

            $isEffectiveVoid = $isTxVoid || $isItemVoid;
            $qty = (float)$row->qty;
            $nominal = $qty * (float)$row->price;

            if ($isEffectiveVoid) {
                // Resolve Menu Identity
                $resolution = $this->getForecastService()->resolveMenuIdentity(
                    $userId,
                    $row->outlet_id !== null ? (int)$row->outlet_id : null,
                    [
                        'menu_id'   => $row->menu_id,
                        'name'      => $row->menu_name,
                        'menu_name' => $row->menu_name,
                    ],
                    $cutoffUtc,
                    $menuCache
                );

                if (!empty($resolution['resolved_menu_id'])) {
                    $mId = (int)$resolution['resolved_menu_id'];
                    if (!isset($voidByMenu[$mId])) {
                        $voidByMenu[$mId] = [
                            'menu_id'      => $mId,
                            'menu_name'    => $menuCache['by_id'][$mId]->name ?? ($row->menu_name ?? "Menu #{$mId}"),
                            'void_qty'     => 0.0,
                            'void_nominal' => 0.0,
                            'void_count'   => 0,
                        ];
                    }
                    $voidByMenu[$mId]['void_qty'] += $qty;
                    $voidByMenu[$mId]['void_nominal'] += $nominal;
                    $voidByMenu[$mId]['void_count']++;
                } else {
                    $unresolvedVoidItems++;
                }
            } else {
                $allValidQtys[] = $qty;
                $dailyQtyMap[$localDate] = ($dailyQtyMap[$localDate] ?? 0.0) + $qty;
            }
        }

        $formattedVoidByMenu = array_values($voidByMenu);
        usort($formattedVoidByMenu, fn($a, $b) => $b['void_qty'] <=> $a['void_qty']);

        return [
            'raw_items'              => $rows->all(),
            'all_valid_qtys'         => $allValidQtys,
            'daily_qty_map'          => $dailyQtyMap,
            'void_by_menu'           => $formattedVoidByMenu,
            'unresolved_void_items'  => $unresolvedVoidItems,
        ];
    }

    /**
     * Calculate statistical baselines (median, IQR, stddev, percentiles).
     */
    public function calculateDistributionStats(array $values): array
    {
        $count = count($values);
        if ($count === 0) {
            return [
                'count'   => 0,
                'min'     => 0.0,
                'max'     => 0.0,
                'mean'    => 0.0,
                'median'  => 0.0,
                'stddev'  => 0.0,
                'p25'     => 0.0,
                'p75'     => 0.0,
                'iqr'     => 0.0,
            ];
        }

        sort($values, SORT_NUMERIC);
        $min = (float)$values[0];
        $max = (float)$values[$count - 1];
        $mean = array_sum($values) / $count;

        // Standard deviation
        $sumSquareDiffs = 0.0;
        foreach ($values as $v) {
            $sumSquareDiffs += pow((float)$v - $mean, 2);
        }
        $stddev = sqrt($sumSquareDiffs / $count);

        $p25Index = (int)floor($count * 0.25);
        $p50Index = (int)floor($count * 0.50);
        $p75Index = (int)floor($count * 0.75);

        $p25 = (float)$values[$p25Index];
        $median = (float)$values[$p50Index];
        $p75 = (float)$values[$p75Index];
        $iqr = max(0.0, $p75 - $p25);

        return [
            'count'   => $count,
            'min'     => round($min, 2),
            'max'     => round($max, 2),
            'mean'    => round($mean, 2),
            'median'  => round($median, 2),
            'stddev'  => round($stddev, 2),
            'p25'     => round($p25, 2),
            'p75'     => round($p75, 2),
            'iqr'     => round($iqr, 2),
        ];
    }

    /**
     * Detect daily anomalies (Sales Spikes, Sales Drops, Void Spikes).
     */
    protected function detectDailyAnomalies(array $dateMatrix, array $volumeBaseline): array
    {
        $anomalies = [];
        $median = $volumeBaseline['median'];
        $iqr = $volumeBaseline['iqr'];
        $mean = $volumeBaseline['mean'];
        $stddev = $volumeBaseline['stddev'];

        // Extract active trading days (valid_tx > 0) to evaluate operating baselines
        $activeTxCounts = [];
        foreach ($dateMatrix as $d) {
            if ((int)$d['valid_tx'] > 0) {
                $activeTxCounts[] = (int)$d['valid_tx'];
            }
        }
        $activeBaseline = $this->calculateDistributionStats($activeTxCounts);
        $activeMedian = $activeBaseline['median'];

        // Tukey upper threshold for daily tx spike
        $spikeThreshold = max(5, $volumeBaseline['p75'] + (1.5 * $iqr));

        // Threshold for daily drop: evaluate against active trading median if available
        $effectiveMedian = count($activeTxCounts) >= 5 ? $activeMedian : $median;
        $dropThreshold = max(0, $effectiveMedian * 0.35);

        foreach ($dateMatrix as $dStr => $d) {
            $validTx = (int)$d['valid_tx'];
            $totalTx = (int)$d['total_tx'];
            $voidTx = (int)$d['void_tx'];
            $voidRate = $totalTx > 0 ? round(($voidTx / $totalTx) * 100, 2) : 0.0;

            // 1. VOID SPIKE (> 15% void rate with at least 3 voids, or > 30% with 2 voids)
            if (($voidRate >= 15.0 && $voidTx >= 3) || ($voidRate >= 30.0 && $voidTx >= 2)) {
                $anomalies[] = [
                    'date'           => $dStr,
                    'anomaly_type'   => self::ANOMALY_VOID_SPIKE,
                    'severity'       => $voidRate >= 30.0 ? 'HIGH' : 'MEDIUM',
                    'actual_value'   => "{$voidRate}% ({$voidTx}/{$totalTx} tx)",
                    'baseline_value' => "<= 5.0%",
                    'description'    => "Lonjakan rasio pembatalan/void mencapai {$voidRate}% pada tanggal {$dStr}.",
                ];
            }

            // 2. SALES SPIKE (Tx count exceeds Tukey upper fence & at least 1.5x median)
            if ($validTx >= $spikeThreshold && $validTx >= max(5, $effectiveMedian * 1.5)) {
                $anomalies[] = [
                    'date'           => $dStr,
                    'anomaly_type'   => self::ANOMALY_SALES_SPIKE,
                    'severity'       => $validTx >= ($effectiveMedian * 2.0) ? 'HIGH' : 'MEDIUM',
                    'actual_value'   => "{$validTx} transaksi",
                    'baseline_value' => "Median {$effectiveMedian}, P75 {$volumeBaseline['p75']}",
                    'description'    => "Lonjakan volume transaksi ({$validTx} tx) melampaui batas normal pada tanggal {$dStr}.",
                ];
            }

            // 3. SALES DROP (Tx count drops significantly below normal when baseline is established)
            if ($effectiveMedian >= 8 && $validTx <= $dropThreshold && $validTx > 0) {
                $anomalies[] = [
                    'date'           => $dStr,
                    'anomaly_type'   => self::ANOMALY_SALES_DROP,
                    'severity'       => $validTx <= ($effectiveMedian * 0.20) ? 'HIGH' : 'MEDIUM',
                    'actual_value'   => "{$validTx} transaksi",
                    'baseline_value' => "Median Operasional {$effectiveMedian}",
                    'description'    => "Penurunan tajam volume transaksi ({$validTx} tx vs normal {$effectiveMedian} tx) pada tanggal {$dStr}.",
                ];
            }
        }

        return $anomalies;
    }

    /**
     * Detect extreme single transaction amounts (Tukey's extreme upper fence: Q3 + 3.0 * IQR).
     */
    protected function detectExtremeTransactions(
        $txRecords,
        array $amountBaseline,
        ?string $cutoffUtc
    ): array {
        $anomalies = [];
        $extremeThreshold = max(500000.0, $amountBaseline['p75'] + (3.0 * $amountBaseline['iqr']));

        foreach ($txRecords as $tx) {
            $isVoid = $this->isTransactionVoidAtCutoff($tx, $cutoffUtc);
            if ($isVoid) {
                continue;
            }

            $amount = (float)$tx->total_amount;
            if ($amount >= $extremeThreshold) {
                $anomalies[] = [
                    'transaction_id'    => (int)$tx->id,
                    'outlet_id'         => (int)$tx->outlet_id,
                    'cashier'           => !empty($tx->cashier_name) ? trim($tx->cashier_name) : "[Staff #{$tx->staff_id}]",
                    'amount'            => $amount,
                    'extreme_threshold' => $extremeThreshold,
                    'date'              => $tx->created_at_device ?? $tx->created_at,
                    'anomaly_type'      => self::ANOMALY_EXTREME_AMOUNT,
                    'description'       => "Transaksi bernilai ekstrem: Rp " . number_format($amount, 0, ',', '.') . " (ambang batas: Rp " . number_format($extremeThreshold, 0, ',', '.') . ").",
                ];
            }
        }

        usort($anomalies, fn($a, $b) => $b['amount'] <=> $a['amount']);
        return $anomalies;
    }

    /**
     * Detect unusual item quantities (Tukey's fence or bulk threshold).
     */
    protected function detectUnusualItemQuantities(
        array $rawItems,
        array $itemQtyBaseline,
        ?string $cutoffUtc
    ): array {
        $anomalies = [];
        $unusualThreshold = max(20.0, $itemQtyBaseline['p75'] + (3.0 * max(1.0, $itemQtyBaseline['iqr'])));

        foreach ($rawItems as $item) {
            $qty = (float)$item->qty;
            if ($qty >= $unusualThreshold) {
                $anomalies[] = [
                    'item_id'           => (int)$item->item_id,
                    'transaction_id'    => (int)$item->transaction_id,
                    'menu_name'         => $item->menu_name ?? "Item #{$item->menu_id}",
                    'qty'               => $qty,
                    'price'             => (float)$item->price,
                    'total_line'        => round($qty * (float)$item->price, 2),
                    'unusual_threshold' => $unusualThreshold,
                    'anomaly_type'      => self::ANOMALY_UNUSUAL_QUANTITY,
                    'description'       => "Pesanan kuantitas tidak biasa ({$qty} unit) untuk item '{$item->menu_name}'.",
                ];
            }
        }

        usort($anomalies, fn($a, $b) => $b['qty'] <=> $a['qty']);
        return $anomalies;
    }

    /**
     * Determine Void Severity tier.
     */
    protected function determineVoidSeverity(float $voidRatePct, int $totalTx): string
    {
        if ($totalTx < 10) {
            return self::VOID_SEVERITY_LOW;
        }

        if ($voidRatePct > 15.0) {
            return self::VOID_SEVERITY_CRITICAL;
        } elseif ($voidRatePct > 5.0) {
            return self::VOID_SEVERITY_ELEVATED;
        } elseif ($voidRatePct > 2.0) {
            return self::VOID_SEVERITY_MODERATE;
        }

        return self::VOID_SEVERITY_LOW;
    }

    /**
     * Calculate Void Rate Trend between recent and prior halves of the window.
     */
    protected function calculateVoidTrend(array $dateMatrix, int $lookbackDays): array
    {
        $dates = array_keys($dateMatrix);
        $totalDays = count($dates);
        if ($totalDays < 14) {
            return [
                'trend_direction'    => 'INSUFFICIENT_DATA',
                'recent_void_rate'   => 0.0,
                'prior_void_rate'    => 0.0,
                'delta'              => 0.0,
            ];
        }

        $half = (int)floor($totalDays / 2);
        $priorDates = array_slice($dates, 0, $half);
        $recentDates = array_slice($dates, $half);

        $priorTotal = 0;
        $priorVoid = 0;
        foreach ($priorDates as $d) {
            $priorTotal += $dateMatrix[$d]['total_tx'];
            $priorVoid += $dateMatrix[$d]['void_tx'];
        }

        $recentTotal = 0;
        $recentVoid = 0;
        foreach ($recentDates as $d) {
            $recentTotal += $dateMatrix[$d]['total_tx'];
            $recentVoid += $dateMatrix[$d]['void_tx'];
        }

        $priorRate = $priorTotal > 0 ? round(($priorVoid / $priorTotal) * 100, 2) : 0.0;
        $recentRate = $recentTotal > 0 ? round(($recentVoid / $recentTotal) * 100, 2) : 0.0;
        $delta = round($recentRate - $priorRate, 2);

        $direction = 'STABLE';
        if ($recentRate >= 5.0 && $delta >= 2.0) {
            $direction = 'VOID_SPIKE';
        } elseif ($delta <= -2.0) {
            $direction = 'DECLINING';
        }

        return [
            'trend_direction'    => $direction,
            'recent_void_rate'   => $recentRate,
            'prior_void_rate'    => $priorRate,
            'delta'              => $delta,
            'recent_window_days' => count($recentDates),
            'prior_window_days'  => count($priorDates),
        ];
    }

    /**
     * Resolve primary timezone.
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
}
