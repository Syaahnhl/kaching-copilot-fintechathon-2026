<?php

namespace App\Jobs;

use App\Services\Commodity\MarketCommodityPriceIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchMarketCommodityPricesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    protected array $options;

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    public function handle(MarketCommodityPriceIngestionService $ingestionService): void
    {
        $result = $ingestionService->ingestPrices($this->options);
        Log::info('[FetchMarketCommodityPricesJob] Completed commodity price ingestion', $result);
    }
}
