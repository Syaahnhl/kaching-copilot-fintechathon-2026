<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Outlet;
use App\Models\Staff;
use Illuminate\Support\Facades\Auth;

class OutletController extends Controller
{
    // Tampilkan Halaman Daftar Cabang
    public function index()
    {
        $outlets = Outlet::where('user_id', Auth::id())->orderBy('created_at', 'desc')->get();
        // Mengarah langsung ke file outlets.blade.php
        return view('backoffice.outlets', compact('outlets'));
    }

    /**
     * API list outlets for Android POS (offline-first / multi-branch sync)
     * Contract: { status: "success", data: [{id,name,address,phone}] }
     */
    public function apiIndex(Request $request)
    {
        $outlets = Outlet::where('user_id', Auth::id())
            ->orderBy('name')
            ->get(['id', 'name', 'address', 'phone']);

        return response()->json([
            'status' => 'success',
            'data' => $outlets,
        ]);
    }

    // Simpan Cabang Baru
    public function store(Request $request)
    {
        $request->validate([
            'name'              => 'required|string|max:255',
            'address'           => 'nullable|string',
            'phone'             => 'nullable|string|max:20',
            'latitude'          => 'nullable|numeric|between:-90,90',
            'longitude'         => 'nullable|numeric|between:-180,180',
            'attendance_radius' => 'nullable|integer|min:1',
        ]);

        $outlet = Outlet::forceCreate([
            'user_id'           => Auth::id(),
            'name'              => $request->name,
            'address'           => $request->address,
            'phone'             => $request->phone,
            'latitude'          => $request->latitude,
            'longitude'         => $request->longitude,
            'attendance_radius' => $request->attendance_radius ?? 100,
        ]);

        // 🏢 Auto-generate staf default sesuai tier langganan
        $user = Auth::user();
        $tier = strtoupper($user->subscription_plan ?? 'FREE');
        $this->generateDefaultStaff($outlet, $tier, $user->name);

        return back()->with('success', 'Cabang baru berhasil ditambahkan!');
    }

    // Update Data Cabang
    public function update(Request $request, $id)
    {
        $request->validate([
            'name'              => 'required|string|max:255',
            'address'           => 'nullable|string',
            'phone'             => 'nullable|string|max:20',
            'latitude'          => 'nullable|numeric|between:-90,90',
            'longitude'         => 'nullable|numeric|between:-180,180',
            'attendance_radius' => 'nullable|integer|min:1',
        ]);

        $outlet = Outlet::where('id', $id)->where('user_id', Auth::id())->firstOrFail();
        $outlet->update([
            'name'              => $request->name,
            'address'           => $request->address,
            'phone'             => $request->phone,
            'latitude'          => $request->latitude,
            'longitude'         => $request->longitude,
            'attendance_radius' => $request->attendance_radius ?? 100,
        ]);

        return back()->with('success', 'Data cabang berhasil diperbarui!');
    }

    // Hapus Cabang
    public function destroy($id)
    {
        $outlet = Outlet::where('id', $id)->where('user_id', Auth::id())->firstOrFail();
        
        $hasTransactions = \App\Models\Transaction::where('outlet_id', $outlet->id)->exists();
        if ($hasTransactions) {
            return back()->with('error', 'Cabang tidak dapat dihapus karena masih memiliki riwayat transaksi.');
        }

        $outlet->delete();

        return back()->with('success', 'Cabang berhasil dihapus!');
    }

    // ==============================================================
    // 🏢 PRIVATE HELPER: AUTO-GENERATE STAF DEFAULT BERDASARKAN TIER
    // ==============================================================
    /**
     * Generate profil staf default untuk outlet baru berdasarkan subscription tier.
     *
     * @param  \App\Models\Outlet  $outlet     Outlet yang baru dibuat
     * @param  string              $tier        Subscription tier: FREE, BASIC, PRO, ULTIMATE, TRIAL
     * @param  string              $ownerName   Nama user/owner yang mendaftar
     * @return void
     */
    private function generateDefaultStaff(Outlet $outlet, string $tier, string $ownerName): void
    {
        // Definisi mapping staf per tier
        // Setiap elemen: ['role' => string, 'staff_name' => string, 'pin' => string]
        $staffMap = match ($tier) {
            'ULTIMATE' => [
                ['role' => 'OWNER',   'staff_name' => $ownerName,      'pin' => '123456'],
                ['role' => 'MANAGER', 'staff_name' => 'Manager Toko',  'pin' => '000000'],
                ['role' => 'KASIR',   'staff_name' => 'Kasir Utama',   'pin' => '111111'],
                ['role' => 'WAITER',  'staff_name' => 'Pelayan 1',     'pin' => '222222'],
                ['role' => 'KITCHEN', 'staff_name' => 'Dapur Utama',   'pin' => '333333'],
            ],
            'PRO' => [
                ['role' => 'OWNER',   'staff_name' => $ownerName,      'pin' => '123456'],
                ['role' => 'KASIR',   'staff_name' => 'Kasir Utama',   'pin' => '111111'],
                ['role' => 'WAITER',  'staff_name' => 'Pelayan 1',     'pin' => '222222'],
                ['role' => 'KITCHEN', 'staff_name' => 'Dapur Utama',   'pin' => '333333'],
            ],
            'BASIC' => [
                ['role' => 'OWNER',   'staff_name' => $ownerName,      'pin' => '123456'],
                ['role' => 'KASIR',   'staff_name' => 'Kasir Utama',   'pin' => '111111'],
            ],
            // FREE dan TRIAL hanya dapat OWNER
            default => [
                ['role' => 'OWNER',   'staff_name' => $ownerName,      'pin' => '123456'],
            ],
        };

        // Loop dan buat setiap entri staf
        foreach ($staffMap as $staffData) {
            Staff::forceCreate([
                'user_id'         => $outlet->user_id,
                'outlet_id'       => $outlet->id,
                'staff_name'      => $staffData['staff_name'],
                'role'            => $staffData['role'],
                'pin'             => $staffData['pin'],
                'is_active'       => true,
                'must_change_pin' => true,
            ]);
        }
    }
}
