<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Staff; // 🏢 [WAJIB ADA]
use App\Models\Outlet; // 🏢 [WAJIB ADA]

class StaffController extends Controller
{
    public function index()
    {
        $userId = Auth::id();

        // 🏢 [BARU] Ambil data Cabang & gabungkan tabel (Join) agar nama cabang muncul di kartu karyawan
        $staffs = Staff::query()
            ->leftJoin('outlets', 'staff.outlet_id', '=', 'outlets.id')
            ->where('staff.user_id', $userId)
            ->select('staff.*', 'outlets.name as outlet_name') // Ambil nama cabang
            ->orderBy('staff.created_at', 'desc')
            ->get();

        // 🏢 [BARU] Ambil list cabang untuk mengisi Dropdown di form
        $outlets = Outlet::where('user_id', $userId)->get();

        return view('backoffice.staff', compact('staffs', 'outlets'));
    }

    // ==========================================================
    // FUNGSI KHUSUS UNTUK DIBACA OLEH APLIKASI ANDROID (API)
    // ==========================================================
    public function apiIndex(Request $request)
    {
        $userId = Auth::id();
        $query = Staff::where('user_id', $userId)
            ->where('is_active', true);

        // Multi-Outlet isolation: filter by outlet_id if provided
        if ($request->has('outlet_id') && !empty($request->outlet_id)) {
            $outletId = (int) $request->outlet_id;
            
            // Authorization guard: outlet must belong to the authenticated user/tenant
            $outletExists = Outlet::where('id', $outletId)
                ->where('user_id', $userId)
                ->exists();

            if (!$outletExists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Outlet tidak valid atau tidak memiliki akses.',
                ], 403);
            }

            $query->where('outlet_id', $outletId);
        }

        $staffs = $query->orderBy('created_at', 'desc')->get();

        // Reveal hashed PIN safely for offline authentication
        $staffs->makeVisible(['pin']);

        return response()->json([
            'status' => 'success',
            'data' => $staffs
        ]);
    }

    public function store(Request $request)
    {
        $request->merge(['role' => strtoupper($request->input('role'))]);

        $request->validate([
            'name' => 'required|string|max:255', // Nama input form-nya adalah 'name'
            'role' => 'required|string|max:50',
            'pin' => [
                'required',
                'string',
                'min:6',
                'max:10',
                Rule::unique('staff')->where(function ($query) {
                    return $query->where('user_id', Auth::id());
                })
            ],
            'hourly_rate' => 'nullable|numeric|min:0',
            'outlet_id' => 'required|integer', // 🏢 [BARU] Validasi Wajib Pilih Cabang
            'employment_type' => 'nullable|string|in:full_time,part_time,freelance', // 🏢 [PENGGAJIAN]
            'base_salary' => 'nullable|numeric|min:0',
            'daily_allowance' => 'nullable|numeric|min:0',
        ], [
            'pin.unique' => 'PIN ini sudah digunakan oleh karyawan lain.',
        ]);

        $staff = new Staff();
        $staff->user_id = Auth::id();
        $staff->outlet_id = $request->outlet_id; // 🏢 [BARU] Simpan ID Cabang
        $staff->staff_name = $request->name;
        $staff->role = $request->role;
        $staff->pin = Staff::normalizePin($request->pin);
        $staff->hourly_rate = $request->hourly_rate ?? 0;
        $staff->employment_type = $request->employment_type ?? 'full_time'; // 🏢 [PENGGAJIAN]
        $staff->base_salary = $request->base_salary ?? 0;
        $staff->daily_allowance = $request->daily_allowance ?? 0;
        $staff->is_active = true;
        $staff->save();

        return redirect()->back()->with('success', 'Karyawan berhasil ditambahkan!');
    }

    public function destroy($id)
    {
        $staff = Staff::where('id', $id)->where('user_id', Auth::id())->firstOrFail();
        $staff->delete();
        return back()->with('success', 'Data Karyawan berhasil dihapus!');
    }

    public function update(Request $request, $id)
    {
        $request->merge(['role' => strtoupper($request->input('role'))]);

        $request->validate([
            'name' => 'required|string|max:255',
            'role' => 'required|string|max:50',
            'pin' => [
                'nullable',
                'string',
                'min:6',
                'max:10',
                Rule::unique('staff')->where(function ($query) {
                    return $query->where('user_id', Auth::id());
                })->ignore($id)
            ],
            'hourly_rate' => 'nullable|numeric|min:0',
            'outlet_id' => 'required|integer', // 🏢 [BARU] Validasi Wajib Pilih Cabang saat Edit
            'employment_type' => 'nullable|string|in:full_time,part_time,freelance', // 🏢 [PENGGAJIAN]
            'base_salary' => 'nullable|numeric|min:0',
            'daily_allowance' => 'nullable|numeric|min:0',
        ], [
            'pin.unique' => 'PIN ini sudah digunakan oleh karyawan lain.',
        ]);

        $userId = Auth::id();
        $staff = Staff::where('id', $id)->where('user_id', $userId)->firstOrFail();

        $staff->outlet_id = $request->outlet_id; // 🏢 [BARU] Update ID Cabang
        $staff->staff_name = $request->name;
        $staff->role = $request->role;

        if ($request->filled('pin')) {
            $staff->pin = Staff::normalizePin($request->pin);
        }

        $staff->hourly_rate = $request->hourly_rate ?? 0;
        $staff->employment_type = $request->employment_type ?? 'full_time'; // 🏢 [PENGGAJIAN]
        $staff->base_salary = $request->base_salary ?? 0;
        $staff->daily_allowance = $request->daily_allowance ?? 0;
        $staff->save();

        return redirect()->back()->with('success', 'Profil karyawan berhasil diperbarui!');
    }
}
