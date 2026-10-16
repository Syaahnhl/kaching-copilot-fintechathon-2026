<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckSuperadmin
{
    /**
     * Protect /superadmin/* routes.
     *
     * RCA 2026-07-26: sidebar Keuangan SaaS / Paket often 302'd to /dashboard
     * when the browser still held an impersonation session (role=owner) or a
     * BFCache'd kaching-board tab. Auto-restore the original superadmin when
     * possible; never silently dump a non-admin into merchant backoffice
     * without an explicit leave-impersonate path.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect('/login');
        }

        $user = Auth::user();

        // Still impersonating a mitra → restore superadmin before serving board.
        if ($user->role !== 'superadmin' && $request->session()->has('impersonated_by')) {
            $adminId = (int) $request->session()->get('impersonated_by');
            $admin = User::find($adminId);

            if ($admin && $admin->role === 'superadmin') {
                Auth::loginUsingId($admin->id);
                $request->session()->forget('impersonated_by');
                $request->session()->regenerate(true);
                $user = Auth::user();
            } else {
                $request->session()->forget('impersonated_by');
            }
        }

        if (! $user || $user->role !== 'superadmin') {
            // Prefer login over merchant /dashboard so a stale owner session
            // does not look like "superadmin menu opens backoffice".
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->withErrors([
                'email' => 'Sesi Superadmin tidak valid atau sudah diganti ke akun toko. Login ulang sebagai Superadmin.',
            ]);
        }

        $response = $next($request);

        // Stop BFCache / back-button from replaying a stale board after impersonate.
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
