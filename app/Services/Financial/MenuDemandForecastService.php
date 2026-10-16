<?php

namespace App\Services\Financial;

use App\Helpers\TimezoneContract;
use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * MenuDemandForecastService
 *
 * Deterministic Menu Demand Intelligence Engine for Kaching POS.
 *
 * Architectural Invariants:
 * 1. ZERO lookahead leakage (point-in-time safe cutoff for transactions and status mutations).
 * 2. Strict tenant and outlet isolation (fail-closed, zero cross-tenant lookup).
 * 3. Exact tenant-scoped menu identity resolution (COALESCE direct ID, exact name match; zero fuzzy/LIKE).
 * 4. Deterministic DOW Median and WMA-14 baselines with data-driven Payday adjustments.
 * 5. Finished-menu stock monitoring only (zero fabricated raw-material BOM predictions).
 * 6. High-performance 2-query architecture (zero N+1 query loops).
 * 7. Zero database mutation (murni kalkulasi memori dan read-only queries).
 */
class MenuDemandForecastService
{
    // Window and Sufficiency Constants
    public const DOW_CALENDAR_WINDOW_DAYS = 28;
    public const DOW_MIN_ACTIVE_DAYS      = 14;
    public const WMA_CALENDAR_WINDOW_DAYS = 14;
    public const WMA_MIN_ACTIVE_DAYS      = 7;
    public const PAYDAY_WINDOW_DAYS       = 60;
    public const PAYDAY_MIN_OBSERVATIONS  = 7;

    // History Classification Tiers
    public const TIER_STRONG_HISTORY       = 'STRONG_HISTORY';
    public const TIER_INTERMITTENT_HISTORY = 'INTERMITTENT_HISTORY';
    public const TIER_INSUFFICIENT_HISTORY = 'INSUFFICIENT_HISTORY';

    // Menu Identity Resolution Sources
    public const RES_DIRECT_MENU_ID   = 'TRANSACTION_ITEM_MENU_ID';
    public const RES_EXACT_TENANT_NAME = 'EXACT_TENANT_MENU_NAME';
    public const RES_UNRESOLVED        = 'UNRESOLVED';

    // Stock Monitoring States
    public const STOCK_UNTRACKED  = 'STOCK_UNTRACKED';
    public const STOCK_ANOMALOUS  = 'STOCK_ANOMALOUS';
    public const STOCK_TRACKED    = 'STOCK_TRACKED';

    // Evaluation and Backtest Statuses (Phase 3.4C)
    public const BACKTEST_STATUS_SUCCESS           = 'SUCCESS';
    public const BACKTEST_STATUS_INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';
    public const BACKTEST_STATUS_NO_ACTUAL_SALES   = 'NO_ACTUAL_SALES';
    public const BACKTEST_VERDICT_SUPERIOR         = 'EMPIRICALLY_SUPERIOR';
    public const BACKTEST_VERDICT_COMPARABLE       = 'EMPIRICALLY_COMPARABLE';
    public const BACKTEST_VERDICT_INFERIOR         = 'EMPIRICALLY_INFERIOR';

    /**
     * @var UnitOfMeasureService
     */
    protected UnitOfMeasureService $uomService;

    /**
     * Cache for resolved primary timezones to avoid redundant DB roundtrips.
     */
    protected array $timezoneCache = [];

    public function __construct(?UnitOfMeasureService $uomService = null)
    {
        $this->uomService = $uomService ?? app(UnitOfMeasureService::class);
    }

    /**
     * Resolve menu identity from a single transaction item row.
     *
     * @param int         $userId
     * @param int|null    $outletId
     * @param array       $itemRow   Keys: menu_id, menu_name
     * @param string|null $cutoffUtc Optional cutoff timestamp
     * @param array|null  $menuCache Optional preloaded menu maps: ['byId' => ..., 'byName' => ..., 'ambiguous' => ...]
     * @return array
     */
    public function resolveMenuIdentity(
        int $userId,
        ?int $outletId,
        array $itemRow,
        ?string $cutoffUtc = null,
        ?array $menuCache = null
    ): array {
        if ($menuCache === null) {
            $menuCache = $this->preloadMenuCache($userId, $outletId);
        }

        $directMenuId = isset($itemRow['menu_id']) && $itemRow['menu_id'] !== '' && $itemRow['menu_id'] !== null
            ? (int) $itemRow['menu_id']
            : null;

        // Priority A: Direct menu_id populated and valid for tenant
        if ($directMenuId !== null && $directMenuId > 0) {
            if (isset($menuCache['byId'][$directMenuId])) {
                return [
                    'resolved_menu_id'  => $directMenuId,
                    'resolution_source' => self::RES_DIRECT_MENU_ID,
                    'confidence'        => 1.0,
                    'status'            => 'RESOLVED',
                    'reason'            => 'Menu ID valid dan terverifikasi pada tenant.',
                ];
            }

            return [
                'resolved_menu_id'  => null,
                'resolution_source' => self::RES_UNRESOLVED,
                'confidence'        => 0.0,
                'status'            => 'UNRESOLVED',
                'reason'            => 'Menu ID tidak ditemukan pada katalog tenant (cross-tenant atau ID tidak sah).',
            ];
        }

        // Priority B: menu_id NULL -> Exact tenant-scoped menu_name match
        $rawName = isset($itemRow['menu_name']) ? (string) $itemRow['menu_name'] : '';
        $cleanName = strtolower((string) preg_replace('/\s+/', ' ', trim($rawName)));

        if ($cleanName === '') {
            return [
                'resolved_menu_id'  => null,
                'resolution_source' => self::RES_UNRESOLVED,
                'confidence'        => 0.0,
                'status'            => 'UNRESOLVED',
                'reason'            => 'Nama menu kosong atau null.',
            ];
        }

        // Defense: Check for duplicate exact names within the tenant
        if (!empty($menuCache['ambiguous'][$cleanName])) {
            return [
                'resolved_menu_id'  => null,
                'resolution_source' => self::RES_UNRESOLVED,
                'confidence'        => 0.0,
                'status'            => 'UNRESOLVED',
                'reason'            => 'Duplikasi nama menu terdeteksi pada katalog tenant (ambigu, fail-closed).',
            ];
        }

        if (isset($menuCache['byName'][$cleanName])) {
            $resolvedId = (int) $menuCache['byName'][$cleanName];
            return [
                'resolved_menu_id'  => $resolvedId,
                'resolution_source' => self::RES_EXACT_TENANT_NAME,
                'confidence'        => 0.95,
                'status'            => 'RESOLVED',
                'reason'            => 'Nama menu cocok tepat secara eksak dengan master menu tenant.',
            ];
        }

        // Priority C: Zero match
        return [
            'resolved_menu_id'  => null,
            'resolution_source' => self::RES_UNRESOLVED,
            'confidence'        => 0.0,
            'status'            => 'UNRESOLVED',
            'reason'            => 'Nama menu tidak cocok dengan item apa pun pada katalog tenant.',
        ];
    }

    /**
     * Build point-in-time safe daily demand vectors for requested menus across calendar days.
     *
     * @param int         $userId
     * @param int|null    $outletId
     * @param string      $startDate 'Y-m-d'
     * @param string      $endDate   'Y-m-d'
     * @param string|null $cutoffUtc Optional cutoff timestamp in UTC
     * @param array       $menuIds   Optional menu IDs to filter
     * @param string|null $timezone  Optional timezone override
     * @return array
     */
    public function buildDailyDemand(
        int $userId,
        ?int $outletId,
        string $startDate,
        string $endDate,
        ?string $cutoffUtc = null,
        array $menuIds = [],
        ?string $timezone = null,
        ?array $menuCache = null
    ): array {
        // Defensive: if caller passed an options array as 6th argument
        if (!empty($menuIds) && !array_is_list($menuIds)) {
            $options = $menuIds;
            $menuIds = isset($options['menu_ids']) ? (array) $options['menu_ids'] : [];
            $timezone = $timezone ?? ($options['timezone'] ?? null);
            $menuCache = $menuCache ?? ($options['menu_cache'] ?? null);
        }

        $primaryTz = $timezone ?? $this->resolvePrimaryTimezone($userId, $outletId);
        $dtz = new DateTimeZone($primaryTz);

        // Preload tenant menu cache (Query 1 if not passed)
        $menuCache = $menuCache ?? $this->preloadMenuCache($userId, $outletId);
        $targetMenuIds = !empty($menuIds) ? array_map('intval', $menuIds) : array_keys($menuCache['byId']);

        // Build continuous calendar days array
        $calendarDays = $this->buildCalendarDaysList($startDate, $endDate, $primaryTz);

        // Initialize daily matrix for each target menu with 0.0
        $dailyMatrix = [];
        foreach ($targetMenuIds as $mId) {
            $dailyMatrix[$mId] = [];
            foreach ($calendarDays as $day) {
                $dailyMatrix[$mId][$day] = 0.0;
            }
        }

        // Compute half-open UTC query bounds
        [$utcStart, $utcEnd] = TimezoneContract::businessDateRangeToUtcRange($startDate, $endDate, $primaryTz);

        // Query transactions ledger (Query 2)
        $txQuery = DB::table('transactions as t')
            ->join('transaction_items as ti', 'ti.transaction_id', '=', 't.id')
            ->where('t.user_id', $userId)
            ->where('t.created_at_device', '>=', $utcStart)
            ->where('t.created_at_device', '<', $utcEnd);

        if ($outletId !== null) {
            $txQuery->where('t.outlet_id', $outletId);
        }

        // Point-in-time safety filtering at SQL level
        if ($cutoffUtc !== null) {
            $txQuery->where('t.created_at_device', '<=', $cutoffUtc);
            $txQuery->where(function ($q) use ($cutoffUtc) {
                $q->whereNotIn('t.status', ['Batal', 'Refund'])
                  ->orWhere('t.updated_at', '>', $cutoffUtc);
            });
            $txQuery->where(function ($q) use ($cutoffUtc) {
                $q->whereNotIn('ti.status', ['Batal', 'Refund'])
                  ->orWhere('ti.updated_at', '>', $cutoffUtc)
                  ->orWhere(function ($sub) use ($cutoffUtc) {
                      $sub->whereNull('ti.updated_at')
                          ->where('t.updated_at', '>', $cutoffUtc);
                  })
                  ->orWhereNull('ti.status');
            });
        } else {
            $txQuery->whereNotIn('t.status', ['Batal', 'Refund']);
            $txQuery->where(function ($q) {
                $q->whereNotIn('ti.status', ['Batal', 'Refund'])
                  ->orWhereNull('ti.status');
            });
        }

        $rows = $txQuery->select([
            't.id as tx_id',
            't.user_id',
            't.outlet_id',
            't.created_at_device',
            't.status as tx_status',
            't.updated_at as tx_updated_at',
            'ti.id as item_id',
            'ti.menu_id',
            'ti.menu_name',
            'ti.qty',
            'ti.status as item_status',
            'ti.updated_at as item_updated_at',
        ])->get();

        $unresolvedCount = 0;
        $processedTxIds = [];

        foreach ($rows as $row) {
            // Memory-level point-in-time double check (defense against driver variance)
            if ($cutoffUtc !== null) {
                if ($row->created_at_device > $cutoffUtc) {
                    continue;
                }

                $txUpdated = $row->tx_updated_at;
                $isTxCancelledAtCutoff = in_array($row->tx_status, ['Batal', 'Refund'], true)
                    && ($txUpdated !== null && $txUpdated <= $cutoffUtc);

                if ($isTxCancelledAtCutoff) {
                    continue;
                }

                $itemUpdated = $row->item_updated_at ?? $row->tx_updated_at;
                $isItemCancelledAtCutoff = in_array($row->item_status, ['Batal', 'Refund'], true)
                    && ($itemUpdated !== null && $itemUpdated <= $cutoffUtc);

                if ($isItemCancelledAtCutoff) {
                    continue;
                }
            }

            // Resolve menu identity
            $resolution = $this->resolveMenuIdentity($userId, $outletId, [
                'menu_id'   => $row->menu_id,
                'menu_name' => $row->menu_name,
            ], $cutoffUtc, $menuCache);

            $resolvedMenuId = $resolution['resolved_menu_id'];
            if ($resolvedMenuId === null || !isset($dailyMatrix[$resolvedMenuId])) {
                $unresolvedCount++;
                continue;
            }

            // Map transaction timestamp to local business date
            $dtUtc = new DateTimeImmutable($row->created_at_device, new DateTimeZone('UTC'));
            $dtLocal = $dtUtc->setTimezone($dtz);
            $businessDate = $dtLocal->format('Y-m-d');

            if (isset($dailyMatrix[$resolvedMenuId][$businessDate])) {
                $dailyMatrix[$resolvedMenuId][$businessDate] += (float) $row->qty;
            }

            $processedTxIds[$row->tx_id] = true;
        }

        // Compile canonical flat records
        $canonicalRecords = [];
        foreach ($dailyMatrix as $mId => $days) {
            foreach ($days as $date => $qty) {
                $canonicalRecords[] = [
                    'date'    => $date,
                    'menu_id' => $mId,
                    'qty'     => round($qty, 2),
                ];
            }
        }

        return [
            'status'                   => 'SUCCESS',
            'start_date'               => $startDate,
            'end_date'                 => $endDate,
            'calendar_days_count'      => count($calendarDays),
            'total_transactions_count' => count($processedTxIds),
            'unresolved_items_count'   => $unresolvedCount,
            'records'                  => $canonicalRecords,
            'by_menu'                  => $dailyMatrix,
        ];
    }

    /**
     * Classify historical demand vector into sufficiency tiers.
     *
     * @param array $dailyVector Array of 'YYYY-MM-DD' => float qty
     * @param int   $totalHistoryDays Total calendar days in window (e.g. 28)
     * @return array
     */
    public function classifyHistory(array $dailyVector, int $totalHistoryDays): array
    {
        $activeSalesDays = 0;
        $zeroSalesDays = 0;
        $totalQty = 0.0;

        foreach ($dailyVector as $qty) {
            $val = (float) $qty;
            $totalQty += $val;
            if ($val > 0.0) {
                $activeSalesDays++;
            } else {
                $zeroSalesDays++;
            }
        }

        if ($totalHistoryDays < self::WMA_CALENDAR_WINDOW_DAYS) {
            return [
                'tier'                 => self::TIER_INSUFFICIENT_HISTORY,
                'total_history_days'   => $totalHistoryDays,
                'active_sales_days'    => $activeSalesDays,
                'zero_sales_days'      => $zeroSalesDays,
                'total_historical_qty' => round($totalQty, 2),
                'reason'               => "Rentang waktu historis baru {$totalHistoryDays} hari kalender (minimal 14 hari dibutuhkan).",
            ];
        }

        if ($activeSalesDays < self::WMA_MIN_ACTIVE_DAYS) {
            return [
                'tier'                 => self::TIER_INSUFFICIENT_HISTORY,
                'total_history_days'   => $totalHistoryDays,
                'active_sales_days'    => $activeSalesDays,
                'zero_sales_days'      => $zeroSalesDays,
                'total_historical_qty' => round($totalQty, 2),
                'reason'               => "Hari aktif penjualan hanya {$activeSalesDays} hari dalam {$totalHistoryDays} hari kalender terakhir (minimal 7 hari dibutuhkan).",
            ];
        }

        if ($activeSalesDays >= self::DOW_MIN_ACTIVE_DAYS && $totalHistoryDays >= self::DOW_CALENDAR_WINDOW_DAYS) {
            return [
                'tier'                 => self::TIER_STRONG_HISTORY,
                'total_history_days'   => $totalHistoryDays,
                'active_sales_days'    => $activeSalesDays,
                'zero_sales_days'      => $zeroSalesDays,
                'total_historical_qty' => round($totalQty, 2),
                'reason'               => "Riwayat penjualan kuat ({$activeSalesDays} hari aktif dalam {$totalHistoryDays} hari kalender).",
            ];
        }

        return [
            'tier'                 => self::TIER_INTERMITTENT_HISTORY,
            'total_history_days'   => $totalHistoryDays,
            'active_sales_days'    => $activeSalesDays,
            'zero_sales_days'      => $zeroSalesDays,
            'total_historical_qty' => round($totalQty, 2),
            'reason'               => "Riwayat penjualan tergolong intermiten/volume rendah ({$activeSalesDays} hari aktif dalam {$totalHistoryDays} hari kalender).",
        ];
    }

    /**
     * Calculate data-driven payday multiplier from historical observations before cutoff.
     *
     * @param array  $historicalDailyQuantities 'YYYY-MM-DD' => float
     * @param string $anchorDate
     * @param string $targetDate
     * @param string $primaryTz
     * @return array
     */
    public function calculatePaydayMultiplier(
        array $historicalDailyQuantities,
        string $anchorDate,
        string $targetDate,
        string $primaryTz = 'Asia/Jakarta'
    ): array {
        $targetDom = (int) Carbon::parse($targetDate, $primaryTz)->day;
        $isTargetPayday = ($targetDom >= 25);

        // Filter historical dates strictly before anchorDate
        // Guard against counting zero-padded days before menu existed/started selling
        ksort($historicalDailyQuantities);
        $firstActiveDate = null;
        foreach ($historicalDailyQuantities as $d => $q) {
            if ((float) $q > 0.0) {
                $firstActiveDate = $d;
                break;
            }
        }

        if ($firstActiveDate === null) {
            return [
                'status'                => 'INSUFFICIENT_PAYDAY_EVIDENCE',
                'is_target_payday'      => $isTargetPayday,
                'payday_observations'   => 0,
                'regular_observations'  => 0,
                'payday_avg_qty'        => 0.0,
                'regular_avg_qty'       => 0.0,
                'raw_multiplier'        => null,
                'calculated_multiplier' => null,
                'applied_multiplier'    => 1.0,
                'reason'                => 'Tidak ada riwayat penjualan aktif pada rentang observasi.',
            ];
        }

        $paydayObs = [];
        $regularObs = [];

        foreach ($historicalDailyQuantities as $date => $qty) {
            if ($date >= $anchorDate) {
                continue; // Guard: zero lookahead leakage into forecast period
            }
            if ($date < $firstActiveDate) {
                continue; // Do not treat pre-introduction zero-padding as valid historical observations
            }

            $dom = (int) Carbon::parse($date, $primaryTz)->day;
            $val = (float) $qty;

            if ($dom >= 25) {
                $paydayObs[$date] = $val;
            } else {
                $regularObs[$date] = $val;
            }
        }

        $paydayCount = count($paydayObs);
        $regularCount = count($regularObs);

        if ($paydayCount < self::PAYDAY_MIN_OBSERVATIONS) {
            return [
                'status'                => 'INSUFFICIENT_PAYDAY_EVIDENCE',
                'is_target_payday'      => $isTargetPayday,
                'payday_observations'   => $paydayCount,
                'regular_observations'  => $regularCount,
                'payday_avg_qty'        => $paydayCount > 0 ? round(array_sum($paydayObs) / $paydayCount, 2) : 0.0,
                'regular_avg_qty'       => $regularCount > 0 ? round(array_sum($regularObs) / $regularCount, 2) : 0.0,
                'raw_multiplier'        => null,
                'calculated_multiplier' => null,
                'applied_multiplier'    => 1.0,
                'reason'                => "Observasi hari gajian historis hanya {$paydayCount} hari (minimal dibutuhkan 7 hari).",
            ];
        }

        $paydayAvg = array_sum($paydayObs) / max(1, $paydayCount);
        $regularAvg = $regularCount > 0 ? array_sum($regularObs) / $regularCount : 0.0;

        if ($regularAvg <= 0.0) {
            return [
                'status'                => 'ZERO_REGULAR_BASELINE',
                'is_target_payday'      => $isTargetPayday,
                'payday_observations'   => $paydayCount,
                'regular_observations'  => $regularCount,
                'payday_avg_qty'        => round($paydayAvg, 2),
                'regular_avg_qty'       => 0.0,
                'raw_multiplier'        => null,
                'calculated_multiplier' => null,
                'applied_multiplier'    => 1.0,
                'reason'                => 'Rata-rata penjualan hari biasa adalah nol.',
            ];
        }

        $rawMultiplier = round($paydayAvg / $regularAvg, 4);
        $clampedMultiplier = round(max(0.80, min(1.50, $rawMultiplier)), 4);

        if ($isTargetPayday) {
            $appliedMultiplier = $clampedMultiplier;
            $status = 'APPLIED_PAYDAY_ADJUSTMENT';
            $reason = 'Pengali hari gajian diterapkan secara proporsional.';
        } else {
            $appliedMultiplier = 1.0;
            $status = 'NOT_APPLIED_REGULAR_DAY';
            $reason = 'Tanggal target bukan merupakan periode gajian.';
        }

        return [
            'status'                => $status,
            'is_target_payday'      => $isTargetPayday,
            'payday_observations'   => $paydayCount,
            'regular_observations'  => $regularCount,
            'payday_avg_qty'        => round($paydayAvg, 2),
            'regular_avg_qty'       => round($regularAvg, 2),
            'raw_multiplier'        => $rawMultiplier,
            'calculated_multiplier' => $clampedMultiplier,
            'applied_multiplier'    => $appliedMultiplier,
            'reason'                => $reason,
        ];
    }

    /**
     * Calculate DOW Median for target date from historical 28-day window.
     *
     * @param array  $dailyVector Array of 'YYYY-MM-DD' => float qty (28 days)
     * @param string $targetDate
     * @param string $primaryTz
     * @return array
     */
    public function calculateDowMedian(array $dailyVector, string $targetDate, string $primaryTz = 'Asia/Jakarta'): array
    {
        $targetDow = (int) Carbon::parse($targetDate, $primaryTz)->dayOfWeekIso;

        $matchingDowQuantities = [];
        foreach ($dailyVector as $date => $qty) {
            $dow = (int) Carbon::parse($date, $primaryTz)->dayOfWeekIso;
            if ($dow === $targetDow) {
                $matchingDowQuantities[] = (float) $qty;
            }
        }

        $activeCount = count(array_filter($matchingDowQuantities, fn ($v) => $v > 0.0));
        $totalObservations = count($matchingDowQuantities);

        // Fallback rule: If matching DOW active observations < 2, fallback to WMA-14
        if ($activeCount < 2) {
            return [
                'fallback_needed'         => true,
                'target_dow'              => $targetDow,
                'dow_observations_count'  => $totalObservations,
                'active_dow_observations' => $activeCount,
                'active_observations'     => $activeCount,
                'median_qty'              => null,
                'reason'                  => "Observasi aktif hari yang sama (DOW {$targetDow}) hanya {$activeCount} titik (minimal 2 titik).",
            ];
        }

        sort($matchingDowQuantities);
        $count = count($matchingDowQuantities);
        $middle = (int) floor($count / 2);

        if ($count % 2 === 0) {
            $median = ($matchingDowQuantities[$middle - 1] + $matchingDowQuantities[$middle]) / 2.0;
        } else {
            $median = $matchingDowQuantities[$middle];
        }

        return [
            'fallback_needed'         => false,
            'target_dow'              => $targetDow,
            'dow_observations_count'  => $totalObservations,
            'active_dow_observations' => $activeCount,
            'active_observations'     => $activeCount,
            'median_qty'              => round((float) $median, 2),
            'reason'                  => "DOW {$targetDow} median terhitung dari {$activeCount} observasi aktif.",
        ];
    }

    /**
     * Calculate WMA-14 from the most recent 14 calendar days of the vector.
     *
     * @param array  $dailyVector
     * @param string $anchorDate
     * @param string $primaryTz
     * @return float
     */
    public function calculateWma14(array $dailyVector, string $anchorDate, string $primaryTz = 'Asia/Jakarta'): float
    {
        // Sort dates ascending
        ksort($dailyVector);

        // Take up to 14 days strictly before anchorDate
        $eligibleDates = [];
        foreach ($dailyVector as $date => $qty) {
            if ($date < $anchorDate) {
                $eligibleDates[$date] = (float) $qty;
            }
        }

        $last14 = array_slice($eligibleDates, -self::WMA_CALENDAR_WINDOW_DAYS, self::WMA_CALENDAR_WINDOW_DAYS, true);
        $values = array_values($last14);
        $n = count($values);

        if ($n === 0) {
            return 0.0;
        }

        $weightedSum = 0.0;
        $totalWeight = 0;

        for ($t = 1; $t <= $n; $t++) {
            $val = $values[$t - 1];
            $weightedSum += ($t * $val);
            $totalWeight += $t;
        }

        return round($weightedSum / max(1, $totalWeight), 2);
    }

    /**
     * Generate complete demand forecast for a single menu.
     *
     * @param int         $userId
     * @param int         $menuId
     * @param int|null    $outletId
     * @param string|null $anchorDate
     * @param array       $options
     * @return array
     */
    public function forecastMenu(
        int $userId,
        int $menuId,
        ?int $outletId = null,
        ?string $anchorDate = null,
        array $options = []
    ): array {
        $primaryTz = $options['timezone'] ?? $this->resolvePrimaryTimezone($userId, $outletId);
        $anchorDate = $anchorDate ?? Carbon::now($primaryTz)->format('Y-m-d');
        $horizonDays = max(1, min(7, (int) ($options['horizon_days'] ?? 1)));
        $targetDate = Carbon::parse($anchorDate, $primaryTz)->addDays(1)->format('Y-m-d');

        // Verify menu ownership
        $menuRecord = DB::table('menus')
            ->where('id', $menuId)
            ->where('user_id', $userId)
            ->select(['id', 'name', 'price', 'cost_price', 'stock', 'outlet_id', 'deleted_at', 'unit'])
            ->first();

        if (!$menuRecord) {
            return [
                'status'                 => 'MENU_NOT_FOUND',
                'menu_id'                => $menuId,
                'menu_name'              => null,
                'anchor_date'            => $anchorDate,
                'target_date'            => $targetDate,
                'horizon_days'           => $horizonDays,
                'history_classification' => [
                    'tier'   => self::TIER_INSUFFICIENT_HISTORY,
                    'reason' => 'Menu tidak ditemukan atau tidak berada pada otoritas tenant.',
                ],
                'forecast'               => [
                    'method'       => 'NONE',
                    'forecast_qty' => null,
                ],
                'financial_impact'       => [
                    'status'                     => 'INSUFFICIENT_DATA',
                    'potential_forecast_revenue' => null,
                    'label'                      => 'potensi pendapatan berdasarkan forecast',
                ],
                'stock_monitoring'       => [
                    'status' => self::STOCK_UNTRACKED,
                    'note'   => 'Menu tidak terdaftar.',
                ],
            ];
        }

        // Establish window dates
        $dowStartDate = Carbon::parse($anchorDate, $primaryTz)->subDays(self::DOW_CALENDAR_WINDOW_DAYS)->format('Y-m-d');
        $paydayStartDate = Carbon::parse($anchorDate, $primaryTz)->subDays(self::PAYDAY_WINDOW_DAYS)->format('Y-m-d');
        $windowEndDate = Carbon::parse($anchorDate, $primaryTz)->subDays(1)->format('Y-m-d');

        // Determine cutoff UTC
        $cutoffUtc = $options['cutoff_utc'] ?? null;
        if ($cutoffUtc === null) {
            [$anchorStartUtc] = TimezoneContract::businessDateRangeToUtcRange($anchorDate, $anchorDate, $primaryTz);
            $cutoffUtc = $anchorStartUtc;
        }

        // Build 60-day daily demand vector in single query (covers both 28d DOW and 60d Payday)
        $demandResult = $this->buildDailyDemand(
            $userId,
            $outletId,
            $paydayStartDate,
            $windowEndDate,
            $cutoffUtc,
            [$menuId],
            $primaryTz
        );

        $full60Vector = $demandResult['by_menu'][$menuId] ?? [];

        return $this->compileForecastPayload(
            $menuRecord,
            $full60Vector,
            $anchorDate,
            $targetDate,
            $horizonDays,
            $dowStartDate,
            $windowEndDate,
            $primaryTz
        );
    }

    /**
     * Batch forecast for all active menus of a tenant/outlet (Strict 2-query design).
     *
     * @param int         $userId
     * @param int|null    $outletId
     * @param string|null $anchorDate
     * @param array       $options
     * @return array
     */
    public function forecastAllMenus(
        int $userId,
        ?int $outletId = null,
        ?string $anchorDate = null,
        array $options = []
    ): array {
        $primaryTz = $options['timezone'] ?? $this->resolvePrimaryTimezone($userId, $outletId);
        $anchorDate = $anchorDate ?? Carbon::now($primaryTz)->format('Y-m-d');
        $horizonDays = max(1, min(7, (int) ($options['horizon_days'] ?? 1)));

        // Preload menus (Query 1)
        $menuCache = $this->preloadMenuCache($userId, $outletId);
        $menuIds = array_keys($menuCache['byId']);

        if (empty($menuIds)) {
            return [
                'status'       => 'NO_MENUS',
                'anchor_date'  => $anchorDate,
                'horizon_days' => $horizonDays,
                'timezone'     => $primaryTz,
                'total_menus'  => 0,
                'menus'        => [],
                'summary'      => [
                    'strong_history_count'       => 0,
                    'intermittent_history_count' => 0,
                    'insufficient_history_count' => 0,
                    'total_forecast_qty'         => 0.0,
                    'total_potential_revenue'    => 0.0,
                ],
            ];
        }

        // Establish window dates
        $paydayStartDate = Carbon::parse($anchorDate, $primaryTz)->subDays(self::PAYDAY_WINDOW_DAYS)->format('Y-m-d');
        $windowEndDate = Carbon::parse($anchorDate, $primaryTz)->subDays(1)->format('Y-m-d');

        // Determine cutoff UTC
        $cutoffUtc = $options['cutoff_utc'] ?? null;
        if ($cutoffUtc === null) {
            [$anchorStartUtc] = TimezoneContract::businessDateRangeToUtcRange($anchorDate, $anchorDate, $primaryTz);
            $cutoffUtc = $anchorStartUtc;
        }

        // Retrieve transaction ledger for all menus (Query 2, reusing preloaded menuCache and timezone)
        $demandResult = $this->buildDailyDemand(
            $userId,
            $outletId,
            $paydayStartDate,
            $windowEndDate,
            $cutoffUtc,
            $menuIds,
            $primaryTz,
            $menuCache
        );

        $results = [];
        $strongCount = 0;
        $intermittentCount = 0;
        $insufficientCount = 0;
        $unsupportedUnitCount = 0;
        $totalForecastQty = 0.0;
        $totalPotentialRevenue = 0.0;

        $targetDate = Carbon::parse($anchorDate, $primaryTz)->addDays(1)->format('Y-m-d');
        $dowStartDate = Carbon::parse($anchorDate, $primaryTz)->subDays(self::DOW_CALENDAR_WINDOW_DAYS)->format('Y-m-d');

        foreach ($menuCache['byId'] as $mId => $mRecord) {
            $full60Vector = $demandResult['by_menu'][$mId] ?? [];

            $menuForecast = $this->compileForecastPayload(
                $mRecord,
                $full60Vector,
                $anchorDate,
                $targetDate,
                $horizonDays,
                $dowStartDate,
                $windowEndDate,
                $primaryTz
            );

            if ($menuForecast['history_classification']['tier'] === self::TIER_STRONG_HISTORY) {
                $strongCount++;
            } elseif ($menuForecast['history_classification']['tier'] === self::TIER_INTERMITTENT_HISTORY) {
                $intermittentCount++;
            } elseif ($menuForecast['history_classification']['tier'] === 'UNSUPPORTED_DATA') {
                $unsupportedUnitCount++;
            } else {
                $insufficientCount++;
            }

            if ($menuForecast['forecast']['forecast_qty'] !== null) {
                $totalForecastQty += $menuForecast['forecast']['forecast_qty'];
            }
            if ($menuForecast['financial_impact']['potential_forecast_revenue'] !== null) {
                $totalPotentialRevenue += $menuForecast['financial_impact']['potential_forecast_revenue'];
            }

            $results[] = $menuForecast;
        }

        return [
            'status'       => 'SUCCESS',
            'anchor_date'  => $anchorDate,
            'horizon_days' => $horizonDays,
            'timezone'     => $primaryTz,
            'total_menus'  => count($results),
            'menus'        => $results,
            'summary'      => [
                'strong_history_count'       => $strongCount,
                'intermittent_history_count' => $intermittentCount,
                'insufficient_history_count' => $insufficientCount,
                'unsupported_unit_count'     => $unsupportedUnitCount,
                'total_forecast_qty'         => round($totalForecastQty, 2),
                'total_potential_revenue'    => round($totalPotentialRevenue, 2),
            ],
            'batch_metadata' => [
                'generated_at_utc'   => gmdate('Y-m-d\TH:i:s\Z'),
                'timezone'           => $primaryTz,
                'query_architecture' => [
                    'mode'                => 'TWO_QUERY_BATCH',
                    'data_queries'        => 2,
                    'timezone_resolution' => isset($options['timezone']) ? 'EXPLICIT_OPTION' : (isset($this->timezoneCache[$outletId ?? 0]) ? 'INSTANCE_CACHED' : 'DATABASE_QUERY'),
                    'description'         => '2 data queries (master menus + transaction ledger). Zero N+1 query loop.',
                ],
            ],
        ];
    }

    /**
     * Calculate evaluation metrics (WAPE, MAE, Bias) deterministically.
     *
     * @param array $forecastVector
     * @param array $actualVector
     * @return array
     */
    public function calculateEvaluationMetrics(array $forecastVector, array $actualVector): array
    {
        $n = count($actualVector);
        if ($n === 0 || count($forecastVector) !== $n) {
            return [
                'status'         => 'INVALID_VECTORS',
                'wape'           => null,
                'mae'            => null,
                'bias'           => null,
                'actual_total'   => 0.0,
                'forecast_total' => 0.0,
            ];
        }

        $sumActual   = array_sum($actualVector);
        $sumForecast = array_sum($forecastVector);
        $absErrorSum = 0.0;
        $signedError = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $diff = (float) $forecastVector[$i] - (float) $actualVector[$i];
            $absErrorSum += abs($diff);
            $signedError += $diff;
        }

        $mae = $n > 0 ? round($absErrorSum / $n, 4) : 0.0;

        if ($sumActual <= 0.0001) {
            return [
                'status'         => self::BACKTEST_STATUS_NO_ACTUAL_SALES,
                'wape'           => null,
                'mae'            => $mae,
                'bias'           => null,
                'actual_total'   => round($sumActual, 2),
                'forecast_total' => round($sumForecast, 2),
            ];
        }

        $wape = round(($absErrorSum / $sumActual) * 100, 2);
        $bias = round(($signedError / $sumActual) * 100, 2);

        return [
            'status'         => self::BACKTEST_STATUS_SUCCESS,
            'wape'           => $wape,
            'mae'            => $mae,
            'bias'           => $bias,
            'actual_total'   => round($sumActual, 2),
            'forecast_total' => round($sumForecast, 2),
        ];
    }

    /**
     * Empirical Demand Forecast Backtesting (Phase 3.4C).
     * Point-in-time evaluation of deterministic forecast against actual realization
     * and comparison against empirical baselines (Naive Same DOW, SMA-14, Historical Median).
     *
     * @param int         $userId
     * @param int|null    $outletId
     * @param string|null $anchorDate Historical cutoff T (YYYY-MM-DD)
     * @param array       $options
     * @return array
     */
    public function backtest(
        int $userId,
        ?int $outletId = null,
        ?string $anchorDate = null,
        array $options = []
    ): array {
        $primaryTz = $options['timezone'] ?? $this->resolvePrimaryTimezone($userId, $outletId);
        $horizonDays = (int) ($options['horizon_days'] ?? 7);
        if ($horizonDays < 1 || $horizonDays > 14) {
            $horizonDays = 7;
        }

        if ($anchorDate === null) {
            $anchorDate = Carbon::now($primaryTz)->subDays($horizonDays)->format('Y-m-d');
        }

        // Point-in-time cutoff at anchor date 23:59:59 in business timezone
        [, $histCutoffUtc] = TimezoneContract::businessDateRangeToUtcRange($anchorDate, $anchorDate, $primaryTz);

        // Evaluation realization window [T+1 .. T+horizonDays]
        $evalStartDate = Carbon::parse($anchorDate, $primaryTz)->addDay()->format('Y-m-d');
        $evalEndDate   = Carbon::parse($anchorDate, $primaryTz)->addDays($horizonDays)->format('Y-m-d');
        [, $evalCutoffUtc] = TimezoneContract::businessDateRangeToUtcRange($evalEndDate, $evalEndDate, $primaryTz);

        // Preload menus
        $menuCache = $this->preloadMenuCache($userId, $outletId);
        $targetMenuId = isset($options['menu_id']) ? (int) $options['menu_id'] : null;

        $targetMenus = [];
        if ($targetMenuId !== null) {
            if (isset($menuCache['byId'][$targetMenuId])) {
                $targetMenus[$targetMenuId] = $menuCache['byId'][$targetMenuId];
            } else {
                return [
                    'status'      => 'MENU_NOT_FOUND',
                    'message'     => "Menu ID {$targetMenuId} tidak ditemukan untuk tenant ini.",
                    'anchor_date' => $anchorDate,
                ];
            }
        } else {
            $targetMenus = $menuCache['byId'];
        }

        if (empty($targetMenus)) {
            return [
                'status'      => 'NO_MENUS_FOUND',
                'message'     => 'Tidak ada menu aktif yang dapat dievaluasi.',
                'anchor_date' => $anchorDate,
            ];
        }

        $menuIds = array_keys($targetMenus);

        // 1. Point-in-time historical data up to anchorDate (60 days)
        $histStartDate = Carbon::parse($anchorDate, $primaryTz)->subDays(self::PAYDAY_WINDOW_DAYS)->format('Y-m-d');
        $histDemand = $this->buildDailyDemand(
            $userId,
            $outletId,
            $histStartDate,
            $anchorDate,
            $histCutoffUtc,
            $menuIds,
            $primaryTz,
            $menuCache
        );

        // 2. Actual realized demand in evaluation window
        $actualDemand = $this->buildDailyDemand(
            $userId,
            $outletId,
            $evalStartDate,
            $evalEndDate,
            $evalCutoffUtc,
            $menuIds,
            $primaryTz,
            $menuCache
        );

        // Models to compare
        $models = [
            'engine'            => ['forecasts' => [], 'name' => 'Deterministic 3-Tier Engine (DOW+WMA+Payday)'],
            'naive_last_week'   => ['forecasts' => [], 'name' => 'Naive Same DOW Last Week'],
            'sma_14'            => ['forecasts' => [], 'name' => 'Simple Moving Average (14-Day)'],
            'historical_median' => ['forecasts' => [], 'name' => 'Historical Median (28-Day)'],
        ];
        $actuals = [];

        // Breakdown tracking
        $segments = [
            'strong_history'       => ['actuals' => [], 'engine' => [], 'naive' => [], 'sma' => []],
            'intermittent_history' => ['actuals' => [], 'engine' => [], 'naive' => [], 'sma' => []],
            'insufficient_history' => ['actuals' => [], 'engine' => [], 'naive' => [], 'sma' => []],
            'zero_demand_days'     => ['count' => 0, 'engine_mae' => 0.0, 'sma_mae' => 0.0],
            'payday_days'          => ['actuals' => [], 'engine' => [], 'naive' => [], 'sma' => []],
            'dow_breakdown'        => [],
        ];

        for ($dowIdx = 1; $dowIdx <= 7; $dowIdx++) {
            $segments['dow_breakdown'][$dowIdx] = ['actuals' => [], 'engine' => []];
        }

        $dowStartDate = Carbon::parse($anchorDate, $primaryTz)->subDays(self::DOW_CALENDAR_WINDOW_DAYS)->format('Y-m-d');
        $windowEndDate = Carbon::parse($anchorDate, $primaryTz)->subDays(1)->format('Y-m-d');

        $dailyComparisons = [];

        // Iterate through evaluation dates T+1 .. T+H
        $curDate = Carbon::parse($evalStartDate, $primaryTz);
        $endDateObj = Carbon::parse($evalEndDate, $primaryTz);

        while ($curDate->lte($endDateObj)) {
            $targetDateStr = $curDate->format('Y-m-d');
            $dow = (int) $curDate->dayOfWeekIso; // 1=Mon, 7=Sun
            $isPayday = ((int) $curDate->day) >= 25;

            foreach ($targetMenus as $mId => $mRecord) {
                $histVector = $histDemand['by_menu'][$mId] ?? [];
                $actualQty = (float) ($actualDemand['by_menu'][$mId][$targetDateStr] ?? 0.0);

                // 1. Engine Forecast
                $payload = $this->compileForecastPayload(
                    $mRecord,
                    $histVector,
                    $anchorDate,
                    $targetDateStr,
                    $horizonDays,
                    $dowStartDate,
                    $windowEndDate,
                    $primaryTz
                );
                $engineQty = (float) ($payload['forecast']['forecast_qty'] ?? 0.0);
                $tier = $payload['history_classification']['tier'];

                // Unit Firewall: non-discrete units are safely excluded from discrete engine backtest
                if ($payload['forecast']['forecast_qty'] === null && $tier === 'UNSUPPORTED_DATA') {
                    continue;
                }

                // 2. Baseline: Naive Same DOW Last Week
                $sameDowDate = Carbon::parse($targetDateStr, $primaryTz)->subDays(7)->format('Y-m-d');
                $naiveQty = (float) ($histVector[$sameDowDate] ?? 0.0);

                // 3. Baseline: SMA-14
                $smaSum = 0.0;
                for ($k = 1; $k <= 14; $k++) {
                    $dStr = Carbon::parse($anchorDate, $primaryTz)->subDays($k)->format('Y-m-d');
                    $smaSum += (float) ($histVector[$dStr] ?? 0.0);
                }
                $smaQty = round($smaSum / 14.0, 2);

                // 4. Baseline: Historical Median (28 days)
                $medVals = [];
                for ($k = 1; $k <= 28; $k++) {
                    $dStr = Carbon::parse($anchorDate, $primaryTz)->subDays($k)->format('Y-m-d');
                    $medVals[] = (float) ($histVector[$dStr] ?? 0.0);
                }
                sort($medVals);
                $medQty = round(($medVals[13] + $medVals[14]) / 2.0, 2);

                // Record observation
                $actuals[] = $actualQty;
                $models['engine']['forecasts'][] = $engineQty;
                $models['naive_last_week']['forecasts'][] = $naiveQty;
                $models['sma_14']['forecasts'][] = $smaQty;
                $models['historical_median']['forecasts'][] = $medQty;

                // Segment tracking
                if ($tier === self::TIER_STRONG_HISTORY) {
                    $segments['strong_history']['actuals'][] = $actualQty;
                    $segments['strong_history']['engine'][]  = $engineQty;
                    $segments['strong_history']['naive'][]   = $naiveQty;
                    $segments['strong_history']['sma'][]     = $smaQty;
                } elseif ($tier === self::TIER_INTERMITTENT_HISTORY) {
                    $segments['intermittent_history']['actuals'][] = $actualQty;
                    $segments['intermittent_history']['engine'][]  = $engineQty;
                    $segments['intermittent_history']['naive'][]   = $naiveQty;
                    $segments['intermittent_history']['sma'][]     = $smaQty;
                } else {
                    $segments['insufficient_history']['actuals'][] = $actualQty;
                    $segments['insufficient_history']['engine'][]  = $engineQty;
                    $segments['insufficient_history']['naive'][]   = $naiveQty;
                    $segments['insufficient_history']['sma'][]     = $smaQty;
                }

                if ($actualQty <= 0.0001) {
                    $segments['zero_demand_days']['count']++;
                    $segments['zero_demand_days']['engine_mae'] += abs($engineQty - $actualQty);
                    $segments['zero_demand_days']['sma_mae']    += abs($smaQty - $actualQty);
                }

                if ($isPayday) {
                    $segments['payday_days']['actuals'][] = $actualQty;
                    $segments['payday_days']['engine'][]  = $engineQty;
                    $segments['payday_days']['naive'][]   = $naiveQty;
                    $segments['payday_days']['sma'][]     = $smaQty;
                }

                $segments['dow_breakdown'][$dow]['actuals'][] = $actualQty;
                $segments['dow_breakdown'][$dow]['engine'][]  = $engineQty;

                $dailyComparisons[] = [
                    'date'              => $targetDateStr,
                    'menu_id'           => $mId,
                    'menu_name'         => $mRecord->name,
                    'tier'              => $tier,
                    'actual_qty'        => $actualQty,
                    'engine_qty'        => $engineQty,
                    'naive_qty'         => $naiveQty,
                    'sma_14_qty'        => $smaQty,
                    'engine_abs_error'  => round(abs($engineQty - $actualQty), 2),
                    'sma_abs_error'     => round(abs($smaQty - $actualQty), 2),
                ];
            }
            $curDate->addDay();
        }

        // Calculate overall model metrics
        $evaluationResults = [];
        foreach ($models as $mKey => $mData) {
            $metrics = $this->calculateEvaluationMetrics($mData['forecasts'], $actuals);
            $evaluationResults[$mKey] = array_merge(['name' => $mData['name']], $metrics);
        }

        // Compute segment metrics
        $segmentMetrics = [
            'strong_history'       => $this->calculateEvaluationMetrics($segments['strong_history']['engine'], $segments['strong_history']['actuals']),
            'intermittent_history' => $this->calculateEvaluationMetrics($segments['intermittent_history']['engine'], $segments['intermittent_history']['actuals']),
            'insufficient_history' => $this->calculateEvaluationMetrics($segments['insufficient_history']['engine'], $segments['insufficient_history']['actuals']),
            'zero_demand_days'     => [
                'count'                      => $segments['zero_demand_days']['count'],
                'engine_mean_overprediction' => $segments['zero_demand_days']['count'] > 0 ? round($segments['zero_demand_days']['engine_mae'] / $segments['zero_demand_days']['count'], 4) : 0.0,
                'sma_mean_overprediction'    => $segments['zero_demand_days']['count'] > 0 ? round($segments['zero_demand_days']['sma_mae'] / $segments['zero_demand_days']['count'], 4) : 0.0,
            ],
            'payday_days'          => $this->calculateEvaluationMetrics($segments['payday_days']['engine'], $segments['payday_days']['actuals']),
            'dow_performance'      => [],
        ];

        foreach ($segments['dow_breakdown'] as $dIdx => $dObs) {
            $segmentMetrics['dow_performance'][$dIdx] = $this->calculateEvaluationMetrics($dObs['engine'], $dObs['actuals']);
        }

        // Determine Verdict
        $engineWape = $evaluationResults['engine']['wape'];
        $smaWape    = $evaluationResults['sma_14']['wape'];
        $naiveWape  = $evaluationResults['naive_last_week']['wape'];

        $verdict = self::BACKTEST_VERDICT_COMPARABLE;
        if ($engineWape === null || $evaluationResults['engine']['status'] === self::BACKTEST_STATUS_NO_ACTUAL_SALES) {
            $verdict = self::BACKTEST_STATUS_NO_ACTUAL_SALES;
        } else {
            $bestBaselineWape = min(array_filter([$smaWape, $naiveWape], fn($w) => $w !== null) ?: [$engineWape]);
            if ($engineWape < ($bestBaselineWape - 1.0)) {
                $verdict = self::BACKTEST_VERDICT_SUPERIOR;
            } elseif ($engineWape > ($bestBaselineWape + 1.0)) {
                $verdict = self::BACKTEST_VERDICT_INFERIOR;
            } else {
                $verdict = self::BACKTEST_VERDICT_COMPARABLE;
            }
        }

        return [
            'status'              => self::BACKTEST_STATUS_SUCCESS,
            'verdict'             => $verdict,
            'anchor_date'         => $anchorDate,
            'eval_window'         => [
                'start_date'   => $evalStartDate,
                'end_date'     => $evalEndDate,
                'horizon_days' => $horizonDays,
            ],
            'point_in_time_guard' => [
                'anchor_cutoff_utc' => $histCutoffUtc,
                'eval_cutoff_utc'   => $evalCutoffUtc,
                'future_leakage'    => 'STRICTLY_BLOCKED',
            ],
            'coverage'            => [
                'evaluated_menus'    => count($targetMenus),
                'total_observations' => count($actuals),
            ],
            'models_comparison'   => $evaluationResults,
            'segment_breakdown'   => $segmentMetrics,
            'daily_realization'   => array_slice($dailyComparisons, 0, 50),
        ];
    }

    /**
     * Single Source of Truth for forecast tier dispatch, payday adjustment,
     * financial impact calculation, and stock monitoring boundary rules.
     *
     * @param object $menuRecord
     * @param array  $menuDailyVector 60-day historical vector
     * @param string $anchorDate
     * @param string $targetDate
     * @param int    $horizonDays
     * @param string $dowStartDate
     * @param string $windowEndDate
     * @param string $primaryTz
     * @return array
     */
    protected function compileForecastPayload(
        object $menuRecord,
        array $menuDailyVector,
        string $anchorDate,
        string $targetDate,
        int $horizonDays,
        string $dowStartDate,
        string $windowEndDate,
        string $primaryTz
    ): array {
        // Unit Firewall: enforce discrete F&B demand guard rail
        $rawUnit = isset($menuRecord->unit) ? (string) $menuRecord->unit : null;
        $currentPrice = isset($menuRecord->price) ? (float) $menuRecord->price : 0.0;
        $menuOutletId = isset($menuRecord->outlet_id) ? (int) $menuRecord->outlet_id : null;

        $unitResolution = $this->uomService->resolveForecastUnit(
            (int) $menuRecord->id,
            $rawUnit,
            $currentPrice,
            $menuOutletId
        );

        if ($unitResolution['status'] !== UnitOfMeasureService::STATUS_SUPPORTED) {
            $reason = $unitResolution['failure_reason'] ?? 'Unit tidak didukung oleh discrete demand engine.';
            return [
                'status'                 => $unitResolution['status'],
                'menu_id'                => (int) $menuRecord->id,
                'menu_name'              => $menuRecord->name ?? null,
                'unit'                   => $rawUnit,
                'unit_resolution'        => $unitResolution,
                'anchor_date'            => $anchorDate,
                'target_date'            => $targetDate,
                'horizon_days'           => $horizonDays,
                'history_classification' => [
                    'tier'   => 'UNSUPPORTED_DATA',
                    'reason' => $reason,
                ],
                'forecast'               => [
                    'method'                 => 'NONE',
                    'baseline_qty'           => null,
                    'forecast_qty'           => null,
                    'uom'                    => $unitResolution['canonical_unit'] ?? null,
                    'dimension'              => $unitResolution['dimension'] ?? null,
                    'is_discrete'            => false,
                    'dow_observations_count' => 0,
                    'payday_multiplier'      => 1.0,
                    'payday_is_payday_cycle' => false,
                    'payday_sample_size'     => 0,
                ],
                'financial_impact'       => [
                    'status'                     => 'INSUFFICIENT_DATA',
                    'unit_price'                 => $currentPrice > 0.0 ? $currentPrice : null,
                    'potential_forecast_revenue' => null,
                    'label'                      => 'potensi pendapatan berdasarkan forecast',
                    'indicative_unit_cost'       => null,
                    'potential_gross_profit'     => null,
                    'note'                       => 'Perhitungan finansial ditolak karena ketidaksesuaian unit fisik.',
                ],
                'stock_monitoring'       => [
                    'status' => self::STOCK_UNTRACKED,
                    'note'   => $reason,
                ],
            ];
        }

        // Slice 28-day vector for DOW analysis
        $dow28Vector = [];
        $current = Carbon::parse($dowStartDate, $primaryTz);
        $end = Carbon::parse($windowEndDate, $primaryTz);
        while ($current->lte($end)) {
            $d = $current->format('Y-m-d');
            $dow28Vector[$d] = $menuDailyVector[$d] ?? 0.0;
            $current->addDay();
        }

        // Classify history
        $classification = $this->classifyHistory($dow28Vector, self::DOW_CALENDAR_WINDOW_DAYS);

        // Calculate Payday Multiplier
        $paydayResult = $this->calculatePaydayMultiplier($menuDailyVector, $anchorDate, $targetDate, $primaryTz);

        // Forecast computation
        $method = 'NONE';
        $baselineQty = null;
        $forecastQty = null;
        $dowObservationsCount = 0;

        if ($classification['tier'] === self::TIER_STRONG_HISTORY) {
            $dowResult = $this->calculateDowMedian($dow28Vector, $targetDate, $primaryTz);
            $dowObservationsCount = $dowResult['dow_observations_count'];

            if ($dowResult['fallback_needed']) {
                $method = 'WMA_14_FALLBACK';
                $baselineQty = $this->calculateWma14($dow28Vector, $anchorDate, $primaryTz);
            } else {
                $method = 'DOW_MEDIAN';
                $baselineQty = $dowResult['median_qty'];
            }

            $forecastQty = round($baselineQty * $paydayResult['applied_multiplier'], 2);
            $status = 'SUCCESS';
        } elseif ($classification['tier'] === self::TIER_INTERMITTENT_HISTORY) {
            $method = 'WMA_14';
            $baselineQty = $this->calculateWma14($dow28Vector, $anchorDate, $primaryTz);
            $forecastQty = round($baselineQty * $paydayResult['applied_multiplier'], 2);
            $status = 'SUCCESS';
        } else {
            $method = 'NONE';
            $baselineQty = null;
            $forecastQty = null;
            $status = self::TIER_INSUFFICIENT_HISTORY;
        }

        // Financial Impact
        $price = isset($menuRecord->price) ? (float) $menuRecord->price : 0.0;
        $costPrice = isset($menuRecord->cost_price) ? (float) $menuRecord->cost_price : null;

        if ($forecastQty === null || $price <= 0.0) {
            $financialImpact = [
                'status'                     => 'INSUFFICIENT_DATA',
                'unit_price'                 => $price > 0.0 ? $price : null,
                'potential_forecast_revenue' => null,
                'label'                      => 'potensi pendapatan berdasarkan forecast',
                'indicative_unit_cost'       => $costPrice > 0.0 ? $costPrice : null,
                'potential_gross_profit'     => null,
            ];
        } else {
            $potentialRevenue = round($forecastQty * $price, 2);
            $potentialGrossProfit = ($costPrice !== null && $costPrice > 0.0)
                ? round($forecastQty * ($price - $costPrice), 2)
                : null;

            $financialImpact = [
                'status'                     => 'CALCULATED',
                'unit_price'                 => $price,
                'potential_forecast_revenue' => $potentialRevenue,
                'label'                      => 'potensi pendapatan berdasarkan forecast',
                'indicative_unit_cost'       => $costPrice > 0.0 ? $costPrice : null,
                'potential_gross_profit'     => $potentialGrossProfit,
            ];
        }

        // Finished Goods Stock Monitoring Boundary
        $stock = isset($menuRecord->stock) ? (float) $menuRecord->stock : -1.0;

        if ($stock == -1.0) {
            $stockMonitoring = [
                'status'         => self::STOCK_UNTRACKED,
                'recorded_stock' => -1,
                'run_rate_days'  => null,
                'note'           => 'Menu tidak membatasi stok fisik',
            ];
        } elseif ($stock > 1000.0) {
            $stockMonitoring = [
                'status'         => self::STOCK_ANOMALOUS,
                'recorded_stock' => $stock,
                'run_rate_days'  => null,
                'note'           => 'Nilai stok menu di luar rentang kalibrasi fisik (stok dummy/uncalibrated)',
            ];
        } else {
            $runRateDays = ($forecastQty !== null && $forecastQty > 0.0)
                ? (int) floor($stock / $forecastQty)
                : null;

            $stockMonitoring = [
                'status'         => self::STOCK_TRACKED,
                'recorded_stock' => $stock,
                'run_rate_days'  => $runRateDays,
                'note'           => 'Pemantauan stok menu jadi terkalibrasi',
            ];
        }

        return [
            'status'                 => $status,
            'menu_id'                => (int) $menuRecord->id,
            'menu_name'              => $menuRecord->name ?? null,
            'unit'                   => $rawUnit,
            'unit_resolution'        => $unitResolution,
            'anchor_date'            => $anchorDate,
            'target_date'            => $targetDate,
            'horizon_days'           => $horizonDays,
            'history_classification' => [
                'tier'                    => $classification['tier'],
                'total_history_days'      => $classification['total_history_days'],
                'active_sales_days'       => $classification['active_sales_days'],
                'zero_sales_days'         => $classification['zero_sales_days'],
                'target_dow_observations' => $dowObservationsCount,
                'reason'                  => $classification['reason'],
            ],
            'forecast'               => [
                'method'            => $method,
                'baseline_qty'      => $baselineQty,
                'payday_adjustment' => $paydayResult,
                'forecast_qty'      => $forecastQty,
                'uom'               => $unitResolution['canonical_unit'],
                'dimension'         => $unitResolution['dimension'],
                'is_discrete'       => true,
            ],
            'financial_impact'       => $financialImpact,
            'stock_monitoring'       => $stockMonitoring,
        ];
    }

    /**
     * Preload tenant menus into structured memory cache.
     *
     * @param int       $userId
     * @param int|null  $outletId
     * @param bool      $includeSoftDeleted
     * @return array ['byId' => ..., 'byName' => ..., 'ambiguous' => ...]
     */
    public function preloadMenuCache(int $userId, ?int $outletId = null, bool $includeSoftDeleted = false): array
    {
        $query = DB::table('menus')
            ->where('user_id', $userId)
            ->select(['id', 'name', 'price', 'cost_price', 'stock', 'outlet_id', 'deleted_at', 'unit']);

        if (!$includeSoftDeleted) {
            $query->whereNull('deleted_at');
        }

        if ($outletId !== null) {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id')->orWhere('outlet_id', 0);
            });
        }

        $records = $query->get();

        $byId = [];
        $nameBuckets = [];
        $ambiguous = [];

        foreach ($records as $r) {
            $byId[$r->id] = $r;
            $cleanName = strtolower((string) preg_replace('/\s+/', ' ', trim((string) $r->name)));
            if ($cleanName !== '') {
                $nameBuckets[$cleanName][] = $r;
            }
        }

        $byName = [];
        foreach ($nameBuckets as $name => $recList) {
            if (count($recList) === 1) {
                $byName[$name] = $recList[0]->id;
            } else {
                $activeList = array_filter($recList, fn($rec) => $rec->deleted_at === null);
                if (count($activeList) === 1) {
                    $activeRec = reset($activeList);
                    $byName[$name] = $activeRec->id;
                } else {
                    $ambiguous[$name] = true;
                }
            }
        }

        return [
            'byId'      => $byId,
            'byName'    => $byName,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * Build continuous calendar dates array.
     */
    protected function buildCalendarDaysList(string $startDate, string $endDate, string $timezone): array
    {
        $current = Carbon::parse($startDate, $timezone);
        $end = Carbon::parse($endDate, $timezone);
        $days = [];

        while ($current->lte($end)) {
            $days[] = $current->format('Y-m-d');
            $current->addDay();
        }

        return $days;
    }

    /**
     * Resolve primary timezone for tenant/outlet.
     */
    protected function resolvePrimaryTimezone(int $userId, ?int $outletId): string
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
