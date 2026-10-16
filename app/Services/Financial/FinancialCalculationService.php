<?php

namespace App\Services\Financial;

use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Helpers\TimezoneContract;
use Carbon\Carbon;

/**
 * Phase A: Financial Calculation Engine Core
 *
 * Deterministic calculation service — NO LLM, NO threshold warnings, NO forecasting.
 * All formulas are extracted from the existing ReportController::export() SSOT.
 *
 * Tenant isolation: every aggregation query is scoped by user_id + outlet_id.
 * Timezone: all date ranges use TimezoneContract::businessDateRangeToUtcRange() with half-open UTC range.
 * Cancel statuses: unified across Revenue and COGS queries.
 *
 * @see App\Http\Controllers\ReportController::export() — canonical formula source
 * @see App\Models\Transaction::getActualPaidSql() — actual paid SQL expression
 * @see App\Helpers\TimezoneContract — business date → UTC conversion
 */
class FinancialCalculationService
{
    /**
     * Canonical list of cancel/excluded statuses.
     * SSOT: ReportController::export() line 321.
     * Applied uniformly to BOTH Revenue AND COGS queries.
     */
    public const CANCEL_STATUSES = [
        'Batal', 'Refund', 'batal', 'refund',
        'Canceled', 'Cancel', 'Void',
        'batal ', 'refund ',
    ];

    /**
     * Default burn rate lookback window in days.
     */
    public const BURN_RATE_WINDOW_DAYS = 14;

    // ────────────────────────────────────────────────────────────
    // PUBLIC API
    // ────────────────────────────────────────────────────────────

    /**
     * Calculate Net Revenue (Net Paid Sales) for a given period.
     *
     * Formula: SUM(Transaction::getActualPaidSql())
     * Excludes: all CANCEL_STATUSES
     * Timezone: half-open UTC range via TimezoneContract
     *
     * @param int      $userId
     * @param int|null $outletId  null = all outlets for this user
     * @param string   $startDate Business date Y-m-d
     * @param string   $endDate   Business date Y-m-d
     * @param string   $timezone  IANA timezone (default: resolved from outlet)
     * @return array{value: float, records_count: int}
     */
    public function calculateRevenue(
        int $userId,
        ?int $outletId,
        string $startDate,
        string $endDate,
        ?string $timezone = null
    ): array {
        $tz = $timezone ?? $this->resolveTimezone($outletId);
        [$rangeStart, $rangeEnd] = TimezoneContract::businessDateRangeToUtcRange($startDate, $endDate, $tz);

        $query = DB::table('transactions')
            ->where('user_id', $userId)
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->where('created_at_device', '>=', $rangeStart)
            ->where('created_at_device', '<', $rangeEnd)
            ->whereNotIn('status', self::CANCEL_STATUSES);

        $revenue = (float) (clone $query)->sum(DB::raw(Transaction::getActualPaidSql()));
        $count   = (clone $query)->count();

        return [
            'value'         => $revenue,
            'records_count' => $count,
        ];
    }

    /**
     * Calculate COGS / HPP for a given period.
     *
     * Formula: SUM(transaction_items.qty * COALESCE(transaction_items.cost_price, 0))
     * Excludes: all CANCEL_STATUSES applied to the parent transaction
     * Timezone: half-open UTC range on transactions.created_at_device
     *
     * @return array{value: float, records_count: int, has_untracked_cogs: bool, untracked_items_count: int, total_items_count: int, untracked_items_percentage: float}
     */
    public function calculateCogs(
        int $userId,
        ?int $outletId,
        string $startDate,
        string $endDate,
        ?string $timezone = null
    ): array {
        $tz = $timezone ?? $this->resolveTimezone($outletId);
        [$rangeStart, $rangeEnd] = TimezoneContract::businessDateRangeToUtcRange($startDate, $endDate, $tz);

        $baseQuery = DB::table('transaction_items as ti')
            ->join('transactions as t', 'ti.transaction_id', '=', 't.id')
            ->where('t.user_id', $userId)
            ->when($outletId !== null, fn ($q) => $q->where('t.outlet_id', $outletId))
            ->where('t.created_at_device', '>=', $rangeStart)
            ->where('t.created_at_device', '<', $rangeEnd)
            ->whereNotIn('t.status', self::CANCEL_STATUSES);

        // COGS value: SUM(qty * COALESCE(cost_price, 0))
        $cogs = (float) (clone $baseQuery)->sum(DB::raw('ti.qty * COALESCE(ti.cost_price, 0)'));

        // Data quality: count items with missing/zero cost_price
        $totalItems     = (clone $baseQuery)->count();
        $untrackedItems = (clone $baseQuery)
            ->where(function ($q) {
                $q->whereNull('ti.cost_price')
                  ->orWhere('ti.cost_price', 0);
            })
            ->count();

        $hasUntrackedCogs = $untrackedItems > 0;
        $untrackedPct     = $totalItems > 0
            ? round(($untrackedItems / $totalItems) * 100, 2)
            : 0.0;

        return [
            'value'                      => $cogs,
            'records_count'              => $totalItems,
            'has_untracked_cogs'         => $hasUntrackedCogs,
            'untracked_items_count'      => $untrackedItems,
            'total_items_count'          => $totalItems,
            'untracked_items_percentage' => $untrackedPct,
        ];
    }

    /**
     * Calculate Gross Profit.
     *
     * Formula: Net Revenue - COGS
     */
    public function calculateGrossProfit(float $revenue, float $cogs): float
    {
        return $revenue - $cogs;
    }

    /**
     * Calculate total operational expenses for a given period.
     *
     * Source: expenses table (date column is a business date, not UTC timestamp).
     * Tenant isolation: user_id + outlet_id.
     *
     * @return array{value: float, records_count: int}
     */
    public function calculateExpenses(
        int $userId,
        ?int $outletId,
        string $startDate,
        string $endDate
    ): array {
        $query = DB::table('expenses')
            ->where('user_id', $userId)
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->whereBetween('date', [$startDate, $endDate]);

        $total = (float) (clone $query)->sum('amount');
        $count = (clone $query)->count();

        return [
            'value'         => $total,
            'records_count' => $count,
        ];
    }

    /**
     * Calculate Net Profit.
     *
     * Formula: Gross Profit - Operational Expenses
     * Equivalent to: Revenue - COGS - Expenses
     */
    public function calculateNetProfit(float $grossProfit, float $expenses): float
    {
        return $grossProfit - $expenses;
    }

    /**
     * Get Cash Position from the latest shift for a given outlet.
     *
     * Source: shifts table (Cash Drawer, NOT digital revenue).
     * - Active shift (status != 'closed'): start_cash + total_cash_sales - COALESCE(cash_drop, 0) - COALESCE(total_expense, 0) - COALESCE(total_cash_refunded, 0)
     * - Closed shift: end_cash (actual physical count by cashier)
     *
     * @return array{value: float|null, source: string, shift_id: int|null, shift_status: string|null, cashier_name: string|null}
     */
    public function getCashPosition(int $userId, ?int $outletId): array
    {
        $query = DB::table('shifts')
            ->where('user_id', $userId)
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->orderByDesc('start_time')
            ->first();

        if (!$query) {
            return [
                'value'        => null,
                'source'       => 'NO_SHIFT_DATA',
                'shift_id'     => null,
                'shift_status' => null,
                'cashier_name' => null,
            ];
        }

        $status = strtolower($query->status ?? '');

        if ($status === 'closed' || $query->end_time !== null) {
            // Closed shift: use actual physical count
            $cashPosition = (float) ($query->end_cash ?? 0);
            $source       = 'CLOSED_SHIFT_END_CASH';
        } else {
            // Active shift: compute from drawer components
            $cashPosition = (float) ($query->start_cash ?? 0)
                + (float) ($query->total_cash_sales ?? 0)
                - (float) ($query->cash_drop ?? 0)
                - (float) ($query->total_expense ?? 0)
                - (float) ($query->total_cash_refunded ?? 0);
            $source       = 'ACTIVE_SHIFT_COMPUTED';
        }

        return [
            'value'        => $cashPosition,
            'source'       => $source,
            'shift_id'     => $query->id,
            'shift_status' => $query->status,
            'cashier_name' => $query->cashier_name,
        ];
    }

    /**
     * Calculate Daily Cash Burn Rate.
     *
     * Formula: Total Expenses (last N days) / min(days_active, N)
     * days_active = calendar days since first transaction (minimum 1).
     *
     * @param int    $userId
     * @param int|null $outletId
     * @param string $referenceDate  Business date (Y-m-d) to compute from (usually today)
     * @param int    $windowDays     Lookback window (default: 14)
     * @return array{value: float|null, total_expenses: float, days_used: int, status: string}
     */
    public function calculateDailyCashBurnRate(
        int $userId,
        ?int $outletId,
        string $referenceDate,
        int $windowDays = self::BURN_RATE_WINDOW_DAYS
    ): array {
        // Compute lookback start date (inclusive)
        $windowStart = Carbon::parse($referenceDate)->subDays($windowDays - 1)->format('Y-m-d');

        // Total expenses in the window
        $expenseResult = $this->calculateExpenses($userId, $outletId, $windowStart, $referenceDate);
        $totalExpenses = $expenseResult['value'];

        // Determine days_active: days since first transaction for this tenant
        $firstTrxDate = DB::table('transactions')
            ->where('user_id', $userId)
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->min('created_at_device');

        if ($firstTrxDate === null) {
            return [
                'value'          => null,
                'total_expenses' => $totalExpenses,
                'days_used'      => 0,
                'status'         => 'NO_TRANSACTION_HISTORY',
            ];
        }

        $firstDate  = Carbon::parse($firstTrxDate)->format('Y-m-d');
        $refDate    = Carbon::parse($referenceDate);
        $daysActive = max(1, $refDate->diffInDays(Carbon::parse($firstDate), true) + 1);
        $divisor    = min($daysActive, $windowDays);

        if ($totalExpenses <= 0) {
            return [
                'value'          => null,
                'total_expenses' => $totalExpenses,
                'days_used'      => $divisor,
                'status'         => 'NO_EXPENSE_RECORDED',
            ];
        }

        $burnRate = $totalExpenses / $divisor;

        return [
            'value'          => round($burnRate, 2),
            'total_expenses' => $totalExpenses,
            'days_used'      => $divisor,
            'status'         => 'OK',
        ];
    }

    /**
     * Calculate Cash Runway.
     *
     * Formula: Cash Position / Daily Cash Burn Rate
     * Safety: if burn rate is null or <= 0, returns null with descriptive status.
     * NEVER produces INF or NaN.
     *
     * @return array{value: float|null, cash_position: float|null, daily_burn_rate: float|null, status: string}
     */
    public function calculateCashRunway(
        ?float $cashPosition,
        ?float $dailyBurnRate
    ): array {
        if ($cashPosition === null) {
            return [
                'value'           => null,
                'cash_position'   => null,
                'daily_burn_rate' => $dailyBurnRate,
                'status'          => 'NO_CASH_POSITION',
            ];
        }

        if ($dailyBurnRate === null || $dailyBurnRate <= 0) {
            return [
                'value'           => null,
                'cash_position'   => $cashPosition,
                'daily_burn_rate' => $dailyBurnRate,
                'status'          => 'BURN_RATE_UNDEFINED',
            ];
        }

        $runway = round($cashPosition / $dailyBurnRate, 1);

        return [
            'value'           => $runway,
            'cash_position'   => $cashPosition,
            'daily_burn_rate' => $dailyBurnRate,
            'status'          => 'OK',
        ];
    }

    /**
     * Compute a full financial snapshot for a tenant + period.
     *
     * Convenience method that orchestrates all individual calculations
     * and returns a structured result with evidence metadata.
     *
     * @return array Full financial snapshot with evidence
     */
    public function computeSnapshot(
        int $userId,
        ?int $outletId,
        string $startDate,
        string $endDate,
        ?string $timezone = null
    ): array {
        $tz = $timezone ?? $this->resolveTimezone($outletId);
        [$utcStart, $utcEnd] = TimezoneContract::businessDateRangeToUtcRange($startDate, $endDate, $tz);

        // 1. Revenue
        $revenue = $this->calculateRevenue($userId, $outletId, $startDate, $endDate, $tz);

        // 2. COGS
        $cogs = $this->calculateCogs($userId, $outletId, $startDate, $endDate, $tz);

        // 3. Expenses
        $expenses = $this->calculateExpenses($userId, $outletId, $startDate, $endDate);

        // 4. Gross Profit
        $grossProfit = $this->calculateGrossProfit($revenue['value'], $cogs['value']);

        // 5. Net Profit
        $netProfit = $this->calculateNetProfit($grossProfit, $expenses['value']);

        // 6. Cash Position
        $cashPosition = $this->getCashPosition($userId, $outletId);

        // 7. Burn Rate
        $burnRate = $this->calculateDailyCashBurnRate($userId, $outletId, $endDate);

        // 8. Cash Runway
        $cashRunway = $this->calculateCashRunway($cashPosition['value'], $burnRate['value']);

        return [
            'revenue'       => $revenue,
            'cogs'          => $cogs,
            'gross_profit'  => [
                'value'   => $grossProfit,
                'formula' => 'revenue - cogs',
            ],
            'expenses'      => $expenses,
            'net_profit'    => [
                'value'   => $netProfit,
                'formula' => 'gross_profit - expenses',
            ],
            'cash_position' => $cashPosition,
            'burn_rate'     => $burnRate,
            'cash_runway'   => $cashRunway,
            'data_quality'  => [
                'has_untracked_cogs'         => $cogs['has_untracked_cogs'],
                'untracked_items_count'      => $cogs['untracked_items_count'],
                'total_items_count'          => $cogs['total_items_count'],
                'untracked_items_percentage' => $cogs['untracked_items_percentage'],
            ],
            'evidence'      => [
                'period_start'     => $startDate,
                'period_end'       => $endDate,
                'timezone'         => $tz,
                'utc_range_start'  => $utcStart,
                'utc_range_end'    => $utcEnd,
                'user_id'          => $userId,
                'outlet_id'        => $outletId,
                'cancel_statuses'  => self::CANCEL_STATUSES,
            ],
        ];
    }

    // ────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ────────────────────────────────────────────────────────────

    /**
     * Resolve timezone for a given outlet, with Asia/Jakarta fallback.
     */
    private function resolveTimezone(?int $outletId): string
    {
        if ($outletId !== null) {
            return TimezoneContract::resolveOutletTimezone($outletId);
        }
        return 'Asia/Jakarta';
    }
}
