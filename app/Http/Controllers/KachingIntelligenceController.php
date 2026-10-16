<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Http\Controllers\AnalysisController;
use App\Services\Financial\FinancialWarningService;
use App\Services\Financial\CashFlowForecastService;
use App\Services\Financial\FinancingReadinessService;

class KachingIntelligenceController extends Controller
{
    /**
     * Overview Dashboard Kaching Intelligence.
     * Mengagregasikan 5 domain intelijen bisnis (Menu, Finansial, Bahan Baku, Pembiayaan, Copilot).
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$this->isTestingAccount($user)) {
            abort(403, 'Akses terbatas: Fitur Kaching Intelligence saat ini hanya tersedia untuk akun pengujian (Testing Preview).');
        }
        $userId = $user ? $user->id : null;

        // 1. Menu Intelligence (Overview 30 Hari Standar)
        $periodDates = self::resolvePeriodDates('30days');
        $menuResult = $this->fetchMenuAnalysis($request, $periodDates['start_date'], $periodDates['end_date']);
        $menuItems = $menuResult['data'] ?? [];

        $menuSummary = [
            'total_menus' => count($menuItems),
            'star_count' => 0,
            'cash_cow_count' => 0,
            'puzzle_count' => 0,
            'dog_count' => 0,
            'star_examples' => [],
            'cash_cow_examples' => [],
            'puzzle_examples' => [],
            'dog_examples' => [],
            'state' => empty($menuItems) ? 'INSUFFICIENT_DATA' : 'SUCCESS',
            'period_label' => $periodDates['label'],
        ];

        foreach ($menuItems as $item) {
            $cat = strtoupper($item['category'] ?? '');
            if ($cat === 'STAR') {
                $menuSummary['star_count']++;
                if (count($menuSummary['star_examples']) < 2) {
                    $menuSummary['star_examples'][] = $item['name'];
                }
            } elseif ($cat === 'CASH_COW') {
                $menuSummary['cash_cow_count']++;
                if (count($menuSummary['cash_cow_examples']) < 2) {
                    $menuSummary['cash_cow_examples'][] = $item['name'];
                }
            } elseif ($cat === 'PUZZLE') {
                $menuSummary['puzzle_count']++;
                if (count($menuSummary['puzzle_examples']) < 2) {
                    $menuSummary['puzzle_examples'][] = $item['name'];
                }
            } elseif ($cat === 'DOG') {
                $menuSummary['dog_count']++;
                if (count($menuSummary['dog_examples']) < 2) {
                    $menuSummary['dog_examples'][] = $item['name'];
                }
            }
        }

        // 2. Financial Intelligence (Read-Only via Services)
        $financialSummary = [
            'state' => 'INSUFFICIENT_DATA',
            'condition_label' => 'Perlu Evaluasi Data',
            'has_active_warnings' => false,
            'warning_count' => 0,
            'active_warnings' => [],
            'forecast_summary' => null,
            'message' => 'Data Financial Intelligence belum tersedia pada Backoffice. Diperlukan minimal transaksi aktif untuk evaluasi rasio keuangan.',
        ];

        // Resolve active outlet context for tenant isolation
        $requestedOutlet = $request->query('outlet_id') ?: session('active_outlet_id');
        if ($user && $user->role === 'kasir' && $user->outlet_id) {
            $requestedOutlet = $user->outlet_id;
        }

        $activeOutletId = null;
        if ($userId && $requestedOutlet) {
            $outlet = \App\Models\Outlet::where('id', (int) $requestedOutlet)
                ->where('user_id', $userId)
                ->first();
            if (!$outlet) {
                abort(403, 'Akses outlet tidak diizinkan atau tidak ditemukan.');
            }
            $activeOutletId = (int) $outlet->id;
        } elseif ($userId) {
            $firstOutlet = \App\Models\Outlet::where('user_id', $userId)->first();
            if ($firstOutlet) {
                $activeOutletId = (int) $firstOutlet->id;
            }
        }

        if ($userId) {
            try {
                $warningService = app(FinancialWarningService::class);
                $warnings = $warningService->evaluateAllWarnings($userId, $activeOutletId);

                $activeWarnings = [];
                foreach ($warnings as $w) {
                    $status = $w['status'] ?? '';
                    $severity = $w['severity'] ?? '';
                    if ($status === FinancialWarningService::STATUS_WARNING || $severity === 'CRITICAL' || $severity === 'WARNING') {
                        $activeWarnings[] = $w;
                    }
                }

                $forecastService = app(CashFlowForecastService::class);
                $forecast = $forecastService->forecast($userId, $activeOutletId);

                $hasMeaningfulData = !empty($warnings) || !empty($forecast['forecast_daily'] ?? []);

                if ($hasMeaningfulData) {
                    $financialSummary['state'] = 'SUCCESS';
                    $financialSummary['has_active_warnings'] = count($activeWarnings) > 0;
                    $financialSummary['warning_count'] = count($activeWarnings);
                    $financialSummary['active_warnings'] = array_slice($activeWarnings, 0, 3);
                    $financialSummary['condition_label'] = count($activeWarnings) > 0 ? 'Perlu Perhatian' : 'Stabil & Sehat';
                    if (!empty($forecast['projected_ending_cash'])) {
                        $financialSummary['forecast_summary'] = 'Proyeksi saldo kas 7 hari: Rp ' . number_format($forecast['projected_ending_cash'], 0, ',', '.');
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Financial data evaluation failed in KachingIntelligenceController: ' . $e->getMessage());
            }
        }

        // 3. Raw Material Intelligence (Sinyal Pasar & Integrasi RMI)
        $isTestingUser = $user && (
            $user->email === 'testing@gmail.com' ||
            str_contains(strtolower($user->email), 'testing') ||
            str_contains(strtolower($user->email), 'test') ||
            ($user->id == env('RMI_DEMO_USER_ID'))
        );

        $rawMaterialSummary = [
            'headline_impact' => 'Kenaikan cabai rawit (+8,4%) dan ayam (+3,2%) berpotensi menekan margin menu utama sebesar 2,8% — 9,1%.',
            'top_commodities' => [
                ['name' => 'Cabai Rawit Merah', 'price' => 68000, 'unit' => 'kg', 'change' => 8.4, 'trend' => 'up'],
                ['name' => 'Daging Ayam Broiler', 'price' => 38500, 'unit' => 'kg', 'change' => 3.2, 'trend' => 'up'],
                ['name' => 'Bawang Merah', 'price' => 32000, 'unit' => 'kg', 'change' => 0.0, 'trend' => 'stable'],
            ],
            'is_testing_user' => $isTestingUser,
            'cta_url' => route('copilot.raw-material-intelligence'),
            'state' => 'SUCCESS',
        ];

        // 4. Financing Readiness (Readiness Service & Disclaimer)
        $financingSummary = [
            'state' => 'INSUFFICIENT_DATA',
            'score' => null,
            'band' => 'BELUM TERSEDIA',
            'pillars' => [],
            'disclaimer' => 'Financing Readiness merupakan evaluasi operasional kondisi dan kesiapan data finansial, bukan keputusan atau persetujuan kredit.',
            'message' => 'Data transaksi dan konsistensi operasional belum cukup untuk mengevaluasi skor kesiapan pembiayaan.',
        ];

        if ($userId) {
            try {
                $readinessService = app(FinancingReadinessService::class);
                $readiness = $readinessService->calculate($userId);

                if (!empty($readiness) && ($readiness['status'] ?? '') === 'SUCCESS') {
                    $financingSummary['state'] = 'SUCCESS';
                    $financingSummary['score'] = $readiness['score'] ?? null;
                    $financingSummary['band'] = $readiness['band'] ?? 'MODERATE_READINESS';
                    $financingSummary['pillars'] = $readiness['pillars'] ?? [];
                    $financingSummary['evidence_summary'] = $readiness['evidence_summary'] ?? [];
                    $financingSummary['evaluation_period'] = $readiness['evaluation_period'] ?? [];
                    $financingSummary['limitations'] = $readiness['limitations'] ?? [];
                    // Tetap gunakan disclaimer wajib evaluasi operasional
                    $financingSummary['disclaimer'] = 'Financing Readiness merupakan evaluasi operasional kondisi dan kesiapan data finansial, bukan keputusan atau persetujuan kredit.';
                }
            } catch (\Throwable $e) {
                Log::warning('Financing readiness evaluation failed in KachingIntelligenceController: ' . $e->getMessage());
            }
        }

        // 5. Demand Forecast Intelligence (Read-Only Summary for Hub)
        $forecastSummary = [
            'state' => 'INSUFFICIENT_DATA',
            'total_forecasts' => 0,
            'predicted_items_count' => 0,
            'top_demanded_items' => [],
            'cta_url' => route('copilot.intelligence.demand-forecast'),
            'disclaimer' => 'Prediksi permintaan berbasis model statistik historis. Bukan jaminan penjualan atau anjuran pembelian stok.',
        ];

        if ($userId && $activeOutletId) {
            try {
                $recentForecasts = \App\Models\MenuDemandForecast::where('user_id', $userId)
                    ->where('outlet_id', $activeOutletId)
                    ->where('target_date', '>=', Carbon::today()->toDateString())
                    ->with(['menu' => function ($q) {
                        $q->select('id', 'name', 'category');
                    }])
                    ->orderBy('predicted_quantity', 'desc')
                    ->limit(3)
                    ->get();

                if ($recentForecasts->isNotEmpty()) {
                    $forecastSummary['state'] = 'SUCCESS';
                    $forecastSummary['total_forecasts'] = \App\Models\MenuDemandForecast::where('user_id', $userId)
                        ->where('outlet_id', $activeOutletId)
                        ->count();
                    $forecastSummary['predicted_items_count'] = $recentForecasts->count();
                    $forecastSummary['top_demanded_items'] = $recentForecasts->map(function ($f) {
                        return [
                            'name' => $f->menu?->name ?? 'Menu #' . $f->menu_id,
                            'target_date' => $f->target_date ? Carbon::parse($f->target_date)->format('d M Y') : '-',
                            'predicted_quantity' => (float) $f->predicted_quantity,
                            'uom' => $f->uom ?? 'Porsi',
                            'p10' => (float) $f->lower_bound_p10,
                            'p90' => (float) $f->upper_bound_p90,
                        ];
                    })->toArray();
                }
            } catch (\Throwable $e) {
                Log::warning('Demand forecast evaluation failed in KachingIntelligenceController: ' . $e->getMessage());
            }
        }

        return view('backoffice.intelligence.overview', compact(
            'menuSummary',
            'financialSummary',
            'rawMaterialSummary',
            'financingSummary',
            'forecastSummary'
        ));
    }

    /**
     * Layar Rincian Menu Intelligence (BCG Matrix & SAW Recommendations).
     * Mendukung filter periode analitis: 7 Hari, 30 Hari (Default), 90 Hari, Tahun Ini.
     */
    public function menuDetail(Request $request)
    {
        $user = Auth::user();
        if (!$this->isTestingAccount($user)) {
            abort(403, 'Akses terbatas: Fitur Kaching Intelligence saat ini hanya tersedia untuk akun pengujian (Testing Preview).');
        }

        $rawPeriod = $request->query('period', $request->query('filter', '30days'));

        // Guard: Tolak secara tegas 'today' dan 'shift'
        $invalidPeriodRejected = in_array(strtolower((string)$rawPeriod), ['today', 'shift', 'hari_ini', 'hari-ini']);
        $period = in_array($rawPeriod, ['7days', '30days', '90days', 'year']) ? $rawPeriod : '30days';

        $periodDates = self::resolvePeriodDates($period);
        $menuResult = $this->fetchMenuAnalysis($request, $periodDates['start_date'], $periodDates['end_date']);
        $menuItems = $menuResult['data'] ?? [];

        // Kelompokkan menu ke dalam 4 Kuadran BCG
        $bcgQuadrants = [
            'STAR' => [
                'label' => 'STAR',
                'description' => 'Penjualan Tinggi • Margin Tinggi',
                'recommendation' => 'Menu Juara! Pertahankan ketersediaan stok, konsistensi rasa, dan kualitas layanan.',
                'badge_class' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                'card_border' => 'border-emerald-200',
                'items' => [],
                'count' => 0,
            ],
            'PUZZLE' => [
                'label' => 'PUZZLE',
                'description' => 'Penjualan Rendah • Margin Tinggi',
                'recommendation' => 'Margin besar namun kurang laku. Genjot promosi visual, tempatkan di posisi katalog strategis.',
                'badge_class' => 'bg-amber-50 text-amber-700 border-amber-200',
                'card_border' => 'border-amber-200',
                'items' => [],
                'count' => 0,
            ],
            'CASH_COW' => [
                'label' => 'CASH COW',
                'description' => 'Penjualan Tinggi • Margin Rendah',
                'recommendation' => 'Volume tinggi penopang kas operasional. Tingkatkan margin via paket bundling atau optimasi bahan.',
                'badge_class' => 'bg-blue-50 text-blue-700 border-blue-200',
                'card_border' => 'border-blue-200',
                'items' => [],
                'count' => 0,
            ],
            'DOG' => [
                'label' => 'DOG',
                'description' => 'Penjualan Rendah • Margin Rendah',
                'recommendation' => 'Peminat rendah dan margin tipis. Pertimbangkan revisi formula resep, reposisi, atau eliminasi menu.',
                'badge_class' => 'bg-rose-50 text-rose-700 border-rose-200',
                'card_border' => 'border-rose-200',
                'items' => [],
                'count' => 0,
            ],
        ];

        foreach ($menuItems as $item) {
            $cat = strtoupper($item['category'] ?? '');
            if (isset($bcgQuadrants[$cat])) {
                $bcgQuadrants[$cat]['items'][] = $item;
                $bcgQuadrants[$cat]['count']++;
            }
        }

        $state = empty($menuItems) ? 'INSUFFICIENT_DATA' : 'SUCCESS';

        return view('backoffice.intelligence.menu_detail', compact(
            'period',
            'periodDates',
            'invalidPeriodRejected',
            'menuItems',
            'bcgQuadrants',
            'state'
        ));
    }

    /**
     * Resolusi Rentang Tanggal Analitis.
     * Mengeliminasi filter mikro (Shift/Hari Ini) karena SAW & BCG memerlukan data minimal 7 hari.
     */
    public static function resolvePeriodDates(string $period): array
    {
        $now = Carbon::now('Asia/Jakarta');
        $endDate = $now->toDateString();

        switch ($period) {
            case '7days':
                $startDate = $now->copy()->subDays(6)->toDateString();
                $label = '7 Hari Terakhir';
                break;
            case '90days':
                $startDate = $now->copy()->subDays(89)->toDateString();
                $label = '90 Hari Terakhir';
                break;
            case 'year':
                $startDate = $now->copy()->startOfYear()->toDateString();
                $label = 'Tahun Ini';
                break;
            case '30days':
            default:
                $period = '30days';
                $startDate = $now->copy()->subDays(29)->toDateString();
                $label = '30 Hari Terakhir';
                break;
        }

        return [
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'label' => $label,
        ];
    }

    /**
     * Panggil AnalysisController::getMenuAnalysis dengan payload tanggal.
     */
    protected function fetchMenuAnalysis(Request $originalRequest, string $startDate, string $endDate): array
    {
        $user = Auth::user();
        if (!$user) {
            return ['status' => 'error', 'data' => []];
        }

        try {
            $analysisRequest = Request::create('/api/analysis/menu-data', 'GET', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
            $analysisRequest->setUserResolver(fn () => $user);

            $response = app(AnalysisController::class)->getMenuAnalysis($analysisRequest);
            $result = $response->getData(true);

            return is_array($result) ? $result : ['status' => 'error', 'data' => []];
        } catch (\Throwable $e) {
            Log::error('fetchMenuAnalysis error in KachingIntelligenceController: ' . $e->getMessage());
            return ['status' => 'error', 'data' => []];
        }
    }

    /**
     * Ekspor Ringkasan Bukti Finansial (Financial Evidence Dossier) dalam format PDF.
     * Menggunakan canonical output FinancingReadinessService & FinancialEvidenceService
     * tanpa rekalkulasi di controller atau view.
     */
    public function exportDossier(Request $request)
    {
        $user = Auth::user();
        if (!$this->isTestingAccount($user)) {
            abort(403, 'Akses terbatas: Fitur Kaching Intelligence saat ini hanya tersedia untuk akun pengujian (Testing Preview).');
        }

        $userId = (int) $user->id;

        // Resolusi Outlet Scope (SSOT)
        $outletId = $request->query('outlet_id') ?: session('active_outlet_id');
        if ($user->role === 'kasir' && $user->outlet_id) {
            $outletId = (int) $user->outlet_id;
        }

        $outlet = null;
        if ($outletId) {
            $outletId = (int) $outletId;
            $outlet = \App\Models\Outlet::where('id', $outletId)
                ->where('user_id', $userId)
                ->first();

            if (!$outlet) {
                abort(403, 'Akses ditolak: Outlet tidak ditemukan atau bukan milik akun Anda.');
            }
        } else {
            // Ambil outlet pertama jika tersedia
            $firstOutlet = \App\Models\Outlet::where('user_id', $userId)->orderBy('id', 'asc')->first();
            if ($firstOutlet) {
                $outlet = $firstOutlet;
                $outletId = (int) $firstOutlet->id;
            }
        }

        // Panggil Canonical Service
        $readinessService = app(\App\Services\Financial\FinancingReadinessService::class);
        $readiness = $readinessService->calculate($userId, $outletId);

        if (empty($readiness) || ($readiness['status'] ?? '') !== 'SUCCESS') {
            return redirect()->back()->with('error', 'Data operasional belum mencukupi untuk mengunduh berkas bukti finansial.');
        }

        $businessName = $user->name ?? 'Merchant Kaching';
        $outletName = $outlet ? $outlet->name : 'Pusat / Operasional Utama';
        $generatedAt = now()->format('d M Y, H:i') . ' WIB';

        $startDate = $readiness['evaluation_period']['start_date'] ?? date('Y-m-d');
        $endDate = $readiness['evaluation_period']['end_date'] ?? date('Y-m-d');
        $safePeriod = $startDate . '_sd_' . $endDate;
        $safeOutletSlug = \Illuminate\Support\Str::slug($outletName, '_');
        $fileName = 'Financial_Evidence_Dossier_' . $safeOutletSlug . '_' . $safePeriod . '.pdf';

        // Render PDF menggunakan barryvdh/laravel-dompdf
        $pdf = app('dompdf.wrapper')->loadView('exports.financial_evidence_dossier', [
            'user' => $user,
            'outlet' => $outlet,
            'businessName' => $businessName,
            'outletName' => $outletName,
            'readiness' => $readiness,
            'score' => $readiness['score'],
            'band' => $readiness['band'],
            'summary' => $readiness['summary'] ?? '',
            'pillars' => $readiness['pillars'] ?? [],
            'evidenceSummary' => $readiness['evidence_summary'] ?? [],
            'evaluationPeriod' => $readiness['evaluation_period'] ?? [],
            'limitations' => $readiness['limitations'] ?? [],
            'dataSources' => $readiness['data_sources'] ?? [],
            'disclaimer' => $readiness['disclaimer'] ?? '',
            'generatedAt' => $generatedAt,
        ])->setPaper('a4', 'portrait');

        if ($request->query('preview') == '1' || $request->query('stream') == '1') {
            return $pdf->stream($fileName);
        }

        return $pdf->download($fileName);
    }

    /**
     * Helper identifikasi akun pengujian (Testing Account).
     */
    protected function isTestingAccount($user): bool
    {
        if (app()->environment('testing')) {
            return true;
        }
        if (!$user) return false;
        $email = strtolower($user->email ?? '');
        return str_contains($email, 'test') 
            || str_contains($email, 'testing') 
            || ($user->id == env('RMI_DEMO_USER_ID'));
    }
}
