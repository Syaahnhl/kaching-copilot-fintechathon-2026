<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CashFlow; // [FIX] Gunakan Model CashFlow
use Illuminate\Support\Facades\Auth; // [FIX] Tambah Auth untuk SaaS
use Illuminate\Support\Facades\Validator;

class CashFlowController extends Controller
{
    // 1. SIMPAN DATA DARI ANDROID
    public function store(Request $request)
    {
        try {
            // Validasi data
            $validator = Validator::make($request->all(), [
                'type' => 'required|in:IN,OUT',    // Hanya boleh IN atau OUT
                'amount' => 'required|numeric',
                'description' => 'required|string',
                'date' => 'required|date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            // [SaaS] Simpan Data dengan User ID
            // Pastikan 'user_id' sudah ada di $fillable Model CashFlow
            $cashFlow = CashFlow::forceCreate([
                'user_id' => Auth::id(), // <--- KUNCI SAAS (Milik User Login)
                'type' => $request->type,
                'amount' => $request->amount,
                'description' => $request->description,
                'operator' => $request->input('operator', 'Kasir'),
                'date' => $request->date,
            ]);

            if ($request->wantsJson()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Data Kas berhasil disimpan!',
                    'data' => $cashFlow
                ], 201);
            }

            return redirect()->route('cash_flows.index')->with('success', 'Catatan kas berhasil disimpan!');
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // 2. LIHAT DATA (Hanya Milik Toko Sendiri)
    public function index()
    {
        // [SaaS] Filter where('user_id', Auth::id())
        $data = CashFlow::where('user_id', Auth::id())
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(100) // Batasi 100 transaksi terakhir biar ringan
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $data
        ], 200);
    }

    public function webIndex(Request $request)
    {
        $userId = Auth::id();

        $now = \Carbon\Carbon::now('Asia/Jakarta');
        $filter = $request->query('filter', 'today'); // default today

        if ($filter === 'custom' && $request->has('start_date') && $request->has('end_date')) {
            $startDate = \Carbon\Carbon::parse($request->start_date, 'Asia/Jakarta')->startOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            $endDate = \Carbon\Carbon::parse($request->end_date, 'Asia/Jakarta')->endOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            $titleText =  \Carbon\Carbon::parse($request->start_date)->translatedFormat('d F Y') . ' - ' . \Carbon\Carbon::parse($request->end_date)->translatedFormat('d F Y');
        } elseif ($filter === '7days') {
            $startDate = $now->copy()->subDays(6)->startOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            $endDate = $now->copy()->endOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            $titleText = "7 Hari Terakhir";
        } elseif ($filter === '30days') {
            $startDate = $now->copy()->subDays(29)->startOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            $endDate = $now->copy()->endOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            $titleText = "30 Hari Terakhir";
        } elseif ($filter === 'all') {
            $startDate = '2020-01-01 00:00:00';
            $endDate = '2099-12-31 23:59:59';
            $titleText = "Semua Waktu";
        } else {
            // today
            $filter = 'today';
            $startDate = $now->copy()->startOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            $endDate = $now->copy()->endOfDay()->timezone('UTC')->format('Y-m-d H:i:s');
            $titleText = "Hari Ini";
        }

        $combined = collect();

        // 1. Ambil Cash Flows Manual
        $cfs = CashFlow::where('user_id', $userId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get()->map(function ($c) {
                return (object)[
                    'id' => 'CF-' . $c->id,
                    'date' => \Carbon\Carbon::parse($c->created_at)->timezone('Asia/Jakarta'),
                    'type' => $c->type,
                    'title' => $c->description,
                    'subtitle' => 'Tipe Kas: Manual',
                    'operator' => $c->operator ?? 'Kasir',
                    'amount' => $c->amount,
                ];
            });
        $combined = $combined->merge($cfs);

        // 2. Ambil Penjualan Tunai
        $trx = \App\Models\Transaction::where('user_id', $userId)
            ->where('status', '!=', 'Batal')
            ->where('payment_method', 'Tunai')
            ->whereBetween('created_at_device', [$startDate, $endDate])
            ->get()->map(function ($t) {
                return (object)[
                    'id' => 'TRX-' . $t->id,
                    'date' => \Carbon\Carbon::parse($t->created_at_device ?? $t->created_at)->timezone('Asia/Jakarta'),
                    'type' => 'IN',
                    'title' => 'Penjualan Tunai #' . $t->id,
                    'subtitle' => $t->order_type . ($t->table_number ? ' • Meja ' . $t->table_number : ''),
                    'operator' => $t->cashier_name ?? 'Sistem',
                    'amount' => $t->total_amount, // Nilai pemasukan kas
                ];
            });
        $combined = $combined->merge($trx);

        // 3. Ambil Modal Awal Shift
        $shifts = \App\Models\Shift::where('user_id', $userId)
            ->where('start_cash', '>', 0)
            ->whereBetween('start_time', [$startDate, $endDate])
            ->get()->map(function ($s) {
                return (object)[
                    'id' => 'SHF-' . $s->id,
                    'date' => \Carbon\Carbon::parse($s->start_time ?? $s->created_at)->timezone('Asia/Jakarta'),
                    'type' => 'IN',
                    'title' => 'Modal Awal Shift',
                    'subtitle' => 'Kasir: ' . ($s->cashier_name ?? 'Sistem'),
                    'operator' => $s->cashier_name ?? 'Kasir',
                    'amount' => $s->start_cash,
                ];
            });
        $combined = $combined->merge($shifts);

        // 4. Ambil Expenses (Pengeluaran)
        $expenses = \App\Models\Expense::where('user_id', $userId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get()->map(function ($e) {
                return (object)[
                    'id' => 'EXP-' . $e->id,
                    'date' => \Carbon\Carbon::parse($e->created_at)->timezone('Asia/Jakarta'),
                    'type' => 'OUT',
                    'title' => 'Pengeluaran: ' . $e->name,
                    'subtitle' => 'Kategori: ' . ($e->category ?? 'Lainnya'),
                    'operator' => 'Sistem',
                    'amount' => $e->amount,
                ];
            });
        $combined = $combined->merge($expenses);

        // Urutkan berdasarkan tanggal terbaru ke terlama
        $collection = $combined->sortByDesc('date');

        // Kalkulasi Total yang Akurat Berdasarkan Semua Arus Uang
        $totalIn = $collection->where('type', 'IN')->sum('amount');
        $totalOut = $collection->where('type', 'OUT')->sum('amount');
        $balance = $totalIn - $totalOut;
        $saldoAkhir = $balance;

        // Custom Pagination
        $perPage = 15;
        $currentPage = \Illuminate\Pagination\Paginator::resolveCurrentPage();
        $currentPageItems = $collection->slice(($currentPage - 1) * $perPage, $perPage)->values();
        $cashFlows = new \Illuminate\Pagination\LengthAwarePaginator(
            $currentPageItems,
            $collection->count(),
            $perPage,
            $currentPage,
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]
        );
        $cashFlows->appends($request->all()); // Agar pagination tidak menghilangkan parameter filter

        return view('backoffice.cashflow', compact('cashFlows', 'totalIn', 'totalOut', 'balance', 'saldoAkhir', 'filter', 'titleText'));
    }
}
