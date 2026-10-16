<?php

namespace App\Services\DemandForecast;

use App\Models\DailyMenuSalesSummary;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\User;
use Carbon\Carbon;
use InvalidArgumentException;

class NativeDemandFeatureExtractor
{
    /**
     * Feature vector keys in strict canonical order.
     */
    public const FEATURE_KEYS = [
        'f01_user_id',
        'f02_outlet_id',
        'f03_menu_id',
        'f04_category',
        'f05_lag_1',
        'f06_lag_2',
        'f07_lag_7',
        'f08_lag_14',
        'f09_lag_28',
        'f10_rolling_mean_7',
        'f11_rolling_mean_14',
        'f12_rolling_mean_28',
        'f13_rolling_std_7',
        'f14_rolling_std_14',
        'f15_rolling_std_28',
        'f16_same_dow_lag_7',
        'f17_same_dow_lag_14',
        'f18_same_dow_lag_21',
        'f19_same_dow_lag_28',
        'f20_same_dow_mean_4w',
        'f21_nonzero_ratio_7',
        'f22_nonzero_ratio_28',
        'f23_days_since_last_sale',
        'f24_zero_sales_streak',
        'f25_weekday',
        'f26_is_weekend',
        'f27_day_of_month',
        'f28_month',
        'f29_target_weekday',
        'f30_target_is_weekend',
        'f31_is_payday_window',
        'f32_last_observed_price',
        'f33_last_observed_cost',
        'f34_observed_margin',
        'f35_observed_margin_pct',
        'f36_demand_trend_7_28',
        'f37_cv_28',
    ];

    /**
     * Extract features for a single menu item.
     *
     * @param User $user
     * @param Outlet $outlet
     * @param Menu $menu
     * @param string $targetDate Format YYYY-MM-DD
     * @param string|null $uom Unit of measure (defaults to menu->unit or 'porsi')
     * @param string|null $explicitCutoffDate Optional override for cutoff date
     * @return array
     */
    public function extractForMenu(
        User $user,
        Outlet $outlet,
        Menu $menu,
        string $targetDate,
        ?string $uom = null,
        ?string $explicitCutoffDate = null
    ): array {
        // Enforce tenant & outlet ownership invariants
        if ((int) $outlet->user_id !== (int) $user->id) {
            throw new InvalidArgumentException("Tenant isolation violation: Outlet {$outlet->id} does not belong to User {$user->id}.");
        }

        if ((int) $menu->outlet_id !== (int) $outlet->id) {
            throw new InvalidArgumentException("Outlet isolation violation: Menu {$menu->id} does not belong to Outlet {$outlet->id}.");
        }

        if (isset($menu->user_id) && (int) $menu->user_id !== (int) $user->id) {
            throw new InvalidArgumentException("Tenant isolation violation: Menu {$menu->id} does not belong to User {$user->id}.");
        }

        $targetCarbon = Carbon::parse($targetDate, 'Asia/Jakarta');
        $cutoffCarbon = $explicitCutoffDate
            ? Carbon::parse($explicitCutoffDate, 'Asia/Jakarta')
            : $targetCarbon->copy()->subDay();

        $cutoffDateStr = $cutoffCarbon->toDateString();
        $targetDateStr = $targetCarbon->toDateString();
        $startDateStr = $cutoffCarbon->copy()->subDays(27)->toDateString();

        $effectiveUom = strtolower(trim($uom ?: ($menu->unit ?: 'porsi')));

        // Load 28-day panel from DailyMenuSalesSummary
        $summaries = DailyMenuSalesSummary::where('user_id', $user->id)
            ->where('outlet_id', $outlet->id)
            ->where('menu_id', $menu->id)
            ->where('uom', $effectiveUom)
            ->whereBetween('business_date', [$startDateStr, $cutoffDateStr])
            ->get()
            ->keyBy(function ($item) {
                return Carbon::parse($item->business_date)->toDateString();
            });

        // Build 28-day continuous series with zero-filling
        $series = [];
        for ($i = 0; $i < 28; $i++) {
            $currDate = $cutoffCarbon->copy()->subDays(27 - $i)->toDateString();
            if (isset($summaries[$currDate])) {
                $series[$i] = (float) $summaries[$currDate]->total_qty;
            } else {
                $series[$i] = 0.0;
            }
        }

        // History status evaluation
        $totalHistoryDays = DailyMenuSalesSummary::where('user_id', $user->id)
            ->where('outlet_id', $outlet->id)
            ->where('menu_id', $menu->id)
            ->where('uom', $effectiveUom)
            ->where('business_date', '<=', $cutoffDateStr)
            ->count();

        $earliestDate = DailyMenuSalesSummary::where('user_id', $user->id)
            ->where('outlet_id', $outlet->id)
            ->where('menu_id', $menu->id)
            ->where('uom', $effectiveUom)
            ->where('business_date', '<=', $cutoffDateStr)
            ->min('business_date');

        $spanDays = $earliestDate
            ? $cutoffCarbon->diffInDays(Carbon::parse($earliestDate, 'Asia/Jakarta')) + 1
            : 0;

        $historyDaysAvailable = max($totalHistoryDays, $spanDays);

        $sum28 = array_sum($series);
        if ($historyDaysAvailable < 14) {
            $historyStatus = 'INSUFFICIENT_HISTORY';
        } elseif ($sum28 <= 0.0 && $totalHistoryDays >= 14) {
            $historyStatus = 'ZERO_DEMAND';
        } else {
            $historyStatus = 'SUFFICIENT';
        }

        // Feature calculations
        $f01_user_id = (string) $user->id;
        $f02_outlet_id = (string) $outlet->id;
        $f03_menu_id = (string) $menu->id;
        $f04_category = (string) ($menu->category ?: 'Uncategorized');

        // Lags
        $f05_lag_1 = $series[27]; // cutoff date (t)
        $f06_lag_2 = $series[26]; // t-1
        $f07_lag_7 = $series[21]; // t-6
        $f08_lag_14 = $series[14]; // t-13
        $f09_lag_28 = $series[0];  // t-27

        // Rolling statistics
        $last7 = array_slice($series, 21, 7);
        $last14 = array_slice($series, 14, 14);
        $last28 = $series;

        $f10_rolling_mean_7 = $this->mean($last7);
        $f11_rolling_mean_14 = $this->mean($last14);
        $f12_rolling_mean_28 = $this->mean($last28);

        $f13_rolling_std_7 = $this->stdDev($last7);
        $f14_rolling_std_14 = $this->stdDev($last14);
        $f15_rolling_std_28 = $this->stdDev($last28);

        // Same-DOW Lags
        $f16_same_dow_lag_7 = $series[21];
        $f17_same_dow_lag_14 = $series[14];
        $f18_same_dow_lag_21 = $series[7];
        $f19_same_dow_lag_28 = $series[0];
        $f20_same_dow_mean_4w = ($f16_same_dow_lag_7 + $f17_same_dow_lag_14 + $f18_same_dow_lag_21 + $f19_same_dow_lag_28) / 4.0;

        // Non-zero ratios
        $nonzeroCount7 = count(array_filter($last7, fn($q) => $q > 0));
        $nonzeroCount28 = count(array_filter($last28, fn($q) => $q > 0));
        $f21_nonzero_ratio_7 = $nonzeroCount7 / 7.0;
        $f22_nonzero_ratio_28 = $nonzeroCount28 / 28.0;

        // Days since last sale & zero sales streak
        $f23_days_since_last_sale = 28.0;
        for ($i = 27; $i >= 0; $i--) {
            if ($series[$i] > 0) {
                $f23_days_since_last_sale = (float) (27 - $i);
                break;
            }
        }

        $f24_zero_sales_streak = 0.0;
        for ($i = 27; $i >= 0; $i--) {
            if ($series[$i] == 0.0) {
                $f24_zero_sales_streak += 1.0;
            } else {
                break;
            }
        }

        // Calendar features
        $cutoffDow = $cutoffCarbon->dayOfWeekIso - 1; // 0=Mon..6=Sun
        $f25_weekday = (float) $cutoffDow;
        $f26_is_weekend = ($cutoffDow >= 5) ? 1.0 : 0.0;
        $f27_day_of_month = (float) $cutoffCarbon->day;
        $f28_month = (float) $cutoffCarbon->month;

        $targetDow = $targetCarbon->dayOfWeekIso - 1;
        $f29_target_weekday = (float) $targetDow;
        $f30_target_is_weekend = ($targetDow >= 5) ? 1.0 : 0.0;

        $targetDay = $targetCarbon->day;
        $f31_is_payday_window = ($targetDay >= 25 || $targetDay <= 5) ? 1.0 : 0.0;

        // Price & Cost features
        $price = (float) ($menu->price ?? 0.0);
        $cost = (float) ($menu->cost_price ?? $menu->cogs ?? 0.0);

        if ($price <= 0.0 && $summaries->isNotEmpty()) {
            $latestSummaryWithPrice = $summaries->sortByDesc('business_date')->first(fn($s) => $s->avg_price > 0);
            if ($latestSummaryWithPrice) {
                $price = (float) $latestSummaryWithPrice->avg_price;
            }
        }

        if ($cost <= 0.0 && $summaries->isNotEmpty()) {
            $latestSummaryWithCost = $summaries->sortByDesc('business_date')->first(fn($s) => $s->avg_cost > 0);
            if ($latestSummaryWithCost) {
                $cost = (float) $latestSummaryWithCost->avg_cost;
            }
        }

        $f32_last_observed_price = $price;
        $f33_last_observed_cost = $cost;
        $f34_observed_margin = $f32_last_observed_price - $f33_last_observed_cost;
        $f35_observed_margin_pct = ($f32_last_observed_price > 0.0)
            ? ($f34_observed_margin / $f32_last_observed_price)
            : 0.0;

        // Trend & CV
        $f36_demand_trend_7_28 = ($f10_rolling_mean_7 - $f12_rolling_mean_28) / max(1.0, $f12_rolling_mean_28);
        $f37_cv_28 = $f15_rolling_std_28 / ($f12_rolling_mean_28 + 1e-4);

        $features = [
            'f01_user_id' => $f01_user_id,
            'f02_outlet_id' => $f02_outlet_id,
            'f03_menu_id' => $f03_menu_id,
            'f04_category' => $f04_category,
            'f05_lag_1' => $this->finiteFloat($f05_lag_1),
            'f06_lag_2' => $this->finiteFloat($f06_lag_2),
            'f07_lag_7' => $this->finiteFloat($f07_lag_7),
            'f08_lag_14' => $this->finiteFloat($f08_lag_14),
            'f09_lag_28' => $this->finiteFloat($f09_lag_28),
            'f10_rolling_mean_7' => $this->finiteFloat($f10_rolling_mean_7),
            'f11_rolling_mean_14' => $this->finiteFloat($f11_rolling_mean_14),
            'f12_rolling_mean_28' => $this->finiteFloat($f12_rolling_mean_28),
            'f13_rolling_std_7' => $this->finiteFloat($f13_rolling_std_7),
            'f14_rolling_std_14' => $this->finiteFloat($f14_rolling_std_14),
            'f15_rolling_std_28' => $this->finiteFloat($f15_rolling_std_28),
            'f16_same_dow_lag_7' => $this->finiteFloat($f16_same_dow_lag_7),
            'f17_same_dow_lag_14' => $this->finiteFloat($f17_same_dow_lag_14),
            'f18_same_dow_lag_21' => $this->finiteFloat($f18_same_dow_lag_21),
            'f19_same_dow_lag_28' => $this->finiteFloat($f19_same_dow_lag_28),
            'f20_same_dow_mean_4w' => $this->finiteFloat($f20_same_dow_mean_4w),
            'f21_nonzero_ratio_7' => $this->finiteFloat($f21_nonzero_ratio_7),
            'f22_nonzero_ratio_28' => $this->finiteFloat($f22_nonzero_ratio_28),
            'f23_days_since_last_sale' => $this->finiteFloat($f23_days_since_last_sale),
            'f24_zero_sales_streak' => $this->finiteFloat($f24_zero_sales_streak),
            'f25_weekday' => $this->finiteFloat($f25_weekday),
            'f26_is_weekend' => $this->finiteFloat($f26_is_weekend),
            'f27_day_of_month' => $this->finiteFloat($f27_day_of_month),
            'f28_month' => $this->finiteFloat($f28_month),
            'f29_target_weekday' => $this->finiteFloat($f29_target_weekday),
            'f30_target_is_weekend' => $this->finiteFloat($f30_target_is_weekend),
            'f31_is_payday_window' => $this->finiteFloat($f31_is_payday_window),
            'f32_last_observed_price' => $this->finiteFloat($f32_last_observed_price),
            'f33_last_observed_cost' => $this->finiteFloat($f33_last_observed_cost),
            'f34_observed_margin' => $this->finiteFloat($f34_observed_margin),
            'f35_observed_margin_pct' => $this->finiteFloat($f35_observed_margin_pct),
            'f36_demand_trend_7_28' => $this->finiteFloat($f36_demand_trend_7_28),
            'f37_cv_28' => $this->finiteFloat($f37_cv_28),
        ];

        return [
            'menu_id' => (string) $menu->id,
            'menu_name' => (string) $menu->name,
            'category' => $f04_category,
            'uom' => $effectiveUom,
            'cutoff_date' => $cutoffDateStr,
            'target_date' => $targetDateStr,
            'history_days_available' => $historyDaysAvailable,
            'history_status' => $historyStatus,
            'features' => $features,
        ];
    }

    /**
     * Extract features for all active menus in an outlet.
     *
     * @param User $user
     * @param Outlet $outlet
     * @param string $targetDate
     * @param string|null $explicitCutoffDate
     * @return array
     */
    public function extractForOutlet(
        User $user,
        Outlet $outlet,
        string $targetDate,
        ?string $explicitCutoffDate = null
    ): array {
        if ((int) $outlet->user_id !== (int) $user->id) {
            throw new InvalidArgumentException("Tenant isolation violation: Outlet {$outlet->id} does not belong to User {$user->id}.");
        }

        $menus = Menu::where('outlet_id', $outlet->id)
            ->where(function ($q) use ($user) {
                $q->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->where('is_available', true)
            ->get();

        $items = [];
        foreach ($menus as $menu) {
            $items[] = $this->extractForMenu($user, $outlet, $menu, $targetDate, null, $explicitCutoffDate);
        }

        return $items;
    }

    protected function mean(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }
        return array_sum($values) / (float) $count;
    }

    protected function stdDev(array $values): float
    {
        $count = count($values);
        if ($count <= 1) {
            return 0.0;
        }
        $mean = $this->mean($values);
        $sumSquares = 0.0;
        foreach ($values as $v) {
            $diff = (float) $v - $mean;
            $sumSquares += ($diff * $diff);
        }
        return sqrt($sumSquares / (float) $count);
    }

    protected function finiteFloat(float $val): float
    {
        if (is_nan($val) || is_infinite($val)) {
            return 0.0;
        }
        return round($val, 4);
    }
}
