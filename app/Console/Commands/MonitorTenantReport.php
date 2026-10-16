<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class MonitorTenantReport extends Command
{
    protected $signature = 'monitor:tenant-report
        {--user=1 : Tenant user_id (default 1 = Mila)}
        {--date= : Date in YYYY-MM-DD (default: today WIB)}';
    protected $description = 'Send daily per-tenant resto report to Telegram (testing: Mila -> owner Tuan)';

    public function handle()
    {
        $tz = 'Asia/Jakarta';
        $userId = (int) $this->option('user');
        $today = $this->option('date')
            ? Carbon::parse($this->option('date'), $tz)
            : Carbon::now($tz);
        $yesterday = $today->copy()->subDay();
        $sevenDaysAgo = $today->copy()->subDays(7);

        $tenant = DB::table('users')->where('id', $userId)->first();
        if (!$tenant) {
            $this->error("User ID $userId not found");
            return 1;
        }

        $stats = $this->collectStats($userId, $today, $yesterday, $sevenDaysAgo);
        $message = $this->formatMessage($tenant, $stats, $today, $yesterday);

        $this->sendTelegram($message);
        $this->info("Tenant report sent: {$tenant->name} | {$stats['rev']->tx_count} TX | Rp " . number_format($stats['rev']->subtotal + $stats['rev']->sc + $stats['rev']->tip - $stats['rev']->promo_disc, 0, ',', '.'));
    }

    private function collectStats(int $userId, Carbon $today, Carbon $yesterday, Carbon $sevenDaysAgo): array
    {
        $todayStart = $today->copy()->startOfDay()->utc();
        $todayEnd = $today->copy()->endOfDay()->utc();
        $yestStart = $yesterday->copy()->startOfDay()->utc();
        $yestEnd = $yesterday->copy()->endOfDay()->utc();
        $sevenStart = $sevenDaysAgo->copy()->startOfDay()->utc();

        $txBase = DB::table('transactions')
            ->where('user_id', $userId)
            ->whereIn('status', ['Selesai', 'Served', 'Done', 'completed', 'selesai', 'paid', 'done', 'success']);

        // ---- REVENUE (hari ini) ----
        $rev = (clone $txBase)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->selectRaw('
                COUNT(*) as tx_count,
                COALESCE(SUM(subtotal), 0) as subtotal,
                COALESCE(SUM(ppn_amount), 0) as ppn,
                COALESCE(SUM(service_charge_amount), 0) as sc,
                COALESCE(SUM(tip_amount), 0) as tip,
                COALESCE(SUM(promo_discount), 0) as promo_disc,
                COALESCE(SUM(total_amount), 0) as gross_total
            ')->first();

        // ---- TRANSAKSI DETAIL ----
        $txDetail = (clone $txBase)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->selectRaw('status, COUNT(*) as cnt, payment_method, order_type')
            ->groupBy('status', 'payment_method', 'order_type')
            ->get();

        $txCancelled = (clone $txBase)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->where('status', 'cancelled')
            ->count();

        $byPayment = [];
        $byOrderType = [];
        foreach ($txDetail as $r) {
            $byPayment[$r->payment_method] = ($byPayment[$r->payment_method] ?? 0) + $r->cnt;
            $byOrderType[$r->order_type ?? 'unknown'] = ($byOrderType[$r->order_type ?? 'unknown'] ?? 0) + $r->cnt;
        }

        // ---- TOP 5 MENU ----
        $topMenus = DB::table('transaction_items as ti')
            ->join('transactions as t', 'ti.transaction_id', '=', 't.id')
            ->leftJoin('menus as m', function($join) use ($userId) {
                $join->on('ti.menu_name', '=', 'm.name')
                     ->where('m.user_id', '=', $userId);
            })
            ->where('t.user_id', $userId)
            ->whereBetween('t.created_at', [$todayStart, $todayEnd])
            ->whereIn('t.status', ['Selesai', 'Served', 'Done', 'completed', 'selesai', 'paid', 'done', 'success'])
            ->select(
                'ti.menu_name',
                DB::raw('SUM(ti.qty) as total_qty'),
                DB::raw('SUM(ti.qty * ti.price) as total_revenue'),
                DB::raw('MAX(m.unit) as unit')
            )
            ->groupBy('ti.menu_name')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get();

        // ---- BOTTOM MENU (tidak laku) ----
        $soldMenuNames = DB::table('transaction_items as ti')
            ->join('transactions as t', 'ti.transaction_id', '=', 't.id')
            ->where('t.user_id', $userId)
            ->whereBetween('t.created_at', [$todayStart, $todayEnd])
            ->whereIn('t.status', ['Selesai', 'Served', 'Done', 'completed', 'selesai', 'paid', 'done', 'success'])
            ->distinct()
            ->pluck('ti.menu_name')
            ->toArray();

        $unsoldMenus = DB::table('menus')
            ->where('user_id', $userId)
            ->where('is_available', 1)
            ->whereNotIn('name', $soldMenuNames)
            ->where('name', 'not like', 'add on%')
            ->where('name', 'not like', 'addon%')
            ->where('category', 'not like', '%add-on%')
            ->where('category', 'not like', '%addon%')
            ->where('category', 'not like', '%modifier%')
            ->select('name', 'category')
            ->limit(5)
            ->get();

        // ---- STAFF PERFORMANCE ----
        $topStaff = (clone $txBase)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->whereNotNull('cashier_name')
            ->select(
                'cashier_name',
                DB::raw('COUNT(*) as tx_count'),
                DB::raw('SUM(total_amount) as revenue')
            )
            ->groupBy('cashier_name')
            ->orderByDesc('tx_count')
            ->limit(3)
            ->get();

        // ---- CUSTOMER ----
        $uniqueCustomerIds = (clone $txBase)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->whereNotNull('customer_id')
            ->distinct()
            ->pluck('customer_id');

        $newCustomerIds = DB::table('customers')
            ->whereIn('id', $uniqueCustomerIds)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->pluck('id');
        $returningCustomerIds = $uniqueCustomerIds->diff($newCustomerIds);

        // ---- PROMO USAGE ----
        $promoUsage = (clone $txBase)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->whereNotNull('promo_code_applied')
            ->select(
                'promo_code_applied',
                DB::raw('COUNT(*) as usage_count'),
                DB::raw('SUM(promo_discount) as total_disc')
            )
            ->groupBy('promo_code_applied')
            ->get();

        // ---- PERBANDINGAN ----
        $yestRev = (clone $txBase)
            ->whereBetween('created_at', [$yestStart, $yestEnd])
            ->selectRaw('COUNT(*) as tx, COALESCE(SUM(total_amount), 0) as rev')
            ->first();

        $sevenDayAvg = (clone $txBase)
            ->whereBetween('created_at', [$sevenStart, $yestEnd])
            ->selectRaw('COUNT(*) as tx, COALESCE(SUM(total_amount), 0) as rev')
            ->first();
        $sevenDayAvgTx = $sevenDayAvg->tx / 7;
        $sevenDayAvgRev = $sevenDayAvg->rev / 7;

        // ---- JAM TERSIBUK ----
        $hourly = (clone $txBase)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->selectRaw('HOUR(created_at) as hr, COUNT(*) as cnt')
            ->groupBy('hr')
            ->orderByDesc('cnt')
            ->first();

        return compact(
            'rev', 'txCancelled', 'byPayment', 'byOrderType',
            'topMenus', 'unsoldMenus', 'topStaff',
            'uniqueCustomerIds', 'newCustomerIds', 'returningCustomerIds',
            'promoUsage',
            'yestRev', 'sevenDayAvgTx', 'sevenDayAvgRev',
            'hourly'
        );
    }

    private function formatMessage(object $tenant, array $s, Carbon $today, Carbon $yesterday): string
    {
        $rev = $s['rev'];
        $omzet = ($rev->subtotal - $rev->promo_disc) + $rev->sc + $rev->tip; // subtotal-disc + SC + Tip
        $avgTx = $rev->tx_count > 0 ? $rev->subtotal / $rev->tx_count : 0;
        $txCount = (int) $rev->tx_count;

        $txDeltaPct = $s['yestRev']->tx > 0
            ? round((($txCount - $s['yestRev']->tx) / $s['yestRev']->tx) * 100, 1)
            : 0;
        $revDeltaPct = $s['yestRev']->rev > 0
            ? round((($omzet - $s['yestRev']->rev) / $s['yestRev']->rev) * 100, 1)
            : 0;

        $msg = "[Laporan Harian Resto] - {$tenant->name}\n";
        $msg .= $today->locale('id')->isoFormat('dddd, D MMMM YYYY') . "\n";
        $msg .= "=============================\n\n";

        $msg .= "REVENUE\n";
        $msg .= "  Omzet: Rp " . number_format($omzet, 0, ',', '.') . "\n";
        $msg .= "    - Subtotal: Rp " . number_format($rev->subtotal, 0, ',', '.') . "\n";
        $msg .= "    - Service Charge: Rp " . number_format($rev->sc, 0, ',', '.') . "\n";
        $msg .= "    - Tip: Rp " . number_format($rev->tip, 0, ',', '.') . "\n";
        $msg .= "    - Diskon promo: -Rp " . number_format($rev->promo_disc, 0, ',', '.') . "\n";
        $msg .= "  PPN (diteruskan ke pemerintah): Rp " . number_format($rev->ppn, 0, ',', '.') . "\n";
        $msg .= "  Gross (customer bayar): Rp " . number_format($rev->gross_total, 0, ',', '.') . "\n";
        $msg .= "  Avg / transaksi: Rp " . number_format($avgTx, 0, ',', '.') . "\n\n";

        $msg .= "TRANSAKSI\n";
        $msg .= "  Total: $txCount | Batal: {$s['txCancelled']}\n";
        if (!empty($s['byOrderType'])) {
            $parts = [];
            foreach ($s['byOrderType'] as $k => $v) $parts[] = "$k: $v";
            $msg .= "  Tipe: " . implode(' | ', $parts) . "\n";
        }
        if (!empty($s['byPayment'])) {
            $parts = [];
            foreach ($s['byPayment'] as $k => $v) $parts[] = "$k: $v";
            $msg .= "  Bayar: " . implode(' | ', $parts) . "\n";
        }
        $msg .= "\n";

        if ($s['topMenus']->count() > 0) {
            $msg .= "TOP 5 MENU HARI INI\n";
            foreach ($s['topMenus'] as $i => $m) {
                $rank = $i + 1;
                $unit = $m->unit ?: 'x';
                $qtyFormatted = (strtolower($unit) === 'gram') 
                    ? number_format($m->total_qty, 0, ',', '.') . ' ' . $unit
                    : (int) $m->total_qty . $unit;
                
                if ($unit === 'x') {
                    $qtyFormatted = (int) $m->total_qty . 'x';
                } elseif (in_array(strtolower($unit), ['pcs', 'porsi', 'pax', 'piring', 'gelas', 'botol'])) {
                    $qtyFormatted = (int) $m->total_qty . ' ' . $unit;
                }

                $msg .= "  {$rank}. {$m->menu_name} ({$qtyFormatted}) Rp " . number_format($m->total_revenue, 0, ',', '.') . "\n";
            }
            $msg .= "\n";
        }

        if ($s['unsoldMenus']->count() > 0) {
            $msg .= "MENU TIDAK LAKU (perlu evaluasi)\n";
            foreach ($s['unsoldMenus'] as $m) {
                $msg .= "  - {$m->name} ({$m->category})\n";
            }
            $msg .= "\n";
        }

        if ($s['topStaff']->count() > 0) {
            $msg .= "STAFF PERFORMANCE\n";
            foreach ($s['topStaff'] as $st) {
                $msg .= "  - {$st->cashier_name}: {$st->tx_count} TX, Rp " . number_format($st->revenue, 0, ',', '.') . "\n";
            }
            $msg .= "\n";
        }

        $newC = count($s['newCustomerIds']);
        $retC = count($s['returningCustomerIds']);
        $totalC = $newC + $retC;
        if ($totalC > 0) {
            $msg .= "CUSTOMER\n";
            $msg .= "  Total: $totalC customer unik\n";
            $msg .= "  Baru: $newC | Returning: $retC\n\n";
        }

        if ($s['promoUsage']->count() > 0) {
            $msg .= "PROMO DIGUNAKAN\n";
            foreach ($s['promoUsage'] as $p) {
                $msg .= "  - {$p->promo_code_applied}: {$p->usage_count}x (hemat customer Rp " . number_format($p->total_disc, 0, ',', '.') . ")\n";
            }
            $msg .= "\n";
        }

        $msg .= "PERBANDINGAN\n";
        $txArr = $txDeltaPct >= 0 ? '+' : '';
        $revArr = $revDeltaPct >= 0 ? '+' : '';
        $msg .= "  Vs kemarin: {$txArr}{$txDeltaPct}% TX, {$revArr}{$revDeltaPct}% omzet\n";
        $sevenArr = $s['sevenDayAvgTx'] > 0 && $txCount > $s['sevenDayAvgTx'] ? '+' : '';
        $sevenPct = $s['sevenDayAvgTx'] > 0
            ? round((($txCount - $s['sevenDayAvgTx']) / $s['sevenDayAvgTx']) * 100, 1)
            : 0;
        $msg .= "  Vs rata-rata 7 hari: {$sevenArr}{$sevenPct}% TX\n";
        if ($s['hourly']) {
            $hr = str_pad($s['hourly']->hr, 2, '0', STR_PAD_LEFT);
            $msg .= "  Jam tersibuk: {$hr}:00 ({$s['hourly']->cnt} TX)\n";
        }

        return $msg;
    }

    private function sendTelegram(string $message): void
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id'); // Tuan's chat for testing
        if (empty($token) || empty($chatId)) {
            $this->error('Telegram token/chat_id not configured');
            return;
        }
        if (strlen($message) > 4000) {
            $message = substr($message, 0, 3950) . "\n\n... (dipotong)";
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
}
