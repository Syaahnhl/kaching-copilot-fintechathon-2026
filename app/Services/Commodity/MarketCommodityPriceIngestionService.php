<?php

namespace App\Services\Commodity;

use App\Models\MarketCommodityPrice;
use Illuminate\Support\Facades\Log;

class MarketCommodityPriceIngestionService
{
    protected CommodityPriceProviderInterface $provider;

    public function __construct(?CommodityPriceProviderInterface $provider = null)
    {
        $this->provider = $provider ?? app(BapanasCommodityPriceProvider::class);
    }

    /**
     * Ingest harga komoditas pasar harian secara idempotent.
     */
    public function ingestPrices(array $options = []): array
    {
        $response = $this->provider->fetchPrices($options);

        if (!($response['success'] ?? false)) {
            Log::warning('[MarketCommodityPriceIngestionService] Commodity price ingestion failed', [
                'provider' => $this->provider->getProviderName(),
                'error' => $response['error'] ?? 'UNKNOWN_ERROR',
            ]);

            return [
                'status' => 'FAILED',
                'provider' => $this->provider->getProviderName(),
                'records_upserted' => 0,
                'error' => $response['error'] ?? 'UNKNOWN_ERROR',
            ];
        }

        $records = $response['data'] ?? [];
        $source = $response['provider'] ?? $this->provider->getProviderName();
        $retrievedAt = $response['retrieved_at'] ?? now();
        $upsertCount = 0;

        foreach ($records as $item) {
            $refDate = \Carbon\Carbon::parse($item['reference_date'])->toDateString();

            MarketCommodityPrice::updateOrCreate(
                [
                    'source' => $source,
                    'commodity_code' => $item['commodity_code'],
                    'regency_city' => $item['regency_city'],
                    'price_level' => $item['price_level'],
                    'reference_date' => $refDate,
                ],
                [
                    'commodity_name' => $item['commodity_name'],
                    'category' => $item['category'],
                    'province' => $item['province'],
                    'price' => $item['price'],
                    'raw_uom' => $item['raw_uom'],
                    'standard_uom' => $item['standard_uom'],
                    'standard_price_per_uom' => $item['standard_price_per_uom'],
                    'retrieved_at' => $retrievedAt,
                    'raw_payload' => $item['raw_payload'] ?? null,
                ]
            );

            $upsertCount++;
        }

        return [
            'status' => 'SUCCESS',
            'provider' => $source,
            'records_upserted' => $upsertCount,
            'reference_date' => $response['reference_date'] ?? now()->toDateString(),
        ];
    }
}
