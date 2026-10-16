<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\Transaction;
use App\Helpers\TimezoneContract;

class ReportController extends Controller
{
    // 1. LAPORAN RINGKASAN (Dual Engine: Web & Android)
    public function index(Request $request)
    {
        $userId = Auth::id(); // [SaaS] Ambil ID User

        // Default range: month (Awal bulan ini s/d Hari ini)
        $range = $request->input('range', 'month');

        if ($range === 'today') {
            $startDate = Carbon::now()->format('Y-m-d');
            $endDate = Carbon::now()->format('Y-m-d');
        } elseif ($range === 'yesterday') {
            $startDate = Carbon::yesterday()->format('Y-m-d');
            $endDate = Carbon::yesterday()->format('Y-m-d');
        } elseif ($range === '7days') {
            $startDate = Carbon::now()->subDays(6)->format('Y-m-d');
            $endDate = Carbon::now()->format('Y-m-d');
        } elseif ($range === '30days') {
            $startDate = Carbon::now()->subDays(29)->format('Y-m-d');
            $endDate = Carbon::now()->format('Y-m-d');
        } elseif ($range === 'month') {
            $startDate = Carbon::now()->startOfMonth()->format('Y-m-d');
            $endDate = Carbon::now()->format('Y-m-d');
        } elseif ($range === 'lastmonth') {
            $startDate = Carbon::now()->subMonth()->startOfMonth()->format('Y-m-d');
            $endDate = Carbon::now()->subMonth()->endOfMonth()->format('Y-m-d');
        } elseif ($range === 'year') {
            $startDate = Carbon::now()->startOfYear()->format('Y-m-d');
            $endDate = Carbon::now()->format('Y-m-d');
        } elseif ($range === 'all') {
            $startDate = '2020-01-01';
            $endDate = '2099-12-31';
        } else {
            $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
            $endDate = $request->input('end_date', Carbon::now()->format('Y-m-d'));
            $range = 'custom';
        }

        $all_time_request = ($range === 'all');

        // 🆕 [SHIFT FILTER] Tangkap Filter Shift
        $shiftFilter = $request->input('shift_id', 'all');
        $selectedShift = null;
        if ($shiftFilter !== 'all' && $shiftFilter > 0) {
            $selectedShift = \App\Models\Shift::where('id', $shiftFilter)
                ->where('user_id', $userId)
                ->first();
            if ($selectedShift) {
                // Override date range berdasarkan waktu shift
                $startDate = Carbon::parse($selectedShift->start_time)->format('Y-m-d H:i:s');
                $endDate = $selectedShift->end_time
                    ? Carbon::parse($selectedShift->end_time)->format('Y-m-d H:i:s')
                    : now()->format('Y-m-d H:i:s');
            }
        }

        // 🆕 Ambil daftar shift untuk dropdown (30 terakhir)
        $shifts = \App\Models\Shift::where('user_id', $userId)
            ->orderBy('start_time', 'desc')
            ->limit(30)
            ->get();

        // 🏢 [BARU] Tangkap Filter Cabang
        $outletFilter = $request->input('outlet_id', 'all');

        // 🛡️ [FIX] Tangani Global Mode (0 dari Android atau 'all' dari Web)
        if ($outletFilter === 'all' || $outletFilter == 0) {
            $outletFilter = 'all';
        } else {
            $outletFilter = (int) $outletFilter;
            $outletValid = \App\Models\Outlet::where('id', $outletFilter)
                ->where('user_id', $userId)
                ->exists();
            if (!$outletValid) {
                abort(403, 'Akses ditolak: Outlet ini bukan milik akun Anda.');
            }
        }

        // 🛡️ [SECURITY] Mencegah Kasir melakukan Privilege Escalation dengan melihat semua laporan cabang
        $user = Auth::user();
        if ($user->role === 'kasir') {
            $outletFilter = (int) $user->outlet_id;
        }

        // 🏢 [BARU] Ambil daftar cabang untuk Dropdown di Web
        $outlets = \App\Models\Outlet::where('user_id', $userId)->get();

        // Phase 2: Resolve outlet timezone for business-date → UTC conversion
        // For multi-outlet "all" mode, use default Asia/Jakarta (conservative — all Indonesian tz are +7/+8/+9)
        $resolvedOutletTz = 'Asia/Jakarta';
        if ($outletFilter !== 'all') {
            $resolvedOutletTz = TimezoneContract::resolveOutletTimezone($outletFilter);
        }

        // Tentukan range filter (shift = datetime UTC, manual = business-date → UTC half-open range)
        if ($selectedShift) {
            // Shift times are already stored as UTC in DB — use directly
            $rangeStart = $startDate;
            $rangeEnd = $endDate;
            $useHalfOpen = false; // shift uses inclusive between
        } else {
            // Phase 2: Convert business dates to UTC half-open range
            [$rangeStart, $rangeEnd] = TimezoneContract::businessDateRangeToUtcRange($startDate, $endDate, $resolvedOutletTz);
            $useHalfOpen = true; // use >= start AND < nextDayStart
        }

        // Phase 2: Scope helper for half-open UTC range or shift inclusive range
        $applyDateRange = function ($query, $column = 'created_at_device') use ($rangeStart, $rangeEnd, $useHalfOpen) {
            if ($useHalfOpen) {
                return $query->where($column, '>=', $rangeStart)->where($column, '<', $rangeEnd);
            }
            return $query->whereBetween($column, [$rangeStart, $rangeEnd]);
        };

        // A. Total Penjualan Kotor (Gross Sales)
        $totalOmset = DB::table('transactions')
            ->where('user_id', $userId)
            ->when($outletFilter !== 'all', function ($q) use ($outletFilter) {
                return $q->where('outlet_id', $outletFilter); // 🆕 [HERMES FIX] outlet_id, bukan cabang_id
            })
            ->where(function ($q) use ($applyDateRange) { $applyDateRange($q); })
            ->whereNotIn('status', ['Batal', 'Refund', 'batal', 'refund', 'Canceled', 'Cancel', 'Void', 'batal ', 'refund '])
            ->sum(DB::raw(\App\Models\Transaction::getActualPaidSql()));

        // B. Total Transaksi (Jumlah Struk)
        $totalTransaksi = DB::table('transactions')
            ->where('user_id', $userId)
            ->when($outletFilter !== 'all', function ($q) use ($outletFilter) {
                return $q->where('outlet_id', $outletFilter); // 🆕 [HERMES FIX]
            })
            ->where(function ($q) use ($applyDateRange) { $applyDateRange($q); })
            ->where('status', '!=', 'Batal')
            ->count();

        // C. Total Pengeluaran (Expense)
        $expenseStart = $selectedShift ? Carbon::parse($selectedShift->start_time)->format('Y-m-d') : $startDate;
        $expenseEnd = $selectedShift ? Carbon::parse($selectedShift->end_time ?? now())->format('Y-m-d') : $endDate;
        $totalPengeluaran = DB::table('expenses')
            ->where('user_id', $userId)
            ->when($outletFilter !== 'all', function ($q) use ($outletFilter) {
                return $q->where('outlet_id', $outletFilter); // 🆕 [HERMES FIX]
            })
            ->whereBetween('date', [$expenseStart, $expenseEnd])
            ->sum('amount');

        // D. Hitung HPP (Murni dari Transaction Items - Anti Historical Distortion)
        $totalCOGS = DB::table('transaction_items as ti')
            ->join('transactions as t', 'ti.transaction_id', '=', 't.id')
            ->where('t.user_id', $userId)
            ->when($outletFilter !== 'all', function ($q) use ($outletFilter) {
                return $q->where('t.outlet_id', $outletFilter); // 🆕 [HERMES FIX]
            })
            ->where(function ($q) use ($applyDateRange) { $applyDateRange($q, 't.created_at_device'); })
            ->where('t.status', '!=', 'Batal')
            ->sum(DB::raw('ti.qty * ti.cost_price'));

        // E. Laba Bersih = Omset - HPP - Pengeluaran Operasional
        $labaBersih = $totalOmset - $totalCOGS - $totalPengeluaran;

        // ========================================================
        // [ENGINE 1] JIKA DIAKSES DARI APLIKASI ANDROID (API)
        // ========================================================
        if ($request->wantsJson() || $request->is('api/*')) {
            // Channel breakdown for API
            $channelBreakdownApi = DB::table('transactions')
                ->where('user_id', $userId)
                ->when($outletFilter !== 'all', function ($q) use ($outletFilter) {
                    return $q->where('outlet_id', $outletFilter);
                })
                ->where(function ($q) use ($applyDateRange) { $applyDateRange($q); })
                ->whereNotIn('status', ['Batal', 'Refund', 'batal', 'refund', 'Canceled', 'Cancel', 'Void', 'batal ', 'refund '])
                ->selectRaw('COALESCE(order_type, "Walk In") as channel, COUNT(*) as trx_count, SUM(' . \App\Models\Transaction::getActualPaidSql() . ') as total_revenue')
                ->groupBy('order_type')
                ->orderByRaw('total_revenue DESC')
                ->get()
                ->map(fn($r) => ['channel' => $r->channel, 'trx_count' => (int)$r->trx_count, 'total_revenue' => (float)$r->total_revenue]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'period'        => $all_time_request ? 'Semua Waktu' : "$startDate s/d $endDate",
                    'gross_sales'   => (float)$totalOmset,
                    'trx_count'     => (int)$totalTransaksi,
                    'total_cogs'    => (float)$totalCOGS,
                    'total_expense' => (float)$totalPengeluaran,
                    'net_profit'    => (float)$labaBersih,
                    'channels'      => $channelBreakdownApi
                ]
            ], 200);
        }

        // ========================================================
        // [ENGINE 2] JIKA DIAKSES DARI WEB BROWSER (DASHBOARD)
        // ========================================================

        // Ambil list transaksi untuk ditampilkan di tabel Pemasukan (Paginated 50/page, no unused eager-load)
        $transactions = Transaction::query()
            ->select([
                'id',
                'official_invoice_number',
                'created_at_device',
                'payment_method',
                'total_amount',
                'status',
                'user_id',
                'outlet_id',
            ])
            ->where('user_id', $userId)
            ->when($outletFilter !== 'all', function ($q) use ($outletFilter) {
                return $q->where('outlet_id', $outletFilter); // 🆕 [HERMES FIX]
            })
            ->where(function ($q) use ($applyDateRange) { $applyDateRange($q); })
            ->where('status', '!=', 'Batal')
            ->orderBy('created_at_device', 'desc')
            ->paginate(50)
            ->withQueryString();

        // Ambil list pengeluaran untuk ditampilkan di tabel Pengeluaran
        $expenses = DB::table('expenses')
            ->where('user_id', $userId)
            ->when($outletFilter !== 'all', function ($q) use ($outletFilter) {
                return $q->where('outlet_id', $outletFilter); // 🆕 [HERMES FIX]
            })
            ->whereBetween('date', [$expenseStart, $expenseEnd])
            ->orderBy('date', 'desc')
            ->get();

        // F. Breakdown per Order Type (Channel Reporting)
        $channelBreakdown = DB::table('transactions')
            ->where('user_id', $userId)
            ->when($outletFilter !== 'all', function ($q) use ($outletFilter) {
                return $q->where('outlet_id', $outletFilter);
            })
            ->where(function ($q) use ($applyDateRange) { $applyDateRange($q); })
            ->whereNotIn('status', ['Batal', 'Refund', 'batal', 'refund', 'Canceled', 'Cancel', 'Void', 'batal ', 'refund '])
            ->selectRaw('COALESCE(order_type, "Walk In") as channel, COUNT(*) as trx_count, SUM(' . \App\Models\Transaction::getActualPaidSql() . ') as total_revenue')
            ->groupBy('order_type')
            ->orderByRaw('total_revenue DESC')
            ->get();

        return view('backoffice.reports', compact(
            'startDate',
            'endDate',
            'totalOmset',
            'totalTransaksi',
            'totalPengeluaran',
            'totalCOGS',
            'labaBersih',
            'transactions',
            'expenses',
            'outlets',
            'outletFilter',
            'all_time_request',
            'shifts',
            'shiftFilter',
            'selectedShift',
            'channelBreakdown',
            'range'
        ));
    }

    /**
     * Export laporan ke PDF/CSV untuk Android + Web.
     * Route: GET /api/reports/export  (throttle:30,1)
     *
     * Query params:
     * - start_date, end_date (Y-m-d)
     * - format: pdf|csv (default csv)
     * - report_type: finance|inventory|audit|comprehensive (default comprehensive)
     * - outlet_id: all|0|int
     */
    public function export(Request $request)
    {
        // Mitigasi timeout untuk rentang data besar
        set_time_limit(0);

        $userId = Auth::id();
        $user = Auth::user();

        $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->input('end_date', Carbon::now()->format('Y-m-d'));
        $format = strtolower((string) $request->input('format', 'csv'));
        $reportType = strtolower((string) $request->input('report_type', 'comprehensive'));
        $outletFilter = $request->input('outlet_id', 'all');

        // Global mode (0 dari Android atau 'all' dari Web)
        if ($outletFilter === 'all' || $outletFilter == 0 || $outletFilter === '0' || $outletFilter === null || $outletFilter === '') {
            $outletFilter = 'all';
        } else {
            $outletFilter = (int) $outletFilter;
            $outletValid = \App\Models\Outlet::where('id', $outletFilter)
                ->where('user_id', $userId)
                ->exists();
            if (!$outletValid) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Akses ditolak: Outlet ini bukan milik akun Anda.',
                ], 403);
            }
        }

        // Kasir tidak boleh escalate ke semua cabang
        if ($user && $user->role === 'kasir') {
            $outletFilter = (int) $user->outlet_id;
        }

        $cancelStatuses = ['Batal', 'Refund', 'batal', 'refund', 'Canceled', 'Cancel', 'Void', 'batal ', 'refund '];

        // Phase 2: Resolve outlet timezone and compute UTC half-open range
        $exportOutletTz = 'Asia/Jakarta';
        if ($outletFilter !== 'all') {
            $exportOutletTz = TimezoneContract::resolveOutletTimezone($outletFilter);
        }
        [$rangeStart, $rangeEnd] = TimezoneContract::businessDateRangeToUtcRange($startDate, $endDate, $exportOutletTz);

        // Phase 2: Half-open range scope helper for export queries
        $applyExportDateRange = function ($query, $column = 'created_at_device') use ($rangeStart, $rangeEnd) {
            return $query->where($column, '>=', $rangeStart)->where($column, '<', $rangeEnd);
        };

        // Query transaksi utama (outlet_id, NOT cabang_id)
        $baseQuery = DB::table('transactions')
            ->where('user_id', $userId)
            ->when($outletFilter !== 'all', function ($q) use ($outletFilter) {
                return $q->where('outlet_id', $outletFilter);
            })
            ->where(function ($q) use ($applyExportDateRange) { $applyExportDateRange($q); })
            ->whereNotIn('status', $cancelStatuses)
            ->orderBy('created_at_device', 'asc');

        // ===========================================
        // PDF
        // ===========================================
        if ($format === 'pdf') {
            // [PHASE 3.1 SAFETY CAP] Cegah DomPDF memory exhaustion
            // Hitung dulu secara murah tanpa load dataset ke memory
            $totalPdfTransactions = (clone $baseQuery)->count();
            if ($totalPdfTransactions > 500) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Export PDF dibatasi maksimal 500 transaksi untuk menjaga stabilitas sistem. Silakan persempit rentang tanggal/filter cabang, atau gunakan format CSV untuk data lengkap.',
                    'total_records' => $totalPdfTransactions,
                    'max_allowed' => 500,
                ], 422);
            }

            // Ambil hanya kolom yang digunakan PDF dan perhitungannya
            $transactions = (clone $baseQuery)
                ->selectRaw('transactions.id, transactions.created_at_device, transactions.cashier_name, transactions.payment_method, transactions.total_amount, ' . Transaction::getActualPaidSql() . ' as actual_paid')
                ->get();

            // Ringkasan keuangan (actual_paid sudah dihitung di SQL)
            $totalOmset = $transactions->sum(function ($trx) {
                return (float) ($trx->actual_paid ?? 0);
            });
            $totalTransaksi = $transactions->count();

            // Pengeluaran
            $expenseDetails = DB::table('expenses')
                ->where('user_id', $userId)
                ->when($outletFilter !== 'all', function ($q) use ($outletFilter) {
                    return $q->where('outlet_id', $outletFilter);
                })
                ->whereBetween('date', [$startDate, $endDate])
                ->get();
            $totalPengeluaran = (float) $expenseDetails->sum('amount');

            $averageTransactionValue = $totalTransaksi > 0 ? $totalOmset / $totalTransaksi : 0;

            // Peak hours
            $peakHourStats = $transactions->groupBy(function ($item) {
                return Carbon::parse($item->created_at_device)->format('H');
            })->map(function ($group) {
                return $group->count();
            })->sortDesc();

            $peakHour = $peakHourStats->keys()->first();
            $peakHourCount = $peakHourStats->first();
            $peakHourString = $peakHour !== null
                ? $peakHour . ':00 - ' . str_pad((string) (((int) $peakHour + 1) % 24), 2, '0', STR_PAD_LEFT) . ':00 (' . $peakHourCount . ' Transaksi)'
                : 'Tidak ada transaksi';

            // HPP
            $totalCOGS = (float) DB::table('transaction_items as ti')
                ->join('transactions as t', 'ti.transaction_id', '=', 't.id')
                ->where('t.user_id', $userId)
                ->when($outletFilter !== 'all', function ($q) use ($outletFilter) {
                    return $q->where('t.outlet_id', $outletFilter);
                })
                ->where(function ($q) use ($applyExportDateRange) { $applyExportDateRange($q, 't.created_at_device'); })
                ->whereNotIn('t.status', $cancelStatuses)
                ->sum(DB::raw('ti.qty * ti.cost_price'));

            $labaBersih = $totalOmset - $totalCOGS - $totalPengeluaran;

            // Metode pembayaran
            $paymentStats = $transactions->groupBy(function ($trx) {
                return $trx->payment_method ?: 'Lainnya';
            })->map(function ($row) {
                return $row->sum(function ($trx) {
                    return (float) ($trx->actual_paid ?? 0);
                });
            });

            // Top 10 produk
            $topProducts = DB::table('transaction_items as ti')
                ->join('transactions as t', 'ti.transaction_id', '=', 't.id')
                ->select('ti.menu_name', DB::raw('SUM(ti.qty) as total_qty'), DB::raw('SUM(ti.qty * ti.price) as total_revenue'))
                ->where('t.user_id', $userId)
                ->when($outletFilter !== 'all', function ($q) use ($outletFilter) {
                    return $q->where('t.outlet_id', $outletFilter);
                })
                ->where(function ($q) use ($applyExportDateRange) { $applyExportDateRange($q, 't.created_at_device'); })
                ->whereNotIn('t.status', $cancelStatuses)
                ->groupBy('ti.menu_name')
                ->orderByDesc('total_qty')
                ->limit(10)
                ->get();

            // report_type reserved for future section filtering; comprehensive = full template
            // finance / inventory / audit currently still use full comprehensive layout
            // so Android always gets a valid multi-page PDF.
            unset($reportType); // silence unused until sectioned templates exist

            $fileName = 'Laporan_Kaching_' . $startDate . '-sd-' . $endDate . '.pdf';

            $pdf = app('dompdf.wrapper')->loadView('exports.pdf_report', compact(
                'transactions',
                'startDate',
                'endDate',
                'totalOmset',
                'totalTransaksi',
                'totalPengeluaran',
                'totalCOGS',
                'labaBersih',
                'paymentStats',
                'topProducts',
                'averageTransactionValue',
                'expenseDetails',
                'peakHourString'
            ))->setPaper('a4', 'portrait');

            // Binary PDF stream — Android Retrofit Streaming expects application/pdf body
            return $pdf->download($fileName);
        }

        // ===========================================
        // CSV
        // ===========================================
        $filename = 'Laporan_Kaching_' . ($outletFilter === 'all' ? 'all' : $outletFilter) . '_' . $startDate . '-sd-' . $endDate . '.csv';
        $headers = [
            'Content-type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename=' . $filename,
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        $callback = function () use ($baseQuery) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['No', 'Tanggal', 'Jam', 'ID Struk', 'Kasir', 'Meja', 'Metode Bayar', 'Total (Rp)', 'Dibayar (Rp)']);

            // CSV injection sanitizer
            $sanitize = function ($value) {
                if (is_string($value) && in_array(substr($value, 0, 1), ['=', '+', '-', '@'], true)) {
                    return "'" . $value;
                }
                return $value;
            };

            $no = 1;
            // cursor() = lazy, hemat RAM
            foreach ((clone $baseQuery)->selectRaw('transactions.*, ' . Transaction::getActualPaidSql() . ' as actual_paid')->cursor() as $row) {
                fputcsv($file, [
                    $no++,
                    Carbon::parse($row->created_at_device)->format('d-m-Y'),
                    Carbon::parse($row->created_at_device)->format('H:i'),
                    $sanitize('#' . $row->id),
                    $sanitize($row->cashier_name ?? 'Owner'),
                    $sanitize($row->table_number ?? 'Take Away'),
                    $sanitize($row->payment_method),
                    $row->total_amount,
                    $row->actual_paid ?? 0,
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    // 3. API UNTUK MENU INTELLIGENCE (BCG MATRIX + SAW)
    public function menuPerformance(Request $request)
    {
        $userId = Auth::id();
        $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->input('end_date', Carbon::now()->format('Y-m-d'));

        // 🆕 [HERMES FIX] Dukung all-time
        $all_time_request = $request->boolean('all_time') || $request->input('range') === 'all';
        if ($all_time_request) {
            $startDate = '2020-01-01';
            $endDate = '2099-12-31';
        }

        // Phase 2: Convert business dates to UTC half-open range
        // menuPerformance is always global (no outlet filter), use Asia/Jakarta default
        [$rangeStart, $rangeEnd] = TimezoneContract::businessDateRangeToUtcRange($startDate, $endDate, 'Asia/Jakarta');

        // Ambil performa tiap menu
        $menuStats = DB::table('transaction_items as ti')
            ->join('transactions as t', 'ti.transaction_id', '=', 't.id')
            ->select(
                'ti.menu_name',
                DB::raw('SUM(ti.qty) as total_qty'),
                DB::raw('SUM(ti.qty * ti.price) as total_revenue')
            )
            ->where('t.user_id', $userId)
            ->where('t.status', '!=', 'Batal')
            ->where('t.created_at_device', '>=', $rangeStart)
            ->where('t.created_at_device', '<', $rangeEnd)
            ->groupBy('ti.menu_name')
            ->get();

        // Hitung rata-rata untuk patokan BCG Matrix
        $avgQty = $menuStats->avg('total_qty');
        $avgRevenue = $menuStats->avg('total_revenue');

        $analysis = $menuStats->map(function ($item) use ($avgQty, $avgRevenue) {
            $category = 'DOG';
            $action = 'Kurang diminati. Pertimbangkan untuk hapus/ganti menu.';
            if ($item->total_qty >= $avgQty && $item->total_revenue >= $avgRevenue) {
                $category = 'STAR';
                $action = 'Pertahankan kualitas dan promosikan lebih masif!';
            } elseif ($item->total_qty >= $avgQty && $item->total_revenue < $avgRevenue) {
                $category = 'CASH_COW';
                $action = 'Laris tapi untung tipis. Coba naikkan harga perlahan.';
            } elseif ($item->total_qty < $avgQty && $item->total_revenue >= $avgRevenue) {
                $category = 'PUZZLE';
                $action = 'Untung besar tapi kurang laku. Perbaiki teknik marketing.';
            }
            return [
                'name' => $item->menu_name,
                'category' => $category,
                'stats' => "Terjual {$item->total_qty} porsi",
                'action' => $action,
                'score' => number_format($item->total_revenue / ($avgRevenue ?: 1), 2)
            ];
        });

        return response()->json(['status' => 'success', 'data' => $analysis]);
    }
}
