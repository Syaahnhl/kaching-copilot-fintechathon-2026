<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * UpdateLastSeen Middleware
 *
 * Update kolom last_seen_at di tabel users setiap kali user ter-autentikasi
 * melakukan request API. Digunakan untuk kalkulasi "Live Online Users"
 * di KachingBoard superadmin dashboard.
 *
 * Cara pakai: tambahkan ke grup auth:sanctum di api.php
 * atau di $middlewareGroups di Kernel.php.
 */
class UpdateLastSeen
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Hanya update jika user sudah ter-autentikasi via Sanctum
        if ($request->user()) {
            $userId = $request->user()->id;

            // Gunakan cache lock agar tidak update DB setiap hit (throttle 2 menit per user)
            $cacheKey = "last_seen_updated_{$userId}";

            if (!Cache::has($cacheKey)) {
                // Update langsung via DB query (tidak lewat Eloquent agar bypass fillable)
                \Illuminate\Support\Facades\DB::table('users')
                    ->where('id', $userId)
                    ->update(['last_seen_at' => Carbon::now()]);

                // Lock selama 2 menit — tidak perlu update setiap detik
                Cache::put($cacheKey, true, 120);
            }
        }

        return $response;
    }
}
