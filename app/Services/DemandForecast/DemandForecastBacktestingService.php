<?php

namespace App\Services\DemandForecast;

use App\Models\DailyMenuSalesSummary;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\OutletWeatherForecast;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class DemandForecastBacktestingService
{
    public const TARGET_TRANSACTION_COUNT = 'transaction_count';
    public const TARGET_MENU_QTY = 'menu_qty';
    public const TARGET_CUSTOMER_COUNT = 'customer_count';

    /**
     * Indonesian Public Holidays (Fixed & Key Known Observances for calendar feature extraction)
     */
    protected const KNOWN_HOLIDAYS = [
        '01-01', // Tahun Baru Masehi
        '05-01', // Hari Buruh
        '06-01', // Hari Lahir Pancasila
        '08-17', // Hari Kemerdekaan RI
        '12-25', // Hari Raya Natal
    ];

    /**
     * Calculate WAPE (Weighted Absolute Percentage Error).
     * WAPE = sum(|actual - pred|) / sum(actual)
     *
     * @param array $actuals
     * @param array $predictions
     * @return float|null
     */
    public function calculateWape(array $actuals, array $predictions): ?float
    {
        $count = count($actuals);
        if ($count === 0 || $count !== count($predictions)) {
            return null;
        }

        $sumActual = 0.0;
        $sumAbsError = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $act = (float) $actuals[$i];
            $pred = (float) $predictions[$i];
            $sumActual += $act;
            $sumAbsError += abs($act - $pred);
        }

        if ($sumActual <= 0.0) {
            // Jika total aktual nol, jika semua error juga nol maka WAPE = 0, selain itu null
            return $sumAbsError == 0.0 ? 0.0 : null;
        }

        return round($sumAbsError / $sumActual, 4);
    }

    /**
     * Calculate MAE (Mean Absolute Error).
     *
     * @param array $actuals
     * @param array $predictions
     * @return float|null
     */
    public function calculateMae(array $actuals, array $predictions): ?float
    {
        $count = count($actuals);
        if ($count === 0 || $count !== count($predictions)) {
            return null;
        }

        $sumAbsError = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $sumAbsError += abs((float) $actuals[$i] - (float) $predictions[$i]);
        }

        return round($sumAbsError / $count, 4);
    }

    /**
     * Calculate Forecast Bias (Mean Forecast Error / Total Actual).
     * Bias = sum(pred - actual) / sum(actual)
     *
     * @param array $actuals
     * @param array $predictions
     * @return float|null
     */
    public function calculateBias(array $actuals, array $predictions): ?float
    {
        $count = count($actuals);
        if ($count === 0 || $count !== count($predictions)) {
            return null;
        }

        $sumActual = 0.0;
        $sumError = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $act = (float) $actuals[$i];
            $pred = (float) $predictions[$i];
            $sumActual += $act;
            $sumError += ($pred - $act);
        }

        if ($sumActual <= 0.0) {
            return $sumError == 0.0 ? 0.0 : null;
        }

        return round($sumError / $sumActual, 4);
    }

    /**
     * Calculate Relative WAPE Improvement of candidate over baseline.
     * RelImprovement = (WAPE_baseline - WAPE_candidate) / WAPE_baseline
     *
     * @param float|null $wapeBaseline
     * @param float|null $wapeCandidate
     * @return float|null
     */
    public function calculateRelativeWapeImprovement(?float $wapeBaseline, ?float $wapeCandidate): ?float
    {
        if ($wapeBaseline === null || $wapeCandidate === null || $wapeBaseline <= 0.0) {
            return null;
        }

        return round(($wapeBaseline - $wapeCandidate) / $wapeBaseline, 4);
    }

    /**
     * Extract historical daily series for backtesting.
     *
     * @param int $outletId
     * @param string $targetType TARGET_TRANSACTION_COUNT or TARGET_MENU_QTY
     * @param int|null $menuId Required if targetType is TARGET_MENU_QTY
     * @param string $startDate YYYY-MM-DD
     * @param string $endDate YYYY-MM-DD
     * @return array Continuous array of [date => string, value => float, is_zero => bool]
     */
    public function extractDailySeries(
        int $outletId,
        string $targetType,
        ?int $menuId,
        string $startDate,
        string $endDate
    ): array {
        $start = Carbon::parse($startDate, 'Asia/Jakarta')->startOfDay();
        $end = Carbon::parse($endDate, 'Asia/Jakarta')->startOfDay();

        if ($start->gt($end)) {
            throw new InvalidArgumentException("Start date {$startDate} cannot be after end date {$endDate}.");
        }

        $rawMap = [];

        if ($targetType === self::TARGET_TRANSACTION_COUNT) {
            // Aggregate valid completed transactions strictly in WIB
            $transactions = Transaction::where('outlet_id', $outletId)
                ->whereIn('status', ['completed', 'Selesai'])
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [
                    $start->copy()->setTimezone('UTC'),
                    $end->copy()->endOfDay()->setTimezone('UTC'),
                ])
                ->get();

            foreach ($transactions as $tx) {
                $wibDate = Carbon::parse($tx->created_at)->setTimezone('Asia/Jakarta')->toDateString();
                $rawMap[$wibDate] = ($rawMap[$wibDate] ?? 0) + 1;
            }
        } elseif ($targetType === self::TARGET_MENU_QTY) {
            if (!$menuId) {
                throw new InvalidArgumentException("menuId is required for TARGET_MENU_QTY.");
            }

            $summaries = DailyMenuSalesSummary::where('outlet_id', $outletId)
                ->where('menu_id', $menuId)
                ->whereBetween('business_date', [$startDate, $endDate])
                ->get();

            foreach ($summaries as $summary) {
                $bDate = $summary->business_date instanceof \DateTimeInterface
                    ? $summary->business_date->format('Y-m-d')
                    : Carbon::parse($summary->business_date)->toDateString();
                $rawMap[$bDate] = (float) $summary->total_qty;
            }
        } else {
            throw new InvalidArgumentException("Unsupported target type: {$targetType}");
        }

        // Build gap-free daily timeline
        $series = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $dateStr = $cursor->toDateString();
            $val = isset($rawMap[$dateStr]) ? (float) $rawMap[$dateStr] : 0.0;
            $series[] = [
                'date' => $dateStr,
                'value' => $val,
                'is_zero' => ($val === 0.0),
                'day_of_week' => (int) $cursor->dayOfWeekIso, // 1 (Mon) to 7 (Sun)
            ];
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * Compute statistical baseline: DOW-Median (Day-of-Week Median).
     * Computes median of the same day-of-week on historical observations up to cutoff date.
     *
     * @param array $historySeries Array of ['date' => string, 'value' => float, 'day_of_week' => int]
     * @param string $targetDate YYYY-MM-DD
     * @param int $maxWeeks Maximum historical weeks to consider (default 8)
     * @return float
     */
    public function predictDowMedian(array $historySeries, string $targetDate, int $maxWeeks = 8): float
    {
        $targetDow = (int) Carbon::parse($targetDate, 'Asia/Jakarta')->dayOfWeekIso;
        $sameDowValues = [];

        // Traverse history from latest to oldest
        $reversed = array_reverse($historySeries);
        foreach ($reversed as $item) {
            if ((int) $item['day_of_week'] === $targetDow) {
                $sameDowValues[] = (float) $item['value'];
                if (count($sameDowValues) >= $maxWeeks) {
                    break;
                }
            }
        }

        if (empty($sameDowValues)) {
            return 0.0;
        }

        sort($sameDowValues);
        $count = count($sameDowValues);
        $mid = intdiv($count, 2);

        if ($count % 2 === 1) {
            return round($sameDowValues[$mid], 3);
        }

        return round(($sameDowValues[$mid - 1] + $sameDowValues[$mid]) / 2.0, 3);
    }

    /**
     * Compute statistical baseline: WMA-14 (14-day Weighted Moving Average).
     * Linearly decaying weights from day 14 (weight 14) down to day 1 (weight 1).
     *
     * @param array $historySeries Array of ['date' => string, 'value' => float]
     * @param int $windowSize Default 14
     * @return float
     */
    public function predictWma(array $historySeries, int $windowSize = 14): float
    {
        $count = count($historySeries);
        if ($count === 0) {
            return 0.0;
        }

        $slice = array_slice($historySeries, max(0, $count - $windowSize));
        $actualWindow = count($slice);
        if ($actualWindow === 0) {
            return 0.0;
        }

        $weightedSum = 0.0;
        $totalWeight = 0;

        for ($i = 0; $i < $actualWindow; $i++) {
            $weight = $i + 1;
            $weightedSum += ((float) $slice[$i]['value'] * $weight);
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? round($weightedSum / $totalWeight, 3) : 0.0;
    }

    /**
     * Compute baseline: Naive-7 (Seasonal persistence).
     *
     * @param array $historySeries
     * @param int $lagDays Default 7
     * @return float
     */
    public function predictNaiveLag(array $historySeries, int $lagDays = 7): float
    {
        $count = count($historySeries);
        if ($count < $lagDays) {
            return $count > 0 ? (float) end($historySeries)['value'] : 0.0;
        }

        return round((float) $historySeries[$count - $lagDays]['value'], 3);
    }

    /**
     * Extract tabular feature vector for ML modeling.
     * Prevents target leakage by taking ONLY history <= cutoffDate.
     *
     * @param array $historySeries Continuous history up to cutoff date
     * @param string $targetDate Target date to predict
     * @param int|null $outletId
     * @param bool $includeWeather Whether to include weather features
     * @return array [features => array, weather_status => string]
     */
    public function extractFeaturesForBacktest(
        array $historySeries,
        string $targetDate,
        ?int $outletId = null,
        bool $includeWeather = false,
        $asOfDate = null
    ): array {
        $n = count($historySeries);
        $targetCarbon = Carbon::parse($targetDate, 'Asia/Jakarta');

        // Extract Lags relative to cutoff date (history index: n-1 is lag 1, n-2 is lag 2, etc.)
        $lag1 = $n >= 1 ? (float) $historySeries[$n - 1]['value'] : 0.0;
        $lag2 = $n >= 2 ? (float) $historySeries[$n - 2]['value'] : $lag1;
        $lag7 = $n >= 7 ? (float) $historySeries[$n - 7]['value'] : $lag1;
        $lag14 = $n >= 14 ? (float) $historySeries[$n - 14]['value'] : $lag7;
        $lag28 = $n >= 28 ? (float) $historySeries[$n - 28]['value'] : $lag14;

        // Rolling statistics (7, 14, 28)
        $roll7 = $this->calculateRollingStats(array_slice($historySeries, max(0, $n - 7)));
        $roll14 = $this->calculateRollingStats(array_slice($historySeries, max(0, $n - 14)));
        $roll28 = $this->calculateRollingStats(array_slice($historySeries, max(0, $n - 28)));

        // Same DOW stats
        $dowMedian = $this->predictDowMedian($historySeries, $targetDate, 8);
        $wma14 = $this->predictWma($historySeries, 14);

        // Calendar features
        $dayOfWeek = (int) $targetCarbon->dayOfWeekIso; // 1-7
        $isWeekend = in_array($dayOfWeek, [6, 7], true) ? 1.0 : 0.0;
        $dayOfMonth = (int) $targetCarbon->day;
        $month = (int) $targetCarbon->month;
        $isPayday = ($dayOfMonth >= 25 || $dayOfMonth <= 1) ? 1.0 : 0.0;
        $isHoliday = in_array($targetCarbon->format('m-d'), self::KNOWN_HOLIDAYS, true) ? 1.0 : 0.0;

        $features = [
            'f_lag_1' => $lag1,
            'f_lag_2' => $lag2,
            'f_lag_7' => $lag7,
            'f_lag_14' => $lag14,
            'f_lag_28' => $lag28,
            'f_roll_mean_7' => $roll7['mean'],
            'f_roll_std_7' => $roll7['std'],
            'f_roll_mean_14' => $roll14['mean'],
            'f_roll_std_14' => $roll14['std'],
            'f_roll_mean_28' => $roll28['mean'],
            'f_roll_std_28' => $roll28['std'],
            'f_cv_28' => $roll28['cv'],
            'f_trend_7_28' => $roll28['mean'] > 0 ? round($roll7['mean'] / $roll28['mean'], 4) : 1.0,
            'f_dow_median' => $dowMedian,
            'f_wma_14' => $wma14,
            'f_target_dow' => (float) $dayOfWeek,
            'f_target_is_weekend' => $isWeekend,
            'f_target_day_of_month' => (float) $dayOfMonth,
            'f_target_month' => (float) $month,
            'f_target_is_payday' => $isPayday,
            'f_target_is_holiday' => $isHoliday,
        ];

        $weatherStatus = 'NOT_REQUESTED';
        if ($includeWeather) {
            $weatherData = $this->fetchTargetWeather($outletId, $targetDate, $asOfDate);
            $features['w_temp_mean_c'] = $weatherData['temp_mean'];
            $features['w_precipitation_mm'] = $weatherData['precip_sum'];
            $features['w_rain_prob_pct'] = $weatherData['rain_prob_max'];
            $features['w_is_rainy'] = $weatherData['is_rainy'];
            $features['w_weather_code'] = (float) $weatherData['weather_code'];
            $weatherStatus = $weatherData['status'];
        }

        return [
            'features' => $features,
            'weather_status' => $weatherStatus,
        ];
    }

    /**
     * Compute rolling mean, std, and coefficient of variation.
     */
    protected function calculateRollingStats(array $slice): array
    {
        $count = count($slice);
        if ($count === 0) {
            return ['mean' => 0.0, 'std' => 0.0, 'cv' => 0.0];
        }

        $sum = 0.0;
        foreach ($slice as $item) {
            $sum += (float) $item['value'];
        }
        $mean = $sum / $count;

        $varianceSum = 0.0;
        foreach ($slice as $item) {
            $diff = (float) $item['value'] - $mean;
            $varianceSum += ($diff * $diff);
        }
        $std = $count > 1 ? sqrt($varianceSum / ($count - 1)) : 0.0;
        $cv = $mean > 0.0 ? ($std / $mean) : 0.0;

        return [
            'mean' => round($mean, 4),
            'std' => round($std, 4),
            'cv' => round($cv, 4),
        ];
    }

    /**
     * Fetch weather data for target date with explicit point-in-time vintage filtering and leakage prevention.
     *
     * @param int|null $outletId
     * @param string $targetDate
     * @param \Carbon\Carbon|string|null $asOfDate Forecast origin cutoff (point-in-time boundary)
     * @return array
     */
    public function fetchTargetWeather(?int $outletId, string $targetDate, $asOfDate = null): array
    {
        if (!$outletId) {
            return [
                'temp_mean' => 28.0,
                'precip_sum' => 0.0,
                'rain_prob_max' => 0.0,
                'is_rainy' => 0.0,
                'weather_code' => 0,
                'status' => 'NO_OUTLET_SPECIFIED',
            ];
        }

        $targetStart = Carbon::parse($targetDate, 'Asia/Jakarta')->startOfDay()->setTimezone('UTC');
        $targetEnd = Carbon::parse($targetDate, 'Asia/Jakarta')->endOfDay()->setTimezone('UTC');

        $asOfCarbon = $asOfDate !== null
            ? ($asOfDate instanceof Carbon ? $asOfDate->copy()->setTimezone('UTC') : Carbon::parse($asOfDate, 'Asia/Jakarta')->endOfDay()->setTimezone('UTC'))
            : null;

        if ($asOfCarbon !== null) {
            // Point-in-time evaluation mode: STRICTLY query forecast vintages available on or before $asOfDate
            $subQuery = OutletWeatherForecast::query()
                ->selectRaw('MAX(id) as max_id')
                ->where('outlet_id', $outletId)
                ->where('is_forecast', true)
                ->whereBetween('forecast_for_timestamp', [$targetStart, $targetEnd])
                ->where('retrieved_at', '<=', $asOfCarbon)
                ->where(function ($q) use ($asOfCarbon) {
                    $q->whereNull('forecast_published_at')
                      ->orWhere('forecast_published_at', '<=', $asOfCarbon);
                })
                ->groupBy('forecast_for_timestamp');

            $forecasts = OutletWeatherForecast::query()
                ->whereIn('id', $subQuery)
                ->orderBy('forecast_for_timestamp', 'asc')
                ->get();

            if ($forecasts->isEmpty()) {
                return [
                    'temp_mean' => 28.0,
                    'precip_sum' => 0.0,
                    'rain_prob_max' => 0.0,
                    'is_rainy' => 0.0,
                    'weather_code' => 0,
                    'status' => 'WEATHER_DATA_MISSING_VINTAGE_UNAVAILABLE',
                ];
            }

            $tempSum = 0.0;
            $precipSum = 0.0;
            $rainProbMax = 0.0;
            $dominantCode = 0;

            foreach ($forecasts as $row) {
                $tempSum += (float) $row->temperature_c;
                $precipSum += (float) $row->precipitation_mm;
                if ((float) $row->precipitation_probability_pct > $rainProbMax) {
                    $rainProbMax = (float) $row->precipitation_probability_pct;
                }
                $dominantCode = (int) $row->weather_code;
            }

            $count = $forecasts->count();

            return [
                'temp_mean' => round($tempSum / $count, 2),
                'precip_sum' => round($precipSum, 2),
                'rain_prob_max' => round($rainProbMax, 2),
                'is_rainy' => $precipSum > 1.0 ? 1.0 : 0.0,
                'weather_code' => $dominantCode,
                'status' => 'POINT_IN_TIME_FORECAST_VALID',
            ];
        }

        // Legacy / Unbounded mode (reanalysis or latest available forecast)
        $subQuery = OutletWeatherForecast::query()
            ->selectRaw('MAX(id) as max_id')
            ->where('outlet_id', $outletId)
            ->whereBetween('forecast_for_timestamp', [$targetStart, $targetEnd])
            ->groupBy('forecast_for_timestamp');

        $forecasts = OutletWeatherForecast::query()
            ->whereIn('id', $subQuery)
            ->orderBy('forecast_for_timestamp', 'asc')
            ->get();

        if ($forecasts->isEmpty()) {
            return [
                'temp_mean' => 28.0,
                'precip_sum' => 0.0,
                'rain_prob_max' => 0.0,
                'is_rainy' => 0.0,
                'weather_code' => 0,
                'status' => 'WEATHER_DATA_MISSING_FALLBACK_DEFAULT',
            ];
        }

        $tempSum = 0.0;
        $precipSum = 0.0;
        $rainProbMax = 0.0;
        $dominantCode = 0;
        $isAllForecast = true;

        foreach ($forecasts as $row) {
            $tempSum += (float) $row->temperature_c;
            $precipSum += (float) $row->precipitation_mm;
            if ((float) $row->precipitation_probability_pct > $rainProbMax) {
                $rainProbMax = (float) $row->precipitation_probability_pct;
            }
            $dominantCode = (int) $row->weather_code;
            if (!$row->is_forecast) {
                $isAllForecast = false;
            }
        }

        $count = $forecasts->count();
        $status = $isAllForecast
            ? 'POINT_IN_TIME_FORECAST_VALID'
            : 'REANALYSIS_ARCHIVE_PROXY_LEAKAGE_RISK';

        return [
            'temp_mean' => round($tempSum / $count, 2),
            'precip_sum' => round($precipSum, 2),
            'rain_prob_max' => round($rainProbMax, 2),
            'is_rainy' => $precipSum > 1.0 ? 1.0 : 0.0,
            'weather_code' => $dominantCode,
            'status' => $status,
        ];
    }

    /**
     * Run deterministic native regression solver (Regularized Ridge / Linear ensemble) on tabular features.
     *
     * @param array $trainX 2D array of feature vectors
     * @param array $trainY 1D array of actual values
     * @param array $testX 1D feature vector for test instance
     * @param float $alpha L2 regularization parameter (default 1.0)
     * @return float
     */
    public function predictNativeRidge(array $trainX, array $trainY, array $testX, float $alpha = 1.0): float
    {
        $nSamples = count($trainX);
        if ($nSamples === 0) {
            return 0.0;
        }

        $featureKeys = array_keys($testX);
        $nFeatures = count($featureKeys);

        // Standardize features to prevent numerical overflow
        $means = [];
        $stds = [];
        foreach ($featureKeys as $k) {
            $colSum = 0.0;
            for ($i = 0; $i < $nSamples; $i++) {
                $colSum += (float) ($trainX[$i][$k] ?? 0.0);
            }
            $mean = $colSum / $nSamples;
            $varSum = 0.0;
            for ($i = 0; $i < $nSamples; $i++) {
                $diff = (float) ($trainX[$i][$k] ?? 0.0) - $mean;
                $varSum += ($diff * $diff);
            }
            $std = $nSamples > 1 ? sqrt($varSum / ($nSamples - 1)) : 1.0;
            $means[$k] = $mean;
            $stds[$k] = $std > 1e-6 ? $std : 1.0;
        }

        // Compute Y mean
        $ySum = array_sum($trainY);
        $yMean = $nSamples > 0 ? $ySum / $nSamples : 0.0;

        // Gradient descent with L2 penalty on normalized features
        $weights = array_fill(0, $nFeatures, 0.0);
        $learningRate = 0.01;
        $iterations = 200;

        for ($iter = 0; $iter < $iterations; $iter++) {
            $grad = array_fill(0, $nFeatures, 0.0);
            for ($i = 0; $i < $nSamples; $i++) {
                $yPred = $yMean;
                for ($j = 0; $j < $nFeatures; $j++) {
                    $k = $featureKeys[$j];
                    $valNorm = ((float) ($trainX[$i][$k] ?? 0.0) - $means[$k]) / $stds[$k];
                    $yPred += $weights[$j] * $valNorm;
                }
                $err = $yPred - (float) $trainY[$i];
                for ($j = 0; $j < $nFeatures; $j++) {
                    $k = $featureKeys[$j];
                    $valNorm = ((float) ($trainX[$i][$k] ?? 0.0) - $means[$k]) / $stds[$k];
                    $grad[$j] += ($err * $valNorm);
                }
            }

            for ($j = 0; $j < $nFeatures; $j++) {
                $weights[$j] -= $learningRate * (($grad[$j] / $nSamples) + ($alpha * $weights[$j]));
            }
        }

        // Test inference
        $pred = $yMean;
        for ($j = 0; $j < $nFeatures; $j++) {
            $k = $featureKeys[$j];
            $valNorm = ((float) ($testX[$k] ?? 0.0) - $means[$k]) / $stds[$k];
            $pred += $weights[$j] * $valNorm;
        }

        return max(0.0, round($pred, 3));
    }

    /**
     * Execute full Rolling-Origin Expanding-Window Backtesting.
     *
     * @param array $fullSeries Full continuous daily series of length N
     * @param int $minTrainDays Minimum days before opening the first evaluation origin (e.g. 28)
     * @param array $horizons List of horizons to evaluate (e.g. [1, 3, 7])
     * @param int|null $outletId
     * @return array
     */
    public function executeRollingOriginBacktest(
        array $fullSeries,
        int $minTrainDays = 28,
        array $horizons = [1, 3, 7],
        ?int $outletId = null
    ): array {
        $totalDays = count($fullSeries);
        $maxHorizon = max($horizons);

        if ($totalDays < ($minTrainDays + $maxHorizon)) {
            return [
                'status' => 'BLOCKED_BY_DATA_GAPS',
                'message' => "Insufficient total days ({$totalDays}) for min_train_days ({$minTrainDays}) and max_horizon ({$maxHorizon}).",
                'total_days' => $totalDays,
                'min_required_days' => $minTrainDays + $maxHorizon,
                'evaluations' => [],
                'summary' => [],
            ];
        }

        $resultsPerHorizon = [];
        foreach ($horizons as $h) {
            $resultsPerHorizon[$h] = [
                'actuals' => [],
                'pred_dow_median' => [],
                'pred_wma14' => [],
                'pred_naive7' => [],
                'pred_ml_no_weather' => [],
                'pred_ml_with_weather' => [],
                'windows' => [],
            ];
        }

        $weatherStatuses = [];

        // Rolling origins from originIdx = minTrainDays - 1 up to totalDays - maxHorizon - 1
        for ($originIdx = $minTrainDays - 1; $originIdx < ($totalDays - $maxHorizon); $originIdx++) {
            $trainHistory = array_slice($fullSeries, 0, $originIdx + 1);
            $cutoffDate = $fullSeries[$originIdx]['date'];

            // Prepare expanding training matrix for ML models
            $trainSamplesNoWeather = [];
            $trainSamplesWithWeather = [];
            $trainTargets = [];

            // We build training instances from history (using lag 1 step sliding within trainHistory)
            for ($t = 28; $t < count($trainHistory); $t++) {
                $subHistory = array_slice($trainHistory, 0, $t);
                $targetDateForTrain = $trainHistory[$t]['date'];
                $cutoffTrain = $subHistory[$t - 1]['date'];
                $featNoWeather = $this->extractFeaturesForBacktest($subHistory, $targetDateForTrain, $outletId, false, $cutoffTrain);
                $featWithWeather = $this->extractFeaturesForBacktest($subHistory, $targetDateForTrain, $outletId, true, $cutoffTrain);

                $trainSamplesNoWeather[] = $featNoWeather['features'];
                $trainSamplesWithWeather[] = $featWithWeather['features'];
                $trainTargets[] = (float) $trainHistory[$t]['value'];
            }

            foreach ($horizons as $h) {
                $targetIdx = $originIdx + $h;
                $targetItem = $fullSeries[$targetIdx];
                $targetDate = $targetItem['date'];
                $actual = (float) $targetItem['value'];

                // 1. Baselines
                $predDow = $this->predictDowMedian($trainHistory, $targetDate);
                $predWma = $this->predictWma($trainHistory, 14);
                $predNaive = $this->predictNaiveLag($trainHistory, 7);

                // 2. ML Models (with point-in-time cutoff date)
                $featTestNoWeather = $this->extractFeaturesForBacktest($trainHistory, $targetDate, $outletId, false, $cutoffDate);
                $featTestWithWeather = $this->extractFeaturesForBacktest($trainHistory, $targetDate, $outletId, true, $cutoffDate);

                $weatherStatuses[] = $featTestWithWeather['weather_status'];

                $predMlNoWeather = !empty($trainSamplesNoWeather)
                    ? $this->predictNativeRidge($trainSamplesNoWeather, $trainTargets, $featTestNoWeather['features'])
                    : $predDow;

                $predMlWithWeather = !empty($trainSamplesWithWeather)
                    ? $this->predictNativeRidge($trainSamplesWithWeather, $trainTargets, $featTestWithWeather['features'])
                    : $predMlNoWeather;

                $resultsPerHorizon[$h]['actuals'][] = $actual;
                $resultsPerHorizon[$h]['pred_dow_median'][] = $predDow;
                $resultsPerHorizon[$h]['pred_wma14'][] = $predWma;
                $resultsPerHorizon[$h]['pred_naive7'][] = $predNaive;
                $resultsPerHorizon[$h]['pred_ml_no_weather'][] = $predMlNoWeather;
                $resultsPerHorizon[$h]['pred_ml_with_weather'][] = $predMlWithWeather;

                $resultsPerHorizon[$h]['windows'][] = [
                    'cutoff_date' => $cutoffDate,
                    'target_date' => $targetDate,
                    'horizon' => $h,
                    'actual' => $actual,
                    'dow_median' => $predDow,
                    'wma14' => $predWma,
                    'naive7' => $predNaive,
                    'ml_no_weather' => $predMlNoWeather,
                    'ml_with_weather' => $predMlWithWeather,
                ];
            }
        }

        // Aggregate Metrics per Horizon and Overall
        $summary = [];
        $models = ['dow_median', 'wma14', 'naive7', 'ml_no_weather', 'ml_with_weather'];

        foreach ($horizons as $h) {
            $data = $resultsPerHorizon[$h];
            $actuals = $data['actuals'];
            $obsCount = count($actuals);

            $horizonSummary = [
                'horizon_days' => $h,
                'observations_count' => $obsCount,
                'models' => [],
            ];

            foreach ($models as $m) {
                $preds = $data["pred_{$m}"];
                $wape = $this->calculateWape($actuals, $preds);
                $mae = $this->calculateMae($actuals, $preds);
                $bias = $this->calculateBias($actuals, $preds);

                $horizonSummary['models'][$m] = [
                    'wape' => $wape,
                    'mae' => $mae,
                    'bias' => $bias,
                    'coverage' => $obsCount > 0 ? 1.0 : 0.0,
                ];
            }

            // Relative WAPE improvements
            $baseDowWape = $horizonSummary['models']['dow_median']['wape'];
            $baseWmaWape = $horizonSummary['models']['wma14']['wape'];

            $horizonSummary['relative_improvements'] = [
                'ml_no_weather_vs_dow' => $this->calculateRelativeWapeImprovement($baseDowWape, $horizonSummary['models']['ml_no_weather']['wape']),
                'ml_with_weather_vs_dow' => $this->calculateRelativeWapeImprovement($baseDowWape, $horizonSummary['models']['ml_with_weather']['wape']),
                'ml_with_weather_vs_no_weather' => $this->calculateRelativeWapeImprovement(
                    $horizonSummary['models']['ml_no_weather']['wape'],
                    $horizonSummary['models']['ml_with_weather']['wape']
                ),
            ];

            $summary[$h] = $horizonSummary;
        }

        $hasReanalysisProxy = in_array('REANALYSIS_ARCHIVE_PROXY_LEAKAGE_RISK', $weatherStatuses, true);

        return [
            'status' => 'COMPLETED',
            'total_days_evaluated' => $totalDays,
            'total_windows_per_horizon' => count($resultsPerHorizon[$horizons[0]]['actuals']),
            'weather_data_nature' => $hasReanalysisProxy
                ? 'REANALYSIS_PROXY_WITH_LEAKAGE_RISK'
                : 'POINT_IN_TIME_FORECAST',
            'horizons_summary' => $summary,
            'detailed_results' => $resultsPerHorizon,
        ];
    }
}
