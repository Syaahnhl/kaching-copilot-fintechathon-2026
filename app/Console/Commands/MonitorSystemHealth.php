<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\HealthCheckService;
use Illuminate\Support\Facades\Http;

class MonitorSystemHealth extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:health {--no-alert : Skip Telegram notification (used for manual/cron checks)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor system health and send Telegram alerts if unhealthy';

    /**
     * Execute the console command.
     */
    public function handle(HealthCheckService $healthService)
    {
        $result = $healthService->runChecks();
        $suppressAlert = $this->option('no-alert');

        if ($result['status'] === 'unhealthy') {
            if (!$suppressAlert) {
                $serverRole = config('app.server_role', 'local');
                if ($serverRole === 'production') {
                    $this->sendTelegramAlert($result['checks']);
                    $this->error('System is unhealthy. Alert sent to Telegram.');
                } else {
                    $this->error("System is unhealthy. Telegram alert skipped (SERVER_ROLE={$serverRole}).");
                }
            } else {
                $this->error('System is unhealthy. Alert suppressed (--no-alert).');
            }
        } else {
            $this->info('System is healthy.');
        }
    }

    private function sendTelegramAlert(array $checks)
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (empty($token) || empty($chatId)) {
            return;
        }

        $time = now()->format('Y-m-d H:i');

        $dbStatus = strtoupper($checks['database']);
        $queueStatus = strtoupper($checks['queue']);
        $diskStatus = strtoupper($checks['disk']);
        $backupStatus = strtoupper($checks['backup']);

        $message = "[Kaching Alert]\n\n";
        $message .= "Database: $dbStatus\n";
        $message .= "Queue: $queueStatus\n";
        $message .= "Disk: $diskStatus\n";
        $message .= "Backup: $backupStatus\n\n";
        $message .= "Time: $time";

        try {
            Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
            ]);
        } catch (\Exception $e) {
            // Do not crash
        }
    }
}

