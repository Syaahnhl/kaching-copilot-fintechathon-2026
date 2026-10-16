<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Menu;
use Illuminate\Support\Facades\Auth;
use App\Helpers\TimezoneContract;

class AnalysisController extends Controller
{
    /**
     * 1. Tampilkan Halaman Web (View)
     * Dipanggil saat klik menu "Analisis Menu (DSS)" di Sidebar
     */
    public function index()
    {
        return view('backoffice.menu_performance');
    }

    /**
     * 2. Olah Data DSS (Logic SAW & Quadrant)
     * Dipanggil via AJAX dari Web atau Request dari Android
     */
    public function getMenuAnalysis(Request $request)
    {
        // Ambil Input Tanggal (Default: Hari Ini)
        $startDate = $request->input('start_date', date('Y-m-d'));
        $endDate   = $request->input('end_date', date('Y-m-d'));

        // [SaaS] Filter by User
        $userId = Auth::id();

        // Phase 2: Convert business dates to UTC half-open range
        // AnalysisController is global (no outlet filter), use Asia/Jakarta default
        [$utcStart, $utcEnd] = TimezoneContract::businessDateRangeToUtcRange($startDate, $endDate, 'Asia/Jakarta');

        // Query Data Mentah (Join kanonikal berbasis menu_id dengan fallback nama jika data legacy)
        $rawStats = Menu::withTrashed()
            ->join('transaction_items as ti', function($join) {
                $join->on('menus.id', '=', 'ti.menu_id')
                    ->orWhere(function($q) {
                        $q->whereNull('ti.menu_id')->whereColumn('menus.name', '=', 'ti.menu_name');
                    });
            })
            ->join('transactions as t', 'ti.transaction_id', '=', 't.id')
            ->select(
                'menus.id as menu_id',
                'menus.name',
                'menus.category',
                'menus.price as current_selling_price',
                'menus.cost_price',
                DB::raw('SUM(ti.qty) as total_qty'),
                DB::raw('COUNT(ti.id) as popularity')
            )
            ->where('menus.user_id', $userId)
            ->where('t.user_id', $userId)
            ->where('t.created_at_device', '>=', $utcStart)
            ->where('t.created_at_device', '<', $utcEnd)
            ->where('t.status', '!=', 'Batal')
            ->groupBy('menus.id', 'menus.name', 'menus.category', 'menus.price', 'menus.cost_price')
            ->get();

        if ($rawStats->isEmpty()) {
            return response()->json([
                'status'       => 'success',
                'data'         => [],
                'chart_data'   => [],
                'total'        => 0,
                'per_page'     => 15,
                'current_page' => 1,
                'last_page'    => 1,
            ]);
        }

        // Persiapan Variabel Hitungan
        $totalMenus   = $rawStats->count();
        $sumQty       = 0;
        $sumProfit    = 0;
        $matrix       = [];
        $maxQty       = 0;
        $maxProfit    = 0;
        $maxPopularity = 0;

        foreach ($rawStats as $item) {
            $marginPerUnit = $item->current_selling_price - $item->cost_price;
            $totalProfit   = $marginPerUnit * $item->total_qty;

            $sumQty    += $item->total_qty;
            $sumProfit += $totalProfit;

            if ($item->total_qty > $maxQty) $maxQty = $item->total_qty;
            if ($totalProfit > $maxProfit) $maxProfit = $totalProfit;
            if ($item->popularity > $maxPopularity) $maxPopularity = $item->popularity;

            $matrix[] = [
                'name'       => $item->name,
                'qty'        => $item->total_qty,
                'profit'     => $totalProfit,
                'popularity' => $item->popularity,
            ];
        }

        $avgQty    = ($totalMenus > 0) ? $sumQty / $totalMenus : 0;
        $avgProfit = ($totalMenus > 0) ? $sumProfit / $totalMenus : 0;

        // Bobot SAW
        $w1 = 0.4; // Qty (Laris)
        $w2 = 0.4; // Profit (Untung)
        $w3 = 0.2; // Popularity

        $results = [];

        foreach ($matrix as $row) {
            $isHighQty    = $row['qty'] >= $avgQty;
            $isHighProfit = $row['profit'] >= $avgProfit;

            // Teks bersih tanpa emotikon — ikon dirender di Frontend
            if ($isHighQty && $isHighProfit) {
                $category = 'STAR';
                $action   = 'Menu Juara! Pertahankan stok dan kualitas.';
            } elseif ($isHighQty && !$isHighProfit) {
                $category = 'CASH_COW';
                $action   = 'Laris tapi margin tipis. Naikkan harga atau buat paket bundling.';
            } elseif (!$isHighQty && $isHighProfit) {
                $category = 'PUZZLE';
                $action   = 'Margin besar tapi kurang laku. Genjot promosi di media sosial.';
            } else {
                $category = 'DOG';
                $action   = 'Kurang diminati. Pertimbangkan penghapusan atau revisi resep.';
            }

            $normQty    = ($maxQty > 0) ? $row['qty'] / $maxQty : 0;
            $normProfit = ($maxProfit > 0) ? $row['profit'] / $maxProfit : 0;
            $normPop    = ($maxPopularity > 0) ? $row['popularity'] / $maxPopularity : 0;

            $finalScore = ($normQty * $w1) + ($normProfit * $w2) + ($normPop * $w3);

            $results[] = [
                'name'         => $row['name'],
                'category'     => $category,
                'score'        => round($finalScore, 4),
                'stats'        => "{$row['qty']} Terjual • Profit Rp " . number_format($row['profit'], 0, ',', '.'),
                'action'       => $action,
                'ai_generated' => false,
            ];
        }

        // Urutkan Ranking (Skor Tertinggi ke Terendah)
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        // ============================================
        // [CHART DATA] Top 10 + Bottom 10 saja
        // ============================================
        $top10    = array_slice($results, 0, 10);
        $bottom10 = array_slice($results, -10);

        $chartData    = $top10;
        $top10Names   = array_column($top10, 'name');
        if (count($results) > 10) {
            foreach ($bottom10 as $b) {
                if (!in_array($b['name'], $top10Names)) {
                    $chartData[] = $b;
                }
            }
        }

        // ============================================
        // [PAGINATION] 15 baris per halaman (sisi PHP)
        // ============================================
        $perPage      = 15;
        $page         = max(1, (int) $request->input('page', 1));
        $total        = count($results);
        $pagedResults = array_slice($results, ($page - 1) * $perPage, $perPage);

        // ============================================
        // [AI GROQ] Rekomendasi teks untuk Top 10 saja
        // ============================================
        set_time_limit(60);

        try {
            $apiKey = config('services.groq.key');
            $baseUrl = config('services.groq.base_url', 'https://api.groq.com');
            $apiUrl = str_contains($baseUrl, 'completions')
                ? $baseUrl
                : rtrim($baseUrl, '/') . (str_contains($baseUrl, '/v1') ? '/chat/completions' : '/openai/v1/chat/completions');
            $model = config('services.groq.model', 'qwen/qwen3.8-27b');

            $promptData = '';
            foreach ($top10 as $ti) {
                $promptData .= "- {$ti['name']} ({$ti['category']}, Skor SAW: {$ti['score']})\n";
            }

            $systemPrompt = 'Anda adalah Konsultan F&B Kaching POS. '
                . 'Berikan rekomendasi strategi bisnis (maks 10 kata per menu) '
                . 'dalam format JSON MURNI tanpa markdown dan TANPA emoji/emotikon. '
                . 'Format: {"Nama Menu": "Rekomendasi singkat"}';

            $groqResp = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ])->timeout(15)->post($apiUrl, [
                'model'           => $model,
                'temperature'     => 0.4,
                'messages'        => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => "Data menu:\n" . $promptData],
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($groqResp->successful()) {
                $aiMap = json_decode($groqResp->json('choices.0.message.content'), true);
                if (is_array($aiMap)) {
                    foreach ($pagedResults as &$row) {
                        if (isset($aiMap[$row['name']])) {
                            // Strip semua karakter emoji dari respons AI
                            $clean = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]+/u', '', $aiMap[$row['name']]);
                            $row['action']       = trim($clean);
                            $row['ai_generated'] = true;
                        }
                    }
                    unset($row);
                }
            } else {
                throw new \Exception('Groq HTTP ' . $groqResp->status());
            }
        } catch (\Exception $e) {
            // AI gagal → tabel tetap tampil dengan teks lokal, ai_generated = false
            \Illuminate\Support\Facades\Log::warning('DSS AI Fallback: ' . $e->getMessage());
        }

        return response()->json([
            'status'       => 'success',
            'data'         => $pagedResults,
            'chart_data'   => $chartData,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int) ceil($total / $perPage),
        ]);
    }
}
