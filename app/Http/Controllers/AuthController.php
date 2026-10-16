<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Setting; // [PENTING] Import Model Setting
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail; // [BARU] Import Mail
use App\Mail\OtpMail;
use App\Services\Auth\OtpService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Support\EmailAddress;

class AuthController extends Controller
{
    // --- 1. LOGIN API (ANDROID) ---
    public function login(Request $request)
    {
        // 0. Validasi Input (Cegah TypeError trim(null))
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        // 1. Normalisasi Input (Pembersihan Spasi)
        $email = strtolower(trim($request->email));
        $password = trim($request->password);

        // 2. Anti Brute-Force Limit
        $throttleKey = \Illuminate\Support\Str::transliterate($email . '|' . $request->ip());
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($throttleKey);
            return response()->json([
                'status' => 'error',
                'message' => "Terlalu banyak percobaan gagal. Coba lagi dalam $seconds detik."
            ], 429);
        }

        // 3. 🚨 STATELESS CHECK (Wajib untuk API, Jangan pakai Auth::attempt)
        $user = \App\Models\User::where('email', $email)->first();

        if (!$user || !\Illuminate\Support\Facades\Hash::check($password, $user->password)) {
            \Illuminate\Support\Facades\RateLimiter::hit($throttleKey, 60);
            return response()->json([
                'status' => 'error',
                'message' => 'Email atau Password salah'
            ], 401);
        }

        // Email hygiene gate (new registers only; legacy grandfathered)
        $emailStatus = (string) ($user->email_status ?? 'legacy');
        if ($emailStatus === 'bounced') {
            return response()->json([
                'status' => 'error',
                'code' => 'email_bounced',
                'message' => 'Email ini ditolak server mail (bounce). Daftar ulang dengan email aktif.',
            ], 403);
        }
        if ($emailStatus === 'pending' || ($user->email_verified_at === null && $emailStatus !== 'legacy' && $emailStatus !== 'verified')) {
            return response()->json([
                'status' => 'error',
                'code' => 'email_unverified',
                'message' => 'Email belum diverifikasi. Cek inbox OTP, lalu verifikasi dulu.',
                'email' => $user->email,
            ], 403);
        }

        \Illuminate\Support\Facades\RateLimiter::clear($throttleKey);

        // 1. Generate Token
        $token = $user->createToken('auth_token')->plainTextToken;

        // 2. Eager Load Relasi Profil (Active profiles only, reveal hashed pin for offline login)
        $user->load(['profiles' => function ($query) {
            $query->where('is_active', true);
        }]);

        if ($user->profiles) {
            $user->profiles->makeVisible(['pin']);
        }

        // 3. Cek Status Outlet
        $hasOutlet = \App\Models\Setting::where('user_id', $user->id)->whereNotNull('business_type')->exists();

        // 4. Return DTO Strict Android
        return response()->json([
            'status'  => 'success',
            'message' => 'Login berhasil',
            'data'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'token' => $token,
                'has_outlet' => $hasOutlet,

                // MAPPING KOLOM DB KE JSON ANDROID
                'subscription_tier' => strtoupper($user->subscription_plan ?? 'BASIC'),
                'subscription_expired_at' => $user->subscription_end_date ?? null,

                // PROFIL KASIR (Dari hasil load relasi)
                'profiles' => $user->profiles
            ]
        ], 200);
    }

    // [BARU] FUNGSI VERIFIKASI PIN PROFIL
    public function verifyPin(Request $request)
    {
        $request->merge([
            'pin' => \App\Models\Staff::normalizePin((string) $request->input('pin', '')),
        ]);

        $request->validate([
            'staff_id' => 'required|integer',
            'pin' => 'required|string|regex:/^[0-9]{4,6}$/',
            'outlet_id' => 'nullable|integer',
        ]);

        // [FIX] Manual Throttle Logic per Staff + IP (bukan IP saja)
        $throttleKey = 'pin-attempt:' . $request->staff_id . ':' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'status' => 'error',
                'message' => "Terlalu banyak percobaan. Coba lagi dalam $seconds detik."
            ], 429);
        }

        $userId = Auth::id();
        $staff = \App\Models\Staff::where('id', $request->staff_id)
            ->where('user_id', $userId)
            ->first();

        if (!$staff) {
            return response()->json(['status' => 'error', 'message' => 'Profil tidak ditemukan'], 404);
        }

        // Multi-outlet scope validation: reject cross-outlet staff login attempt
        if ($request->has('outlet_id') && !empty($request->outlet_id)) {
            $requestedOutletId = (int) $request->outlet_id;
            
            // Check outlet ownership
            $outletExists = \App\Models\Outlet::where('id', $requestedOutletId)
                ->where('user_id', $userId)
                ->exists();

            if (!$outletExists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Outlet tidak valid atau tidak memiliki akses.',
                ], 403);
            }

            if ($staff->outlet_id !== null && (int)$staff->outlet_id !== $requestedOutletId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Staff tidak terdaftar pada outlet yang dipilih.',
                ], 403);
            }
        }

        if ($staff->is_active === false || (int)$staff->is_active === 0) {
            return response()->json(['status' => 'error', 'message' => 'Profil karyawan tidak aktif'], 403);
        }

        if ($staff->pinMatches($request->pin)) {
            // [FIX] Bersihkan limit jika PIN benar
            RateLimiter::clear($throttleKey);

            $mustChange = (bool) ($staff->must_change_pin ?? false);

            return response()->json([
                'status' => 'success',
                'message' => $mustChange ? 'Wajib ganti PIN default' : 'PIN Benar',
                'must_change_pin' => $mustChange,
                'data' => [
                    'staff_id' => $staff->id,
                    'staff_name' => $staff->staff_name,
                    'role' => $staff->role,
                    'outlet_id' => $staff->outlet_id !== null ? (int) $staff->outlet_id : null,
                    'must_change_pin' => $mustChange,
                ]
            ], 200);
        }

        // [FIX] Tambah hitungan gagal jika PIN salah (Blokir 60 detik jika limit tercapai)
        RateLimiter::hit($throttleKey, 60);

        return response()->json(['status' => 'error', 'message' => 'PIN Salah!'], 401);
    }

    /**
     * Endpoint untuk ganti PIN staf (wajib ganti PIN default atau ganti mandiri).
     * POST /api/staff/{id}/change-pin
     */
    public function changePin(Request $request, $id)
    {
        $request->validate([
            'current_pin' => 'required|string',
            'new_pin' => 'required|string|min:4|max:6',
        ]);

        $staff = \App\Models\Staff::find($id);
        if (!$staff) {
            return response()->json([
                'status' => 'error',
                'message' => 'Staf tidak ditemukan'
            ], 404);
        }

        if (!$staff->pinMatches($request->current_pin)) {
            return response()->json([
                'status' => 'error',
                'message' => 'PIN saat ini salah'
            ], 401);
        }

        $newPin = \App\Models\Staff::normalizePin($request->new_pin);

        if (\App\Models\Staff::isDefaultPin($newPin)) {
            return response()->json([
                'status' => 'error',
                'message' => 'PIN baru tidak boleh menggunakan PIN default sistem'
            ], 422);
        }

        if ($staff->pinMatches($newPin)) {
            return response()->json([
                'status' => 'error',
                'message' => 'PIN baru tidak boleh sama dengan PIN saat ini'
            ], 422);
        }

        $staff->changePin($newPin);

        return response()->json([
            'status' => 'success',
            'message' => 'PIN berhasil diubah',
            'must_change_pin' => false,
            'data' => [
                'staff_id' => $staff->id,
                'staff_name' => $staff->staff_name,
                'role' => $staff->role,
                'must_change_pin' => false,
            ]
        ], 200);
    }

    // --- 2. REGISTER ---
    public function register(Request $request)
    {
        // 1. Validasi Input via Validator untuk cegah HTML redirect
        // Normalize email early (typo check + unique)
        $email = EmailAddress::normalize($request->input('email'));
        if ($reject = EmailAddress::rejectMessage($email)) {
            return response()->json([
                'status' => 'error',
                'message' => $reject,
            ], 422);
        }

        $validator = Validator::make(array_merge($request->all(), [
            'email' => $email,
        ]), [
            'name' => 'required|string|max:255',
            'email' => EmailAddress::validationRule(uniqueUsers: true),
            'password' => 'required|min:8',
        ], [
            'email.unique' => 'Ops, email ini sudah ada yang pakai boss!',
            'email.required' => 'Email wajib diisi!',
            'email.email' => 'Format email tidak valid atau domain tidak punya DNS/MX.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        // Keep request email normalized for downstream create
        $request->merge(['email' => $email]);

        // 🛡️ [BARU] LIMIT SPAM REGISTRATION
        $throttleKey = 'register_api|' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'status' => 'error',
                'message' => "Batas pembuatan akun tercapai. Harap tunggu " . ceil($seconds / 60) . " menit."
            ], 429);
        }

        $otpService = new OtpService();
        $otpCode = $otpService->generate($request->email, 'register', 4);

        // Bungkus dengan Try-Catch agar API tidak throwing 500 HTML
        try {
            // 2. Simpan ke DB dengan instansiasi agar kolom terlarang (Mass Assignment) 
            // bisa dilewatkan sebelum dikirim ke database (Mencegah SQLSTATE crash).
            $user = new User();
            $user->name = trim($request->name);
            $user->email = strtolower(trim($request->email));
            $user->password = Hash::make($request->password);
            // $user->otp tidak diisi lagi (menggunakan OtpService)

            // Set kolom restricted secara manual sebelum di-save
            $user->subscription_plan = 'trial';
            $user->role = 'mitra';
            $user->email_verified_at = null;
            $user->email_status = 'pending';
            $user->save();

            // 3. Buat Outlet default terlebih dahulu agar outlet_id tersedia untuk staf

            $outlet = new \App\Models\Outlet();
            $outlet->user_id = $user->id;
            $outlet->name    = 'Cabang Utama';
            $outlet->address = 'Belum diatur';
            $outlet->phone   = '0000000000';
            $outlet->save();

            // 4. Auto-generate staf default berdasarkan tier (API register default = TRIAL/FREE)
            $tier = strtoupper($user->subscription_plan ?? 'FREE');
            $this->generateDefaultStaff($outlet, $tier, $user->name);

            RateLimiter::hit($throttleKey, 3600); // 🛡️ Limit IP per 1 Jam

            $response = response()->json([
                'status' => 'success',
                'message' => 'Registrasi Berhasil, Kode OTP dikirim ke email!',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ]
            ], 201);

            app()->terminating(function () use ($user, $otpCode) {
                try {
                    Mail::to($user->email)->send(new OtpMail($otpCode, $user->name));
                } catch (\Exception $e) {
                    \Log::error("Gagal kirim OTP registrasi: " . $e->getMessage());
                }
            });

            return $response;
        } catch (\Exception $e) {
            // 4. Tangkap error DB atau sistem dan return JSON
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan sistem saat membuat akun: ' . $e->getMessage()
            ], 500);
        }
    }

    // --- 3. VERIFIKASI OTP ---
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'otp' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => 'Data tidak lengkap'], 400);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Kode OTP Salah atau Email tidak ditemukan'], 400);
        }

        $otpService = new OtpService();
        try {
            $isValid = $otpService->verify($request->email, 'register', $request->otp);
            if (!$isValid) {
                return response()->json(['status' => 'error', 'message' => 'Kode OTP Salah atau Email tidak ditemukan'], 400);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => $e->validator->errors()->first()], 400);
        }

        $user->email_verified_at = now();
        $user->email_status = 'verified';
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Verifikasi Berhasil! Akun aktif.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'subscription_plan' => $user->subscription_plan
            ]
        ], 200);
    }

    // --- 4. KIRIM ULANG OTP ---
    public function resendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'type'  => 'nullable|string|in:register,reset_pwd'
        ]);

        $throttleKey = 'resend-otp:' . strtolower($request->email) . ':' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'status' => 'error',
                'message' => "Terlalu banyak permintaan. Harap tunggu " . ceil($seconds / 60) . " menit."
            ], 429);
        }
        RateLimiter::hit($throttleKey, 5 * 60);

        $user = User::where('email', EmailAddress::normalize($request->email))->first();

        if (!$user) return response()->json(['status' => 'error', 'message' => 'Email tidak ditemukan'], 404);

        if (($user->email_status ?? '') === 'bounced') {
            return response()->json([
                'status' => 'error',
                'message' => 'Email ini pernah bounce. Pakai email lain untuk daftar.',
            ], 403);
        }

        $type = $request->input('type', 'register');
        $length = ($type === 'reset_pwd') ? 6 : 4;

        $otpService = new OtpService();
        $newOtp = $otpService->generate($request->email, $type, $length);

        // 1. Siapkan response sukses
        $response = response()->json(['status' => 'success', 'message' => 'OTP baru sedang dikirim!'], 200);

        // 2. Tambahkan $newOtp ke dalam blok use() agar bisa terbaca oleh Mail
        app()->terminating(function () use ($user, $newOtp) {
            try {
                Mail::to($user->email)->send(new OtpMail($newOtp, $user->name));
            } catch (\Exception $e) {
                \Log::error("Resend Error: " . $e->getMessage());
            }
        });

        return $response;
    }

    // --- [BARU] 5. LUPA PASSWORD (KIRIM OTP) ---
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $throttleKey = 'forgot-password:' . strtolower($request->email) . ':' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'status' => 'error',
                'message' => "Terlalu banyak permintaan. Harap tunggu " . ceil($seconds / 60) . " menit."
            ], 429);
        }
        RateLimiter::hit($throttleKey, 15 * 60);

        $user = User::where('email', $request->email)->first();

        $responseMessage = 'Jika email terdaftar, kode verifikasi telah dikirim!';

        if (!$user) {
            return response()->json([
                'status' => 'success',
                'message' => $responseMessage
            ], 200);
        }

        // Buat OTP baru via OtpService
        $otpService = new OtpService();
        $resetOtp = $otpService->generate($request->email, 'reset_pwd', 6);

        // Respon cepat Anti-Timeout
        $response = response()->json([
            'status' => 'success',
            'message' => $responseMessage
        ], 200);

        // Kirim email di background
        app()->terminating(function () use ($user, $resetOtp) {
            try {
                Mail::to($user->email)->send(new OtpMail($resetOtp, $user->name));
            } catch (\Exception $e) {
                \Log::error("Gagal Kirim OTP Reset ke {$user->email}: " . $e->getMessage());
            }
        });

        return $response;
    }

    // --- [BARU] 6. RESET PASSWORD (SIMPAN SANDI BARU) ---
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'otp' => 'required|string',
            'new_password' => 'required|min:8'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 400);
        }

        $user = User::where('email', $request->email)->first();

        // Cek apakah user ada
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Kode OTP Salah atau Email tidak ditemukan'], 400);
        }

        $otpService = new OtpService();
        try {
            $isValid = $otpService->verify($request->email, 'reset_pwd', $request->otp);
            if (!$isValid) {
                return response()->json(['status' => 'error', 'message' => 'Kode OTP Salah atau Email tidak ditemukan'], 400);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => $e->validator->errors()->first()], 400);
        }

        // Timpa password lama dengan yang baru
        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Password berhasil diubah! Silakan Login dengan sandi baru.'
        ], 200);
    }

    // --- 7. LOGOUT ---
    public function logout(Request $request)
    {
        if ($request->user()) {
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully'
        ]);
    }

    // ==============================================================
    // [BARU] FUNGSI KHUSUS UNTUK WEB DASHBOARD
    // ==============================================================

    // --- 5. LOGIN WEB ---
    public function webLogin(Request $request)
    {
        // 🛡️ [BARU] Validasi DULU sebelum di-trim untuk mencegah TypeError
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // 🛡️ [BARU] NORMALISASI INPUT
        $email = EmailAddress::normalize($request->email);
        $password = trim($request->password);

        if ($typo = EmailAddress::typoMessage($email)) {
            return back()->withErrors(['email' => $typo])->withInput();
        }

        // 🛡️ [BARU] ANTI BRUTE-FORCE: Batasi percobaan login (Maks 5x)
        $throttleKey = Str::transliterate($email . '|' . $request->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return back()->withErrors(['email' => "Terlalu banyak percobaan. Coba lagi dalam $seconds detik."]);
        }

        $credentials = [
            'email' => $email,
            'password' => $password,
        ];

        $remember = $request->has('remember');

        // Cek kecocokan email dan password
        if (Auth::attempt($credentials, $remember)) {
            RateLimiter::clear($throttleKey); // 🛡️ Bersihkan limit jika berhasil

            // 🛡️ [BARU] ANTI SESSION FIXATION: Wajib ganti ID Sesi setelah login
            $request->session()->regenerate();

            /** @var \App\Models\User $user */
            $user = Auth::user();

            $emailStatus = (string) ($user->email_status ?? 'legacy');
            if ($emailStatus === 'bounced') {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return back()->withErrors([
                    'email' => 'Email ditolak server mail (bounce). Daftar ulang dengan email aktif.',
                ])->withInput();
            }
            if ($emailStatus === 'pending' || ($user->email_verified_at === null && !in_array($emailStatus, ['legacy', 'verified'], true))) {
                Auth::logout();
                return redirect()->route('web.verify-otp.show', ['email' => $user->email])
                    ->with('success', 'Email belum diverifikasi. Masukkan kode OTP dari inbox.');
            }

            // ========================================================
            // 1. LOGIKA REDIRECT SUPERADMIN
            // ========================================================
            if ($user->role === 'superadmin') {
                return redirect('/superadmin/kaching-board');
            }

            // ========================================================
            // 2. LOGIKA CUSTOMER / OWNER TOKO (Cek Blokir & Expired)
            // ========================================================
            $expiredDate = $user->subscription_expired_at;
            $isExpired = false;

            if (!empty($expiredDate)) {
                $isExpired = \Carbon\Carbon::now()->startOfDay()->greaterThan($expiredDate);
            }

            if ($isExpired) {
                Auth::logout(); // Segera putuskan sesi
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return back()->withErrors([
                    'email' => 'AKSES DIBLOKIR: Masa langganan habis. Hubungi Admin untuk perpanjangan.',
                ]);
            }

            // Jika aman, lempar ke dashboard toko
            return redirect()->intended('/dashboard');
        }

        // 🛡️ [BARU] Tambah hitungan gagal ke Rate Limiter (Blokir 60 detik jika penuh)
        RateLimiter::hit($throttleKey, 60);

        // 🛡️ [IMPROVED] Diagnosa kenapa login gagal supaya user tidak bingung.
        // - Kalau email tidak ada di DB: kasih hint daftar / cek ejaan
        // - Kalau email ada tapi password salah: kasih hint lupa password
        // - Default: generic
        $userExists = \App\Models\User::where('email', $email)->exists();
        if (!$userExists) {
            $message = "Email \"{$email}\" belum terdaftar. Cek penulisan email Anda, atau daftar akun baru.";
        } else {
            $message = "Password salah. Coba lagi, atau klik \"Lupa Password\" untuk reset.";
        }

        // Kalau gagal, kembalikan ke halaman login bawa pesan error
        return back()->withErrors([
            'email' => $message,
        ]);
    }

    // --- 6. LOGOUT WEB ---
    public function webLogout(Request $request)
    {
        Auth::logout(); // Putus sesi
        $request->session()->invalidate(); // Hancurkan sesi
        $request->session()->regenerateToken(); // Hancurkan token perlindungan form

        return redirect('/login'); // Lempar balik ke pintu depan
    }

    // ==============================================================
    // [BARU] 8. SETUP BISNIS AWAL (ONBOARDING)
    // ==============================================================
    public function setupBusiness(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_name' => 'required|string|max:255',
            'business_type' => 'required|string' // Hapus 'in:...' agar semua jenis industri dari Android bisa masuk
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 400);
        }

        $user = Auth::user();

        // Cari cabang pertama untuk melampirkan outlet_id ke setting
        $firstOutlet = \App\Models\Outlet::where('user_id', $user->id)->orderBy('id', 'asc')->first();
        $outletId = $firstOutlet ? $firstOutlet->id : null;

        // Buat atau Update Setting Toko
        $setting = Setting::updateOrCreate(
            ['user_id' => $user->id, 'outlet_id' => $outletId],
            [
                'store_name' => $request->store_name,
                'business_type' => $request->business_type,
                // [FIX] Tambahkan default string kosong agar tidak error database NOT NULL
                'store_address' => $request->store_address ?? '-',
                'store_phone' => $request->store_phone ?? '-',
                'receipt_footer' => 'Terima Kasih',
                'tax_rate' => 0,
                'service_charge' => 0,
                'service_mode' => 'classic'
            ]
        );

        // =========================================================
        // 🏢 [BARU] SINKRONISASI NAMA TOKO KE CABANG PERTAMA 🏢
        // =========================================================
        if ($firstOutlet) {
            $firstOutlet->update([
                'name' => $request->store_name, // Timpa "Cabang Utama" jadi nama toko
                'address' => $request->store_address ?? $firstOutlet->address,
                'phone' => $request->store_phone ?? $firstOutlet->phone,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Profil Bisnis berhasil disiapkan!',
            'has_outlet' => true, // Tambahkan flag ini
            'data' => $setting
        ]);
    }

    // ==============================================================
    // [BARU] 9. REGISTER KHUSUS WEB (LANDING PAGE)
    // ==============================================================
    public function webRegister(Request $request)
    {
        // 🛡️ [BARU] NORMALISASI INPUT
        $email = EmailAddress::normalize($request->email);
        $password = trim($request->password);

        if ($reject = EmailAddress::rejectMessage($email)) {
            return back()->withErrors(['email' => $reject])->withInput();
        }

        // 🛡️ [BARU] LIMIT SPAM REGISTRATION
        $throttleKey = 'register_web|' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $minutes = ceil($seconds / 60);
            return back()->withErrors(['email' => "Batas pendaftaran tercapai. Harap tunggu $minutes menit."])->withInput();
        }

        // 1. Validasi Inputan User
        $validator = Validator::make(array_merge($request->all(), [
            'email' => $email,
            'password' => $password,
            'password_confirmation' => trim($request->password_confirmation),
        ]), [
            'name'       => 'required|string|max:255',
            'email'      => EmailAddress::validationRule(uniqueUsers: true),
            'store_name' => 'required|string|max:255',
            'phone'      => 'required|string|min:8|max:15|regex:/^[0-9]+$/',
            'password'   => 'required|min:8|confirmed', // Mewajibkan konfirmasi password
        ], [
            'email.unique'        => 'Waduh bos, email ini sudah terdaftar. Silakan menuju halaman Login.',
            'email.email'         => 'Format email tidak valid atau domain tidak punya DNS/MX. Cek typo (mis. gmial → gmail).',
            'password.confirmed'  => 'Konfirmasi passwordnya beda bos, coba cek lagi.',
            'email.required'      => 'Email wajib diisi bos.',
            'store_name.required' => 'Nama usaha/toko wajib diisi bos.',
            'phone.required'      => 'Nomor WhatsApp wajib diisi bos.',
            'phone.regex'         => 'Nomor WhatsApp hanya boleh berisi angka (tanpa tanda hubung atau spasi).',
            'phone.min'           => 'Nomor WhatsApp minimal 8 digit.',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // 2. Simpan User Baru (Otomatis Mitra & Trial 14 Hari)
        // [FIX KRITIS] Gunakan property assignment untuk field sensitif,
        // dan NAMA KOLOM yang sesuai dengan migration (users table).
        $user = new User();
        $user->name     = $request->name;
        $user->email    = $email;
        $user->password = Hash::make($password);
        // [FIX] Mengembalikan nama kolom sesuai db asli
        $user->subscription_plan       = 'trial';
        $user->role                    = 'mitra';
        // [FIX] Kolom asli di DB: subscription_expired_at (bukan subscription_end_date)
        $user->subscription_expired_at = now()->addDays(14);
        $user->email_verified_at       = null;
        $user->email_status            = 'pending';
        $user->save();

        // 3. AUTO-GENERATE CABANG UTAMA dengan nama toko & nomor WA dari form
        $outlet = \App\Models\Outlet::forceCreate([
            'user_id' => $user->id,
            'name'    => $request->store_name,
            'address' => 'Belum diatur',
            'phone'   => $request->phone,
        ]);

        // 3.5. Auto-generate staf default berdasarkan tier langganan
        $tier = strtoupper($user->subscription_plan ?? 'TRIAL');
        $this->generateDefaultStaff($outlet, $tier, $user->name);

        // 3.7. [FIX BARU] Simpan store_name & store_phone ke tabel Settings
        Setting::forceCreate([
            'user_id'         => $user->id,
            'outlet_id'       => $outlet->id,
            'store_name'      => $request->store_name,
            'store_phone'     => $request->phone,
            'store_address'   => 'Belum diatur',
            'receipt_footer'  => 'Terima kasih telah berbelanja!',
            'tax_rate'        => 0,
            'service_charge'  => 0,
            'service_mode'    => 'fast_casual',
        ]);

        RateLimiter::hit($throttleKey, 3600); // 🛡️ Limit IP per 1 Jam

        // 4. Kirim OTP — jangan auto-login sebelum email verified
        $otpService = new OtpService();
        $otpCode = $otpService->generate($email, 'register', 4);
        try {
            Mail::to($email)->send(new OtpMail($otpCode, $user->name));
        } catch (\Exception $e) {
            \Log::error('Gagal kirim OTP web register: ' . $e->getMessage());
        }

        // 5. Arahkan ke halaman verifikasi OTP
        return redirect()->route('web.verify-otp.show', ['email' => $email])
            ->with('success', 'Registrasi berhasil. Cek inbox untuk kode OTP, lalu verifikasi email.');
    }

    // ==============================================================
    // WEB: halaman + submit verifikasi OTP (setelah register)
    // ==============================================================
    public function showWebVerifyOtp(Request $request)
    {
        $email = EmailAddress::normalize($request->query('email', $request->input('email')));
        if ($email === '' || !str_contains($email, '@')) {
            return redirect()->route('login')->withErrors(['email' => 'Email tidak valid untuk verifikasi.']);
        }

        return view('auth.verify-otp', ['email' => $email]);
    }

    public function webVerifyOtp(Request $request)
    {
        $email = EmailAddress::normalize($request->input('email'));
        $otp = trim((string) $request->input('otp'));

        $validator = Validator::make([
            'email' => $email,
            'otp' => $otp,
        ], [
            'email' => 'required|email',
            'otp' => 'required|string',
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            return back()->withErrors(['otp' => 'Email tidak ditemukan.'])->withInput();
        }
        if (($user->email_status ?? '') === 'bounced') {
            return back()->withErrors(['otp' => 'Email ini pernah bounce. Daftar ulang dengan email lain.']);
        }

        $otpService = new OtpService();
        try {
            $isValid = $otpService->verify($email, 'register', $otp);
            if (!$isValid) {
                return back()->withErrors(['otp' => 'Kode OTP salah atau kedaluwarsa.'])->withInput();
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors(['otp' => $e->validator->errors()->first()])->withInput();
        }

        $user->email_verified_at = now();
        $user->email_status = 'verified';
        $user->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard')
            ->with('success', 'Email terverifikasi. Selamat datang! Trial 14 hari dimulai.');
    }

    public function webResendOtp(Request $request)
    {
        $email = EmailAddress::normalize($request->input('email'));
        $user = User::where('email', $email)->first();
        if (!$user) {
            return back()->withErrors(['otp' => 'Email tidak ditemukan.']);
        }
        if (($user->email_status ?? '') === 'bounced') {
            return back()->withErrors(['otp' => 'Email ini pernah bounce. Pakai email lain.']);
        }

        $throttleKey = 'resend-otp-web:' . $email . ':' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return back()->withErrors(['otp' => 'Terlalu banyak permintaan. Tunggu ' . ceil($seconds / 60) . ' menit.']);
        }
        RateLimiter::hit($throttleKey, 5 * 60);

        $otpService = new OtpService();
        $newOtp = $otpService->generate($email, 'register', 4);
        try {
            Mail::to($email)->send(new OtpMail($newOtp, $user->name));
        } catch (\Exception $e) {
            \Log::error('Web resend OTP error: ' . $e->getMessage());
            return back()->withErrors(['otp' => 'Gagal kirim OTP. Coba lagi sebentar.']);
        }

        return back()->with('success', 'OTP baru dikirim ke ' . $email);
    }

    // ==============================================================
    // 🏢 PRIVATE HELPER: AUTO-GENERATE STAF DEFAULT BERDASARKAN TIER
    // ==============================================================
    /**
     * Generate profil staf default untuk outlet baru berdasarkan subscription tier.
     *
     * @param  \App\Models\Outlet  $outlet      Outlet yang baru dibuat
     * @param  string              $tier         Subscription tier: FREE, BASIC, PRO, ULTIMATE, TRIAL
     * @param  string              $ownerName    Nama user/owner yang mendaftar
     * @return void
     */
    private function generateDefaultStaff(\App\Models\Outlet $outlet, string $tier, string $ownerName): void
    {
        $staffMap = match ($tier) {
            'ULTIMATE' => [
                ['role' => 'OWNER',   'staff_name' => $ownerName,     'pin' => '123456'],
                ['role' => 'MANAGER', 'staff_name' => 'Manager Toko', 'pin' => '000000'],
                ['role' => 'KASIR',   'staff_name' => 'Kasir Utama',  'pin' => '111111'],
                ['role' => 'WAITER',  'staff_name' => 'Pelayan 1',    'pin' => '222222'],
                ['role' => 'KITCHEN', 'staff_name' => 'Dapur Utama',  'pin' => '333333'],
            ],
            'PRO' => [
                ['role' => 'OWNER',   'staff_name' => $ownerName,     'pin' => '123456'],
                ['role' => 'KASIR',   'staff_name' => 'Kasir Utama',  'pin' => '111111'],
                ['role' => 'WAITER',  'staff_name' => 'Pelayan 1',    'pin' => '222222'],
                ['role' => 'KITCHEN', 'staff_name' => 'Dapur Utama',  'pin' => '333333'],
            ],
            'BASIC' => [
                ['role' => 'OWNER',   'staff_name' => $ownerName,     'pin' => '123456'],
                ['role' => 'KASIR',   'staff_name' => 'Kasir Utama',  'pin' => '111111'],
            ],
            // FREE, TRIAL, dan tier tidak dikenal -> hanya OWNER
            default => [
                ['role' => 'OWNER',   'staff_name' => $ownerName,     'pin' => '123456'],
            ],
        };

        foreach ($staffMap as $staffData) {
            \App\Models\Staff::forceCreate([
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
