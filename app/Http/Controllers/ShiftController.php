<?php

namespace App\Http\Controllers; // [FIX] Kata \Api sudah dihapus!

use Illuminate\Http\Request;
use App\Models\Shift;       // [FIX] Gunakan Model
use App\Models\Transaction; // [FIX] Gunakan Model
use App\Models\Outlet;
use Illuminate\Support\Facades\Auth; // [FIX] Tambah Auth
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ShiftController extends Controller
{
    /**
     * Resolve canonical outlet ID for shift operations.
     */
    protected function resolveCanonicalOutlet(Request $request, $userId)
    {
        $rawOutlet = (int) $request->header('Outlet-ID', $request->input('outlet_id', 0));
        if ($rawOutlet > 0) {
            $outletExists = DB::table('outlets')->where('id', $rawOutlet)->where('user_id', $userId)->exists();
            if ($outletExists) {
                return $rawOutlet;
            }
            // Fail-closed if outlet provided does not belong to this user
            return false;
        }

        $user = Auth::user();
        if ($user && $user->role === 'kasir' && $user->outlet_id) {
            return (int) $user->outlet_id;
        }

        $firstOutlet = DB::table('outlets')->where('user_id', $userId)->orderBy('id', 'asc')->value('id');
        return $firstOutlet ? (int) $firstOutlet : null;
    }

    // 1. BUKA SHIFT (OPEN)
    // 1. BUKA SHIFT (OPEN) - [FIXED VERSION]
    public function openShift(Request $request)
    {
        // 1. Validasi Input
        $validator = Validator::make($request->all(), [
            'cashier_name' => 'required',
            'start_cash' => 'required|numeric',
            'cashier_type' => 'required|in:tunai,non_cash,mixed',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        // [FIX 1] Gunakan Fallback ID 1 jika token gagal terbaca (Supaya tidak Crash 500)
        $userId = Auth::id() ?? 1;

        $outletId = $this->resolveCanonicalOutlet($request, $userId);
        if ($outletId === false) {
            return response()->json([
                'status' => 'error',
                'message' => 'Outlet tidak valid atau tidak terdaftar untuk pengguna ini.'
            ], 403);
        }

        // [SaaS] Cek Double Shift Scoped per User & Outlet
        $activeShiftQuery = Shift::where('user_id', $userId)
            ->where('status', 'open');

        if ($outletId) {
            $activeShiftQuery->where('outlet_id', $outletId);
        }

        $activeShift = $activeShiftQuery->first();

        if ($activeShift) {
            return response()->json([
                'status' => 'error',
                'message' => 'Anda masih memiliki shift yang belum ditutup!',
                'data' => $activeShift
            ], 400); // Error 400 akan ditangkap Android sebagai failure
        }

        // [FIX 2] Pastikan Format Tanggal Benar (Carbon)
        // Phase 2: Parse shift start time as UTC instant
        if ($request->start_time) {
            // If ISO-8601 with offset/Z, Carbon::parse respects the timezone in the string
            // If naive wall-clock, assume outlet/Jakarta timezone then convert to UTC
            $startTime = Carbon::parse($request->start_time)->timezone('UTC');
        } else {
            $startTime = now();
        }

        try {
            // Buat Shift Baru
            $shift = Shift::forceCreate([
                'user_id' => $userId,
                'outlet_id' => $outletId,
                'app_uuid' => $request->app_uuid ?? 'manual-' . time(),
                'cashier_name' => $request->cashier_name,
                'staff_id' => $request->staff_id ?? null,
                'cashier_type' => $request->cashier_type,
                'start_cash' => $request->start_cash,
                'start_time' => $startTime, // Gunakan variabel Carbon
                'status' => 'open',
                'end_cash' => 0
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Shift berhasil dibuka',
                'data' => $shift
            ]);
        } catch (\Exception $e) {
            // [DEBUG] Tangkap error SQL dan kirim ke Android biar tau salahnya dimana
            return response()->json([
                'status' => 'error',
                'message' => 'Server Error: ' . $e->getMessage()
            ], 500);
        }
    }

    // 2. TUTUP SHIFT (CLOSE) - Versi Server Side Calculation
    public function closeShift(Request $request)
    {
        $request->validate([
            'shift_id' => 'required',
            'end_cash' => 'required|numeric',
            'end_time' => 'required'
        ]);

        $userId = Auth::id();

        // Cari Shift Milik User Ini
        $shiftQuery = Shift::where('id', $request->shift_id)
            ->where('user_id', $userId); // [SaaS]

        $rawOutlet = (int) $request->header('Outlet-ID', $request->input('outlet_id', 0));
        if ($rawOutlet > 0) {
            $shiftQuery->where('outlet_id', $rawOutlet);
        }

        $shift = $shiftQuery->first();

        if (!$shift || $shift->status == 'closed') {
            return response()->json(['message' => 'Shift tidak ditemukan atau sudah ditutup'], 404);
        }

        // Phase 2: Compute UTC instants for shift boundaries
        // created_at_device is stored as UTC in DB.
        // Shift boundaries MUST be compared as UTC instants, NOT local wall-clock strings.
        // This preserves shift integrity across midnight boundaries and all outlet timezones.
        $startUtc = Carbon::parse($shift->start_time)->timezone('UTC')->format('Y-m-d H:i:s');
        $endUtc = Carbon::parse($request->end_time)->timezone('UTC')->format('Y-m-d H:i:s');
        
        $totalCashSalesQuery = Transaction::where('user_id', $userId)
            ->where('cashier_name', $shift->cashier_name)
            ->where('created_at_device', '>=', $startUtc)
            ->where('created_at_device', '<=', $endUtc);

        if ($shift->outlet_id) {
            $totalCashSalesQuery->where('outlet_id', $shift->outlet_id);
        } elseif ($rawOutlet > 0) {
            $totalCashSalesQuery->where('outlet_id', $rawOutlet);
        }

        $totalCashSales = $totalCashSalesQuery
            ->where(function ($q) {
                $q->whereRaw('LOWER(TRIM(payment_method)) IN (?, ?, ?, ?, ?)', [
                    'tunai',
                    'cash',
                    'tunai / cash',
                    'tunai/cash',
                    'kasir',
                ])
                // residual pure-cash alias containing tunai/cash but NOT digital/split tokens
                ->orWhere(function ($q2) {
                    $q2->where(function ($q3) {
                        $q3->where('payment_method', 'LIKE', '%Tunai%')
                            ->orWhere('payment_method', 'LIKE', '%Cash%')
                            ->orWhere('payment_method', 'LIKE', '%cash%');
                    })
                    ->where('payment_method', 'NOT LIKE', '%QRIS%')
                    ->where('payment_method', 'NOT LIKE', '%Transfer%')
                    ->where('payment_method', 'NOT LIKE', '%Midtrans%')
                    ->where('payment_method', 'NOT LIKE', '%Online%')
                    ->where('payment_method', 'NOT LIKE', '%Ojol%')
                    ->where('payment_method', 'NOT LIKE', '%Bayar Nanti%')
                    ->where('payment_method', 'NOT LIKE', '%Hutang%')
                    ->where('payment_method', 'NOT LIKE', '%+%'); // split e.g. "QRIS + Tunai"
                });
            })
            ->whereNotIn('status', ['Batal', 'Refund', 'batal', 'refund'])
            ->sum('total_amount');

        $expectedCash = $shift->start_cash + $totalCashSales;
        $actualCash = $request->end_cash;
        $difference = $actualCash - $expectedCash;

        $shift->update([
            'end_time' => $request->end_time,
            'end_cash' => $actualCash,
            'total_cash_sales' => $totalCashSales,
            'expected_cash' => $expectedCash,
            'difference' => $difference,
            'status' => 'closed'
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Shift berhasil ditutup',
            'data' => $shift
        ]);
    }

    // 3. CEK STATUS SHIFT (Untuk Sinkronisasi Awal)
    public function checkShift(Request $request)
    {
        $userId = Auth::id() ?? 1;
        $rawOutlet = (int) $request->header('Outlet-ID', $request->input('outlet_id', 0));

        $query = Shift::where('user_id', $userId)
            ->where('status', 'open');

        if ($rawOutlet > 0) {
            $query->where('outlet_id', $rawOutlet);
        } elseif ($user = Auth::user()) {
            if ($user->role === 'kasir' && $user->outlet_id) {
                $query->where('outlet_id', (int)$user->outlet_id);
            }
        }

        $shift = $query->latest('start_time')->first();

        if ($shift) {
            // Jika ada shift terbuka (misal dibuka oleh HP A), 
            // HP B akan menerima data ini dan otomatis ikut 'Open'
            // Include cashier_type untuk mode konsistensi
            return response()->json([
                'status' => 'open',
                'data' => $shift,
                'cashier_type' => $shift->cashier_type
            ]);
        } else {
            return response()->json([
                'status' => 'closed',
                'data' => null,
                'cashier_type' => null
            ]);
        }
    }

    // 4. UPLOAD REPORT (Sinkronisasi Laporan Lengkap dari Android)
    public function uploadReport(Request $request)
    {
        // 1. Validasi
        $validator = Validator::make($request->all(), [
            'app_uuid' => 'required',
            'cashier_name' => 'required',
            'staff_id' => 'nullable|integer',
            'actual_cash' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        try {
            $userId = Auth::id();

            $outletId = $this->resolveCanonicalOutlet($request, $userId);
            if ($outletId === false) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Outlet tidak valid atau tidak terdaftar untuk pengguna ini.'
                ], 403);
            }

            // 2. Cek Duplikat (User ID + UUID)
            // Mencegah laporan ganda kalau tombol ditekan 2x
            $existingShift = Shift::where('app_uuid', $request->app_uuid)
                ->where('user_id', $userId) // [SaaS]
                ->first();

            if ($existingShift) {
                return response()->json(['message' => 'Data sudah ada (Synced)'], 200);
            }

            // 3. Simpan Laporan Baru (Phase 2: Parse to UTC instants)
            $parsedEndTime = $request->date ? Carbon::parse($request->date)->timezone('UTC') : now();
            $parsedStartTime = $request->start_time ? Carbon::parse($request->start_time)->timezone('UTC') : $parsedEndTime;

            $shift = Shift::forceCreate([
                'user_id' => $userId, // [SaaS] Wajib ada
                'outlet_id' => $outletId,
                'app_uuid' => $request->app_uuid,
                'cashier_name' => $request->cashier_name,
                'staff_id' => $request->staff_id ?? null,
                'cashier_type' => $request->cashier_type ?? 'tunai',

                'end_time' => $parsedEndTime,
                'start_time' => $parsedStartTime,

                'start_cash' => $request->has('start_cash') ? $request->start_cash : $request->capital_in,
                'end_cash' => $request->actual_cash,

                'total_cash_sales' => $request->cash_sales,
                'total_expense' => $request->total_expense ?? 0,
                'cash_drop' => $request->cash_drop ?? 0,

                'expected_cash' => $request->expected_cash,
                'difference' => ($request->actual_cash - $request->expected_cash),

                'status' => 'closed',
                'payment_details' => is_array($request->payment_breakdown) ? json_encode($request->payment_breakdown) : $request->payment_breakdown
            ]); // [FIX] Penutup fungsi create (kurung siku array + kurung biasa fungsi + titik koma)

            // ========================================================
            // [BARU] RESET SEMUA MEJA OUTLET INI JADI KOSONG
            // ========================================================
            // Supaya saat sinkronisasi ulang, HP tidak menarik data "Occupied" lagi.
            $tableQuery = DB::table('tables')->where('user_id', $userId);
            if ($outletId) {
                $tableQuery->where('outlet_id', $outletId);
            }
            $tableQuery->update([
                'is_occupied' => 0
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Laporan tersimpan dan Meja berhasil di-reset',
                'data' => $shift
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // 5. HISTORY SHIFT
    public function getHistory(Request $request)
    {
        $rawOutlet = (int) $request->header('Outlet-ID', $request->input('outlet_id', 0));

        // [SaaS] Hanya ambil data milik user ini
        $query = Shift::where('user_id', Auth::id())
            ->where('status', 'closed');

        if ($rawOutlet > 0) {
            $query->where('outlet_id', $rawOutlet);
        }

        $shifts = $query->orderBy('created_at', 'desc')
            ->limit(30)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $shifts
        ]);
    }

    /**
     * Web Command Center: Daftar Riwayat Shift & Rekonsiliasi Kas
     */
    public function webIndex(Request $request)
    {
        $userId = Auth::id();
        $user = Auth::user();

        $outletFilter = $request->query('outlet_id', 'all');
        $range = $request->query('range', 'all');
        $statusFilter = $request->query('status', 'all');

        // Tenant outlet options
        $outlets = Outlet::where('user_id', $userId)->get();

        // Enforce cashier outlet locking
        if ($user && $user->role === 'kasir') {
            $outletFilter = (string) ($user->outlet_id ?? 'all');
        } elseif ($outletFilter !== 'all' && $outletFilter != 0) {
            $outletFilterInt = (int) $outletFilter;
            $outletValid = Outlet::where('id', $outletFilterInt)
                ->where('user_id', $userId)
                ->exists();
            if (!$outletValid) {
                abort(403, 'Akses ditolak: Cabang bukan milik akun Anda.');
            }
        }

        $baseQuery = Shift::where('user_id', $userId);

        if ($outletFilter !== 'all' && $outletFilter != 0) {
            $baseQuery->where('outlet_id', (int) $outletFilter);
        }

        // Search
        if ($request->filled('search')) {
            $search = trim($request->search);
            $baseQuery->where(function ($q) use ($search) {
                $q->where('cashier_name', 'like', '%' . $search . '%')
                    ->orWhere('app_uuid', 'like', '%' . $search . '%');
                if (is_numeric($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        // Date Range (Asia/Jakarta -> UTC boundary)
        $now = Carbon::now('Asia/Jakarta');
        $dateTitle = 'Semua Waktu';

        if ($range === 'today') {
            $startUtc = $now->copy()->startOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            $endUtc = $now->copy()->endOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            $baseQuery->whereBetween('start_time', [$startUtc, $endUtc]);
            $dateTitle = 'Hari Ini';
        } elseif ($range === 'yesterday') {
            $yesterday = $now->copy()->subDay();
            $startUtc = $yesterday->copy()->startOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            $endUtc = $yesterday->copy()->endOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            $baseQuery->whereBetween('start_time', [$startUtc, $endUtc]);
            $dateTitle = 'Kemarin';
        } elseif ($range === '7days') {
            $startUtc = $now->copy()->subDays(7)->startOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            $baseQuery->where('start_time', '>=', $startUtc);
            $dateTitle = '7 Hari Terakhir';
        } elseif ($range === '30days') {
            $startUtc = $now->copy()->subDays(30)->startOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            $baseQuery->where('start_time', '>=', $startUtc);
            $dateTitle = '30 Hari Terakhir';
        } elseif ($range === 'month') {
            $startUtc = $now->copy()->startOfMonth()->timezone('UTC')->format('Y-m-d H:i:s');
            $endUtc = $now->copy()->endOfMonth()->timezone('UTC')->format('Y-m-d H:i:s');
            $baseQuery->whereBetween('start_time', [$startUtc, $endUtc]);
            $dateTitle = 'Bulan Ini (' . $now->translatedFormat('F Y') . ')';
        } elseif ($range === 'lastmonth') {
            $last = $now->copy()->subMonth();
            $startUtc = $last->copy()->startOfMonth()->timezone('UTC')->format('Y-m-d H:i:s');
            $endUtc = $last->copy()->endOfMonth()->timezone('UTC')->format('Y-m-d H:i:s');
            $baseQuery->whereBetween('start_time', [$startUtc, $endUtc]);
            $dateTitle = 'Bulan Lalu (' . $last->translatedFormat('F Y') . ')';
        } elseif ($range === 'custom' && $request->filled('start_date') && $request->filled('end_date')) {
            $startUtc = Carbon::parse($request->start_date, 'Asia/Jakarta')->startOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            $endUtc = Carbon::parse($request->end_date, 'Asia/Jakarta')->endOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            $baseQuery->whereBetween('start_time', [$startUtc, $endUtc]);
            $dateTitle = Carbon::parse($request->start_date)->translatedFormat('d M Y') . ' — ' . Carbon::parse($request->end_date)->translatedFormat('d M Y');
        }

        // Status Filter
        if ($statusFilter === 'open') {
            $baseQuery->where('status', 'open');
        } elseif ($statusFilter === 'closed') {
            $baseQuery->where('status', 'closed');
        } elseif ($statusFilter === 'seimbang') {
            $baseQuery->where('status', 'closed')->where('difference', 0);
        } elseif ($statusFilter === 'selisih') {
            $baseQuery->where('status', 'closed')->where('difference', '!=', 0);
        } elseif ($statusFilter === 'lebih') {
            $baseQuery->where('status', 'closed')->where('difference', '>', 0);
        } elseif ($statusFilter === 'kurang') {
            $baseQuery->where('status', 'closed')->where('difference', '<', 0);
        }

        // Aggregates for KPI Header (from unpaginated clone)
        $aggQuery = clone $baseQuery;
        $kpiData = $aggQuery->selectRaw("
            COUNT(*) as total_shifts,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as active_shifts,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_shifts,
            SUM(CASE WHEN status = 'closed' AND difference = 0 THEN 1 ELSE 0 END) as balanced_shifts,
            SUM(CASE WHEN status = 'closed' AND difference != 0 THEN 1 ELSE 0 END) as discrepant_shifts,
            SUM(CASE WHEN status = 'closed' THEN expected_cash ELSE 0 END) as total_expected_cash,
            SUM(CASE WHEN status = 'closed' THEN end_cash ELSE 0 END) as total_actual_cash,
            SUM(CASE WHEN status = 'closed' THEN difference ELSE 0 END) as total_net_variance,
            SUM(CASE WHEN status = 'closed' THEN ABS(difference) ELSE 0 END) as total_abs_variance
        ")->first();

        $shifts = $baseQuery->with(['outlet', 'staff'])
            ->orderBy('start_time', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(15)
            ->withQueryString();

        return view('backoffice.shifts.index', compact(
            'shifts',
            'outlets',
            'outletFilter',
            'range',
            'statusFilter',
            'dateTitle',
            'kpiData'
        ));
    }

    /**
     * Web Command Center: Detail Rekonsiliasi Shift
     */
    public function webShow(Request $request, $id)
    {
        $userId = Auth::id();
        $user = Auth::user();

        $shift = Shift::with(['outlet', 'staff', 'transactions' => function ($q) {
            $q->orderBy('created_at_device', 'desc')->limit(50);
        }])
            ->where('user_id', $userId)
            ->findOrFail($id);

        if ($user && $user->role === 'kasir' && $user->outlet_id && $shift->outlet_id != $user->outlet_id) {
            abort(403, 'Akses ditolak: Shift ini berada di luar cabang Anda.');
        }

        $paymentBreakdown = [];
        if (!empty($shift->payment_details)) {
            $decoded = is_array($shift->payment_details) ? $shift->payment_details : json_decode($shift->payment_details, true);
            if (is_array($decoded)) {
                $paymentBreakdown = $decoded;
            }
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'status' => 'success',
                'shift' => $shift,
                'payment_breakdown' => $paymentBreakdown,
            ]);
        }

        return view('backoffice.shifts.show', compact('shift', 'paymentBreakdown'));
    }
}
