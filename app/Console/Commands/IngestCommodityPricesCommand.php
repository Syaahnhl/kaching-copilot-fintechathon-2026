<?php

namespace App\Console\Commands;

use App\Services\Commodity\MarketCommodityPriceIngestionService;
use Illuminate\Console\Command;

class IngestCommodityPricesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kaching:ingest-commodity-prices
                            {--date= : Tanggal referensi (YYYY-MM-DD, default hari ini)}
                            {--city=NASIONAL : Nama kabupaten/kota atau NASIONAL}
                            {--level=KONSUMEN : Tingkat harga (KONSUMEN/PRODUSEN/GROSIR)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ingest data harga komoditas pangan harian dari Panel Harga Bapanas / pasar eksternal';

    /**
     * Execute the console command.
     */
    public function handle(MarketCommodityPriceIngestionService $ingestionService): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        $city = $this->option('city');
        $level = $this->option('level');

        $this->info("Starting commodity price ingestion for date: {$date}, city: {$city}, level: {$level}...");

        $result = $ingestionService->ingestPrices([
            'reference_date' => $date,
            'regency_city' => $city,
            'price_level' => $level,
        ]);

        if ($result['status'] === 'SUCCESS') {
            $this->info("Successfully ingested {$result['records_upserted']} commodity price records from [{$result['provider']}].");
            return Command::SUCCESS;
        }

        $this->error("Commodity price ingestion failed: {$result['error']}");
        return Command::FAILURE;
    }
}
