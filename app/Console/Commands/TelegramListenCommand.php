<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\HealthCheckService;
use App\Models\Transaction;
use Carbon\Carbon;

class TelegramListenCommand extends Command
{
    protected $signature = 'telegram:listen';
    protected $description = 'Listen to Telegram commands for admin tasks + expense logging';

    public function handle()
    {
        $botToken = config('services.telegram.bot_token');
        $allowedChatId = config('services.telegram.chat_id');

        if (empty($botToken) || empty($allowedChatId)) {
            $this->error('Telegram bot token or chat ID is not configured.');
            return Command::FAILURE;
        }

        try {
            $offset = Cache::get('telegram_update_offset', 0);
        } catch (\Exception $e) {
            $offset = 0;
            $this->warn("Cache unavailable, starting offset at 0.");
        }

        $url = "https://api.telegram.org/bot{$botToken}/getUpdates";
        $this->info("Starting Kaching Bot Daemon (admin + expense logger)...");

        while (true) {
            try {
                $response = Http::timeout(35)->get($url, [
                    'offset' => $offset,
                    'timeout' => 30
                ]);

                if ($response->successful()) {
                    $updates = $response->json('result');

                    foreach ($updates as $update) {
                        $updateId = $update['update_id'];
                        $offset = $updateId + 1;

                        try {
                            Cache::put('telegram_update_offset', $offset, now()->addDays(7));
                        } catch (\Exception $e) {
                            // Ignore cache failure
                        }

                        if (!isset($update['message']['text'])) {
                            continue;
                        }

                        $message = $update['message'];
                        $chatId = (string) $message['chat']['id'];
                        $text = trim($message['text']);

                        if ($chatId !== (string) $allowedChatId) {
                            Log::warning("Telegram from unauthorized chat: {$chatId}. Message: {$text}");
                            continue;
                        }

                        $this->processCommand($text, $chatId, $botToken);
                    }
                } else {
                    $this->error("Failed to fetch updates. HTTP " . $response->status());
                    sleep(5);
                }
            } catch (\Exception $e) {
                $this->error("Exception: " . $e->getMessage());
                sleep(5);
            }
            sleep(1);
        }
    }

    private function processCommand(string $commandText, string $chatId, string $botToken)
    {
        $replyText = "";
        $command = strtolower(explode('@', $commandText)[0]);

        // Slash commands
        switch ($command) {
            case '/help':
            case '/start':
                $replyText = "🤖 *Kaching POS Bot*\n\n"
                    . "*Admin commands:*\n"
                    . "`/status` - System health\n"
                    . "`/backup` - Latest backup info\n"
                    . "`/sales` - Today's transactions\n\n"
                    . "*Expense logger:*\n"
                    . "Kirim teks bebas, contoh:\n"
                    . "  `lele 16k` → catat lele Rp 16.000\n"
                    . "  `bayar listrik 250rb` → Rp 250.000\n"
                    . "  `ayam 50k kategori bahan baku`\n\n"
                    . "*Expense commands:*\n"
                    . "`/today` - Pengeluaran hari ini\n"
                    . "`/bulan` - Rekap bulan ini\n"
                    . "`/expenses` - List 10 terakhir";
                break;

            case '/status':
                $healthService = new HealthCheckService();
                $health = $healthService->runChecks();
                $statusIcon = $health['status'] === 'healthy' ? '✅' : '❌';
                $replyText = "🏥 *System Status* {$statusIcon}\n\n";
                foreach ($health['checks'] as $key => $value) {
                    $valIcon = in_array(strtolower($value), ['ok', 'healthy']) ? '✅' : (str_contains($value, '%') ? 'ℹ️' : '❌');
                    $replyText .= ucfirst($key) . ": {$valIcon} " . strtoupper($value) . "\n";
                }
                break;

            case '/backup':
                $replyText = $this->cmdBackup();
                break;

            case '/sales':
                $replyText = $this->cmdSales();
                break;

            case '/today':
                $replyText = $this->cmdToday();
                break;

            case '/bulan':
                $replyText = $this->cmdMonth();
                break;

            case '/expenses':
                $replyText = $this->cmdExpensesList();
                break;

            default:
                // Natural language expense: "lele 16k", "bayar listrik 250rb", etc.
                $parsed = $this->parseExpense($commandText);
                if ($parsed) {
                    $replyText = $this->saveExpense($parsed);
                } else {
                    $replyText = "Tidak dikenali. Kirim seperti: `lele 16k` atau `bayar listrik 250rb`. Ketik /help untuk bantuan.";
                }
                break;
        }

        $this->sendMessage($chatId, $botToken, $replyText);
    }

    // ================== EXPENSE PARSER ==================

    private function parseExpense(string $text): ?array
    {
        // Strip leading slash if any
        $text = trim(preg_replace('/^\/expense\s*/i', '', $text));
        if (empty($text)) return null;

        // Extract optional category: "... kategori X" or "... category: X"
        $category = null;
        if (preg_match('/\s+(kategori|category)\s*[:=]?\s*([^\s].*?)$/iu', $text, $catMatch)) {
            $category = ucfirst(trim($catMatch[2]));
            $text = trim(preg_replace('/\s+(kategori|category)\s*[:=]?\s*([^\s].*?)$/iu', '', $text));
        }

        // Parse amount: find number with optional suffix
        // Match: 16k, 16K, 16rb, 16.000, 1.5jt, 2juta, 500, etc.
        $patterns = [
            // 1.5jt / 1,5jt
            '/(\d{1,3}(?:[.,]\d{1,3})?)\s*(jt|juta)\b/i',
            // 16k / 16K / 16rb
            '/(\d{1,3}(?:[.,]\d{1,3})?)\s*(k|rb|ribu)\b/i',
            // 1.500.000 or 1,500,000 (Indonesian thousands)
            '/(\d{1,3}(?:[.,]\d{3}){1,3})\b/',
            // bare number
            '/(\d{2,9})\b/',
        ];

        $amount = null;
        $matchedText = '';
        foreach ($patterns as $p) {
            if (preg_match($p, $text, $m)) {
                $matchedText = $m[0];
                $raw = $m[1];
                $suffix = strtolower($m[2] ?? '');
                // Normalize: 1,5jt = 1.5jt, etc.
                $rawNormalized = str_replace(',', '.', $raw);
                $num = (float) $rawNormalized;
                if ($suffix === 'jt' || $suffix === 'juta') {
                    $amount = (int) ($num * 1_000_000);
                } elseif (in_array($suffix, ['k', 'rb', 'ribu'], true)) {
                    $amount = (int) ($num * 1_000);
                } else {
                    // No suffix: if has thousand separator, treat as thousands. Else raw.
                    if (preg_match('/^[0-9]{1,3}([.,][0-9]{3})+$/', $raw)) {
                        $amount = (int) str_replace([',', '.'], '', $raw);
                    } else {
                        $amount = (int) $num;
                    }
                }
                break;
            }
        }

        if ($amount === null || $amount < 100) {
            return null;
        }

        // Description = text minus the amount
        $desc = trim(preg_replace('/' . preg_quote($matchedText, '/') . '/i', '', $text, 1));
        $desc = trim(preg_replace('/\s+/', ' ', $desc));
        if (empty($desc)) {
            $desc = 'Pengeluaran';
        }

        return [
            'name' => ucfirst($desc),
            'amount' => $amount,
            'category' => $category ?? $this->guessCategory($desc),
        ];
    }

    private function guessCategory(string $desc): string
    {
        $d = strtolower($desc);
        $map = [
            'listrik' => 'Operasional', 'air' => 'Operasional', 'internet' => 'Operasional', 'wifi' => 'Operasional',
            'sewa' => 'Sewa', 'rent' => 'Sewa',
            'gaji' => 'Gaji', 'salary' => 'Gaji', 'upah' => 'Gaji',
            'bahan' => 'Bahan Baku', 'ayam' => 'Bahan Baku', 'daging' => 'Bahan Baku', 'sayur' => 'Bahan Baku',
            'beras' => 'Bahan Baku', 'minyak' => 'Bahan Baku', 'gas' => 'Bahan Baku', 'lele' => 'Bahan Baku',
            'telur' => 'Bahan Baku', 'susu' => 'Bahan Baku', 'gula' => 'Bahan Baku', 'garam' => 'Bahan Baku',
            'promosi' => 'Marketing', 'iklan' => 'Marketing', 'ads' => 'Marketing', 'brosur' => 'Marketing',
            'atk' => 'Supplies', 'tissue' => 'Supplies', 'sabun' => 'Supplies', 'pembersih' => 'Supplies',
            'service' => 'Service', 'perbaikan' => 'Service', 'montir' => 'Service', 'teknisi' => 'Service',
            'transport' => 'Transport', 'bensin' => 'Transport', 'gojek' => 'Transport', 'grab' => 'Transport', 'parkir' => 'Transport', 'tol' => 'Transport',
        ];
        foreach ($map as $keyword => $cat) {
            if (str_contains($d, $keyword)) return $cat;
        }
        return 'Lain-lain';
    }

    private function saveExpense(array $parsed): string
    {
        $userId = config('services.telegram.bot_tenant_id', 1); // default Mila for testing
        $today = Carbon::now('Asia/Jakarta')->toDateString();

        $id = DB::table('expenses')->insertGetId([
            'user_id' => $userId,
            'outlet_id' => 0,
            'name' => $parsed['name'],
            'amount' => $parsed['amount'],
            'category' => $parsed['category'],
            'date' => $today,
            'note' => 'Logged via Telegram bot',
            'supplier' => null,
            'is_reimburse' => 0,
            'is_recurring' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'fund_source' => 'Laci Kasir',
            'receipt_image' => null,
            'qty' => 1,
            'unit_price' => $parsed['amount'],
        ]);

        // Today's total
        $todayTotal = DB::table('expenses')
            ->where('user_id', $userId)
            ->where('date', $today)
            ->sum('amount');
        $todayCount = DB::table('expenses')
            ->where('user_id', $userId)
            ->where('date', $today)
            ->count();

        return "✅ *Expense logged*\n\n"
            . "ID: #{$id}\n"
            . "Item: {$parsed['name']}\n"
            . "Amount: Rp " . number_format($parsed['amount'], 0, ',', '.') . "\n"
            . "Category: {$parsed['category']}\n\n"
            . "📊 *Hari ini:*\n"
            . "Total: Rp " . number_format((float) $todayTotal, 0, ',', '.') . "\n"
            . "Count: {$todayCount} item\n\n"
            . "Detail: kaching.id/expenses";
    }

    // ================== EXPENSE COMMANDS ==================

    private function cmdToday(): string
    {
        $userId = config('services.telegram.bot_tenant_id', 1);
        $today = Carbon::now('Asia/Jakarta')->toDateString();

        $items = DB::table('expenses')
            ->where('user_id', $userId)
            ->where('date', $today)
            ->orderBy('created_at', 'desc')
            ->get();

        $total = $items->sum('amount');
        $count = $items->count();

        $msg = "📅 *Pengeluaran Hari Ini* (" . Carbon::now('Asia/Jakarta')->format('d M Y') . ")\n\n";
        $msg .= "Total: Rp " . number_format((float) $total, 0, ',', '.') . "\n";
        $msg .= "Count: {$count} item\n\n";
        if ($items->count() > 0) {
            foreach ($items->take(10) as $i) {
                $msg .= "- {$i->name} (Rp " . number_format($i->amount, 0, ',', '.') . ")\n";
            }
            if ($items->count() > 10) $msg .= "_... +" . ($items->count() - 10) . " more_\n";
        } else {
            $msg .= "_Belum ada expense hari ini_\n";
        }
        return $msg;
    }

    private function cmdMonth(): string
    {
        $userId = config('services.telegram.bot_tenant_id', 1);
        $startOfMonth = Carbon::now('Asia/Jakarta')->startOfMonth()->toDateString();
        $endOfMonth = Carbon::now('Asia/Jakarta')->endOfMonth()->toDateString();

        $items = DB::table('expenses')
            ->where('user_id', $userId)
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->get();

        $total = $items->sum('amount');
        $count = $items->count();

        $byCat = $items->groupBy('category')->map(fn($g) => $g->sum('amount'))->sortDesc();

        $msg = "📆 *Rekap Bulan Ini* (" . Carbon::now('Asia/Jakarta')->format('M Y') . ")\n\n";
        $msg .= "Total: Rp " . number_format((float) $total, 0, ',', '.') . "\n";
        $msg .= "Count: {$count} item\n\n";
        if ($byCat->count() > 0) {
            $msg .= "*By category:*\n";
            foreach ($byCat as $cat => $sum) {
                $pct = $total > 0 ? round(($sum / $total) * 100) : 0;
                $msg .= "- {$cat}: Rp " . number_format($sum, 0, ',', '.') . " ({$pct}%)\n";
            }
        }
        return $msg;
    }

    private function cmdExpensesList(): string
    {
        $userId = config('services.telegram.bot_tenant_id', 1);
        $items = DB::table('expenses')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        if ($items->isEmpty()) {
            return "Belum ada expense tercatat. Kirim teks seperti `lele 16k` untuk mulai.";
        }
        $msg = "📋 *10 Expense Terakhir*\n\n";
        foreach ($items as $i) {
            $d = Carbon::parse($i->date)->format('d M');
            $msg .= "- [{$d}] {$i->name} (Rp " . number_format($i->amount, 0, ',', '.') . ")\n";
        }
        return $msg;
    }

    private function cmdBackup(): string
    {
        $backupDir = storage_path('app/backups');
        if (!is_dir($backupDir)) {
            $backupDir = '/www/backup/kaching';
        }
        if (!is_dir($backupDir)) return "❌ Backup dir not found.";
        $files = glob($backupDir . '/kaching-backup-*.tar.gz');
        if (!$files) return "❌ No backup files found.";
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $newest = $files[0];
        $date = date('Y-m-d H:i:s', filemtime($newest));
        $sizeMb = round(filesize($newest) / (1024 * 1024), 2);
        return "💾 *Latest Backup*\n\n"
            . "File: `" . basename($newest) . "`\n"
            . "Date: {$date}\n"
            . "Size: {$sizeMb} MB";
    }

    private function cmdSales(): string
    {
        $today = Carbon::now('Asia/Jakarta')->format('Y-m-d');
        $completedStatuses = Transaction::getCompletedStatuses();
        $transactions = Transaction::whereDate('created_at_device', $today)
            ->whereIn('status', $completedStatuses)
            ->get();
        $count = $transactions->count();
        $totalRevenue = $transactions->sum('total_amount');
        return "💰 *Sales Report (" . Carbon::now('Asia/Jakarta')->format('d M Y') . ")*\n\n"
            . "Transactions: {$count}\n"
            . "Total Revenue: Rp " . number_format($totalRevenue, 0, ',', '.');
    }

    private function sendMessage(string $chatId, string $botToken, string $text)
    {
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        try {
            Http::timeout(10)->post($url, [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send Telegram reply: " . $e->getMessage());
        }
    }
}
