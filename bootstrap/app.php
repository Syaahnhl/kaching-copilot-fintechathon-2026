<?php

// Ensure classes under App\ are loaded from this sandbox rather than external vendor baseDir
spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $relative = substr($class, 4);
        $file = __DIR__ . '/../app/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
    }
    return false;
}, true, true);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php', // 👈 [FIX] SAKLAR UTAMA PUSHER LARAVEL 11!
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        
        // 🛡️ [FIX 419] Percayai Reverse Proxy (Nginx/Cloudflare) agar Cookie Secure HTTPS dikenali
        $middleware->trustProxies(at: '*');

        // 🛡️ [BARU] PASANG SECURITY HEADERS GLOBAL 🛡️
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // 🛡️ [BARU] PASANG TELEMETRY CAPTURE MIDDLEWARE GLOBAL
        $middleware->append(\App\Http\Middleware\DeveloperHub\TelemetryCaptureMiddleware::class);

        // 🌐 [FIX] SetLocale HARUS ada di web group, BUKAN global stack.
        // Global stack berjalan SEBELUM StartSession, sehingga session() selalu null.
        // Dengan web(append:), middleware ini dijamin berjalan SETELAH session diinisialisasi.
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
        ]);

        // 👇 TAMBAHKAN BARIS INI UNTUK MENDAFTARKAN MIDDLEWARE SUPERADMIN 👇
        $middleware->alias([
            'superadmin' => \App\Http\Middleware\CheckSuperadmin::class,
        ]);

        // [BARU] Pengecualian CSRF untuk Webhook Midtrans & Public Chatbot
        $middleware->validateCsrfTokens(except: [
            'api/midtrans/webhook',
            'midtrans/webhook',
            'chat',
            '/chat',
            'order/chat-ai',
            'api/order/chat-ai'
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 🛡️ [SECURITY] Exception & Error Handler Global
        $exceptions->render(function (\Throwable $exception, \Illuminate\Http\Request $request) {
            // 🔄 [FIX 419] Tangani error 419 CSRF Token Mismatch / Page Expired
            if ($exception instanceof \Illuminate\Session\TokenMismatchException) {
                if ($request->is('api/*') || $request->wantsJson() || $request->ajax()) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Sesi Anda telah berakhir karena halaman dibiarkan terlalu lama. Silakan muat ulang (refresh) halaman.',
                    ], 419);
                }

                return redirect()->back()->withInput($request->except('password', '_token'))
                    ->with('error', 'Sesi Anda telah berakhir karena halaman dibiarkan terlalu lama. Silakan coba lagi.');
            }

            if ($request->is('api/*') || $request->wantsJson() || $request->ajax()) {
                if ($exception instanceof \Illuminate\Validation\ValidationException) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Data tidak valid.',
                        'errors'  => $exception->errors(),
                    ], 422);
                }

                if ($exception instanceof \Illuminate\Auth\AuthenticationException) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Sesi habis, silakan login kembali.',
                    ], 401);
                }

                if ($exception instanceof \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Terlalu banyak percobaan. Coba lagi dalam 1 menit.',
                    ], 429);
                }

                if ($exception instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Endpoint tidak ditemukan.',
                    ], 404);
                }

                if ($exception instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Metode request tidak diizinkan.',
                    ], 405);
                }

                $statusCode = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;

                if ($statusCode === 419 || $exception instanceof \Illuminate\Session\TokenMismatchException) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Sesi Anda telah berakhir karena halaman dibiarkan terlalu lama. Silakan muat ulang (refresh) halaman.',
                    ], 419);
                }

                if ($statusCode === 403 || $exception instanceof \Illuminate\Auth\Access\AuthorizationException) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Akses ditolak atau Anda tidak memiliki izin untuk melakukan aksi ini.',
                    ], 403);
                }

                if ($statusCode === 401) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Sesi habis, silakan login kembali.',
                    ], 401);
                }

                // 🛡️ [FIX SAKTI HERMES 2026-07-27] Jangan log error client (404, 401, 403, 405, 419, 422, 429) ke Telegram.
                // Log hanya jika status code adalah 500 atau lebih (Internal Server Error) demi mencegah spamming scanner bot.
                if ($statusCode >= 500) {
                    Log::error('Unhandled exception', [
                        'message' => $exception->getMessage(),
                        'file'    => $exception->getFile(),
                        'line'    => $exception->getLine(),
                        'exception' => $exception, // ⚡ [BARU] Kirim objek exception agar TelegramLoggerHandler bisa mengekstrak Stacktrace!
                    ]);
                }

                return response()->json([
                    'status'  => 'error',
                    'message' => 'Terjadi kesalahan pada server. Coba beberapa saat lagi.',
                ], $statusCode);
            }
            return null;
        });
    })->create();
