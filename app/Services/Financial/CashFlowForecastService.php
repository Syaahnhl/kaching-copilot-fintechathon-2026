<?php

namespace App\Services\Financial;

use App\Helpers\TimezoneContract;
use App\Models\Outlet;
use App\Models\Transaction;
use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Phase B.5.1 — Deterministic Financial Cash-Flow Forecast Engine
 *
 * Operational Cash-Flow Proxy Forecast based on historical POS transactions
 * and operational recorded expenses.
 *
 * Architecture Invariants:
 * 1. Scope: Operating Cash-Flow Proxy (Inflow - Outflow).
 *    NOT total business liquidity, NOT bank balance, NOT ending cash balance.
 * 2. Timezone: Half-open UTC range queries via TimezoneContract.
 *    Application-layer deterministic bucketing into local outlet business dates.
 *    ZERO raw DATE(created_at_device), ZERO whereDate(), ZERO CONVERT_TZ().
 * 3. Cash Inflow Semantics: 100% Phase A SSOT (Transaction::getActualPaidSql()).
 *    Excludes CANCEL_STATUSES uniformly.
 * 4. Model Hierarchy:
 *    - Tier 1: Day-of-Week (DOW) Seasonal Baseline (>=28d history, >=14 active days).
 *              Median of 4 observations per weekday, robust to single outliers.
 *    - Tier 2: Weighted Moving Average (WMA-14) (14-27d history OR 7-13 active days).
 *              System-wide fallback (never mixed per weekday).
 *    - Tier 3: Fail-Closed (INSUFFICIENT_HISTORY or SPARSE_DATA).
 * 5. Outflow: "Estimated operating outflow based on recorded historical expenses".
 * 6. Multi-Outlet: Per-outlet timezone resolution and bucketing before aggregation.
 * 7. Read-Only: Pure deterministic read and computation. Zero DB mutations.
 */
class CashFlowForecastService
{
    /**
     * Cancellation statuses — SSOT from FinancialCalculationService.
     */
    public const CANCEL_STATUSES = FinancialCalculationService::CANCEL_STATUSES;

    /**
     * Forecast Model identifiers.
     */
    public const MODEL_DOW_SEASONAL = 'DAY_OF_WEEK_SEASONAL_BASELINE';
    public const MODEL_DOW          = self::MODEL_DOW_SEASONAL;
    public const MODEL_WMA14        = 'WEIGHTED_MOVING_AVERAGE_14';
    public const MODEL_NONE         = 'NONE';

    /**
     * Data Quality states.
     */
    public const DQ_SUFFICIENT           = 'SUFFICIENT';
    public const DQ_MODERATE_HISTORY     = 'MODERATE_HISTORY';
    public const DQ_INSUFFICIENT_HISTORY = 'INSUFFICIENT_HISTORY';
    public const DQ_SPARSE_DATA          = 'SPARSE_DATA';
    public const DQ_ZERO_BASELINE        = 'ZERO_BASELINE';
    public const DQ_ACTUAL_SALES_ZERO    = 'ACTUAL_SALES_ZERO';

    /**
     * Standard horizons and window sizes.
     */
    public const DEFAULT_HORIZON_DAYS     = 7;
    public const DOW_CALENDAR_WINDOW_DAYS = 28;
    public const DOW_MIN_ACTIVE_DAYS      = 14;
    public const WMA_CALENDAR_WINDOW_DAYS = 14;
    public const WMA_MIN_ACTIVE_DAYS      = 7;

    /**
     * Indonesian Day-of-Week names (ISO-8601: 1 = Monday ... 7 = Sunday).
     */
    public const WEEKDAY_NAMES = [
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
        7 => 'Minggu',
    ];

    /**
     * Generate 7-day Operating Cash-Flow Proxy Forecast.
     *
     * @param int         $userId
     * @param int|null    $outletId   Specific outlet ID or null for all tenant outlets
     * @param string|null $anchorDate Business date Y-m-d representing the end of baseline (default: yesterday)
     * @param array       $options    Additional forecast options (horizon_days, etc.)
     * @return array
     */
    public function forecast(
        int $userId,
        ?int $outletId = null,
        ?string $anchorDate = null,
        array $options = []
    ): array {
        $horizonDays = (int) ($options['horizon_days'] ?? self::DEFAULT_HORIZON_DAYS);
        if ($horizonDays <= 0 || $horizonDays > 14) {
            $horizonDays = self::DEFAULT_HORIZON_DAYS;
        }

        // 1. Resolve Outlets
        $outletResolution = $this->resolveOutlets($userId, $outletId);
        if (!$outletResolution['valid']) {
            return $this->formatFailClosed(
                'UNAUTHORIZED_OUTLET',
                $outletResolution['error'] ?? 'Outlet tidak ditemukan atau tidak diizinkan.',
                $anchorDate ?? Carbon::today()->subDay()->format('Y-m-d'),
                $horizonDays
            );
        }
        $outlets = $outletResolution['outlets'];

        // 2. Resolve Anchor Date (default: yesterday in primary outlet timezone)
        $primaryTz = $this->resolvePrimaryTimezone($outlets);
        if ($anchorDate === null) {
            $anchorDate = Carbon::now($primaryTz)->subDay()->format('Y-m-d');
        }

        // Baseline date boundaries (28 calendar days ending at anchorDate)
        $windowDays = self::DOW_CALENDAR_WINDOW_DAYS;
        $windowStartDate = Carbon::parse($anchorDate, $primaryTz)->subDays($windowDays - 1)->format('Y-m-d');
        $windowEndDate   = $anchorDate;

        // 3. Check Tenant Earliest Transaction History
        $earliestTxUtc = DB::table('transactions')
            ->where('user_id', $userId)
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->whereNotIn('status', self::CANCEL_STATUSES)
            ->min('created_at_device');

        if ($earliestTxUtc === null) {
            return $this->formatFailClosed(
                self::DQ_INSUFFICIENT_HISTORY,
                'Belum ada riwayat transaksi yang tercatat untuk toko ini.',
                $anchorDate,
                $horizonDays
            );
        }

        // Calculate total history span in calendar days
        $earliestLocalBusinessDate = Carbon::parse($earliestTxUtc, 'UTC')
            ->setTimezone($primaryTz)
            ->format('Y-m-d');
        $totalHistoryDays = Carbon::parse($earliestLocalBusinessDate)->diffInDays(Carbon::parse($anchorDate)) + 1;

        if ($totalHistoryDays < self::WMA_CALENDAR_WINDOW_DAYS) {
            return $this->formatFailClosed(
                self::DQ_INSUFFICIENT_HISTORY,
                "Riwayat transaksi baru berjalan {$totalHistoryDays} hari kalender (minimal dibutuhkan 14 hari).",
                $anchorDate,
                $horizonDays
            );
        }

        // 4. Multi-Outlet Application-Layer Bucketing for 28-day window
        $calendarBuckets = $this->buildCalendarDaysVector($windowStartDate, $windowEndDate);
        $totalTransactionsCount = 0;

        foreach ($outlets as $outlet) {
            $outletTz = !empty($outlet->timezone) ? $outlet->timezone : 'Asia/Jakarta';
            $dtz = new DateTimeZone($outletTz);

            // Compute half-open UTC range for this specific outlet
            [$utcStart, $utcEnd] = TimezoneContract::businessDateRangeToUtcRange(
                $windowStartDate,
                $windowEndDate,
                $outletTz
            );

            // Query transactions strictly using half-open UTC range (NO DATE() or whereDate())
            $query = DB::table('transactions')
                ->where('user_id', $userId)
                ->where('created_at_device', '>=', $utcStart)
                ->where('created_at_device', '<', $utcEnd)
                ->whereNotIn('status', self::CANCEL_STATUSES);

            if ($outlet->id !== null) {
                $query->where('outlet_id', $outlet->id);
            }

            $rows = $query->select([
                'created_at_device',
                DB::raw(Transaction::getActualPaidSql() . ' as paid_amount'),
            ])->get();

            $totalTransactionsCount += $rows->count();

            // Bucket into local business date
            foreach ($rows as $row) {
                $dtUtc = new DateTimeImmutable($row->created_at_device, new DateTimeZone('UTC'));
                $dtLocal = $dtUtc->setTimezone($dtz);
                $bDate = $dtLocal->format('Y-m-d');
                $paid = (float) $row->paid_amount;

                if (isset($calendarBuckets[$bDate])) {
                    $calendarBuckets[$bDate] += $paid;
                }
            }
        }

        // 5. Evaluate Baseline Quality & Gating
        $activeDaysCount = 0;
        foreach ($calendarBuckets as $dailyInflow) {
            if ($dailyInflow > 0.0) {
                $activeDaysCount++;
            }
        }

        $totalBaselineInflow = round(array_sum($calendarBuckets), 2);

        if ($totalBaselineInflow <= 0.0) {
            return $this->formatFailClosed(
                self::DQ_ZERO_BASELINE,
                'Total penerimaan kas operasional bernilai Rp 0 pada periode baseline 28 hari.',
                $anchorDate,
                $horizonDays
            );
        }

        if ($activeDaysCount < self::WMA_MIN_ACTIVE_DAYS) {
            return $this->formatFailClosed(
                self::DQ_SPARSE_DATA,
                "Aktivitas penjualan terlalu jarang ({$activeDaysCount} hari aktif bertransaksi dalam 28 hari kalender, minimal 7 hari).",
                $anchorDate,
                $horizonDays
            );
        }

        // 6. Model Selection (Strict Tier Hierarchy)
        // Tier 1: DOW Seasonal Baseline (history >= 28d AND active days >= 14)
        // Tier 2: WMA-14 (history 14-27d OR active days 7-13)
        $isTier1 = ($totalHistoryDays >= self::DOW_CALENDAR_WINDOW_DAYS && $activeDaysCount >= self::DOW_MIN_ACTIVE_DAYS);
        $model = $isTier1 ? self::MODEL_DOW_SEASONAL : self::MODEL_WMA14;
        $dataQualityState = $isTier1 ? self::DQ_SUFFICIENT : self::DQ_MODERATE_HISTORY;

        // 7. Compute Projected Inflow per Day
        $forecastStartDate = Carbon::parse($anchorDate, $primaryTz)->addDay()->format('Y-m-d');
        $forecastEndDate   = Carbon::parse($anchorDate, $primaryTz)->addDays($horizonDays)->format('Y-m-d');

        $regularlyClosedWeekdays = [];
        $dailyForecastInflows = [];

        if ($model === self::MODEL_DOW_SEASONAL) {
            // Group observations by DOW (1 = Monday ... 7 = Sunday)
            $dowObservations = [1 => [], 2 => [], 3 => [], 4 => [], 5 => [], 6 => [], 7 => []];
            foreach ($calendarBuckets as $dateStr => $inflow) {
                $dow = (int) Carbon::parse($dateStr, $primaryTz)->dayOfWeekIso;
                $dowObservations[$dow][] = (float) $inflow;
            }

            // Calculate robust median baseline per DOW and detect regularly closed days
            $dowBaselines = [];
            foreach ($dowObservations as $dow => $obs) {
                // If weekday consistently closed (>= 3 out of 4 observations are 0)
                $zeroCount = count(array_filter($obs, fn ($val) => $val <= 0.0));
                if ($zeroCount >= 3) {
                    $regularlyClosedWeekdays[] = self::WEEKDAY_NAMES[$dow];
                    $dowBaselines[$dow] = 0.0;
                    continue;
                }

                // Robust median of observations
                $dowBaselines[$dow] = $this->calculateMedian($obs);
            }

            // Build forecast for each day in horizon
            for ($i = 1; $i <= $horizonDays; $i++) {
                $targetDate = Carbon::parse($anchorDate, $primaryTz)->addDays($i)->format('Y-m-d');
                $targetDow  = (int) Carbon::parse($targetDate, $primaryTz)->dayOfWeekIso;
                $dailyForecastInflows[$targetDate] = $dowBaselines[$targetDow] ?? 0.0;
            }
        } else {
            // Tier 2: WMA-14 System-Level Fallback
            // Take the last 14 calendar days ending at anchorDate
            $wmaStartDate = Carbon::parse($anchorDate, $primaryTz)->subDays(self::WMA_CALENDAR_WINDOW_DAYS - 1)->format('Y-m-d');
            $wmaDaysVector = [];
            for ($k = 0; $k < self::WMA_CALENDAR_WINDOW_DAYS; $k++) {
                $curDate = Carbon::parse($wmaStartDate, $primaryTz)->addDays($k)->format('Y-m-d');
                $wmaDaysVector[] = (float) ($calendarBuckets[$curDate] ?? 0.0);
            }

            // Linear weights w_t = 1 .. 14, sum = 105
            $weightedSum = 0.0;
            $totalWeight = 0;
            for ($t = 1; $t <= self::WMA_CALENDAR_WINDOW_DAYS; $t++) {
                $val = $wmaDaysVector[$t - 1];
                $weightedSum += ($t * $val);
                $totalWeight += $t;
            }
            $wmaDailyAverage = round($weightedSum / max(1, $totalWeight), 2);

            for ($i = 1; $i <= $horizonDays; $i++) {
                $targetDate = Carbon::parse($anchorDate, $primaryTz)->addDays($i)->format('Y-m-d');
                $dailyForecastInflows[$targetDate] = $wmaDailyAverage;
            }
        }

        // 8. Expense / Outflow Forecast (SSOT: Estimated operating outflow based on recorded expenses)
        $expenseQuery = DB::table('expenses')
            ->where('user_id', $userId)
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->whereBetween('date', [$windowStartDate, $windowEndDate]);

        $totalHistoricalExpense = (float) $expenseQuery->sum('amount');
        $historicalExpenseCount = $expenseQuery->count();

        // Baseline daily run-rate over the 28-day window
        $dailyExpenseAverage = round($totalHistoricalExpense / (float) $windowDays, 2);
        $projectedTotalOutflow = round($dailyExpenseAverage * $horizonDays, 2);

        // 9. Build Complete Daily Vector (D1 ... D7)
        $dailyForecast = [];
        $projectedTotalInflow = 0.0;

        for ($i = 1; $i <= $horizonDays; $i++) {
            $targetDate = Carbon::parse($anchorDate, $primaryTz)->addDays($i)->format('Y-m-d');
            $targetDow  = (int) Carbon::parse($targetDate, $primaryTz)->dayOfWeekIso;
            $dayName    = self::WEEKDAY_NAMES[$targetDow];

            $dayInflow  = round($dailyForecastInflows[$targetDate] ?? 0.0, 2);
            $dayOutflow = $dailyExpenseAverage;
            $dayNet     = round($dayInflow - $dayOutflow, 2);

            $projectedTotalInflow += $dayInflow;

            $dailyForecast[] = [
                'date'                              => $targetDate,
                'day_of_week'                       => $dayName,
                'projected_inflow'                  => $dayInflow,
                'projected_outflow'                 => $dayOutflow,
                'projected_net_operating_cash_flow' => $dayNet,
            ];
        }

        $projectedTotalInflow = round($projectedTotalInflow, 2);
        $projectedNetOperatingCashFlow = round($projectedTotalInflow - $projectedTotalOutflow, 2);

        // 10. Backtest Verification (if requested or default single-window backtest)
        $backtestResult = null;
        if (($options['include_backtest'] ?? true) === true && $totalHistoryDays >= (self::DOW_CALENDAR_WINDOW_DAYS + 7)) {
            $backtestAnchor = Carbon::parse($anchorDate, $primaryTz)->subDays(7)->format('Y-m-d');
            $backtestResult = $this->backtest($userId, $outletId, $backtestAnchor, ['horizon_days' => 7]);
        }

        // 11. Compile Structured Output Contract
        return [
            'status'                            => 'SUCCESS',
            'forecast_period'                   => [
                'start_date'   => $forecastStartDate,
                'end_date'     => $forecastEndDate,
                'horizon_days' => $horizonDays,
                'anchor_date'  => $anchorDate,
                'timezone'     => $primaryTz,
            ],
            'horizon_days'                      => $horizonDays,
            'model'                             => $model,
            'data_quality'                      => $dataQualityState,
            'data_quality_details'              => [
                'state'                     => $dataQualityState,
                'total_history_days'        => $totalHistoryDays,
                'baseline_active_days'      => $activeDaysCount,
                'baseline_total_days'       => $windowDays,
                'baseline_total_inflow'     => $totalBaselineInflow,
                'baseline_total_expense'    => $totalHistoricalExpense,
                'expense_records_count'     => $historicalExpenseCount,
                'regularly_closed_weekdays' => $regularlyClosedWeekdays,
            ],
            'regularly_closed_weekdays'         => $regularlyClosedWeekdays,
            'projected_inflow'                  => $projectedTotalInflow,
            'projected_outflow'                 => $projectedTotalOutflow,
            'projected_net_operating_cash_flow' => $projectedNetOperatingCashFlow,
            'daily_forecast'                    => $dailyForecast,
            'backtest'                          => $backtestResult,
            'assumptions'                       => [
                'Arus kas masuk diestimasi berdasarkan pola historis penerimaan kas riil (transaksi berstatus Selesai, memperhitungkan DP/uang muka, dan mengecualikan pembatalan/void).',
                'Beban operasional merupakan estimasi berbasis rata-rata pengeluaran historis yang tercatat di sistem.',
                'Proyeksi mengasumsikan kelanjutan tren operasional normal tanpa disrupsi eksternal besar.',
            ],
            'limitations'                       => [
                'OPERATING CASH-FLOW PROXY: Forecast ini mengukur perkiraan arus kas masuk operasional dan beban tercatat, BUKAN total saldo kas/bank usaha (business liquidity).',
                'Kaching tidak terhubung ke API rekening bank atau e-wallet merchant, sehingga tidak memproyeksikan ending cash balance perbankan.',
                'Pengeluaran masa depan yang belum tercatat (tagihan tempo/utang dagang yang belum dibukukan) tidak dapat diproyeksikan.',
            ],
        ];
    }

    /**
     * Deterministic Historical Backtest against completed actual data.
     *
     * @param int         $userId
     * @param int|null    $outletId
     * @param string|null $anchorDate Date T_anchor where the forecast is simulated
     * @param array       $options    Options (horizon_days, rolling, rolling_windows)
     * @return array
     */
    public function backtest(
        int $userId,
        ?int $outletId = null,
        ?string $anchorDate = null,
        array $options = []
    ): array {
        $horizonDays = (int) ($options['horizon_days'] ?? self::DEFAULT_HORIZON_DAYS);
        if ($horizonDays <= 0 || $horizonDays > 14) {
            $horizonDays = self::DEFAULT_HORIZON_DAYS;
        }

        $outletResolution = $this->resolveOutlets($userId, $outletId);
        if (!$outletResolution['valid']) {
            return [
                'status'  => 'ERROR',
                'message' => $outletResolution['error'] ?? 'Outlet tidak valid.',
            ];
        }
        $outlets = $outletResolution['outlets'];
        $primaryTz = $this->resolvePrimaryTimezone($outlets);

        if ($anchorDate === null) {
            $anchorDate = Carbon::now($primaryTz)->subDays(7)->format('Y-m-d');
        }

        // Check if rolling backtest requested
        if (($options['rolling'] ?? false) === true || ($options['rolling_windows'] ?? 1) >= 3) {
            return $this->executeRollingBacktest($userId, $outletId, $anchorDate, $options, $outlets, $primaryTz);
        }

        return $this->evaluateSingleBacktestWindow($userId, $outletId, $anchorDate, $horizonDays, $outlets, $primaryTz);
    }

    /**
     * Evaluate a single backtest window.
     */
    private function evaluateSingleBacktestWindow(
        int $userId,
        ?int $outletId,
        string $anchorDate,
        int $horizonDays,
        array $outlets,
        string $primaryTz
    ): array {
        // 1. Run forecast simulation strictly using data up to anchorDate
        $simulatedForecast = $this->forecast($userId, $outletId, $anchorDate, [
            'horizon_days'     => $horizonDays,
            'include_backtest' => false,
        ]);

        if ($simulatedForecast['status'] !== 'SUCCESS') {
            return [
                'status'          => $simulatedForecast['status'],
                'message'         => 'Backtest tidak dapat dievaluasi karena data baseline tidak mencukupi.',
                'anchor_date'     => $anchorDate,
                'wape'            => null,
                'mae'             => null,
                'bias'            => null,
                'actual_total'    => null,
                'forecast_total'  => null,
                'comparison'      => [],
            ];
        }

        // 2. Fetch Actual Inflow for the 7 completed days [anchorDate + 1 .. anchorDate + 7]
        $evalStartDate = Carbon::parse($anchorDate, $primaryTz)->addDay()->format('Y-m-d');
        $evalEndDate   = Carbon::parse($anchorDate, $primaryTz)->addDays($horizonDays)->format('Y-m-d');

        $actualBuckets = $this->buildCalendarDaysVector($evalStartDate, $evalEndDate);

        foreach ($outlets as $outlet) {
            $outletTz = !empty($outlet->timezone) ? $outlet->timezone : 'Asia/Jakarta';
            $dtz = new DateTimeZone($outletTz);

            [$utcStart, $utcEnd] = TimezoneContract::businessDateRangeToUtcRange(
                $evalStartDate,
                $evalEndDate,
                $outletTz
            );

            $rows = DB::table('transactions')
                ->where('user_id', $userId)
                ->where('created_at_device', '>=', $utcStart)
                ->where('created_at_device', '<', $utcEnd)
                ->whereNotIn('status', self::CANCEL_STATUSES)
                ->when($outlet->id !== null, fn ($q) => $q->where('outlet_id', $outlet->id))
                ->select([
                    'created_at_device',
                    DB::raw(Transaction::getActualPaidSql() . ' as paid_amount'),
                ])->get();

            foreach ($rows as $row) {
                $dtUtc = new DateTimeImmutable($row->created_at_device, new DateTimeZone('UTC'));
                $dtLocal = $dtUtc->setTimezone($dtz);
                $bDate = $dtLocal->format('Y-m-d');
                $paid = (float) $row->paid_amount;

                if (isset($actualBuckets[$bDate])) {
                    $actualBuckets[$bDate] += $paid;
                }
            }
        }

        // 3. Compute Metrics (WAPE, MAE, Bias)
        $forecastDailyMap = [];
        foreach ($simulatedForecast['daily_forecast'] as $dayItem) {
            $forecastDailyMap[$dayItem['date']] = (float) $dayItem['projected_inflow'];
        }

        $forecastVector = [];
        $actualVector = [];
        $comparison = [];

        for ($i = 1; $i <= $horizonDays; $i++) {
            $curDate = Carbon::parse($anchorDate, $primaryTz)->addDays($i)->format('Y-m-d');
            $dow = (int) Carbon::parse($curDate, $primaryTz)->dayOfWeekIso;

            $actualVal   = round($actualBuckets[$curDate] ?? 0.0, 2);
            $forecastVal = round($forecastDailyMap[$curDate] ?? 0.0, 2);
            $absError    = round(abs($actualVal - $forecastVal), 2);

            $forecastVector[] = $forecastVal;
            $actualVector[]   = $actualVal;

            $comparison[] = [
                'date'           => $curDate,
                'day_of_week'    => self::WEEKDAY_NAMES[$dow],
                'actual_inflow'  => $actualVal,
                'forecast_inflow'=> $forecastVal,
                'absolute_error' => $absError,
            ];
        }

        $metrics = $this->calculateEvaluationMetrics($forecastVector, $actualVector);

        return array_merge([
            'anchor_date'     => $anchorDate,
            'eval_period'     => ['start' => $evalStartDate, 'end' => $evalEndDate],
            'comparison'      => $comparison,
        ], $metrics);
    }

    /**
     * Calculate evaluation metrics (WAPE, MAE, Bias) deterministically.
     *
     * @param array<float> $forecastVector
     * @param array<float> $actualVector
     * @return array
     */
    public function calculateEvaluationMetrics(array $forecastVector, array $actualVector): array
    {
        $horizonDays = max(1, count($forecastVector));
        $sumActual = 0.0;
        $sumForecast = 0.0;
        $sumAbsoluteError = 0.0;

        foreach ($forecastVector as $idx => $fVal) {
            $aVal = (float) ($actualVector[$idx] ?? 0.0);
            $fVal = (float) $fVal;

            $sumActual += $aVal;
            $sumForecast += $fVal;
            $sumAbsoluteError += abs($aVal - $fVal);
        }

        $sumActual   = round($sumActual, 2);
        $sumForecast = round($sumForecast, 2);
        $mae = round($sumAbsoluteError / (float) $horizonDays, 2);

        // Case 1: actual = 0 and forecast = 0 => WAPE null, MAE 0, Bias 0
        if ($sumActual <= 0.0 && $sumForecast <= 0.0) {
            return [
                'status'          => self::DQ_ACTUAL_SALES_ZERO,
                'wape'            => null,
                'mae'             => 0.0,
                'bias'            => 0.0,
                'actual_total'    => 0.0,
                'forecast_total'  => 0.0,
                'actual_inflow'   => 0.0,
                'forecast_inflow' => 0.0,
            ];
        }

        // Case 2: actual = 0 and forecast > 0 => WAPE null, status ACTUAL_SALES_ZERO
        if ($sumActual <= 0.0 && $sumForecast > 0.0) {
            return [
                'status'          => self::DQ_ACTUAL_SALES_ZERO,
                'wape'            => null,
                'mae'             => $mae,
                'bias'            => null,
                'actual_total'    => 0.0,
                'forecast_total'  => $sumForecast,
                'actual_inflow'   => 0.0,
                'forecast_inflow' => $sumForecast,
            ];
        }

        // Case 3: forecast = 0 and actual > 0 => WAPE 100% (1.0), Bias -100% (-1.0)
        if ($sumForecast <= 0.0 && $sumActual > 0.0) {
            return [
                'status'          => 'SUCCESS',
                'wape'            => 1.0,
                'mae'             => $mae,
                'bias'            => -1.0,
                'actual_total'    => $sumActual,
                'forecast_total'  => 0.0,
                'actual_inflow'   => $sumActual,
                'forecast_inflow' => 0.0,
            ];
        }

        // Case 4: Standard Normal Case (actual > 0 and forecast > 0)
        $wape = round($sumAbsoluteError / $sumActual, 4);
        $bias = round(($sumForecast - $sumActual) / $sumActual, 4);

        return [
            'status'          => 'SUCCESS',
            'wape'            => $wape,
            'mae'             => $mae,
            'bias'            => $bias,
            'actual_total'    => $sumActual,
            'forecast_total'  => $sumForecast,
            'actual_inflow'   => $sumActual,
            'forecast_inflow' => $sumForecast,
        ];
    }

    /**
     * Execute rolling backtest across consecutive windows.
     */
    private function executeRollingBacktest(
        int $userId,
        ?int $outletId,
        string $anchorDate,
        array $options,
        array $outlets,
        string $primaryTz
    ): array {
        $windowCount = max(3, (int) ($options['rolling_windows'] ?? 3));
        $horizonDays = (int) ($options['horizon_days'] ?? self::DEFAULT_HORIZON_DAYS);

        $windows = [];
        $validWapeList = [];
        $validMaeList = [];
        $validBiasList = [];

        for ($w = 0; $w < $windowCount; $w++) {
            $curAnchor = Carbon::parse($anchorDate, $primaryTz)->subDays($w * $horizonDays)->format('Y-m-d');
            $eval = $this->evaluateSingleBacktestWindow($userId, $outletId, $curAnchor, $horizonDays, $outlets, $primaryTz);
            $windows[] = $eval;

            if ($eval['status'] === 'SUCCESS') {
                if ($eval['wape'] !== null) {
                    $validWapeList[] = $eval['wape'];
                }
                if ($eval['mae'] !== null) {
                    $validMaeList[] = $eval['mae'];
                }
                if ($eval['bias'] !== null) {
                    $validBiasList[] = $eval['bias'];
                }
            }
        }

        $meanWape = !empty($validWapeList) ? round(array_sum($validWapeList) / count($validWapeList), 4) : null;
        $meanMae  = !empty($validMaeList) ? round(array_sum($validMaeList) / count($validMaeList), 2) : null;
        $meanBias = !empty($validBiasList) ? round(array_sum($validBiasList) / count($validBiasList), 4) : null;

        return [
            'status'             => 'SUCCESS',
            'mode'               => 'ROLLING',
            'is_rolling'         => true,
            'windows_count'      => count($windows),
            'mean_wape'          => $meanWape,
            'mean_mae'           => $meanMae,
            'mean_bias'          => $meanBias,
            'windows'            => $windows,
            'evaluated_windows'  => $windows,
        ];
    }

    // ────────────────────────────────────────────────────────────
    // PRIVATE DETERMINISTIC HELPERS
    // ────────────────────────────────────────────────────────────

    /**
     * Resolve outlets for the tenant.
     */
    private function resolveOutlets(int $userId, ?int $outletId): array
    {
        if ($outletId !== null) {
            $outlet = DB::table('outlets')
                ->where('user_id', $userId)
                ->where('id', $outletId)
                ->first();

            if (!$outlet) {
                return [
                    'valid' => false,
                    'error' => 'Outlet tidak ditemukan atau bukan milik pengguna ini.',
                ];
            }
            return [
                'valid'   => true,
                'outlets' => [$outlet],
            ];
        }

        // All outlets owned by tenant
        $outlets = DB::table('outlets')
            ->where('user_id', $userId)
            ->get()
            ->all();

        if (empty($outlets)) {
            // Virtual fallback outlet record
            $outlets = [(object) ['id' => null, 'timezone' => 'Asia/Jakarta']];
        }

        return [
            'valid'   => true,
            'outlets' => $outlets,
        ];
    }

    /**
     * Resolve primary timezone for tenant aggregation.
     */
    private function resolvePrimaryTimezone(array $outlets): string
    {
        foreach ($outlets as $outlet) {
            if (!empty($outlet->timezone)) {
                return $outlet->timezone;
            }
        }
        return 'Asia/Jakarta';
    }

    /**
     * Build empty calendar vector initialized to 0.0 for all days in range.
     */
    private function buildCalendarDaysVector(string $startDate, string $endDate): array
    {
        $vector = [];
        $start = Carbon::parse($startDate);
        $end   = Carbon::parse($endDate);

        $curr = clone $start;
        while ($curr->lte($end)) {
            $vector[$curr->format('Y-m-d')] = 0.0;
            $curr->addDay();
        }

        return $vector;
    }

    /**
     * Calculate median of numerical array.
     */
    private function calculateMedian(array $numbers): float
    {
        if (empty($numbers)) {
            return 0.0;
        }

        sort($numbers, SORT_NUMERIC);
        $count = count($numbers);
        $middle = (int) floor($count / 2);

        if ($count % 2 === 1) {
            return (float) $numbers[$middle];
        }

        return round(((float) $numbers[$middle - 1] + (float) $numbers[$middle]) / 2.0, 2);
    }

    /**
     * Format fail-closed response when baseline invariants are not met.
     */
    private function formatFailClosed(string $state, string $message, string $anchorDate, int $horizonDays): array
    {
        $startDate = Carbon::parse($anchorDate)->addDay()->format('Y-m-d');
        $endDate   = Carbon::parse($anchorDate)->addDays($horizonDays)->format('Y-m-d');

        return [
            'status'                            => $state,
            'forecast_period'                   => [
                'start_date'   => $startDate,
                'end_date'     => $endDate,
                'horizon_days' => $horizonDays,
                'anchor_date'  => $anchorDate,
                'timezone'     => 'Asia/Jakarta',
            ],
            'horizon_days'                      => $horizonDays,
            'model'                             => self::MODEL_NONE,
            'data_quality'                      => $state,
            'data_quality_details'              => [
                'state'                     => $state,
                'message'                   => $message,
                'regularly_closed_weekdays' => [],
            ],
            'regularly_closed_weekdays'         => [],
            'projected_inflow'                  => null,
            'projected_outflow'                 => null,
            'projected_net_operating_cash_flow' => null,
            'daily_forecast'                    => [],
            'backtest'                          => null,
            'assumptions'                       => [],
            'limitations'                       => [
                $message,
                'Data kualitas baseline tidak memenuhi syarat minimum untuk menghasilkan proyeksi deterministik.',
            ],
        ];
    }
}
