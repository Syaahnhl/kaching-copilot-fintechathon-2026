<?php

namespace App\Http\Controllers; // [FIX] Kata \Api sudah dihapus!

use Illuminate\Http\Request;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class SettingController extends Controller
{
    // API GET: Ambil Data Setting Milik User Login
    public function index(Request $request)
    {
        $userId = Auth::id();
        $outletId = $request->header('Outlet-ID');

        if ($outletId) {
            $isValid = \App\Models\Outlet::where('id', $outletId)->where('user_id', $userId)->exists();
            if (!$isValid) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized outlet access'], 403);
            }
        } else {
            $firstOutlet = \App\Models\Outlet::where('user_id', $userId)->orderBy('id', 'asc')->first();
            $outletId = $firstOutlet ? $firstOutlet->id : null;
        }

        // 1. Ambil setting milik user ini dan outlet ini
        $setting = Setting::where('user_id', $userId)
            ->where('outlet_id', $outletId)
            ->first();

        // 2. Jika setting belum ada, return null
        if (!$setting) {
            return response()->json([
                'status' => 'success',
                'data' => null
            ]);
        }

        // 3. Format URL Logo biar bisa tampil di Android
        if ($setting->logo_url && !str_contains($setting->logo_url, 'http')) {
            $setting->logo_url = asset('storage/' . $setting->logo_url);
        }

        // FINDING-01: Format URL QRIS biar bisa tampil dan di-restore di Android
        if ($setting->qris_image && !str_contains($setting->qris_image, 'http')) {
            $setting->qris_image = asset('storage/' . $setting->qris_image);
        }

        // 4. Transform response agar cocok dengan Gson Android (String/Boolean strict)
        // Root cause blank struk 2026-07-23: tax_rate/service_charge double + show_* int 0/1
        // bikin SettingResponse parse gagal diam-diam → fetchSettingsFromServer() return null.
        $settingData = $setting->toArray();

        // FINDING-06: Defense-in-depth guarantee that midtrans_server_key is never exposed in API response
        unset($settingData['midtrans_server_key']);

        // Numeric money/rate fields → string (Android StoreSettingData uses String?)
        foreach (['tax_rate', 'service_charge', 'packaging_fee'] as $field) {
            $settingData[$field] = (string) ($setting->{$field} ?? 0);
        }
        // [BEP Phase 2] Biaya operasional harian (kartu Titik Impas)
        $settingData['daily_operational_cost'] = (string) ($setting->daily_operational_cost ?? 0);
        // CamelCase alias still used by older clients
        $settingData['packagingFee'] = (string) ($setting->packaging_fee ?? 0);

        // MySQL tinyint 0/1 must be real JSON booleans — Gson refuses NUMBER for Boolean?
        foreach (['show_logo', 'show_tax', 'show_service_charge', 'struk_dobel', 'is_tax_inclusive', 'is_tax_compound'] as $field) {
            $settingData[$field] = (bool) ($setting->{$field} ?? false);
        }

        // paper_size stays int (Android expects Int?)
        $settingData['paper_size'] = (int) ($setting->paper_size ?? 80);
        $settingData['rounding_mode'] = (string) ($setting->rounding_mode ?? 'NONE');
        $settingData['pinned_shortcuts'] = $setting->pinned_shortcuts ? (string) $setting->pinned_shortcuts : null;

        return response()->json([
            'status' => 'success',
            'data' => $settingData
        ]);
    }

    // API POST: Update Setting & Logo (Multi-User Ready)
    public function update(Request $request)
    {
        // 1. Validasi Input [UPDATED]
        // store_name kita buat nullable agar bisa update parsial (misal cuma update mode)
        $validator = Validator::make($request->all(), [
            'store_name' => 'nullable|string|max:100',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'service_mode' => 'nullable|in:classic,fast_casual' // [BARU] Validasi Mode
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }
        $userId = Auth::id();
        $outletId = $request->header('Outlet-ID');

        if ($outletId) {
            $isValid = \App\Models\Outlet::where('id', $outletId)->where('user_id', $userId)->exists();
            if (!$isValid) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized outlet access'], 403);
            }
        } else {
            $firstOutlet = \App\Models\Outlet::where('user_id', $userId)->orderBy('id', 'asc')->first();
            $outletId = $firstOutlet ? $firstOutlet->id : null;
        }

        // 1. Validasi Keamanan Biaya-Biaya
        $filled = function ($val) {
            return $val !== null && $val !== '';
        };

        $request->validate([
            'tax_rate'       => 'nullable|numeric|min:0|max:100',
            'service_charge' => 'nullable|numeric|min:0|max:100',
            'paper_size'     => 'nullable|integer|in:58,80',
            'rounding_mode'  => 'nullable|string|in:NONE,none,None',
            'pinned_shortcuts' => 'nullable|string|max:500',
        ]);

        // 2. Map data
        $data = [];
        if ($request->has('store_name') && $filled($request->store_name)) {
            $data['store_name'] = $request->store_name;
        }
        if ($request->has('store_address') && $filled($request->store_address)) {
            $data['store_address'] = $request->store_address;
        }
        if ($request->has('store_phone') && $filled($request->store_phone)) {
            $data['store_phone'] = $request->store_phone;
        }
        // Footer/website may be intentionally cleared only if client sends non-null string.
        // Still reject pure null (multipart empty part) to avoid NOT NULL crash.
        if ($request->has('receipt_footer') && $request->receipt_footer !== null) {
            $data['receipt_footer'] = (string) $request->receipt_footer;
        }
        if ($request->has('receipt_website') && $request->receipt_website !== null) {
            $data['receipt_website'] = (string) $request->receipt_website;
        }
        if ($request->has('tax_rate')) $data['tax_rate'] = $request->tax_rate;
        if ($request->has('service_charge')) $data['service_charge'] = $request->service_charge;

        // [BARU] Masukkan service_mode ke data update
        if ($request->has('service_mode')) $data['service_mode'] = $request->service_mode;
        // [BARU] Tangkap packagingFee dari Android
        if ($request->has('packagingFee')) $data['packaging_fee'] = (int) $request->packagingFee;
        // [BEP Phase 2] Tangkap biaya operasional harian dari Android (untuk Titik Impas)
        if ($request->has('daily_operational_cost')) $data['daily_operational_cost'] = (int) $request->daily_operational_cost;

        // [BARU] Tangkap flag PPN baru
        if ($request->has('is_tax_inclusive')) $data['is_tax_inclusive'] = filter_var($request->is_tax_inclusive, FILTER_VALIDATE_BOOLEAN);
        if ($request->has('is_tax_compound')) $data['is_tax_compound'] = filter_var($request->is_tax_compound, FILTER_VALIDATE_BOOLEAN);

        // [PRINTER & RECEIPT SETTINGS PERSISTENCE]
        if ($request->has('paper_size') && $request->paper_size !== null && $request->paper_size !== '') {
            $data['paper_size'] = (int) $request->paper_size;
        }
        if ($request->has('show_logo')) {
            $data['show_logo'] = filter_var($request->show_logo, FILTER_VALIDATE_BOOLEAN);
        }
        if ($request->has('show_tax')) {
            $data['show_tax'] = filter_var($request->show_tax, FILTER_VALIDATE_BOOLEAN);
        }
        if ($request->has('show_service_charge')) {
            $data['show_service_charge'] = filter_var($request->show_service_charge, FILTER_VALIDATE_BOOLEAN);
        }
        if ($request->has('struk_dobel')) {
            $data['struk_dobel'] = filter_var($request->struk_dobel, FILTER_VALIDATE_BOOLEAN);
        }
        if ($request->has('rounding_mode') && $request->rounding_mode !== null && $request->rounding_mode !== '') {
            $data['rounding_mode'] = strtoupper(trim((string) $request->rounding_mode));
        }
        if ($request->has('pinned_shortcuts')) {
            $data['pinned_shortcuts'] = $request->pinned_shortcuts !== null && $request->pinned_shortcuts !== ''
                ? trim((string) $request->pinned_shortcuts)
                : null;
        }


        // 3. Handle Upload Logo (Masih sama seperti kodemu, aman)
        if ($request->hasFile('logo')) {

            // A. Cek setting lama
            $oldSetting = Setting::where('user_id', $userId)
                ->where('outlet_id', $outletId)
                ->first();

            // B. Hapus logo lama
            if ($oldSetting && $oldSetting->logo_url) {
                $oldPath = str_replace(asset('storage/'), '', $oldSetting->logo_url);
                if (str_contains($oldSetting->logo_url, 'http')) {
                    $parsed = parse_url($oldSetting->logo_url);
                    $oldPath = ltrim($parsed['path'] ?? '', '/storage/'); // Safety null check
                } else {
                    $oldPath = $oldSetting->logo_url;
                }

                Storage::disk('public')->delete($oldPath);
            }

            // C. Upload logo baru
            $file = $request->file('logo');
            $path = $file->store('logos', 'public');

            // D. Simpan path relatif
            $data['logo_url'] = $path;
        }

        // 4. Update atau Buat Baru (SaaS Logic)
        $data['outlet_id'] = $outletId;
        $setting = Setting::updateOrCreate(
            ['user_id' => $userId, 'outlet_id' => $outletId], // Kunci pencarian
            $data                   // Data update
        );

        // =========================================================
        // 🏢 [FINDING-02] SINKRONISASI NAMA TOKO KE OUTLET TARGET SPESIFIK (API)
        // =========================================================
        if ($outletId) {
            $targetOutlet = \App\Models\Outlet::where('id', $outletId)
                ->where('user_id', $userId)
                ->first();

            if ($targetOutlet) {
                $outletUpdateData = [];
                if ($request->has('store_name') && $filled($request->store_name)) {
                    $outletUpdateData['name'] = $request->store_name;
                }
                if ($request->has('store_address') && $filled($request->store_address)) {
                    $outletUpdateData['address'] = $request->store_address;
                }
                if ($request->has('store_phone') && $filled($request->store_phone)) {
                    $outletUpdateData['phone'] = $request->store_phone;
                }

                if (!empty($outletUpdateData)) {
                    $targetOutlet->update($outletUpdateData);
                }
            } else {
                // [PENYELAMAT AKUN LAMA DARI ANDROID] Buatkan jika belum ada!
                \App\Models\Outlet::forceCreate([
                    'user_id' => $userId,
                    'name'    => ($request->has('store_name') && $filled($request->store_name)) ? $request->store_name : Auth::user()->name,
                    'address' => ($request->has('store_address') && $filled($request->store_address)) ? $request->store_address : 'Belum diatur',
                    'phone'   => ($request->has('store_phone') && $filled($request->store_phone)) ? $request->store_phone : '0000000000',
                ]);
            }
        }

        // 5. Format URL Logo & QRIS untuk respon
        if ($setting->logo_url && !str_contains($setting->logo_url, 'http')) {
            $setting->logo_url = asset('storage/' . $setting->logo_url);
        }
        if ($setting->qris_image && !str_contains($setting->qris_image, 'http')) {
            $setting->qris_image = asset('storage/' . $setting->qris_image);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Pengaturan berhasil diperbarui!',
            'data' => $setting
        ]);
    }

    // ==============================================================
    // FUNGSI KHUSUS WEB BACKOFFICE (DASHBOARD ADMIN)
    // ==============================================================

    // Tampilkan Halaman Web Setting
    public function webIndex()
    {
        $userId = Auth::id();
        $outletId = request()->input('outlet_id');

        if ($outletId) {
            $isValid = \App\Models\Outlet::where('id', $outletId)->where('user_id', $userId)->exists();
            if (!$isValid) {
                abort(403, 'Unauthorized outlet access');
            }
        } else {
            $firstOutlet = \App\Models\Outlet::where('user_id', $userId)->orderBy('id', 'asc')->first();
            $outletId = $firstOutlet ? $firstOutlet->id : null;
        }

        // Cari setting toko milik user yang sedang login, otomatis buat kalau belum ada
        $setting = Setting::firstOrCreate(
            ['user_id' => $userId, 'outlet_id' => $outletId],
            [
                'store_name' => Auth::user()->name,
                'tax_rate' => 0,
                'service_charge' => 0,
            ]
        );

        // Format URL Logo & QRIS biar bisa tampil di preview web
        if ($setting->logo_url && !str_contains($setting->logo_url, 'http')) {
            $setting->logo_url = asset('storage/' . $setting->logo_url);
        }
        if ($setting->qris_image && !str_contains($setting->qris_image, 'http')) {
            $setting->qris_image = asset('storage/' . $setting->qris_image);
        }

        // Tampilkan daftar cabang (outlets) agar bisa dikelola di pengaturan toko
        $outlets = \App\Models\Outlet::where('user_id', $userId)->orderBy('created_at', 'desc')->get();

        return view('backoffice.settings', compact('setting', 'outlets'));
    }

    // Simpan Perubahan dari Web
    public function webUpdate(Request $request)
    {
        try {
            // 1. Validasi Input
            $request->validate([
                'store_name'             => 'nullable|string|max:255',
                'store_phone'            => 'nullable|string|max:50',
                'store_address'          => 'nullable|string',
                'tax_rate'               => 'nullable|numeric|min:0|max:100',
                'service_charge'         => 'nullable|numeric|min:0|max:100',
                'receipt_footer'         => 'nullable|string',
                'logo'                   => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
                'banks'                  => 'nullable|array',
                'qris_image'             => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
                'midtrans_server_key'    => 'nullable|string|max:255', // [BARU]
                'midtrans_client_key'    => 'nullable|string|max:255', // [BARU]
                'midtrans_is_production' => 'nullable|boolean',        // [BARU]
            ]);

            $userId = Auth::id();
            $outletId = $request->input('outlet_id');

            if ($outletId) {
                $isValid = \App\Models\Outlet::where('id', $outletId)->where('user_id', $userId)->exists();
                if (!$isValid) {
                    abort(403, 'Unauthorized outlet access');
                }
            } else {
                $firstOutlet = \App\Models\Outlet::where('user_id', $userId)->orderBy('id', 'asc')->first();
                $outletId = $firstOutlet ? $firstOutlet->id : null;
            }

            $setting = Setting::where('user_id', $userId)
                ->where('outlet_id', $outletId)
                ->first();

            // 2. Ambil data spesifik, masukkan kolom midtrans & tax flags
            $data = $request->only([
                'store_name',
                'store_phone',
                'store_address',
                'tax_rate',
                'service_charge',
                'receipt_footer',
                'midtrans_server_key',    // [BARU]
                'midtrans_client_key',    // [BARU]
                'midtrans_is_production', // [BARU]
                'is_tax_inclusive',
                'is_tax_compound'
            ]);

            // Pastikan format boolean aman
            $data['midtrans_is_production'] = $request->has('midtrans_is_production') ? (bool)$request->midtrans_is_production : false;
            $data['is_tax_inclusive'] = $request->has('is_tax_inclusive') ? (bool)$request->is_tax_inclusive : false;
            $data['is_tax_compound'] = $request->has('is_tax_compound') ? (bool)$request->is_tax_compound : false;

            // Pastikan user_id juga masuk ke array data yang akan diupdate
            $data['user_id'] = $userId;
            $data['outlet_id'] = $outletId;
            
            // 3. Handle Multi Bank (Tetap sama)
            if ($request->has('banks') && is_array($request->banks)) {
                $filteredBanks = array_filter($request->banks, function ($bank) {
                    return !empty($bank['bank_name']) && !empty($bank['account_number']);
                });
                $data['bank_accounts'] = json_encode(array_values($filteredBanks));
            } else {
                $data['bank_accounts'] = null;
            }

            // 4. Handle Upload Logo dari Web
            if ($request->hasFile('logo')) {
                if ($setting && $setting->logo_url) {
                    // Hapus logo lama yang spesifik milik user ini
                    $oldPath = str_replace(asset('storage/'), '', $setting->logo_url);
                    // Hapus file hanya jika file tersebut tidak menggunakan path absolut eksternal
                    if (!str_starts_with($oldPath, 'http')) {
                        Storage::disk('public')->delete($oldPath);
                    }
                }
                $data['logo_url'] = $request->file('logo')->store('logos', 'public');
            }

            // 5. Handle Upload QRIS dari Web
            if ($request->hasFile('qris_image')) {
                if ($setting && $setting->qris_image) {
                    // Hapus QRIS lama yang spesifik milik user ini
                    $oldQrisPath = str_replace(asset('storage/'), '', $setting->qris_image);
                    if (!str_starts_with($oldQrisPath, 'http')) {
                        Storage::disk('public')->delete($oldQrisPath);
                    }
                }
                $data['qris_image'] = $request->file('qris_image')->store('qris', 'public');
            }

            // 6. Simpan atau Update secara ketat berdasarkan user_id dan outlet_id
            Setting::updateOrCreate(
                ['user_id' => $userId, 'outlet_id' => $outletId], // Cari berdasarkan ini
                $data                   // Update/Buat dengan array data ini
            );

            // =========================================================
            // 🏢 [FINDING-02] SINKRONISASI NAMA TOKO KE OUTLET TARGET SPESIFIK (WEB)
            // =========================================================
            $targetOutlet = $outletId 
                ? \App\Models\Outlet::where('id', $outletId)->where('user_id', $userId)->first()
                : \App\Models\Outlet::where('user_id', $userId)->orderBy('id', 'asc')->first();

            if ($targetOutlet) {
                // Jika cabang sudah ada, update namanya
                $targetOutlet->update([
                    'name' => $request->store_name ?: Auth::user()->name,
                    'address' => $request->store_address ?? $targetOutlet->address,
                    'phone' => $request->store_phone ?? $targetOutlet->phone,
                ]);
            } else {
                // [PENYELAMAT AKUN LAMA] Jika belum punya cabang sama sekali, BUATKAN!
                \App\Models\Outlet::forceCreate([
                    'user_id' => $userId,
                    'name'    => $request->store_name ?: Auth::user()->name, // Langsung pakai nama toko atau owner
                    'address' => $request->store_address ?? 'Belum diatur',
                    'phone'   => $request->store_phone ?? '0000000000',
                ]);
            }

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Tersimpan otomatis!'
                ]);
            }

            // Return jadul jika user menekan tombol "Simpan" manual
            return back()->with('success', 'Tadaa! Pengaturan toko berhasil diperbarui. 🎉');
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Settings Upload Error: ' . $e->getMessage());
            
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Gagal menyimpan: ' . $e->getMessage()
                ], 500);
            }
            
            return back()->with('error', 'Gagal menyimpan pengaturan: ' . $e->getMessage());
        }
    }
}
