<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class MonitorWeeklyReport extends Command
{
    protected $signature = 'monitor:weekly-report {--date= : End date in YYYY-MM-DD (default: today WIB)}';
    protected $description = 'Weekly recap report to Telegram owner (every Sunday 22:00 WIB)';

    public function handle()
    {
        $tz = 'Asia/Jakarta';
        $end = $this->option('date')
            ? Carbon::parse($this->option('date'), $tz)
            : Carbon::now($tz);
        $start = $end->copy()->subDays(6); // 7 days inclusive

        $startUtc = $start->copy()->startOfDay()->utc();
        $endUtc = $end->copy()->endOfDay()->utc();
        $priorStart = $start->copy()->subDays(7)->startOfDay()->utc();
        $priorEnd = $start->copy()->subDay()->endOfDay()->utc();

        // Current week
        $cur = DB::table('transactions')
            ->whereIn('status', ['Selesai', 'Served', 'Done', 'completed', 'selesai', 'paid', 'done', 'success'])
            ->whereBetween('created_at', [$startUtc, $endUtc])
            ->selectRaw('COUNT(*) as tx, COALESCE(SUM(total_amount), 0) as gmv, COUNT(DISTINCT user_id) as active_tenants')
            ->first();

        // Prior week
        $prior = DB::table('transactions')
            ->whereIn('status', ['Selesai', 'Served', 'Done', 'completed', 'selesai', 'paid', 'done', 'success'])
            ->whereBetween('created_at', [$priorStart, $priorEnd])
            ->selectRaw('COUNT(*) as tx, COALESCE(SUM(total_amount), 0) as gmv, COUNT(DISTINCT user_id) as active_tenants')
            ->first();

        // Top 5 tenants (current week)
        $topTenants = DB::table('transactions as t')
            ->join('users as u', 't.user_id', '=', 'u.id')
            ->whereBetween('t.created_at', [$startUtc, $endUtc])
            ->whereIn('t.status', ['Selesai', 'Served', 'Done', 'completed', 'selesai', 'paid', 'done', 'success'])
            ->select(
                'u.name',
                DB::raw('COUNT(*) as tx_count'),
                DB::raw('SUM(t.total_amount) as gmv')
            )
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('gmv')
            ->limit(5)
            ->get();

        // Top 5 menu platform-wide
        $topMenus = DB::table('transaction_items as ti')
            ->join('transactions as t', 'ti.transaction_id', '=', 't.id')
            ->whereBetween('t.created_at', [$startUtc, $endUtc])
            ->whereIn('t.status', ['Selesai', 'Served', 'Done', 'completed', 'selesai', 'paid', 'done', 'success'])
            ->select(
                'ti.menu_name',
                DB::raw('SUM(ti.qty) as total_qty'),
                DB::raw('SUM(ti.qty * ti.price) as total_revenue')
            )
            ->groupBy('ti.menu_name')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get();

        // New signups this week
        $newSignups = DB::table('users')
            ->where('role', 'mitra')
            ->whereBetween('created_at', [$startUtc, $endUtc])
            ->count();

        // Churn events: tenants who had TX before this week but not this week
        $churnEvents = DB::table('users as u')
            ->where('u.role', 'mitra')
            ->where('u.account_status', 'active')
            ->whereExists(function ($q) use ($priorStart, $endUtc) {
                $q->select(DB::raw(1))
                    ->from('transactions as t')
                    ->whereColumn('t.user_id', 'u.id')
                    ->whereBetween('t.created_at', [$priorStart, $endUtc])
                    ->whereIn('t.status', ['Selesai', 'Served', 'Done', 'completed', 'selesai', 'paid', 'done', 'success']);
            })
            ->whereNotExists(function ($q) use ($startUtc, $endUtc) {
                $q->select(DB::raw(1))
                    ->from('transactions as t')
                    ->whereColumn('t.user_id', 'u.id')
                    ->whereBetween('t.created_at', [$startUtc, $endUtc])
                    ->whereIn('t.status', ['Selesai', 'Served', 'Done', 'completed', 'selesai', 'paid', 'done', 'success']);
            })
            ->select('u.name')
            ->limit(5)
            ->get();

        $txDelta = $prior->tx > 0 ? round((($cur->tx - $prior->tx) / $prior->tx) * 100, 1) : 0;
        $gmvDelta = $prior->gmv > 0 ? round((($cur->gmv - $prior->gmv) / $prior->gmv) * 100, 1) : 0;
        $tenantsDelta = $prior->active_tenants > 0 ? $cur->active_tenants - $prior->active_tenants : 0;
        $txArr = $txDelta >= 0 ? '+' : '';
        $gmvArr = $gmvDelta >= 0 ? '+' : '';
        $tArr = $tenantsDelta >= 0 ? '+' : '';
        $tDeltaSign = $tenantsDelta > 0 ? '+' : ($tenantsDelta < 0 ? '-' : '');

        $msg = "[Kaching POS - Weekly Recap]\n";
        $msg .= $start->format('d M') . " - " . $end->format('d M Y') . " (7 hari)\n";
        $msg .= "=============================\n\n";

        $msg .= "PLATFORM 7 HARI\n";
        $msg .= "  TX: " . number_format($cur->tx) . " ({$txArr}{$txDelta}% vs minggu lalu)\n";
        $msg .= "  GMV: Rp " . number_format($cur->gmv, 0, ',', '.') . " ({$gmvArr}{$gmvDelta}%)\n";
        $msg .= "  Tenant aktif: " . (int) $cur->active_tenants . " ({$tDeltaSign}{$tenantsDelta})\n";
        $msg .= "  Rata-rata harian: " . number_format($cur->tx / 7, 0) . " TX/hari\n\n";

        if ($topTenants->count() > 0) {
            $msg .= "TOP 5 TENANT (by GMV)\n";
            foreach ($topTenants as $i => $t) {
                $rank = $i + 1;
                $msg .= "  {$rank}. {$t->name} ({$t->tx_count} TX, Rp " . number_format($t->gmv, 0, ',', '.') . ")\n";
            }
            $msg .= "\n";
        }

        if ($topMenus->count() > 0) {
            $msg .= "TOP 5 MENU PLATFORM-WIDE\n";
            foreach ($topMenus as $i => $m) {
                $rank = $i + 1;
                $msg .= "  {$rank}. {$m->menu_name} (" . (int) $m->total_qty . "x) Rp " . number_format($m->total_revenue, 0, ',', '.') . "\n";
            }
            $msg .= "\n";
        }

        $msg .= "GROWTH\n";
        $msg .= "  New signups: {$newSignups} tenant baru\n";
        if ($churnEvents->count() > 0) {
            $msg .= "  Churn (sebelumnya aktif, minggu ini sepi):\n";
            foreach ($churnEvents as $c) {
                $msg .= "    - {$c->name}\n";
            }
        } else {
            $msg .= "  Churn: 0 (semua tenant aktif minggu lalu, masih aktif minggu ini)\n";
        }

        $this->sendTelegram($msg);
        $this->info("Weekly report sent for {$start->toDateString()} - {$end->toDateString()}");
    }

    private function sendTelegram(string $message): void
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');
        if (empty($token) || empty($chatId)) return;
        if (strlen($message) > 4000) {
            $message = substr($message, 0, 3950) . "\n\n... (dipotong)";
        }
        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
            ]);
        } catch (\Exception $e) {
            // silent
        }
    }
}
