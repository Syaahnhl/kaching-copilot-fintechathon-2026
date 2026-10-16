<?php

namespace App\Http\Controllers; // [FIX] Kata \Api sudah dihapus!

use Illuminate\Http\Request;
use App\Models\Expense;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use App\Http\Resources\ExpenseResource;

class ExpenseController extends Controller
{
    // 1. SIMPAN PENGELUARAN (Milik User Login)
    public function store(Request $request)
    {
        try {
            // Validasi Data
            $validator = Validator::make($request->all(), [
                'name' => 'required|string',
                'amount' => 'required|numeric',
                'qty' => 'nullable|numeric|min:0.01',
                'unit_price' => 'nullable|numeric|min:0',
                'category' => 'required|string',
                'fund_source' => 'nullable|string|in:Laci Kasir,Transfer Owner,Lainnya',
                'receipt_image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
                'date' => 'required|date', // Format: "2024-01-01"
                'note' => 'nullable|string',
                'supplier' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Data tidak lengkap',
                    'errors' => $validator->errors()
                ], 422);
            }

            $imagePath = null;
            if ($request->hasFile('receipt_image')) {
                $file = $request->file('receipt_image');
                $filename = \Illuminate\Support\Str::uuid() . '.' . $file->getClientOriginalExtension();
                $imagePath = $file->storeAs('expenses', $filename, 'public');
            }

            $userId = Auth::id();

            // 🏢 [ISOLASI CABANG] Ambil dan tentukan outlet_id saat simpan
            $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));
            if (!$requestedOutlet || $requestedOutlet == 0) {
                $defaultOutlet = \Illuminate\Support\Facades\DB::table('outlets')->where('user_id', $userId)->orderBy('id', 'asc')->first();
                $requestedOutlet = $defaultOutlet ? $defaultOutlet->id : 0;
            }

            // [SaaS] Simpan Data dengan User ID + Outlet ID
            $expense = Expense::forceCreate([
                'user_id'       => $userId,
                'outlet_id'     => (int) $requestedOutlet, // 🏢 [BARU] Ikat ke Cabang
                'name'          => $request->name,
                'amount'        => $request->amount,
                'qty'           => $request->qty ?? 1.0,
                'unit_price'    => $request->unit_price ?? 0,
                'category'      => $request->category,
                'fund_source'   => $request->fund_source ?? 'Laci Kasir',
                'receipt_image' => $imagePath,
                'date'          => $request->date,
                'note'          => $request->note ?? null,
                'supplier'      => $request->supplier ?? null,
                'is_reimburse'  => $request->input('is_reimburse') === '1' ? true : false,
                'is_recurring'  => $request->input('is_recurring') === '1' ? true : false,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Pengeluaran berhasil dicatat!',
                'data' => new ExpenseResource($expense)
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

        // 2. LIHAT SEMUA PENGELUARAN (Hanya Milik Toko Sendiri)
    // [ARCH P0] Pagination + date window — bukan hard limit(100)
    public function index(Request $request)
    {
        $userId = Auth::id();
        // 🏢 [ISOLASI CABANG] Ambil Outlet-ID dari Header Android
        $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));

        $query = Expense::where('user_id', $userId);

        // Jika Kasir menyertakan Outlet-ID, filter per cabang. Jika 0/null = lihat semua cabang milik Owner.
        if ($requestedOutlet > 0) {
            $query->where('outlet_id', $requestedOutlet);
        }

        // Date window (default 365 hari; max 1095 ≈ 3 tahun)
        $days = $request->input('days', 365);
        $days = max(1, min((int) $days, 1095));
        $query->where('date', '>=', now()->subDays($days)->toDateString());

        // Cursor / updated_since (optional)
        if ($request->filled('updated_since')) {
            $query->where('updated_at', '>=', $request->input('updated_since'));
        }
        if ($request->filled('after_id')) {
            $query->where('id', '>', (int) $request->input('after_id'));
        }

        $perPage = (int) $request->input('per_page', $request->input('limit', 100));
        $perPage = max(1, min($perPage, 200));
        $page = max(1, (int) $request->input('page', 1));

        $paginator = $query
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'status' => 'success',
            'data' => ExpenseResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'days' => $days,
            ],
        ], 200);
    }

    public function update(Request $request, $id)
    {
        try {
            $userId = Auth::id();
            $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));

            // 🛡️ [MULTI-TENANT] Cari berdasarkan user_id (wajib) + outlet_id (jika ada)
            $query = Expense::where('id', $id)->where('user_id', $userId);
            if ($requestedOutlet > 0) {
                $query->where('outlet_id', $requestedOutlet);
            }
            $expense = $query->first();

            if (!$expense) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Data tidak ditemukan atau bukan milik Anda'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string',
                'amount' => 'sometimes|required|numeric',
                'qty' => 'nullable|numeric|min:0.01',
                'unit_price' => 'nullable|numeric|min:0',
                'category' => 'sometimes|required|string',
                'fund_source' => 'nullable|string|in:Laci Kasir,Transfer Owner,Lainnya',
                'photoFile' => 'nullable|image|mimes:jpeg,png,jpg|max:2048', // dari Android dikirim sebagai photoFile
                'date' => 'sometimes|required|date',
                'time' => 'nullable|string', // tambahan time jika dikirim terpisah
                'note' => 'nullable|string',
                'supplier' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Data tidak lengkap',
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($request->hasFile('photoFile')) {
                // Hapus gambar lama jika ada
                if ($expense->receipt_image && Storage::disk('public')->exists($expense->receipt_image)) {
                    Storage::disk('public')->delete($expense->receipt_image);
                }
                $file = $request->file('photoFile');
                $filename = \Illuminate\Support\Str::uuid() . '.' . $file->getClientOriginalExtension();
                $expense->receipt_image = $file->storeAs('expenses', $filename, 'public');
            } elseif ($request->hasFile('receipt_image')) {
                // Fallback untuk backward compatibility jika diperlukan
                if ($expense->receipt_image && Storage::disk('public')->exists($expense->receipt_image)) {
                    Storage::disk('public')->delete($expense->receipt_image);
                }
                $file = $request->file('receipt_image');
                $filename = \Illuminate\Support\Str::uuid() . '.' . $file->getClientOriginalExtension();
                $expense->receipt_image = $file->storeAs('expenses', $filename, 'public');
            }

            $expense->name = $request->name ?? $expense->name;
            $expense->amount = $request->amount ?? $expense->amount;
            if ($request->has('qty')) {
                $expense->qty = $request->qty;
            }
            if ($request->has('unit_price')) {
                $expense->unit_price = $request->unit_price;
            }
            $expense->category = $request->category ?? $expense->category;
            if ($request->has('fund_source')) {
                $expense->fund_source = $request->fund_source;
            }
            
            // Gabung date dan time jika time dikirim, atau gunakan date dari request
            $dateVal = $request->date ?? $expense->date;
            if ($request->has('time') && !empty($request->time)) {
                $dateOnly = date('Y-m-d', strtotime($dateVal));
                $expense->date = $dateOnly . ' ' . $request->time;
            } else {
                $expense->date = $dateVal;
            }

            if ($request->has('note')) {
                $expense->note = $request->note;
            }
            if ($request->has('supplier')) {
                $expense->supplier = $request->supplier;
            }
            if ($request->has('is_reimburse')) {
                $expense->is_reimburse = filter_var($request->input('is_reimburse'), FILTER_VALIDATE_BOOLEAN);
            }
            if ($request->has('is_recurring')) {
                $expense->is_recurring = filter_var($request->input('is_recurring'), FILTER_VALIDATE_BOOLEAN);
            }

            $expense->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Pengeluaran berhasil diupdate!',
                'data' => new ExpenseResource($expense)
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // 3. HAPUS PENGELUARAN (KHUSUS API ANDROID)
    public function destroy(Request $request, $id)
    {
        try {
            $userId = Auth::id();
            $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));

            // 🛡️ [MULTI-TENANT] Filter user_id wajib + outlet_id opsional
            $query = Expense::where('id', $id)->where('user_id', $userId);
            if ($requestedOutlet > 0) {
                $query->where('outlet_id', $requestedOutlet);
            }
            $expense = $query->first();

            if (!$expense) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Data tidak ditemukan atau bukan milik Anda'
                ], 404);
            }

            // Hapus gambar jika ada
            if ($expense->receipt_image && Storage::disk('public')->exists($expense->receipt_image)) {
                Storage::disk('public')->delete($expense->receipt_image);
            }

            // Bunuh zombienya!
            $expense->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Data pengeluaran berhasil dihapus permanen!'
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // ==============================================================
    // FUNGSI KHUSUS WEB BACKOFFICE (DASHBOARD ADMIN)
    // ==============================================================

    public function webIndex()
    {
        $expenses = Expense::where('user_id', Auth::id())
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('backoffice.expenses', compact('expenses'));
    }

    public function webStore(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'amount'   => 'required|numeric|min:1',
            'category' => 'required|string',
            'date'     => 'required|date',
            'note'     => 'nullable|string',
        ]);

        Expense::forceCreate([
            'user_id'  => Auth::id(),
            'name'     => $request->name,
            'amount'   => $request->amount,
            'category' => $request->category,
            'date'     => $request->date,
            'note'     => $request->note,
        ]);

        return back()->with('success', 'Catatan pengeluaran berhasil ditambahkan! 💸');
    }

    public function webDestroy($id)
    {
        $expense = Expense::where('id', $id)->where('user_id', Auth::id())->firstOrFail();

        if ($expense->receipt_image && Storage::disk('public')->exists($expense->receipt_image)) {
            Storage::disk('public')->delete($expense->receipt_image);
        }

        $expense->delete();

        return back()->with('success', 'Data pengeluaran berhasil dihapus!');
    }
}
