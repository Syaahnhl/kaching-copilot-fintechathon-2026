<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CrashAlertController extends Controller
{
    public function reportCrash(Request $request)
    {
        $request->validate([
            'app_version' => 'required|string',
            'version_code' => 'required',
            'device' => 'required|string',
            'user' => 'nullable|string',
            'error_message' => 'required|string',
            'stack_trace' => 'required|string',
            'active_staff' => 'nullable|string',
            'active_outlet' => 'nullable',
        ]);

        $botToken = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID') ?? '-1004335595529'; // Default ke group kita jika kosong
        $threadId = env('TELEGRAM_THREAD_ID');

        // Fallback ke thread 10 jika chat_id default group dan thread_id kosong
        if ($chatId === '-1004335595529' && empty($threadId)) {
            $threadId = '10';
        }

        if (empty($botToken)) {
            Log::warning("Crash report received but TELEGRAM_BOT_TOKEN is not configured.");
            return response()->json(['status' => 'skipped', 'message' => 'Telegram token not configured'], 200);
        }

        $appVersion = $request->input('app_version');
        $versionCode = $request->input('version_code');
        $device = $request->input('device');
        $user = $request->input('user') ?? 'Unknown User';
        $staff = $request->input('active_staff') ?? '-';
        $outlet = $request->input('active_outlet') ?? '-';
        $errorMessage = $request->input('error_message');
        $stackTrace = substr($request->input('stack_trace'), 0, 1500); // Batasi 1500 karakter biar muat di Telegram

        $message = "🚨 *CRASH REPORT DETECTED* 🚨\n\n" .
            "📱 *Device:* {$device}\n" .
            "📦 *App Version:* v{$appVersion} (code {$versionCode})\n" .
            "👤 *User:* {$user}\n" .
            "👥 *Active Staff:* {$staff}\n" .
            "🏪 *Outlet ID:* {$outlet}\n\n" .
            "🔥 *Error:* `{$errorMessage}`\n\n" .
            "📋 *Stack Trace (Truncated):*\n```text\n{$stackTrace}\n```";

        try {
            $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
            $payload = [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
            ];

            // Tambahkan message_thread_id jika targetnya adalah group dengan supergroup thread
            if (!empty($threadId) && str_starts_with($chatId, '-')) {
                $payload['message_thread_id'] = (int)$threadId;
            }

            $response = Http::timeout(10)->post($url, $payload);

            if ($response->successful()) {
                return response()->json(['status' => 'success'], 200);
            }

            Log::error("Failed to forward crash alert to Telegram: " . $response->body());
            return response()->json(['status' => 'error', 'message' => 'Telegram API failed'], 502);

        } catch (\Exception $e) {
            Log::error("Exception while sending crash alert to Telegram: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
