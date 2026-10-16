<?php

namespace App\Jobs;

use App\Models\Outlet;
use App\Services\Weather\OutletWeatherIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchOutletWeatherJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    protected ?int $outletId;
    protected array $options;

    public function __construct(?int $outletId = null, array $options = [])
    {
        $this->outletId = $outletId;
        $this->options = $options;
    }

    public function handle(OutletWeatherIngestionService $ingestionService): void
    {
        if ($this->outletId !== null) {
            $outlet = Outlet::find($this->outletId);
            if (!$outlet) {
                Log::warning("[FetchOutletWeatherJob] Outlet #{$this->outletId} not found.");
                return;
            }

            $result = $ingestionService->ingestForOutlet($outlet, $this->options);
            Log::info("[FetchOutletWeatherJob] Completed for Outlet #{$this->outletId}", $result);
        } else {
            $summary = $ingestionService->ingestAllActiveOutlets($this->options);
            Log::info('[FetchOutletWeatherJob] Completed batch weather ingestion', $summary);
        }
    }
}
