<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL; // 🚀 [BARU] Wajib ditambahkan agar fitur URL aktif
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 🚀 [BARU] Paksa semua link & form submit menjadi HTTPS.
        // Ini adalah JURUS AMPUH membunuh error 419 Page Expired saat pindah domain!
        if (config('app.env') !== 'local') {
            URL::forceScheme('https');
        }

        // 🛡️ [BARU] The Spam Blocker: Rate Limiting Global
        RateLimiter::for('api', function (Request $request) {
            // Rute umum: Maksimal 60 request per menit per User / IP
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // 🛡️ [BARU] The Spam Blocker: Strict Rate Limiting (Untuk Endpoint Krusial)
        RateLimiter::for('strict', function (Request $request) {
            // Rute rawan: Maksimal 10 request per menit per User / IP
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // 🛡️ [FIX 2026-07-15] Definisikan rate limiter bulk-sync yang hilang
        // untuk mencegah MissingRateLimiterException pada POST /api/transactions/bulk-sync
        RateLimiter::for('bulk-sync', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

// 🛡️ [BARU] Rate Limiter Khusus KDS (Mencegah 429 Too Many Requests dari Multi-Tablet)
        RateLimiter::for('kds-poll', function (Request $request) {
            return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('kds-mutate', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // 🛡️ [BARU] Rate Limiter Khusus Auth Refresh & Dashboard Metrics (Mencegah 429 pada Multi-Device)
        RateLimiter::for('auth-refresh', function (Request $request) {
            $key = ($request->user()?->id ?: 'guest') . '_' . $request->ip();
            return Limit::perMinutes(5, 60)->by($key);
        });

        RateLimiter::for('dashboard-metrics', function (Request $request) {
            $key = ($request->user()?->id ?: 'guest') . '_' . $request->ip();
            return Limit::perMinute(180)->by($key);
        });

        // 🛡️ [BARU] Rate Limiter Khusus WhatsApp Reservation Import (Mencegah 429 Tabrakan IP NAT)
        RateLimiter::for('whatsapp-import', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // 🛡️ [BARU] Rate Limiter Khusus Menu & Stock Mutate (Mencegah 429 pada Multi-Device / Quick Taps)
        RateLimiter::for('menu-stock', function (Request $request) {
            return Limit::perMinute(240)->by($request->user()?->id ?: $request->ip());
        });

        // 🛡️ [BARU] Rate Limiter Khusus Supplier (Mencegah 429 Tabrakan IP NAT pada multi-user)
        RateLimiter::for('supplier-limiter', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // 🛡️ [BARU] Rate Limiter Khusus Table Write (Mencegah 429 Tabrakan IP NAT & Polling Status)
        RateLimiter::for('tables-write', function (Request $request) {
            $user = $request->user();
            $outletId = $request->header('Outlet-ID', $request->input('outlet_id'));
            $identity = $user
                ? 'tables_write:u:' . $user->id . ':o:' . ($outletId ?: 'default')
                : 'tables_write:ip:' . $request->ip();

            return Limit::perMinute(60)->by($identity);
        });
    }
}
