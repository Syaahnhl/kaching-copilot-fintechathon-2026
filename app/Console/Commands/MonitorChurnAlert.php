<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class MonitorChurnAlert extends Command
{
    protected $signature = 'monitor:churn-alert';
    protected $description = 'Customer churn alert: notify when tenant goes idle (was active, now quiet for 3+ days)';

    public function handle()
    {
        $tz = 'Asia/Jakarta';
        $now = Carbon::now($tz);
        $nowUtc = $now->utc();
        $threeDaysAgo = $now->copy()->subDays(3)->utc();
        $sevenDaysAgo = $now->copy()->subDays(7)->utc();

        // Tenants with TX in last 7 days but no activity in last 3 days
        // (i.e., recently active but now quiet)
        $candidates = DB::table('transactions as t')
            ->join('users as u', 't.user_id', '=', 'u.id')
            ->where('t.created_at', '>=', $sevenDaysAgo)
            ->where('u.account_status', 'active')
            ->whereIn('t.status', ['completed', 'selesai', 'paid', 'done', 'success'])
            ->select(
                'u.id',
                'u.name',
                'u.subscription_plan',
                DB::raw('MAX(t.created_at) as last_tx_at'),
                DB::raw('COUNT(t.id) as tx_last_7d')
            )
            ->groupBy('u.id', 'u.name', 'u.subscription_plan')
            // Only those whose LAST transaction is older than 3 days
            ->havingRaw('last_tx_at < ?', [$threeDaysAgo])
            ->get();

        $alerts = [];
        foreach ($candidates as $c) {
            $lastActive = Carbon::parse($c->last_tx_at)->setTimezone($tz);
            $daysIdle = $lastActive->diffInDays($now);

            // Throttle: only alert once per tenant per 7 days
            $cacheKey = "churn_alert_sent_{$c->id}";
            if (Cache::has($cacheKey)) continue;

            $alerts[] = [
                'id' => $c->id,
                'name' => $c->name,
                'plan' => $c->subscription_plan,
                'last_active' => $lastActive->format('d M H:i'),
                'days_idle' => (int) $daysIdle,
                'tx_last_7d' => (int) $c->tx_last_7d,
            ];
            // Mark as alerted (re-alert in 7 days)
            Cache::put($cacheKey, true, now()->addDays(7));
        }

        if (count($alerts) > 0) {
            $this->sendTelegramAlert($alerts, $now);
            $this->info("Churn alert sent: " . count($alerts) . " tenant");
        } else {
            $this->info("No new churn risk. " . count($candidates) . " candidate(s) checked.");
        }
    }

    private function sendTelegramAlert(array $alerts, Carbon $now): void
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');
        if (empty($token) || empty($chatId)) return;

        $msg = "[CHURN ALERT] - {$now->format('d M H:i WIB')}\n";
        $msg .= "Tenant yang biasanya aktif, sekarang idle:\n\n";
        foreach ($alerts as $a) {
            $msg .= "- {$a['name']} ({$a['plan']})\n";
            $msg .= "  Terakhir TX: {$a['last_active']} ({$a['days_idle']} hari lalu)\n";
            $msg .= "  7 hari terakhir: {$a['tx_last_7d']} TX\n\n";
        }
        $msg .= "Aksi: follow up via WA, tawarkan bantuan atau promo retention.";

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
