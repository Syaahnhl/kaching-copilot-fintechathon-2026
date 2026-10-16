<?php

namespace App\Console\Commands;

use App\Models\Outlet;
use App\Services\Weather\OutletWeatherIngestionService;
use Illuminate\Console\Command;

class IngestWeatherCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kaching:ingest-weather
                            {--outlet= : ID outlet spesifik (opsional)}
                            {--days=7 : Jumlah hari forecast (1-14)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ingest real-time weather observation dan 7-day forecast dari Open-Meteo per outlet';

    /**
     * Execute the console command.
     */
    public function handle(OutletWeatherIngestionService $ingestionService): int
    {
        $outletId = $this->option('outlet');
        $forecastDays = (int) $this->option('days');

        $this->info('Starting weather data ingestion...');

        if ($outletId) {
            $outlet = Outlet::find($outletId);
            if (!$outlet) {
                $this->error("Outlet #{$outletId} not found.");
                return Command::FAILURE;
            }

            $result = $ingestionService->ingestForOutlet($outlet, ['forecast_days' => $forecastDays]);
            $this->line("Outlet #{$outletId} result: [{$result['status']}] Upserted: {$result['records_upserted']} records.");
        } else {
            $summary = $ingestionService->ingestAllActiveOutlets(['forecast_days' => $forecastDays]);
            $this->info("Batch Weather Ingestion Completed.");
            $this->line("Total Outlets: {$summary['total_outlets']}");
            $this->line("Success: {$summary['success_count']}");
            $this->line("Skipped (no coordinates): {$summary['skipped_count']}");
            $this->line("Failed: {$summary['failed_count']}");
            $this->line("Total records upserted: {$summary['total_records_upserted']}");
        }

        return Command::SUCCESS;
    }
}
