<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule; // Import ini

Artisan::command('inspire', function () {
    $this->comment(Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');

// [BARU] Jadwalkan si Tukang Sapu Absen
// Berjalan setiap hari jam 02:00 Pagi server.
Schedule::command('app:prune-attendance')->dailyAt('02:00');
// [HERMES 2026-06-17] Disabled to stop Telegram spam. Replaced by /root/kaching-recovery/healthcheck.sh
// Schedule::command('monitor:health')->everyFiveMinutes();

// [HERMES 2026-06-17] Daily owner report to Telegram (22:00 WIB / 15:00 UTC)
// Laporan harian: tenant activity, platform GMV, top tenant, churn risk, support, system
Schedule::command('monitor:daily-report')
    ->dailyAt('15:00')
    ->timezone('UTC')
    ->name('daily-owner-report')
    ->withoutOverlapping();

// [HERMES 2026-06-18] Per-tenant resto report (Mila testing) -> owner Telegram
Schedule::command('monitor:tenant-report --user=1')
    ->dailyAt('15:00')
    ->timezone('UTC')
    ->name('tenant-daily-mila')
    ->withoutOverlapping();

// [HERMES 2026-06-18] Anomali alert real-time (hourly, only during business hours 9-21 WIB)
Schedule::command('monitor:anomali-alert')
    ->hourly()
    ->between('02:00', '14:00')  // 02:00 UTC = 09:00 WIB, 14:00 UTC = 21:00 WIB
    ->timezone('UTC')
    ->name('anomali-alert')
    ->withoutOverlapping();

// [HERMES 2026-06-18] Customer churn alert (daily at 12:00 WIB)
Schedule::command('monitor:churn-alert')
    ->dailyAt('05:00')  // 05:00 UTC = 12:00 WIB
    ->timezone('UTC')
    ->name('churn-alert')
    ->withoutOverlapping();

// [HERMES 2026-06-18] Weekly recap (every Sunday 22:00 WIB = 15:00 UTC Sunday)
Schedule::command('monitor:weekly-report')
    ->weeklyOn(0, '15:00')  // 0 = Sunday
    ->timezone('UTC')
    ->name('weekly-recap')
    ->withoutOverlapping();

// 📊 [DEVELOPER HUB] API Telemetry Batch Processor & Log Auto-Pruner
Schedule::job(new \App\Jobs\DeveloperHub\ProcessTelemetryBatchJob)->everyMinute();
Schedule::call(function (\App\Repositories\DeveloperHub\TelemetryRepository $repo) {
    $repo->pruneRawLogs(48);
})->dailyAt('03:00')->name('developer-hub-telemetry-prune');

// =========================================================================
// 📈 [DEMAND FORECAST & DAILY SALES AGGREGATION PIPELINE]
// =========================================================================
// Step 1: Agregasi penjualan harian pada 02:45 WIB (Asia/Jakarta)
// Meringkas transaksi harian kemarin (D-1) ke dalam tabel daily_menu_sales_summaries.
Schedule::command('kaching:summarize-daily-sales')
    ->dailyAt('02:45')
    ->timezone('Asia/Jakarta')
    ->name('kaching-daily-sales-summary')
    ->withoutOverlapping(120)
    ->onSuccess(function () {
        $yesterdayWib = \Carbon\Carbon::yesterday('Asia/Jakarta')->toDateString();
        \Illuminate\Support\Facades\Log::info('[Scheduler] kaching:summarize-daily-sales completed successfully for business date: ' . $yesterdayWib);
    })
    ->onFailure(function () {
        $yesterdayWib = \Carbon\Carbon::yesterday('Asia/Jakarta')->toDateString();
        $errorPayload = [
            'status' => 'FAILED',
            'business_date' => $yesterdayWib,
            'scope' => 'ALL_OUTLETS',
            'completed_at' => \Carbon\Carbon::now('Asia/Jakarta')->toIso8601String(),
        ];
        \Illuminate\Support\Facades\Cache::put("kaching:scheduler:daily_sales_summary_status:{$yesterdayWib}", $errorPayload, \Carbon\Carbon::now('Asia/Jakarta')->addHours(24));
        \Illuminate\Support\Facades\Cache::put('kaching:scheduler:daily_sales_summary_status', $errorPayload, \Carbon\Carbon::now('Asia/Jakarta')->addHours(24));
        \Illuminate\Support\Facades\Log::error('[Scheduler] kaching:summarize-daily-sales failed. Downstream demand forecast will be aborted.');
    });

// Step 2: Demand Forecast inferensi harian pada 03:00 WIB (Asia/Jakarta)
// Menghasilkan prediksi permintaan menu 7 hari ke depan (horizon 7 hari).
// Gated dependency: hanya berjalan jika agregasi penjualan harian kemarin telah selesai & sukses untuk seluruh outlet (TASK-07.2 SSOT).
Schedule::command('kaching:forecast-demand --horizon=7')
    ->dailyAt('03:00')
    ->timezone('Asia/Jakarta')
    ->name('kaching-demand-forecast')
    ->withoutOverlapping(120)
    ->when(function () {
        if (! config('services.demand_forecast.enabled', false)) {
            \Illuminate\Support\Facades\Log::info('[Scheduler] kaching:forecast-demand skipped: DEMAND_FORECASTING_ENABLED is false.');
            return false;
        }

        $expectedSummaryDate = \Carbon\Carbon::yesterday('Asia/Jakarta')->toDateString();
        $status = \Illuminate\Support\Facades\Cache::get("kaching:scheduler:daily_sales_summary_status:{$expectedSummaryDate}")
            ?? \Illuminate\Support\Facades\Cache::get('kaching:scheduler:daily_sales_summary_status');

        // Validasi ketat token status kelengkapan agregasi (TASK-07.2 SSOT)
        if (
            is_array($status) &&
            isset($status['status'], $status['business_date'], $status['scope'], $status['completed_at']) &&
            $status['status'] === 'COMPLETED' &&
            $status['business_date'] === $expectedSummaryDate &&
            $status['scope'] === 'ALL_OUTLETS'
        ) {
            \Illuminate\Support\Facades\Log::info('[Scheduler] kaching:forecast-demand allowed. Daily sales summary completed for all outlets.', [
                'business_date' => $expectedSummaryDate,
                'completed_at' => $status['completed_at'],
                'items_count' => $status['items_count'] ?? 0,
                'created_records' => $status['created_records'] ?? 0,
            ]);
            return true;
        }

        \Illuminate\Support\Facades\Log::warning('[Scheduler] kaching:forecast-demand aborted: daily sales summary for ' . $expectedSummaryDate . ' is missing, incomplete, or failed.', [
            'expected_summary_date' => $expectedSummaryDate,
            'cached_status' => $status,
        ]);
        return false;
    });

// Step 3: Ingest Prakiraan & Observasi Cuaca Outlet setiap 3 jam (Asia/Jakarta)
// Memperbarui cache 7-day weather forecast dari Open-Meteo untuk seluruh outlet dengan koordinat sah
Schedule::command('kaching:ingest-weather --days=7')
    ->everyThreeHours()
    ->timezone('Asia/Jakarta')
    ->name('kaching-ingest-weather')
    ->withoutOverlapping(60)
    ->runInBackground();

// Step 4: Ingest Data Harga Pasar Komoditas Pangan Harian pada 07:00 WIB (Asia/Jakarta)
// Mengambil update harga pangan harian Bapanas / pasar eksternal
Schedule::command('kaching:ingest-commodity-prices --city=NASIONAL --level=KONSUMEN')
    ->dailyAt('07:00')
    ->timezone('Asia/Jakarta')
    ->name('kaching-ingest-commodity-prices')
    ->withoutOverlapping(60)
    ->runInBackground();
