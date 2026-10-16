<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class CheckStoreActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // 1. Lewati pengecekan jika user adalah superadmin
        if ($user && $user->role === 'superadmin') {
            return $next($request);
        }

        if ($user) {
            // 2. [FIX PENTING] Pengecekan Tanggal Expired yang Aman dari Null
            // Cek dua kolom (jaga-jaga nama kolom yang berbeda)
            $expiredDate = $user->subscription_end_date ?? $user->subscription_expired_at;
            $isExpired = false;

            // Hanya bandingkan dengan Carbon jika tanggalnya ADA (tidak null)
            if (!empty($expiredDate)) {
                $isExpired = Carbon::now()->startOfDay()->greaterThan($expiredDate);
            }

            // 3. Eksekusi blokir — suspended ATAU expired
            if ($user->account_status === 'suspended' || $isExpired) {
                
                // Jika request datang dari Android/API (berupa JSON)
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'AKSES DIBLOKIR: Masa langganan telah habis atau akun ditangguhkan. Silakan hubungi Admin.'
                    ], 403);
                }

                // Jika request datang dari Web Dashboard
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect('/login')->withErrors([
                    'email' => 'AKSES DIBLOKIR: Masa langganan telah habis atau akun ditangguhkan. Hubungi Admin.',
                ]);
            }
        }

        return $next($request);
    }
}