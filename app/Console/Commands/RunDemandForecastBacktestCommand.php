<?php

namespace App\Console\Commands;

use App\Models\Menu;
use App\Models\Outlet;
use App\Services\DemandForecast\DemandForecastBacktestingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class RunDemandForecastBacktestCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'kaching:backtest-demand-forecast
                            {--outlet= : Specific Outlet ID to backtest}
                            {--target=transaction_count : Target type: transaction_count or menu_qty}
                            {--menu= : Specific Menu ID (required if target=menu_qty)}
                            {--start= : Start date YYYY-MM-DD (defaults to 60 days ago)}
                            {--end= : End date YYYY-MM-DD (defaults to yesterday)}
                            {--min-train=28 : Minimum training days before opening first window}
                            {--horizons=1,3,7 : Comma-separated forecast horizons in days}
                            {--json : Output result strictly as JSON}';

    /**
     * The console command description.
     */
    protected $description = 'Execute rolling-origin expanding-window backtesting comparing statistical baselines vs ML models with/without weather';

    /**
     * Execute the console command.
     */
    public function handle(DemandForecastBacktestingService $backtestService): int
    {
        $target = (string) ($this->option('target') ?: DemandForecastBacktestingService::TARGET_TRANSACTION_COUNT);
        $outletId = $this->option('outlet') ? (int) $this->option('outlet') : null;
        $menuId = $this->option('menu') ? (int) $this->option('menu') : null;

        $yesterday = Carbon::yesterday('Asia/Jakarta')->toDateString();
        $sixtyDaysAgo = Carbon::yesterday('Asia/Jakarta')->subDays(60)->toDateString();

        $startDate = (string) ($this->option('start') ?: $sixtyDaysAgo);
        $endDate = (string) ($this->option('end') ?: $yesterday);
        $minTrainDays = (int) ($this->option('min-train') ?: 28);

        $horizonsRaw = explode(',', (string) ($this->option('horizons') ?: '1,3,7'));
        $horizons = array_map('intval', array_filter($horizonsRaw, 'is_numeric'));
        if (empty($horizons)) {
            $horizons = [1, 3, 7];
        }

        if ($target === DemandForecastBacktestingService::TARGET_MENU_QTY && !$menuId) {
            $this->error("Error: --menu=<id> is required when --target=menu_qty.");
            return self::FAILURE;
        }

        // If no outlet specified, pick first active outlet
        if (!$outletId) {
            $firstOutlet = Outlet::where('status', 'active')->first() ?? Outlet::first();
            if (!$firstOutlet) {
                $this->error("Error: No outlet found in the system.");
                return self::FAILURE;
            }
            $outletId = $firstOutlet->id;
        }

        $isJson = (bool) $this->option('json');

        if (!$isJson) {
            $this->info("Starting demand forecast backtest for Outlet #{$outletId} (Target: {$target})...");
            $this->line("Date Range: {$startDate} to {$endDate} | Min Train: {$minTrainDays} days | Horizons: " . implode(',', $horizons));
        }

        try {
            $series = $backtestService->extractDailySeries($outletId, $target, $menuId, $startDate, $endDate);

            $result = $backtestService->executeRollingOriginBacktest(
                $series,
                $minTrainDays,
                $horizons,
                $outletId
            );

            if ($isJson) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT));
                return self::SUCCESS;
            }

            if ($result['status'] === 'BLOCKED_BY_DATA_GAPS') {
                $this->warn("================================================================================");
                $this->warn("STATUS: BLOCKED BY DATA GAPS");
                $this->warn("{$result['message']}");
                $this->warn("Total Days Available: {$result['total_days']} | Minimum Required: {$result['min_required_days']}");
                $this->warn("================================================================================");
                return self::SUCCESS;
            }

            $this->info("================================================================================");
            $this->info("BACKTEST COMPLETED — ROLLING-ORIGIN EXPANDING WINDOW RESULTS");
            $this->info("Total Series Days: {$result['total_days_evaluated']} | Evaluated Windows: {$result['total_windows_per_horizon']}");
            $this->info("Weather Data Nature: {$result['weather_data_nature']}");
            $this->info("================================================================================");

            foreach ($result['horizons_summary'] as $h => $summary) {
                $this->comment("--- Horizon: {$h} Day(s) Ahead (Obs: {$summary['observations_count']}) ---");

                $headers = ['Model', 'WAPE', 'MAE', 'Forecast Bias', 'Coverage'];
                $rows = [];

                foreach ($summary['models'] as $modelName => $metrics) {
                    $rows[] = [
                        strtoupper(str_replace('_', ' ', $modelName)),
                        $metrics['wape'] !== null ? number_format($metrics['wape'] * 100, 2) . '%' : 'N/A',
                        $metrics['mae'] !== null ? number_format($metrics['mae'], 2) : 'N/A',
                        $metrics['bias'] !== null ? ($metrics['bias'] >= 0 ? '+' : '') . number_format($metrics['bias'] * 100, 2) . '%' : 'N/A',
                        number_format($metrics['coverage'] * 100, 1) . '%',
                    ];
                }

                $this->table($headers, $rows);

                $rel = $summary['relative_improvements'];
                $this->line("Relative WAPE Improvements for Horizon {$h}d:");
                $this->line("  • ML (No Weather) vs DOW-Median : " . ($rel['ml_no_weather_vs_dow'] !== null ? number_format($rel['ml_no_weather_vs_dow'] * 100, 2) . '%' : 'N/A'));
                $this->line("  • ML (With Weather) vs DOW-Median: " . ($rel['ml_with_weather_vs_dow'] !== null ? number_format($rel['ml_with_weather_vs_dow'] * 100, 2) . '%' : 'N/A'));
                $this->line("  • ML (With Weather) vs ML (No W): " . ($rel['ml_with_weather_vs_no_weather'] !== null ? number_format($rel['ml_with_weather_vs_no_weather'] * 100, 2) . '%' : 'N/A'));
                $this->line("");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Backtest execution failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
