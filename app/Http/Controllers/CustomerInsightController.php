<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Customer;
use Carbon\Carbon;

/**
 * Customer Insight Controller
 *
 * [BARU 2026-06-23] RFM-style customer segmentation + churn risk score.
 *
 * Algorithm (sederhana tapi useful untuk MVP FnB):
 * 1. Hitung Recency dari Customer.last_visit_at (hari sejak kunjungan terakhir)
 * 2. Frequency = Customer.visit_count (sudah diagregasi oleh sistem)
 * 3. Monetary = Customer.total_spent (sudah diagregasi oleh sistem)
 * 4. Segment:
 *    - CHAMPIONS: visit>=5 AND recency<=14 AND monetary>=top20%
 *    - LOYAL:     visit>=3 AND recency<=30
 *    - AT_RISK:   visit>=3 AND recency 31-90
 *    - LOST:      recency>90 OR visit=0
 *    - NEW:       visit<=2 AND recency<=30
 * 5. Churn risk = min(100, (recency / 90) * 100) — recency 90 hari = 100% risk
 *
 * Future: Replace dengan logistic regression / XGBoost untuk akurasi lebih baik.
 * Tapi RFM heuristic cukup untuk MVP dan jalan tanpa ML pipeline.
 */
class CustomerInsightController extends Controller
{
    /**
     * Hitung customer insight (RFM segments + at-risk + champions).
     *
     * Response shape:
     * {
     *   "status": "success",
     *   "generated_at": "2026-06-23T10:00:00+07:00",
     *   "window_days": 90,
     *   "total_customers": N,
     *   "segments": {
     *     "champions": X, "loyal": Y, "at_risk": Z, "lost": W, "new": V
     *   },
     *   "at_risk_list": [
     *     {
     *       "customer_id": 1, "name": "Budi", "phone": "08123...",
     *       "visit_count": 8, "total_spent": 450000,
     *       "last_visit_at": "2026-04-15", "recency_days": 68,
     *       "churn_risk": 76, "segment": "AT_RISK"
     *     }, ...
     *   ],
     *   "champions_list": [...sorted by total_spent desc...]
     * }
     */
    public function index(Request $request)
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        }

        $now = Carbon::now();
        $windowDays = (int) $request->input('window_days', 90);
        $windowDays = max(30, min(365, $windowDays));
        $windowStart = $now->copy()->subDays($windowDays);

        // Ambil semua customer aktif milik user (exclude soft-deleted)
        $customers = Customer::where('user_id', $userId)
            ->where('is_active', true)
            ->get(['id', 'name', 'phone', 'visit_count', 'total_spent', 'last_visit_at', 'loyalty_points']);

        if ($customers->isEmpty()) {
            return response()->json([
                'status' => 'success',
                'generated_at' => $now->toIso8601String(),
                'window_days' => $windowDays,
                'total_customers' => 0,
                'segments' => ['champions' => 0, 'loyal' => 0, 'at_risk' => 0, 'lost' => 0, 'new' => 0],
                'at_risk_list' => [],
                'champions_list' => [],
            ]);
        }

        // Hitung threshold monetary untuk Champions (top 20%)
        $monetaryValues = $customers->pluck('total_spent')->map(fn($v) => (float)$v)->sort()->values();
        $thresholdIndex = max(0, (int)floor($monetaryValues->count() * 0.8) - 1);
        $monetaryThreshold = $monetaryValues[$thresholdIndex] ?? 0;

        $segments = ['champions' => 0, 'loyal' => 0, 'at_risk' => 0, 'lost' => 0, 'new' => 0];
        $enriched = [];

        foreach ($customers as $c) {
            $visitCount = (int) $c->visit_count;
            $totalSpent = (float) $c->total_spent;
            $lastVisit = $c->last_visit_at ? Carbon::parse($c->last_visit_at) : null;

            // Recency (hari sejak kunjungan terakhir)
            if ($lastVisit === null) {
                $recencyDays = 9999; // Never visited
            } else {
                $recencyDays = (int) $lastVisit->diffInDays($now, false); // positif = hari lalu
                if ($recencyDays < 0) $recencyDays = 0; // future date guard
            }

            // Segment classification
            if ($visitCount === 0) {
                $segment = 'LOST';
            } elseif ($visitCount <= 2 && $recencyDays <= 30) {
                $segment = 'NEW';
            } elseif ($visitCount >= 5 && $recencyDays <= 14 && $totalSpent >= $monetaryThreshold) {
                $segment = 'CHAMPIONS';
            } elseif ($visitCount >= 3 && $recencyDays <= 30) {
                $segment = 'LOYAL';
            } elseif ($visitCount >= 3 && $recencyDays <= 90) {
                $segment = 'AT_RISK';
            } else {
                $segment = 'LOST';
            }

            // Churn risk score (0-100, lebih tinggi = lebih besar risiko churn)
            // Formula: clamp(recency/90 * 100, 0, 100)
            // Recency 0 hari = 0% risk, 90 hari = 100% risk, >90 = 100%
            $churnRisk = (int) min(100, max(0, ($recencyDays / 90.0) * 100));

            // Boost churn risk untuk customer dengan visit rendah (low engagement)
            if ($visitCount <= 2 && $recencyDays > 30) {
                $churnRisk = min(100, $churnRisk + 20);
            }

            $segments[strtolower($segment)]++;

            $enriched[] = [
                'customer_id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'visit_count' => $visitCount,
                'total_spent' => $totalSpent,
                'loyalty_points' => (int) $c->loyalty_points,
                'last_visit_at' => $lastVisit ? $lastVisit->toDateString() : null,
                'recency_days' => $recencyDays,
                'churn_risk' => $churnRisk,
                'segment' => $segment,
            ];
        }

        // At Risk list — sorted by churn_risk DESC, take top 10
        $atRiskList = array_values(array_filter($enriched, fn($c) => $c['segment'] === 'AT_RISK'));
        usort($atRiskList, fn($a, $b) => $b['churn_risk'] - $a['churn_risk']);
        $atRiskList = array_slice($atRiskList, 0, 10);

        // Champions list — sorted by total_spent DESC, take top 10
        $championsList = array_values(array_filter($enriched, fn($c) => $c['segment'] === 'CHAMPIONS'));
        usort($championsList, fn($a, $b) => $b['total_spent'] <=> $a['total_spent']);
        $championsList = array_slice($championsList, 0, 10);

        return response()->json([
            'status' => 'success',
            'generated_at' => $now->toIso8601String(),
            'window_days' => $windowDays,
            'total_customers' => count($enriched),
            'segments' => $segments,
            'at_risk_list' => $atRiskList,
            'champions_list' => $championsList,
        ]);
    }
}