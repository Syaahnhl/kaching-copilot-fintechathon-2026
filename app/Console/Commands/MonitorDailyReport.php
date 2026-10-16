<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class MonitorDailyReport extends Command
{
    protected $signature = 'monitor:daily-report {--date= : Date in YYYY-MM-DD (default: today WIB)}';
    protected $description = 'Send daily SaaS platform report to Telegram owner (Kaching POS founder)';

    public function handle()
    {
        $tz = 'Asia/Jakarta';
        $today = $this->option('date')
            ? Carbon::parse($this->option('date'), $tz)
            : Carbon::now($tz);
        $yesterday = $today->copy()->subDay();

        $stats = $this->collectStats($today, $yesterday);
        $message = $this->formatMessage($stats, $today);

        $this->sendTelegram($message);
        $this->info("Daily report sent for {$today->toDateString()} ({$stats['activeTenantsToday']} active tenants, {$stats['txToday']} TX, Rp " . number_format($stats['gmvToday'], 0, ',', '.') . " GMV)");
    }

    private function collectStats(Carbon $today, Carbon $yesterday): array
    {
        $todayStart = $today->copy()->startOfDay()->utc();
        $todayEnd = $today->copy()->endOfDay()->utc();
        $yestStart = $yesterday->copy()->startOfDay()->utc();
        $yestEnd = $yesterday->copy()->endOfDay()->utc();

        // ---- TENANTS ----
        $totalTenants = DB::table('users')
            ->where('role', 'mitra')
            ->where('account_status', 'active')
            ->count();

        $newSignups = DB::table('users')
            ->where('role', 'mitra')
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->count();

        $trialTenants = DB::table('users')
            ->where('role', 'mitra')
            ->where('subscription_plan', 'trial')
            ->count();

        $expireToday = DB::table('users')
            ->where('role', 'mitra')
            ->whereDate('subscription_end_date', $today->toDateString())
            ->count();

        $expireSoon = DB::table('users')
            ->where('role', 'mitra')
            ->whereBetween('subscription_end_date', [
                $today->copy()->addDay()->toDateString(),
                $today->copy()->addDays(7)->toDateString()
            ])
            ->count();

        $churnRisk = DB::table('users')
            ->where('role', 'mitra')
            ->where('account_status', 'active')
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $today->copy()->subDays(14)->utc())
            ->count();

        // ---- PLATFORM TX ----
        $txToday = (int) DB::table('transactions')
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->count();
        $gmvToday = (float) DB::table('transactions')
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->sum('total_amount');
        $txYesterday = (int) DB::table('transactions')
            ->whereBetween('created_at', [$yestStart, $yestEnd])
            ->count();
        $gmvYesterday = (float) DB::table('transactions')
            ->whereBetween('created_at', [$yestStart, $yestEnd])
            ->sum('total_amount');
        $cancelledTx = (int) DB::table('transactions')
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->where('status', 'cancelled')
            ->count();

        $activeTenantsToday = (int) DB::table('transactions')
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->distinct()
            ->count('user_id');

        // ---- TOP 5 TENANTS ----
        $topTenants = DB::table('transactions')
            ->select(
                'users.name as name',
                DB::raw('COUNT(*) as tx_count'),
                DB::raw('SUM(total_amount) as gmv')
            )
            ->join('users', 'transactions.user_id', '=', 'users.id')
            ->whereBetween('transactions.created_at', [$todayStart, $todayEnd])
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('tx_count')
            ->limit(5)
            ->get();

        // ---- SUPPORT TICKETS ----
        $openTickets = (int) DB::table('tickets')->where('status', 'open')->count();
        $newTickets = (int) DB::table('tickets')
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->count();

        // ---- ANOMALI: tenant dgn TX turun >50% vs rata-rata ----
        $anomali = $this->detectAnomali($today, $yesterday, $yestStart, $yestEnd);

        // ---- SYSTEM ----
        $failedJobs = (int) DB::table('failed_jobs')->count();
        $diskPercent = $this->getDiskUsage();
        $backupStatus = $this->getBackupStatus();

        return compact(
            'totalTenants', 'newSignups', 'trialTenants', 'expireToday', 'expireSoon', 'churnRisk',
            'txToday', 'gmvToday', 'txYesterday', 'gmvYesterday', 'cancelledTx', 'activeTenantsToday',
            'topTenants', 'openTickets', 'newTickets', 'anomali',
            'failedJobs', 'diskPercent', 'backupStatus'
        );
    }

    private function detectAnomali(Carbon $today, Carbon $yesterday, $yestStart, $yestEnd): array
    {
        // Bandingkan TX per tenant hari ini vs kemarin
        // Tenant yang TX-nya turun >50% dibanding kemarin
        $yestPerTenant = DB::table('transactions')
            ->select('user_id', DB::raw('COUNT(*) as cnt'))
            ->whereBetween('created_at', [$yestStart, $yestEnd])
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $todayPerTenant = DB::table('transactions')
            ->select('user_id', DB::raw('COUNT(*) as cnt'))
            ->whereDate('created_at', $today->toDateString())
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $anomali = [];
        foreach ($yestPerTenant as $uid => $row) {
            $yestCnt = (int) $row->cnt;
            if ($yestCnt < 5) continue; // skip tenant kecil
            $todayCnt = isset($todayPerTenant[$uid]) ? (int) $todayPerTenant[$uid]->cnt : 0;
            if ($todayCnt < $yestCnt * 0.5) {
                $name = DB::table('users')->where('id', $uid)->value('name');
                $anomali[] = [
                    'name' => $name ?? "User#$uid",
                    'yesterday' => $yestCnt,
                    'today' => $todayCnt,
                    'drop' => $yestCnt > 0 ? round((1 - $todayCnt / $yestCnt) * 100) : 100,
                ];
            }
        }
        return $anomali;
    }

    private function formatMessage(array $s, Carbon $today): string
    {
        $txDelta = $s['txYesterday'] > 0
            ? round((($s['txToday'] - $s['txYesterday']) / $s['txYesterday']) * 100, 1)
            : ($s['txToday'] > 0 ? 100.0 : 0.0);
        $gmvDelta = $s['gmvYesterday'] > 0
            ? round((($s['gmvToday'] - $s['gmvYesterday']) / $s['gmvYesterday']) * 100, 1)
            : ($s['gmvToday'] > 0 ? 100.0 : 0.0);

        $txArrow = $txDelta >= 0 ? '+' : '';
        $gmvArrow = $gmvDelta >= 0 ? '+' : '';

        $msg = "[Kaching POS - Laporan Harian Owner]\n";
        $msg .= $today->format('d M Y') . " (WIB)\n";
        $msg .= "=============================\n\n";

        $msg .= "CUSTOMER (TENANT)\n";
        $msg .= "  Aktif: {$s['totalTenants']} tenant\n";
        $msg .= "  Baru signup: " . ($s['newSignups'] > 0 ? "+{$s['newSignups']} customer baru" : '-') . "\n";
        $msg .= "  Trial: {$s['trialTenants']} tenant\n";
        $msg .= "  Expire 1-7 hari: {$s['expireSoon']} tenant\n";
        $msg .= "  Expire hari ini: " . ($s['expireToday'] > 0 ? "{$s['expireToday']} tenant" : '-') . "\n";
        $msg .= "  Churn risk: " . ($s['churnRisk'] > 0 ? "{$s['churnRisk']} tenant (idle >14hr)" : '-') . "\n\n";

        $msg .= "TRANSAKSI PLATFORM\n";
        $msg .= "  TX hari ini: {$s['txToday']} ({$txArrow}{$txDelta}% vs kemarin)\n";
        $msg .= "  GMV: Rp " . number_format($s['gmvToday'], 0, ',', '.') . " ({$gmvArrow}{$gmvDelta}%)\n";
        $msg .= "  Tenant aktif: {$s['activeTenantsToday']} / {$s['totalTenants']}\n";
        $msg .= "  TX batal: {$s['cancelledTx']}\n\n";

        if ($s['topTenants']->count() > 0) {
            $msg .= "TOP 5 TENANT HARI INI\n";
            foreach ($s['topTenants'] as $i => $t) {
                $rank = $i + 1;
                $msg .= "  {$rank}. {$t->name} ({$t->tx_count} TX, Rp " . number_format($t->gmv, 0, ',', '.') . ")\n";
            }
            $msg .= "\n";
        }

        if (count($s['anomali']) > 0) {
            $msg .= "ANOMALI (TX turun >50%)\n";
            foreach (array_slice($s['anomali'], 0, 3) as $a) {
                $msg .= "  - {$a['name']}: {$a['yesterday']}→{$a['today']} TX (-{$a['drop']}%)\n";
            }
            $msg .= "\n";
        }

        $msg .= "SUPPORT\n";
        $ticketLine = "  Tiket terbuka: {$s['openTickets']}";
        if ($s['newTickets'] > 0) $ticketLine .= " (+{$s['newTickets']} baru)";
        $msg .= $ticketLine . "\n\n";

        $msg .= "SYSTEM\n";
        $msg .= "  Queue: " . ($s['failedJobs'] > 0 ? "{$s['failedJobs']} FAILED" : 'OK') . "\n";
        $msg .= "  Disk: {$s['diskPercent']}%\n";
        $msg .= "  Backup: {$s['backupStatus']}\n";

        return $msg;
    }

    private function sendTelegram(string $message): void
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');
        if (empty($token) || empty($chatId)) {
            $this->error('Telegram token/chat_id not configured');
            return;
        }

        // Telegram max 4096 char per message; truncate if needed
        if (strlen($message) > 4000) {
            $message = substr($message, 0, 3950) . "\n\n... (dipotong, lihat dashboard untuk detail)";
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
            ]);
            if (!$response->successful()) {
                $this->error('Telegram send failed: ' . $response->body());
            } else {
                $this->info('Telegram message sent OK');
            }
        } catch (\Exception $e) {
            $this->error('Telegram exception: ' . $e->getMessage());
        }
    }

    private function getDiskUsage(): int
    {
        $total = @disk_total_space('/');
        $free = @disk_free_space('/');
        if (!$total || !$free) return 0;
        return (int) round((1 - $free / $total) * 100);
    }

    private function getBackupStatus(): string
    {
        $dir = storage_path('app/backups');
        if (!is_dir($dir)) return 'MISSING';
        $files = glob($dir . '/kaching-backup-*.tar.gz');
        if (!$files) return 'NONE';
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $newest = $files[0];
        $ageHours = (time() - filemtime($newest)) / 3600;
        $sizeMb = filesize($newest) / (1024 * 1024);
        if ($ageHours < 26 && $sizeMb > 1) return 'OK';
        if ($ageHours < 50) return 'OLD';
        return 'STALE';
    }
}
