<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Menu;
use App\Models\Item;

/**
 * Raw Material Intelligence Controller
 *
 * FinTechathon Topic E: Web Demo UI for Testing Account.
 * Menyediakan dashboard intelijen harga bahan baku, sinyal pasar,
 * estimasi dampak HPP menu, dan integrasi tanya Copilot (Kachi).
 *
 * SIFAT: READ-ONLY. Zero mutation on database, financial records, or POS data.
 */
class RawMaterialIntelligenceController extends Controller
{
    /**
     * Tampilkan Dashboard Raw Material Intelligence.
     * Dibatasi secara ketat hanya untuk akun testing terotorisasi.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        if (!$this->isTestingAccount($user)) {
            abort(403, 'Akses terbatas: Fitur Raw Material Intelligence saat ini hanya tersedia untuk akun pengujian (Testing Preview).');
        }

        // 1. Data 5 Komoditas Pangan F&B Utama (DEMO / SAMPLE DATASET)
        $commodities = $this->getDemoCommodities();

        // 2. Data Tren Historis 30 Hari & 7 Hari untuk Chart.js (DEMO DATASET)
        $trendData = $this->getDemoTrendData();

        // 3. Sinyal Pasar Eksternal (External Business Radar Context)
        $marketSignals = $this->getMarketSignals();

        // 4. Placeholder Status Engine Forecast
        $forecastPlaceholder = [
            'is_connected' => false,
            'status_label' => 'Forecast engine belum terhubung',
            'commodity' => 'Cabai Rawit',
            'projected_trend' => 'NAIK',
            'projected_range' => 'Rp 70.000 — Rp 76.000 / kg',
            'confidence' => 'DEMO / Belum tersedia',
            'horizon' => '7 Hari',
            'note' => 'Model peramalan deterministik berbasis pasar sedang dalam tahap pengembangan. Angka di atas merupakan representasi demonstrasi visual.',
        ];

        // 5. Analisis Dampak Bisnis Terhadap Menu (Read-Only)
        $businessImpact = $this->calculateBusinessImpact($user);

        return view('backoffice.raw_material_intelligence', compact(
            'commodities',
            'trendData',
            'marketSignals',
            'forecastPlaceholder',
            'businessImpact'
        ));
    }

    /**
     * Verifikasi gerbang akun pengujian (Testing Account Gate).
     */
    protected function isTestingAccount($user): bool
    {
        if (!$user) {
            return false;
        }

        $email = strtolower($user->email ?? '');

        // Layer A: Identifikasi eksplisit email testing Kaching
        if ($email === 'testing@gmail.com' || str_contains($email, 'testing') || str_contains($email, 'test')) {
            return true;
        }

        // Layer B: Environment configuration RMI_DEMO_USER_ID
        $demoUserId = env('RMI_DEMO_USER_ID');
        if ($demoUserId && (int)$user->id === (int)$demoUserId) {
            return true;
        }

        return false;
    }

    /**
     * Dataset Demo 5 Komoditas Bahan Baku Utama.
     */
    private function getDemoCommodities(): array
    {
        return [
            'cabai_rawit' => [
                'id' => 'cabai_rawit',
                'name' => 'Cabai Rawit Merah',
                'latest_price' => 68000,
                'unit' => 'kg',
                'change_pct' => 8.4,
                'trend' => 'up', // 'up', 'down', 'stable'
                'last_updated' => '26 Sep 2026',
                'source' => 'Demo Market Dataset (Bapanas/PIHPS Sample)',
                'badge_color' => 'red',
            ],
            'bawang_merah' => [
                'id' => 'bawang_merah',
                'name' => 'Bawang Merah Brebes',
                'latest_price' => 34500,
                'unit' => 'kg',
                'change_pct' => -2.8,
                'trend' => 'down',
                'last_updated' => '26 Sep 2026',
                'source' => 'Demo Market Dataset (Bapanas/PIHPS Sample)',
                'badge_color' => 'emerald',
            ],
            'daging_ayam' => [
                'id' => 'daging_ayam',
                'name' => 'Daging Ayam Broiler',
                'latest_price' => 38000,
                'unit' => 'kg',
                'change_pct' => 3.2,
                'trend' => 'up',
                'last_updated' => '26 Sep 2026',
                'source' => 'Demo Market Dataset (Bapanas/PIHPS Sample)',
                'badge_color' => 'amber',
            ],
            'telur_ayam' => [
                'id' => 'telur_ayam',
                'name' => 'Telur Ayam Ras',
                'latest_price' => 29200,
                'unit' => 'kg',
                'change_pct' => 0.5,
                'trend' => 'stable',
                'last_updated' => '26 Sep 2026',
                'source' => 'Demo Market Dataset (Bapanas/PIHPS Sample)',
                'badge_color' => 'blue',
            ],
            'minyak_goreng' => [
                'id' => 'minyak_goreng',
                'name' => 'Minyak Goreng Curah',
                'latest_price' => 16500,
                'unit' => 'liter',
                'change_pct' => 1.8,
                'trend' => 'up',
                'last_updated' => '26 Sep 2026',
                'source' => 'Demo Market Dataset (Bapanas/PIHPS Sample)',
                'badge_color' => 'amber',
            ],
        ];
    }

    /**
     * Dataset Demo Tren Historis (30 Hari) untuk Visualisasi Chart.
     */
    private function getDemoTrendData(): array
    {
        // 30 hari data dari 28 Agu 2026 sampai 26 Sep 2026
        $dates30 = [];
        $startDate = \Carbon\Carbon::parse('2026-09-26')->subDays(29);
        for ($i = 0; $i < 30; $i++) {
            $dates30[] = $startDate->copy()->addDays($i)->format('d M');
        }

        return [
            'labels_30d' => $dates30,
            'labels_7d' => array_slice($dates30, -7),
            'series' => [
                'cabai_rawit' => [
                    'name' => 'Cabai Rawit Merah',
                    'unit' => 'Rp/kg',
                    'data_30d' => [
                        58000, 58500, 59000, 59500, 59000, 60000, 60500, 61000, 60000, 61500,
                        62000, 61800, 62500, 63000, 62800, 63200, 63000, 63500, 64000, 64200,
                        63800, 64500, 65000, 65800, 66000, 66500, 66200, 67000, 67500, 68000
                    ],
                    'data_7d' => [65800, 66000, 66500, 66200, 67000, 67500, 68000],
                ],
                'bawang_merah' => [
                    'name' => 'Bawang Merah Brebes',
                    'unit' => 'Rp/kg',
                    'data_30d' => [
                        37000, 36800, 36500, 36000, 36200, 35800, 35500, 35200, 35500, 35000,
                        35200, 35000, 34800, 34900, 34700, 34800, 35000, 35200, 35000, 34800,
                        34900, 35000, 35200, 35100, 35000, 34800, 34700, 34600, 34500, 34500
                    ],
                    'data_7d' => [35100, 35000, 34800, 34700, 34600, 34500, 34500],
                ],
                'daging_ayam' => [
                    'name' => 'Daging Ayam Broiler',
                    'unit' => 'Rp/kg',
                    'data_30d' => [
                        36000, 36000, 36200, 36500, 36400, 36500, 36800, 36700, 36800, 37000,
                        37000, 37200, 37100, 37000, 37200, 37400, 37300, 37500, 37500, 37600,
                        37500, 37600, 37700, 37800, 37700, 37800, 37900, 37800, 37900, 38000
                    ],
                    'data_7d' => [37800, 37700, 37800, 37900, 37800, 37900, 38000],
                ],
                'telur_ayam' => [
                    'name' => 'Telur Ayam Ras',
                    'unit' => 'Rp/kg',
                    'data_30d' => [
                        28800, 28900, 29000, 29000, 28900, 29000, 29100, 29000, 29000, 29100,
                        29100, 29000, 29200, 29100, 29000, 29100, 29100, 29200, 29100, 29100,
                        29100, 29200, 29100, 29100, 29200, 29100, 29100, 29200, 29200, 29200
                    ],
                    'data_7d' => [29100, 29200, 29100, 29100, 29200, 29200, 29200],
                ],
                'minyak_goreng' => [
                    'name' => 'Minyak Goreng Curah',
                    'unit' => 'Rp/liter',
                    'data_30d' => [
                        15900, 16000, 16000, 16100, 16100, 16200, 16200, 16200, 16300, 16300,
                        16250, 16300, 16350, 16400, 16350, 16400, 16400, 16450, 16400, 16450,
                        16400, 16450, 16400, 16450, 16450, 16500, 16450, 16480, 16500, 16500
                    ],
                    'data_7d' => [16450, 16450, 16500, 16450, 16480, 16500, 16500],
                ],
            ]
        ];
    }

    /**
     * Sinyal Pasar Eksternal (Sesuai Spesifikasi 9).
     */
    private function getMarketSignals(): array
    {
        return [
            [
                'commodity' => 'Cabai Rawit Merah',
                'signal_type' => 'Potensi Tekanan Pasokan',
                'type_badge' => 'warning',
                'explanation' => 'Curah hujan tinggi dan anomali cuaca di sentra produksi Jawa Timur berpotensi menghambat panen serta memicu lonjakan harga grosir 7–14 hari ke depan.',
                'source' => 'Demo Market Radar Context',
                'timestamp' => '26 Sep 2026, 08:30 WIB',
                'status' => 'Aktif',
            ],
            [
                'commodity' => 'Daging Ayam Broiler',
                'signal_type' => 'Kenaikan Biaya Pakan Ternak',
                'type_badge' => 'info',
                'explanation' => 'Penyesuaian harga jagung dan pakan unggas di tingkat peternak mulai bertransmisi ke harga karkas ayam pasar tradisional.',
                'source' => 'Demo Market Radar Context',
                'timestamp' => '25 Sep 2026, 14:15 WIB',
                'status' => 'Terpantau',
            ],
            [
                'commodity' => 'Bawang Merah Brebes',
                'signal_type' => 'Pasokan Panen Raya Memadai',
                'type_badge' => 'success',
                'explanation' => 'Puncak panen di sentra Brebes dan Nganjuk menjaga kontinuitas distribusi. Tren harga relatif stabil dengan kecenderungan melandai tipis.',
                'source' => 'Demo Market Radar Context',
                'timestamp' => '24 Sep 2026, 11:00 WIB',
                'status' => 'Mereda',
            ],
        ];
    }

    /**
     * Kalkulasi Dampak Bisnis terhadap Menu UMKM (Read-Only).
     */
    private function calculateBusinessImpact($user): array
    {
        // Cari menu milik user yang memiliki resep / ingredients terkait bahan baku utama
        $menus = Menu::where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->with(['ingredients.item'])
            ->limit(5)
            ->get();

        $affectedItems = [];

        foreach ($menus as $menu) {
            $hasRelevantIngredient = false;
            $ingredientNames = [];

            if ($menu->ingredients && $menu->ingredients->count() > 0) {
                foreach ($menu->ingredients as $mi) {
                    $itemName = strtolower($mi->item?->name ?? '');
                    if (str_contains($itemName, 'cabai') || str_contains($itemName, 'bawang') ||
                        str_contains($itemName, 'ayam') || str_contains($itemName, 'telur') ||
                        str_contains($itemName, 'minyak')) {
                        $hasRelevantIngredient = true;
                        $ingredientNames[] = $mi->item->name;
                    }
                }
            }

            if ($hasRelevantIngredient) {
                $baseHpp = (float)$menu->cost_price;
                $estimatedIncrease = $baseHpp > 0 ? round($baseHpp * 0.05, 0) : 1000;
                $currentMargin = $menu->price > 0 ? round((($menu->price - $baseHpp) / $menu->price) * 100, 1) : 0;
                $projectedMargin = $menu->price > 0 ? round((($menu->price - ($baseHpp + $estimatedIncrease)) / $menu->price) * 100, 1) : 0;

                $affectedItems[] = [
                    'menu_id' => $menu->id,
                    'menu_name' => $menu->name,
                    'ingredients' => implode(', ', array_unique($ingredientNames)),
                    'current_price' => (float)$menu->price,
                    'current_hpp' => $baseHpp,
                    'projected_hpp' => $baseHpp + $estimatedIncrease,
                    'estimated_increase' => $estimatedIncrease,
                    'current_margin' => $currentMargin,
                    'projected_margin' => $projectedMargin,
                    'margin_compression' => round($currentMargin - $projectedMargin, 1),
                    'is_live_catalog' => true,
                ];
            }
        }

        // Jika tenant belum menghubungkan modul resep/BOM atau katalog kosong, sediakan sampel representatif F&B UMKM
        if (empty($affectedItems)) {
            $affectedItems = [
                [
                    'menu_id' => null,
                    'menu_name' => 'Ayam Geprek Sambal Korek',
                    'ingredients' => 'Daging Ayam, Cabai Rawit, Minyak Goreng',
                    'current_price' => 22000,
                    'current_hpp' => 12500,
                    'projected_hpp' => 13550,
                    'estimated_increase' => 1050,
                    'current_margin' => 43.2,
                    'projected_margin' => 38.4,
                    'margin_compression' => 4.8,
                    'is_live_catalog' => false,
                ],
                [
                    'menu_id' => null,
                    'menu_name' => 'Nasi Goreng Spesial',
                    'ingredients' => 'Telur Ayam, Bawang Merah, Minyak Goreng',
                    'current_price' => 18000,
                    'current_hpp' => 8800,
                    'projected_hpp' => 9300,
                    'estimated_increase' => 500,
                    'current_margin' => 51.1,
                    'projected_margin' => 48.3,
                    'margin_compression' => 2.8,
                    'is_live_catalog' => false,
                ],
                [
                    'menu_id' => null,
                    'menu_name' => 'Sambal Bawang Extra Pedas',
                    'ingredients' => 'Cabai Rawit Merah, Bawang Merah, Minyak',
                    'current_price' => 6000,
                    'current_hpp' => 2800,
                    'projected_hpp' => 3350,
                    'estimated_increase' => 550,
                    'current_margin' => 53.3,
                    'projected_margin' => 44.2,
                    'margin_compression' => 9.1,
                    'is_live_catalog' => false,
                ],
            ];
        }

        return [
            'impact_headline' => 'Kenaikan cabai rawit (+8,4%) dan ayam (+3,2%) berpotensi menekan margin menu utama sebesar 2,8% — 9,1%.',
            'affected_menus' => $affectedItems,
            'summary_recommendation' => 'Pertimbangkan substitusi porsi bumbu atau penyesuaian strategi bundling sebelum memutuskan menaikkan harga jual.',
        ];
    }
}
