<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HealthCheckService
{
    public function runChecks(): array
    {
        $status = 'healthy';
        $checks = [
            'database' => 'ok',
            'queue' => 'ok',
            'storage' => 'ok',
            'backup' => 'ok',
            'disk' => 'ok',
        ];

        // 1. Database
        try {
            DB::select('SELECT 1');
        } catch (\Exception $e) {
            $checks['database'] = 'failed';
            $status = 'unhealthy';
        }

        // 2. Queue
        try {
            if (!Schema::hasTable('failed_jobs')) {
                // Table doesn't exist, no failed jobs
            } else {
                $failedJobs = DB::table('failed_jobs')->count();
                if ($failedJobs > 0) {
                    $checks['queue'] = 'failed';
                    $status = 'unhealthy';
                }
            }
        } catch (\Exception $e) {
            $checks['queue'] = 'failed';
            $status = 'unhealthy';
        }

        // 3. Storage
        try {
            if (!is_writable(storage_path())) {
                $checks['storage'] = 'failed';
                $status = 'unhealthy';
            }
        } catch (\Exception $e) {
            $checks['storage'] = 'failed';
            $status = 'unhealthy';
        }

        // 4. Backup
        try {
            $backupDir = storage_path('app/backups');
            $backupOk = false;
            $backupWarn = null;
            if (is_dir($backupDir)) {
                $files = glob($backupDir . '/kaching-backup-*.tar.gz');
                if ($files && count($files) > 0) {
                    usort($files, function ($a, $b) {
                        return filemtime($b) - filemtime($a);
                    });
                    $newest = $files[0];
                    $ageHours = (time() - filemtime($newest)) / 3600;
                    $sizeMb = filesize($newest) / (1024 * 1024);
                    if ($ageHours < 26 && $sizeMb > 1) {
                        $backupOk = true;
                    } elseif ($sizeMb > 1) {
                        $backupWarn = 'old';
                    } else {
                        $backupWarn = 'empty';
                    }
                }
            }
            if ($backupOk) {
                $checks['backup'] = 'ok';
            } elseif ($backupWarn) {
                $checks['backup'] = 'warn';
            } else {
                $checks['backup'] = 'failed';
            }} catch (\Exception $e) {
            $checks['backup'] = 'failed';
            $status = 'unhealthy';
        }

        // 5. Disk
        try {
            $diskFree = disk_free_space('/');
            $diskTotal = disk_total_space('/');
            if ($diskTotal > 0) {
                $diskUsedPct = 100 - (($diskFree / $diskTotal) * 100);
                if ($diskUsedPct > 90) {
                    $checks['disk'] = 'critical';
                    $status = 'unhealthy';
                } elseif ($diskUsedPct > 80) {
                    $checks['disk'] = round($diskUsedPct) . '%';
                    $status = 'unhealthy';
                } else {
                    $checks['disk'] = round($diskUsedPct) . '%';
                }
            } else {
                $checks['disk'] = 'unknown';
            }
        } catch (\Exception $e) {
            $checks['disk'] = 'failed';
            $status = 'unhealthy';
        }

        return [
            'status' => $status,
            'checks' => $checks
        ];
    }
}
