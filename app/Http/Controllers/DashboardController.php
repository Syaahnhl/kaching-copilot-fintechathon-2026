<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $userId = Auth::id();
        $filter = $request->query('filter', 'today'); // Filter Tanggal
        $outletFilter = $request->query('outlet_id', 'all'); // 🏢 [BARU] Filter Cabang
        $shiftFilter = $request->query('shift_id', 'all'); // [BARU] Filter Shift

        // [BARU] Ambil daftar cabang untuk dimunculkan di Dropdown Web
        $outlets = \App\Models\Outlet::where('user_id', $userId)->get();

        // [BARU] Ambil daftar shift untuk dropdown
        $shifts = \App\Models\Shift::where('user_id', $userId)
            ->orderBy('start_time', 'desc')
            ->limit(20)
            ->get();

        // 🛡️ [FIX] Tangani Global Mode (0 dari Android atau 'all' dari Web)
        if ($outlets->count() === 1) {
            $outletFilter = $outlets->first()->id;
        } else {
            if ($outletFilter === 'all' || $outletFilter == 0) {
                $outletFilter = 'all';
            } else {
                $outletFilter = (int) $outletFilter;
                $outletValid = $outlets->contains('id', $outletFilter);
                if (!$outletValid) {
                    abort(403, 'Akses ditolak: Outlet ini bukan milik akun Anda.');
                }
            }
        }

        // 🛡️ [SECURITY] Mencegah Kasir melakukan Privilege Escalation dengan melihat semua data cabang
        $user = Auth::user();
        if ($user->role === 'kasir') {
            $outletFilter = (int) $user->outlet_id;
        }

        // 1. SET TIMEZONE
        $now = \Carbon\Carbon::now('Asia/Jakarta');
        $today = \Carbon\Carbon::today('Asia/Jakarta');

        // 2. [BUG FIX] THE GOLDEN RULE OF CASH FLOW
        // Menyelaraskan perhitungan "Total Revenue" dan "Total Transaksi" dengan Android App
        $goldenBaseQuery = \App\Models\Transaction::where('user_id', $userId)
            ->where('is_training', false)
            ->when($outletFilter !== 'all', function ($q) use ($outletFilter) {
                return $q->where('outlet_id', $outletFilter);
            });

        // 3. TERAPKAN FILTER TANGGAL DARI TAB (Hanya jika shift filter tidak aktif)
        // [FIX 2026-07-07] Bypassed if shiftFilter is active to avoid multiple conflicting whereBetween/where clauses
        if ($shiftFilter === 'all') {
            if ($filter === 'today' || $filter === 'custom') {
                $targetDate = ($filter === 'custom') ? $request->date : $now->format('Y-m-d');
                
                $startUtc = \Carbon\Carbon::parse($targetDate, 'Asia/Jakarta')->startOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
                $endUtc = \Carbon\Carbon::parse($targetDate, 'Asia/Jakarta')->endOfDay()->timezone('UTC')->format('Y-m-d H:i:s');

                $goldenBaseQuery->whereBetween('created_at_device', [$startUtc, $endUtc]);
                $title = ($filter === 'custom') ? \Carbon\Carbon::parse($targetDate)->translatedFormat('d F Y') : __('backoffice.dashboard.filters.today');
            } elseif ($filter === '7days') {
                $startUtc = $now->copy()->subDays(7)->startOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
                $goldenBaseQuery->where('created_at_device', '>=', $startUtc);
                $title = __('backoffice.dashboard.filters.7days');
            } elseif ($filter === '30days') {
                $startUtc = $now->copy()->subDays(30)->startOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
                $goldenBaseQuery->where('created_at_device', '>=', $startUtc);
                $title = __('backoffice.dashboard.filters.30days');
            } elseif ($filter === 'year') {
                $startUtc = $now->copy()->startOfYear()->timezone('UTC')->format('Y-m-d H:i:s');
                $endUtc   = $now->copy()->endOfYear()->timezone('UTC')->format('Y-m-d H:i:s');
                $goldenBaseQuery->whereBetween('created_at_device', [$startUtc, $endUtc]);
                $title = __('backoffice.dashboard.filters.this_year');
            } else {
                $title = __('backoffice.dashboard.filters.all_time');
            }
        }

        // [BARU] SHIFT FILTER — override date range jika shift dipilih
        $selectedShift = null;
        if ($shiftFilter !== 'all') {
            $selectedShift = \App\Models\Shift::where('user_id', $userId)
                ->where('id', $shiftFilter)
                ->first();
            if ($selectedShift) {
                $shiftStartWib = \Carbon\Carbon::parse($selectedShift->start_time)->timezone('Asia/Jakarta');
                $shiftStartUtc = \Carbon\Carbon::parse($selectedShift->start_time)->format('Y-m-d H:i:s');
                $shiftEndUtc = $selectedShift->end_time
                    ? \Carbon\Carbon::parse($selectedShift->end_time)->format('Y-m-d H:i:s')
                    : \Carbon\Carbon::now('UTC')->format('Y-m-d H:i:s');

                // Query database created_at_device menggunakan UTC time (karena database menyimpan dalam UTC)
                $goldenBaseQuery->whereBetween('created_at_device', [$shiftStartUtc, $shiftEndUtc]);
                $title = __('backoffice.dashboard.filters.shift_prefix') . " " . $selectedShift->cashier_name . " (" . $shiftStartWib->translatedFormat('d M H:i') . ")";
            }
        }

        // 4. HITUNG METRIK (MURNI DB AGGREGATION - ANTI MEMORY OVERHEAD)
        // Konsolidasi 4 query menjadi 1 query conditional aggregate
        $completedStatuses = \App\Models\Transaction::getActiveStatusesForWeb();
        $completedStatusesStr = "'" . implode("','", $completedStatuses) . "'";
        $actualPaidSql = \App\Models\Transaction::getActualPaidSql($completedStatuses);

        $aggregates = (clone $goldenBaseQuery)->select(
            DB::raw("COUNT(*) as todayCount"),
            DB::raw("SUM(CASE WHEN status IN ($completedStatusesStr) AND (LOWER(payment_method) LIKE '%bayar nanti%' OR LOWER(payment_method) LIKE '%hutang%') THEN (total_amount - pay_amount) ELSE 0 END) as totalPiutang"),
            DB::raw("SUM(CASE WHEN status IN ($completedStatusesStr) THEN $actualPaidSql ELSE 0 END) as todayOmset"),
            DB::raw("SUM(CASE WHEN status IN ($completedStatusesStr) AND payment_method IN ('Tunai', 'Tunai / Cash', 'cash') THEN total_amount ELSE 0 END) as cashSales"),
            DB::raw("SUM(CASE WHEN status IN ($completedStatusesStr) AND payment_method NOT IN ('Tunai', 'Tunai / Cash', 'cash', 'Bayar Nanti') AND LOWER(payment_method) NOT LIKE '%bayar nanti%' AND LOWER(payment_method) NOT LIKE '%hutang%' THEN total_amount ELSE 0 END) as digitalSales")
        )->first();

        $todayCount   = (int) ($aggregates->todayCount ?? 0);
        $totalPiutang = (float) ($aggregates->totalPiutang ?? 0);
        $todayOmset   = (float) ($aggregates->todayOmset ?? 0);
        $cashSales    = (float) ($aggregates->cashSales ?? 0);
        $digitalSales = (float) ($aggregates->digitalSales ?? 0);

        // 5. LOGIKA GRAFIK DINAMIS — Timezone-safe (Asia/Jakarta / WIB)
        // PENTING: Semua grouping wajib pakai CONVERT_TZ agar waktu lokal akurat.
        // whereDate() pada created_at_device (UTC) diganti dengan CONVERT_TZ agar
        // filter batas hari tidak geser akibat selisih +7 jam WIB vs UTC.
        $chartLabels = [];
        $chartData   = [];
        $chartSubtitle = '';
        $tz = "'+07:00'";

        $buildChartQuery = function($startUtc, $endUtc) use ($userId, $outletFilter) {
            return \App\Models\Transaction::where('user_id', $userId)
                ->where('is_training', false)
                ->whereIn('status', \App\Models\Transaction::getActiveStatusesForWeb())
                ->where('payment_method', '!=', 'Bayar Nanti')
                ->when($outletFilter !== 'all', fn($q) => $q->where('outlet_id', $outletFilter))
                ->whereBetween('created_at_device', [$startUtc, $endUtc]);
        };

        if ($filter === 'today' || $filter === 'custom') {
            // --- GRAFIK PER JAM ---
            $targetDate  = ($filter === 'custom') ? $request->date : $now->format('Y-m-d');
            $displayDate = ($filter === 'today') ? __('backoffice.dashboard.filters.today') : \Carbon\Carbon::parse($targetDate)->translatedFormat('d M Y');
            $chartSubtitle = __('backoffice.dashboard.charts.hourly_subtitle', ['date' => $displayDate]);

            $chartRaw = [];
            for ($i = 0; $i <= 23; $i++) {
                $startWib = \Carbon\Carbon::parse($targetDate, 'Asia/Jakarta')->setTime($i, 0, 0);
                $endWib   = $startWib->copy()->endOfHour();
                $startUtc = $startWib->timezone('UTC')->format('Y-m-d H:i:s');
                $endUtc   = $endWib->timezone('UTC')->format('Y-m-d H:i:s');

                $chartRaw[$i] = (float) $buildChartQuery($startUtc, $endUtc)->sum('total_amount');
            }

            // Tampilkan hanya jam operasional 08:00–23:00 agar grafik tidak
            // dipenuhi angka 0 di dini hari (efek transaksi sinkronisasi malam).
            $minHour = 8;
            $maxHour = 23;
            $activeHours = array_keys(array_filter($chartRaw, fn($v) => $v > 0));
            if (!empty($activeHours)) {
                $minHour = min($minHour, min($activeHours));
                $maxHour = max($maxHour, max($activeHours));
            }
            for ($i = $minHour; $i <= $maxHour; $i++) {
                $chartLabels[] = str_pad($i, 2, '0', STR_PAD_LEFT) . ':00';
                $chartData[]   = $chartRaw[$i];
            }
        } elseif ($filter === '7days' || $filter === '30days') {
            // --- GRAFIK PER HARI ---
            $days      = ($filter === '7days') ? 7 : 30;
            $chartSubtitle = __('backoffice.dashboard.charts.days_subtitle', ['days' => $days]);

            $chartRaw = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $dateWib = $now->copy()->subDays($i);
                $startWib = $dateWib->copy()->startOfDay();
                $endWib = $dateWib->copy()->endOfDay();
                $startUtc = $startWib->timezone('UTC')->format('Y-m-d H:i:s');
                $endUtc = $endWib->timezone('UTC')->format('Y-m-d H:i:s');

                $dateString = $dateWib->format('Y-m-d');
                $chartRaw[$dateString] = (float) $buildChartQuery($startUtc, $endUtc)->sum('total_amount');
            }

            for ($i = $days - 1; $i >= 0; $i--) {
                $date       = $now->copy()->subDays($i);
                $dateString = $date->format('Y-m-d');
                $chartLabels[] = $date->translatedFormat('d M');
                $chartData[]   = $chartRaw[$dateString];
            }
        } elseif ($filter === 'year' || $filter === 'all') {
            // --- GRAFIK PER BULAN ---
            $isYear = ($filter === 'year');
            $chartSubtitle = $isYear
                ? __('backoffice.dashboard.charts.yearly_subtitle', ['year' => $now->year])
                : __('backoffice.dashboard.charts.last_12_months_subtitle');

            if ($isYear) {
                $chartRaw = [];
                for ($m = 1; $m <= 12; $m++) {
                    $startWib = \Carbon\Carbon::create($now->year, $m, 1, 0, 0, 0, 'Asia/Jakarta');
                    $endWib = $startWib->copy()->endOfMonth();
                    $startUtc = $startWib->timezone('UTC')->format('Y-m-d H:i:s');
                    $endUtc = $endWib->timezone('UTC')->format('Y-m-d H:i:s');

                    $chartRaw[$m] = (float) $buildChartQuery($startUtc, $endUtc)->sum('total_amount');
                }

                for ($m = 1; $m <= 12; $m++) {
                    $chartLabels[] = \Carbon\Carbon::create($now->year, $m, 1)->translatedFormat('M');
                    $chartData[]   = $chartRaw[$m];
                }
            } else {
                // All Time: 12 bulan terakhir
                $chartRaw = [];
                for ($i = 11; $i >= 0; $i--) {
                    $startWib = $now->copy()->subMonths($i)->startOfMonth();
                    $endWib = $startWib->copy()->endOfMonth();
                    $startUtc = $startWib->timezone('UTC')->format('Y-m-d H:i:s');
                    $endUtc = $endWib->timezone('UTC')->format('Y-m-d H:i:s');

                    $key = $startWib->format('Y-m');
                    $chartRaw[$key] = (float) ($buildChartQuery($startUtc, $endUtc)->sum('total_amount') ?? 0);
                }

                for ($i = 11; $i >= 0; $i--) {
                    $monthDate   = $now->copy()->subMonths($i);
                    $key         = $monthDate->format('Y-m');
                    $chartLabels[] = $monthDate->translatedFormat('M y');
                    $chartData[]   = isset($chartRaw[$key]) ? (float) $chartRaw[$key] : 0.0;
                }
            }
        } else {
            // Fallback
            $chartSubtitle = __('backoffice.dashboard.charts.sales_statistics');
            $chartLabels   = ['No Data'];
            $chartData     = [0];
        }

        // 6. WIDGET Samping (Jadwal & Stok)
        // Range waktu hari ini (start of day s.d. end of day) dalam Asia/Jakarta, di-convert ke UTC
        $startUtcToday = $now->copy()->startOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
        $endUtcToday = $now->copy()->endOfDay()->timezone('UTC')->format('Y-m-d H:i:s');

        // Ambil semua data absensi hari ini untuk dicocokkan statusnya
        $todayAttendances = \App\Models\Attendance::whereBetween('created_at', [$startUtcToday, $endUtcToday])->get();

        $todaySchedules = \App\Models\Schedule::with('staff')
            ->where('user_id', $userId)
            ->whereDate('work_date', $now->format('Y-m-d'))
            ->when($outletFilter !== 'all', function ($q) use ($outletFilter) {
                return $q->whereHas('staff', function ($sq) use ($outletFilter) {
                    $sq->where('outlet_id', $outletFilter);
                });
            })
            ->get();

        $todaySchedules->map(function ($schedule) use ($todayAttendances) {
            $staffId = $schedule->staff_id;
            
            // Cari clock in
            $clockIn = $todayAttendances->where('staff_id', $staffId)->where('type', 'in')->first();
            // Cari clock out
            $clockOut = $todayAttendances->where('staff_id', $staffId)->where('type', 'out')->first();

            if ($clockIn && $clockOut) {
                $schedule->attendance_status = 'selesai'; // Selesai bekerja / Pulang
                $schedule->attendance_time = \Carbon\Carbon::parse($clockIn->created_at)->timezone('Asia/Jakarta')->format('H:i') . ' - ' . \Carbon\Carbon::parse($clockOut->created_at)->timezone('Asia/Jakarta')->format('H:i');
            } elseif ($clockIn) {
                $schedule->attendance_status = 'aktif'; // Sedang aktif bekerja
                $schedule->attendance_time = \Carbon\Carbon::parse($clockIn->created_at)->timezone('Asia/Jakarta')->format('H:i');
            } else {
                $schedule->attendance_status = 'belum'; // Belum absen masuk
                $schedule->attendance_time = null;
            }

            return $schedule;
        });

        $lowStockMenus = \App\Models\Menu::where('user_id', $userId)
            ->where('stock', '>', -1)
            ->where('stock', '<=', 10)
            ->orderBy('stock', 'asc')
            ->take(5)
            ->get();

        // --- [BARU] DATA UNTUK CHART 7 HARI & PAYMENT METHOD ---
        // 1. Trend Omset 7 Hari Terakhir
        $chartOmset7Hari = ['labels' => [], 'data' => []];

        $omset7Raw = [];
        for ($i = 6; $i >= 0; $i--) {
            $dateWib = $now->copy()->subDays($i);
            $startWib = $dateWib->copy()->startOfDay();
            $endWib = $dateWib->copy()->endOfDay();
            $startUtc = $startWib->timezone('UTC')->format('Y-m-d H:i:s');
            $endUtc = $endWib->timezone('UTC')->format('Y-m-d H:i:s');

            $dateString = $dateWib->format('Y-m-d');
            $omset7Raw[$dateString] = (float) $buildChartQuery($startUtc, $endUtc)->sum('total_amount');
        }

        for ($i = 6; $i >= 0; $i--) {
            $date = $now->copy()->subDays($i);
            $dateString = $date->format('Y-m-d');
            $chartOmset7Hari['labels'][] = $date->translatedFormat('d M');
            $chartOmset7Hari['data'][] = $omset7Raw[$dateString];
        }

        // 2. Distribusi Metode Pembayaran untuk Filter Tanggal yang Aktif
        $chartPaymentMethod = ['labels' => [], 'data' => []];
        
        // Sesuaikan dengan startUtc dan endUtc filter tanggal aktif (hari ini / custom date)
        if ($shiftFilter === 'all') {
            if ($filter === 'today' || $filter === 'custom') {
                $targetDate = ($filter === 'custom') ? $request->date : $now->format('Y-m-d');
                $startUtcFilter = \Carbon\Carbon::parse($targetDate, 'Asia/Jakarta')->startOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
                $endUtcFilter = \Carbon\Carbon::parse($targetDate, 'Asia/Jakarta')->endOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            } elseif ($filter === '7days') {
                $startUtcFilter = $now->copy()->subDays(7)->startOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
                $endUtcFilter = $now->copy()->endOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            } elseif ($filter === '30days') {
                $startUtcFilter = $now->copy()->subDays(30)->startOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
                $endUtcFilter = $now->copy()->endOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            } elseif ($filter === 'year') {
                $startUtcFilter = $now->copy()->startOfYear()->timezone('UTC')->format('Y-m-d H:i:s');
                $endUtcFilter = $now->copy()->endOfYear()->timezone('UTC')->format('Y-m-d H:i:s');
            } else {
                $startUtcFilter = null;
                $endUtcFilter = null;
            }
        } else {
            // Jika ada filter Shift aktif
            if (isset($shiftStartUtc) && isset($shiftEndUtc)) {
                $startUtcFilter = $shiftStartUtc;
                $endUtcFilter = $shiftEndUtc;
            } else {
                $startUtcFilter = null;
                $endUtcFilter = null;
            }
        }

        $paymentMethodRaw = \App\Models\Transaction::where('user_id', $userId)
            ->where('is_training', false)
            ->whereIn('status', \App\Models\Transaction::getActiveStatusesForWeb())
            ->when($outletFilter !== 'all', fn($q) => $q->where('outlet_id', $outletFilter))
            ->when($startUtcFilter && $endUtcFilter, fn($q) => $q->whereBetween('created_at_device', [$startUtcFilter, $endUtcFilter]))
            ->select(
                'payment_method',
                DB::raw('COUNT(*) as total_count')
            )
            ->groupBy('payment_method')
            ->get();

        foreach ($paymentMethodRaw as $pm) {
            $pmName = empty($pm->payment_method) ? __('backoffice.dashboard.charts.other_payment') : $pm->payment_method;
            $chartPaymentMethod['labels'][] = $pmName;
            $chartPaymentMethod['data'][] = (int)$pm->total_count;
        }

        // --- [BARU] INTERCEPT UNTUK AJAX ---
        if ($request->ajax() || $request->wantsJson()) {
            $overviewPrefix = __('backoffice.dashboard.overview');
            $receiptsUnit = __('backoffice.dashboard.metrics.receipts_unit');
            return response()->json([
                'title' => $overviewPrefix . ' ' . ($title ?? __('backoffice.dashboard.filters.today')),
                'todayOmset' => 'Rp ' . number_format($todayOmset, 0, ',', '.'),
                'todayCount' => $todayCount . ' <span class="text-xs md:text-lg font-bold text-blue-200">' . $receiptsUnit . '</span>',
                'cashSales' => 'Rp ' . number_format($cashSales ?? 0, 0, ',', '.'),
                'digitalSales' => 'Rp ' . number_format($digitalSales ?? 0, 0, ',', '.'),
                'totalPiutang' => 'Rp ' . number_format($totalPiutang ?? 0, 0, ',', '.'),
                'chartSubtitle' => $chartSubtitle,
                'chartLabels' => $chartLabels,
                'chartData' => $chartData,
                'chartOmset7Hari' => $chartOmset7Hari,
                'chartPaymentMethod' => $chartPaymentMethod,
            ]);
        }

        // [SHIFT] Ambil data shift aktif & history terakhir
        $activeShift = \App\Models\Shift::where('user_id', $userId)
            ->where('status', 'open')
            ->latest('start_time')
            ->first();
        $recentShifts = \App\Models\Shift::where('user_id', $userId)
            ->orderBy('start_time', 'desc')
            ->limit(10)
            ->get();

        return view('backoffice.dashboard', compact(
            'todayOmset',
            'todayCount',
            'cashSales',
            'digitalSales',
            'totalPiutang',
            'chartLabels',
            'chartData',
            'chartSubtitle',
            'todaySchedules',
            'lowStockMenus',
            'filter',
            'title',
            'now',
            'outlets',      // 🏢 [WAJIB ADA] Untuk melooping pilihan dropdown di Web
            'outletFilter', // 🏢 [WAJIB ADA] Untuk menandai cabang mana yang sedang aktif
            'chartOmset7Hari',
            'chartPaymentMethod',
            'activeShift',
            'recentShifts',
            'shifts',        // [BARU] Daftar shift untuk dropdown filter
            'shiftFilter',   // [BARU] Shift yang sedang dipilih
            'selectedShift'  // [BARU] Data shift terpilih (untuk info banner)
        ));
    }
}
