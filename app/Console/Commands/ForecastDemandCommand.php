<?php

namespace App\Console\Commands;

use App\Models\Outlet;
use App\Models\User;
use App\Services\DemandForecast\DemandForecastOrchestrationService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ForecastDemandCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kaching:forecast-demand
                            {--date= : Target forecast date in WIB (YYYY-MM-DD, default: tomorrow)}
                            {--start-date= : Start date of target forecast range in WIB (YYYY-MM-DD)}
                            {--end-date= : End date of target forecast range in WIB (YYYY-MM-DD)}
                            {--horizon= : Forecast horizon in days from start date (1 to 7, default: 1)}
                            {--cutoff-date= : Explicit cutoff date override in WIB (YYYY-MM-DD)}
                            {--outlet= : Filter ID outlet tertentu}
                            {--user= : Filter ID tenant/user tertentu}
                            {--menu= : Filter comma-separated ID menu tertentu}
                            {--dry-run : Simulasi tanpa menulis hasil prediksi ke database}
                            {--limit= : Batasi jumlah menu per outlet untuk pengujian terkontrol}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Orchestrate native CatBoost and statistical fallback demand forecasting across eligible menus and outlets for specified target dates';

    protected DemandForecastOrchestrationService $orchestrationService;

    public function __construct(?DemandForecastOrchestrationService $orchestrationService = null)
    {
        parent::__construct();
        $this->orchestrationService = $orchestrationService ?? new DemandForecastOrchestrationService();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! config('services.demand_forecast.enabled', false)) {
            $this->warn("⚠️  [DISABLED] Demand forecasting is disabled via configuration (DEMAND_FORECASTING_ENABLED=false).");
            $this->info("   Untuk mengaktifkan, set DEMAND_FORECASTING_ENABLED=true di environment atau konfigurasi services.");
            return Command::SUCCESS;
        }

        $lock = Cache::lock('kaching:lock:forecast-demand', 7200);

        if (! $lock->get()) {
            $this->warn("⚠️  [LOCKED] Command kaching:forecast-demand sedang berjalan di proses lain.");
            $this->info("   Eksekusi dibatalkan untuk mencegah konflik konkurensi (command-level lock active).");
            return Command::FAILURE;
        }

        try {
            $this->info("==================================================================");
            $this->info("   KACHING NATIVE DEMAND FORECAST — BATCH ORCHESTRATION PIPELINE  ");
            $this->info("==================================================================");

            $isDryRun = (bool) $this->option('dry-run');
            $limit = $this->option('limit') ? (int) $this->option('limit') : null;

            if ($isDryRun) {
                $this->warn("⚠️  [DRY RUN MODE] Eksekusi inferensi tanpa persistensi database cache.");
            }

            if ($limit !== null && $limit > 0) {
                $this->warn("⚠️  [LIMITED RUN — TEST MODE] Dibatasi maksimal {$limit} menu per outlet.");
            }

            // 1. Resolve & Validate Target Dates & Horizon
            $targetDates = $this->resolveTargetDates();
            if ($targetDates === null) {
                return Command::FAILURE;
            }

            if (count($targetDates) > 7) {
                $this->error("❌ Horizon / rentang tanggal melebihi batas maksimum 7 hari (diminta: " . count($targetDates) . " hari).");
                Log::warning('[ForecastDemandCommand] Horizon exceeded maximum 7 days', [
                    'requested_days' => count($targetDates),
                    'dates' => $targetDates,
                ]);
                return Command::FAILURE;
            }

            // 2. Validate Explicit Cutoff Date if provided
            $explicitCutoffDate = $this->option('cutoff-date');
            if ($explicitCutoffDate !== null) {
                if (!$this->isValidDateFormat($explicitCutoffDate)) {
                    $this->error("❌ Format cutoff-date tidak valid: '{$explicitCutoffDate}'. Format wajib YYYY-MM-DD.");
                    return Command::FAILURE;
                }

                foreach ($targetDates as $tDate) {
                    if ($explicitCutoffDate >= $tDate) {
                        $this->error("❌ Temporal leakage risk: cutoff-date ({$explicitCutoffDate}) harus lebih awal dari target-date ({$tDate}).");
                        Log::warning('[ForecastDemandCommand] Temporal leakage risk detected', [
                            'cutoff_date' => $explicitCutoffDate,
                            'target_date' => $tDate,
                        ]);
                        return Command::FAILURE;
                    }
                }
            }

            // 3. Resolve Scoped Outlets and Tenants
            $targetOutletId = $this->option('outlet') ? (int) $this->option('outlet') : null;
            $targetUserId = $this->option('user') ? (int) $this->option('user') : null;
            $menuIds = $this->option('menu')
                ? array_filter(array_map('intval', explode(',', $this->option('menu'))))
                : null;

            $outletsQuery = Outlet::query();
            if ($targetOutletId) {
                $outletsQuery->where('id', $targetOutletId);
            }
            if ($targetUserId) {
                $outletsQuery->where('user_id', $targetUserId);
            }

            $outlets = $outletsQuery->get();

            if ($outlets->isEmpty()) {
                $this->error("❌ Tidak ditemukan outlet yang memenuhi kriteria filter (outlet: " . ($targetOutletId ?? 'all') . ", user: " . ($targetUserId ?? 'all') . ").");
                return Command::FAILURE;
            }

            $totalDays = count($targetDates);
            $totalOutlets = $outlets->count();

            $this->line("📅 Target Hari Forecast : {$totalDays} hari (" . implode(', ', $targetDates) . ")");
            $this->line("🏢 Target Outlet        : {$totalOutlets} outlet");
            $this->line("------------------------------------------------------------------");

            // 4. Execute Orchestration across dates & outlets
            $grandAttempted = 0;
            $grandSuccessful = 0;
            $grandFallback = 0;
            $grandInsufficientHistory = 0;
            $grandSkipped = 0;
            $grandFailed = 0;
            $grandPersisted = 0;

            foreach ($targetDates as $dIndex => $targetDate) {
                $effectiveCutoff = $explicitCutoffDate ?? Carbon::parse($targetDate, 'Asia/Jakarta')->subDay()->toDateString();
                $this->line("\n[ " . ($dIndex + 1) . "/{$totalDays} ] Target Date: <comment>{$targetDate}</comment> (Cutoff: {$effectiveCutoff})");

                foreach ($outlets as $outlet) {
                    // Tenant isolation verification
                    $user = User::find($outlet->user_id);
                    if (!$user || (int) $outlet->user_id !== (int) $user->id) {
                        $this->error("  ❌ Tenant isolation error: Outlet ID {$outlet->id} memiliki user_id invalid.");
                        Log::error('[ForecastDemandCommand] Tenant isolation error', [
                            'outlet_id' => $outlet->id,
                            'user_id' => $outlet->user_id,
                        ]);
                        $grandFailed++;
                        continue;
                    }

                    if ($targetUserId !== null && (int) $user->id !== $targetUserId) {
                        $this->error("  ❌ Tenant isolation violation: Outlet ID {$outlet->id} (User {$outlet->user_id}) mismatch target user {$targetUserId}.");
                        $grandFailed++;
                        continue;
                    }

                    try {
                        $result = $this->orchestrationService->generateAndCacheForecasts(
                            $user,
                            $outlet,
                            $targetDate,
                            $menuIds,
                            $effectiveCutoff,
                            $isDryRun,
                            $limit
                        );

                        $menusCount = $result['total_menus'];
                        $catboostCount = $result['catboost_count'];
                        $fallbackCount = $result['fallback_count'];
                        $insufficientCount = $result['insufficient_history_count'] ?? 0;
                        $failedCount = $result['failed_count'];
                        $persistedCount = $result['forecasts_generated'];

                        $grandAttempted += $menusCount;
                        $grandSuccessful += $catboostCount;
                        $grandFallback += $fallbackCount;
                        $grandInsufficientHistory += $insufficientCount;
                        $grandFailed += $failedCount;
                        $grandPersisted += $persistedCount;

                        if ($menusCount === 0) {
                            $grandSkipped++;
                            $this->line("  ℹ️  Outlet [{$outlet->id}] {$outlet->name}: <comment>0 menu aktif</comment> (Skipped)");
                        } else {
                            $this->line("  ✓ Outlet [{$outlet->id}] {$outlet->name}: {$menusCount} menus | CatBoost: {$catboostCount} | Fallback: {$fallbackCount} (Insufficient: {$insufficientCount}) | Failed: {$failedCount} | Saved: {$persistedCount}");
                        }
                    } catch (Throwable $e) {
                        $this->error("  ❌ Error memproses Outlet [{$outlet->id}]: " . $e->getMessage());
                        Log::error('[ForecastDemandCommand] Error during batch forecast processing', [
                            'outlet_id' => $outlet->id,
                            'target_date' => $targetDate,
                            'error' => $e->getMessage(),
                        ]);
                        $grandFailed++;
                    }
                }
            }

            // 5. Output Summary Table
            $this->info("\n==================================================================");
            if ($grandFailed > 0) {
                $this->warn("⚠️  RINGKASAN BATCH FORECAST (SELESAI DENGAN PERINGATAN / KEGAGALAN)");
            } else {
                $this->info("✅ RINGKASAN BATCH FORECAST SELESAI");
            }
            $this->info("==================================================================");

            $summaryRows = [
                ['Mode Eksekusi', $isDryRun ? 'DRY-RUN (Simulasi)' : 'PRODUCTION / PERSISTENT'],
                ['Limit Pengujian', $limit ? "Maksimal {$limit} menu/outlet" : 'Tidak Dibatasi (Semua Menu)'],
                ['Total Hari Target', $totalDays],
                ['Total Outlet Diproses', $totalOutlets],
                ['Total Menu Attempted', $grandAttempted],
                ['CatBoost Native Predictions (Successful)', $grandSuccessful],
                ['Statistical Fallbacks (Total)', $grandFallback],
                ['  ↳ Insufficient History (< 7 hari)', $grandInsufficientHistory],
                ['Outlet Skipped (Tanpa Menu Aktif)', $grandSkipped],
                ['Total Failed / Error Records', $grandFailed],
                ['Total Forecast Cache Rows Persisted', $isDryRun ? '0 (Dry-Run)' : $grandPersisted],
            ];

            $this->table(['Metrik Pipeline', 'Nilai'], $summaryRows);

            Log::info('[ForecastDemandCommand] Batch demand forecast finished', [
                'target_dates' => $targetDates,
                'dry_run' => $isDryRun,
                'attempted' => $grandAttempted,
                'successful' => $grandSuccessful,
                'fallback' => $grandFallback,
                'insufficient_history' => $grandInsufficientHistory,
                'failed' => $grandFailed,
                'persisted' => $grandPersisted,
            ]);

            if ($grandFailed > 0) {
                $this->error("❌ Terdapat {$grandFailed} kegagalan selama proses batch forecast.");
                return Command::FAILURE;
            }

            $this->info("✅ Seluruh proses forecasting berhasil dijalankan secara aman dan idempotent.");
            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * Resolve target dates based on command options.
     */
    protected function resolveTargetDates(): ?array
    {
        $singleDate = $this->option('date');
        $startDate = $this->option('start-date');
        $endDate = $this->option('end-date');
        $horizon = $this->option('horizon') ? (int) $this->option('horizon') : null;

        if ($horizon !== null && $horizon < 1) {
            $this->error("❌ Horizon harus minimal 1 hari.");
            return null;
        }

        if ($singleDate !== null) {
            if (!$this->isValidDateFormat($singleDate)) {
                $this->error("❌ Format date tidak valid: '{$singleDate}'. Format wajib YYYY-MM-DD.");
                return null;
            }
            if ($horizon !== null && $horizon > 1) {
                $start = Carbon::parse($singleDate);
                $dates = [];
                for ($i = 0; $i < $horizon; $i++) {
                    $dates[] = $start->copy()->addDays($i)->format('Y-m-d');
                }
                return $dates;
            }
            return [$singleDate];
        }

        if ($startDate !== null && $endDate !== null) {
            if (!$this->isValidDateFormat($startDate) || !$this->isValidDateFormat($endDate)) {
                $this->error("❌ Format start-date atau end-date tidak valid. Format wajib YYYY-MM-DD.");
                return null;
            }
            if ($startDate > $endDate) {
                $this->error("❌ start-date ({$startDate}) tidak boleh lebih besar dari end-date ({$endDate}).");
                return null;
            }
            $period = CarbonPeriod::create($startDate, $endDate);
            $dates = [];
            foreach ($period as $dt) {
                $dates[] = $dt->format('Y-m-d');
            }
            return $dates;
        }

        if ($startDate !== null && $horizon !== null) {
            if (!$this->isValidDateFormat($startDate)) {
                $this->error("❌ Format start-date tidak valid: '{$startDate}'. Format wajib YYYY-MM-DD.");
                return null;
            }
            if ($horizon < 1) {
                $this->error("❌ horizon harus bernilai minimal 1 hari.");
                return null;
            }
            $startCarbon = Carbon::parse($startDate, 'Asia/Jakarta');
            $dates = [];
            for ($i = 0; $i < $horizon; $i++) {
                $dates[] = $startCarbon->copy()->addDays($i)->format('Y-m-d');
            }
            return $dates;
        }

        if ($horizon !== null) {
            if ($horizon < 1) {
                $this->error("❌ horizon harus bernilai minimal 1 hari.");
                return null;
            }
            $startCarbon = Carbon::tomorrow('Asia/Jakarta');
            $dates = [];
            for ($i = 0; $i < $horizon; $i++) {
                $dates[] = $startCarbon->copy()->addDays($i)->format('Y-m-d');
            }
            return $dates;
        }

        // Default: Tomorrow in Asia/Jakarta
        return [Carbon::tomorrow('Asia/Jakarta')->format('Y-m-d')];
    }

    /**
     * Validate YYYY-MM-DD date string.
     */
    protected function isValidDateFormat(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $parts = explode('-', $date);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }
}
