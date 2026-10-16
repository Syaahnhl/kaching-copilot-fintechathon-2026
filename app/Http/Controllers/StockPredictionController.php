<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\Menu;
use Carbon\Carbon;

/**
 * Stock Prediction Controller
 *
 * [BARU 2026-06-22] Prediksi stok berdasarkan TX history 30 hari.
 *
 * Algorithm (sederhana tapi useful untuk MVP):
 * 1. Ambil 30 hari TX history (status != Batal), aggregate qty per menu_name per day
 * 2. Hitung 3 moving averages: 7d / 23d / 30d
 * 3. Trend = 7d_avg - 23d_avg (positif = naik, negatif = turun)
 * 4. Predicted demand = 7d_avg * 7 (jangka pendek) + 30d_avg * 30 (jangka panjang)
 * 5. Days until out = current_stock / max(30d_avg, 0.1) untuk hindari div-by-zero
 * 6. Alert level: OUT / CRITICAL (<3d) / WARN (<7d) / OK
 *
 * Future: Replace dengan ARIMA / Prophet untuk akurasi lebih baik.
 * Tapi MA + trend cukup untuk MVP dan jalan tanpa dependency Python.
 */
class StockPredictionController extends Controller
{
    /**
     * Hitung prediksi stok untuk semua menu user yang login.
     *
     * Response shape:
     * {
     *   "status": "success",
     *   "generated_at": "2026-06-22T22:00:00+07:00",
     *   "window_days": 30,
     *   "total_menus": N,
     *   "alerts": { "out": X, "critical": Y, "warn": Z, "ok": W },
     *   "data": [
     *     {
     *       "menu_id": 1, "name": "Kopi Susu", "category": "Minuman",
     *       "current_stock": 50.0,
     *       "avg_daily_7d": 3.5, "avg_daily_30d": 2.8, "trend": 0.7,
     *       "predicted_7d": 24.5, "predicted_30d": 84.0,
     *       "days_until_out": 17, "alert_level": "OK", "has_history": true
     *     },
     *     ...
     *   ]
     * }
     */
    public function predict(Request $request)
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        }

        $windowDays = (int) $request->input('window_days', 30);
        $windowDays = max(7, min(90, $windowDays));  // Clamp 7-90 hari

        $now = Carbon::now();
        $windowStart = $now->copy()->subDays($windowDays);

        // 1. Ambil semua menu user
        $menus = Menu::where('user_id', $userId)
            ->orderBy('name')
            ->get(['id', 'name', 'category', 'stock', 'unit']);

        if ($menus->isEmpty()) {
            return response()->json([
                'status' => 'success',
                'generated_at' => $now->toIso8601String(),
                'window_days' => $windowDays,
                'total_menus' => 0,
                'alerts' => ['out' => 0, 'critical' => 0, 'warn' => 0, 'ok' => 0],
                'data' => [],
            ]);
        }

        // 2. Aggregate TX history per menu_name per day
        // transaction_items.menu_name == menus.name (string-based join)
        $salesByMenu = DB::table('transaction_items as ti')
            ->join('transactions as t', 'ti.transaction_id', '=', 't.id')
            ->where('t.user_id', $userId)
            ->where('t.status', '!=', 'Batal')
            ->where('t.created_at_device', '>=', $windowStart)
            ->groupBy('ti.menu_name', DB::raw('DATE(t.created_at_device)'))
            ->select(
                'ti.menu_name',
                DB::raw('DATE(t.created_at_device) as sale_date'),
                DB::raw('SUM(ti.qty) as daily_qty')
            )
            ->get();

        // Index by menu_name → array of [date => qty]
        $salesIndex = [];
        foreach ($salesByMenu as $row) {
            $salesIndex[$row->menu_name][$row->sale_date] = (float) $row->daily_qty;
        }

        // 3. Hitung prediksi per menu
        $predictions = [];
        $alerts = ['out' => 0, 'critical' => 0, 'warn' => 0, 'ok' => 0];
        $sevenDayCutoff = $now->copy()->subDays(7);

        foreach ($menus as $menu) {
            $dailyMap = $salesIndex[$menu->name] ?? [];
            $hasHistory = !empty($dailyMap);

            // 30d total + avg
            $totalQty30d = array_sum($dailyMap);
            $avgDaily30d = $windowDays > 0 ? $totalQty30d / $windowDays : 0.0;

            // 7d avg (recent)
            $qty7d = 0.0;
            $qty23d = 0.0;
            foreach ($dailyMap as $date => $qty) {
                $d = Carbon::parse($date);
                if ($d->gte($sevenDayCutoff)) {
                    $qty7d += $qty;
                } else {
                    $qty23d += $qty;
                }
            }
            $avgDaily7d = $qty7d / 7.0;
            $avgDaily23d = $qty23d / max(1, $windowDays - 7);

            // Trend (selisih 7d vs 23d)
            $trend = $avgDaily7d - $avgDaily23d;

            // Predicted demand
            $predicted7d = round($avgDaily7d * 7.0, 1);
            $predicted30d = round($avgDaily30d * 30.0, 1);

            // Days until out (safe divide)
            $safeDailyAvg = max($avgDaily30d, 0.1);
            $currentStock = (float) $menu->stock;
            $daysUntilOut = $currentStock > 0
                ? (int) floor($currentStock / $safeDailyAvg)
                : 0;

            // Alert level
            if ($currentStock <= 0) {
                $alertLevel = 'OUT';
            } elseif ($daysUntilOut < 3) {
                $alertLevel = 'CRITICAL';
            } elseif ($daysUntilOut < 7) {
                $alertLevel = 'WARN';
            } else {
                $alertLevel = 'OK';
            }

            // Skip menu dengan 0 history + 0 stock (tidak relevan)
            if (!$hasHistory && $currentStock <= 0) {
                continue;
            }

            $alerts[strtolower($alertLevel)]++;
            $predictions[] = [
                'menu_id' => $menu->id,
                'name' => $menu->name,
                'category' => $menu->category,
                'unit' => $menu->unit,
                'current_stock' => $currentStock,
                'avg_daily_7d' => round($avgDaily7d, 2),
                'avg_daily_30d' => round($avgDaily30d, 2),
                'trend' => round($trend, 2),
                'predicted_7d' => $predicted7d,
                'predicted_30d' => $predicted30d,
                'days_until_out' => $daysUntilOut,
                'alert_level' => $alertLevel,
                'has_history' => $hasHistory,
            ];
        }

        // Sort: alert level priority (OUT > CRITICAL > WARN > OK), then days_until_out asc
        $priority = ['OUT' => 0, 'CRITICAL' => 1, 'WARN' => 2, 'OK' => 3];
        usort($predictions, function ($a, $b) use ($priority) {
            $pa = $priority[$a['alert_level']] ?? 9;
            $pb = $priority[$b['alert_level']] ?? 9;
            if ($pa !== $pb) return $pa - $pb;
            return $a['days_until_out'] - $b['days_until_out'];
        });

        return response()->json([
            'status' => 'success',
            'generated_at' => $now->toIso8601String(),
            'window_days' => $windowDays,
            'total_menus' => count($predictions),
            'alerts' => $alerts,
            'data' => $predictions,
        ]);
    }
}
