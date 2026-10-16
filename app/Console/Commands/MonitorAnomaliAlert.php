<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class MonitorAnomaliAlert extends Command
{
    protected $signature = 'monitor:anomali-alert';
    protected $description = 'Real-time anomali detector: alert when tenant TX drops >70% vs their 7-day avg';

    public function handle()
    {
        $tz = 'Asia/Jakarta';
        $now = Carbon::now($tz);
        $todayStart = $now->copy()->startOfDay()->utc();
        $nowUtc = $now->utc();
        $sevenStart = $now->copy()->subDays(7)->startOfDay()->utc();
        $yestStart = $now->copy()->subDay()->startOfDay()->utc();

        // 7-day daily avg per tenant (excluding today)
        $avgPerTenant = DB::table('transactions')
            ->select('user_id', DB::raw('COUNT(*) as tx_total'))
            ->whereBetween('created_at', [$sevenStart, $yestStart])
            ->whereIn('status', ['Selesai', 'Served', 'Done', 'completed', 'selesai', 'paid', 'done', 'success'])
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        // Today's TX per tenant
        $todayPerTenant = DB::table('transactions')
            ->select('user_id', DB::raw('COUNT(*) as tx_count'))
            ->whereBetween('created_at', [$todayStart, $nowUtc])
            ->whereIn('status', ['Selesai', 'Served', 'Done', 'completed', 'selesai', 'paid', 'done', 'success'])
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $alerts = [];
        foreach ($avgPerTenant as $userId => $row) {
            $totalTx = (int) $row->tx_total;
            $avgDaily = $totalTx / 7;
            // Only alert if average is meaningful (>= 3 TX/day)
            if ($avgDaily < 3) continue;

            $todayCount = isset($todayPerTenant[$userId]) ? (int) $todayPerTenant[$userId]->tx_count : 0;
            $expected = $avgDaily * ($now->hour / 24); // proportional to time-of-day
            // If today < 30% of proportional expected, AND it's past 10am (reasonable business hour)
            if ($now->hour >= 10 && $expected > 0 && $todayCount < $expected * 0.3) {
                $tenant = DB::table('users')->where('id', $userId)->first();
                if (!$tenant || $tenant->account_status !== 'active') continue;

                // Throttle: max 1 alert per tenant per day
                $cacheKey = "anomali_alert_sent_{$userId}_{$now->toDateString()}";
                if (Cache::has($cacheKey)) continue;

                $dropPct = $expected > 0 ? round((1 - $todayCount / $expected) * 100) : 100;
                $alerts[] = [
                    'user_id' => $userId,
                    'name' => $tenant->name,
                    'avg_daily' => round($avgDaily, 1),
                    'expected_so_far' => round($expected, 1),
                    'today' => $todayCount,
                    'drop_pct' => $dropPct,
                ];

                Cache::put($cacheKey, true, now()->endOfDay());
            }
        }

        if (count($alerts) > 0) {
            $this->sendTelegramAlert($alerts, $now);
            $this->info("Anomali alert sent: " . count($alerts) . " tenant");
        } else {
            $this->info("No anomali detected. " . count($avgPerTenant) . " active tenants checked.");
        }
    }

    private function sendTelegramAlert(array $alerts, Carbon $now): void
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');
        if (empty($token) || empty($chatId)) return;

        $msg = "[ANOMALI ALERT] - {$now->format('H:i WIB')}\n";
        $msg .= "Tenant dengan TX drop >70% dari rata-rata:\n\n";
        foreach ($alerts as $a) {
            $msg .= "- {$a['name']}\n";
            $msg .= "  Rata-rata: {$a['avg_daily']} TX/hari\n";
            $msg .= "  Expected jam ini: {$a['expected_so_far']} TX\n";
            $msg .= "  Aktual: {$a['today']} TX (-{$a['drop_pct']}%)\n\n";
        }
        $msg .= "Cek: kaching.id/login atau hubungi tenant.";

        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $msg,
            ]);
        } catch (\Exception $e) {
            // silent
        }
    }
}
