<?php

namespace App\Services\Weather;

use App\Models\Outlet;
use App\Models\OutletWeatherForecast;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OutletWeatherIngestionService
{
    protected WeatherProviderInterface $provider;

    public function __construct(?WeatherProviderInterface $provider = null)
    {
        $this->provider = $provider ?? app(OpenMeteoWeatherProvider::class);
    }

    /**
     * Validasi apakah koordinat outlet sah dan berada dalam batas geografis wajar.
     */
    public function hasValidCoordinates(Outlet $outlet): bool
    {
        if ($outlet->latitude === null || $outlet->longitude === null) {
            return false;
        }

        $lat = (float) $outlet->latitude;
        $lng = (float) $outlet->longitude;

        // Tolak koordinat null island (0, 0) atau di luar batasan bumi
        if (abs($lat) < 0.0001 && abs($lng) < 0.0001) {
            return false;
        }

        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return false;
        }

        return true;
    }

    /**
     * Ingest data cuaca untuk sebuah Outlet spesifik.
     */
    public function ingestForOutlet(Outlet $outlet, array $options = []): array
    {
        if (!$this->hasValidCoordinates($outlet)) {
            Log::info("[OutletWeatherIngestionService] Outlet #{$outlet->id} skipped due to invalid/missing coordinates.");

            return [
                'outlet_id' => $outlet->id,
                'status' => 'SKIPPED_INVALID_COORDINATES',
                'records_upserted' => 0,
                'reason' => 'Outlet latitude/longitude is missing, zero, or out of range.',
            ];
        }

        $lat = (float) $outlet->latitude;
        $lng = (float) $outlet->longitude;

        $response = $this->provider->fetchForecast($lat, $lng, $options);

        if (!($response['success'] ?? false)) {
            Log::warning("[OutletWeatherIngestionService] Failed fetching weather for Outlet #{$outlet->id}", [
                'error' => $response['error'] ?? 'UNKNOWN_ERROR',
            ]);

            return [
                'outlet_id' => $outlet->id,
                'status' => 'FETCH_FAILED',
                'records_upserted' => 0,
                'error' => $response['error'] ?? 'UNKNOWN_ERROR',
            ];
        }

        $records = $response['data'] ?? [];
        $source = $response['provider'] ?? $this->provider->getProviderName();
        $publishedAt = $response['published_at'] ?? null;
        $retrievedAt = isset($options['retrieved_at']) 
            ? \Carbon\Carbon::parse($options['retrieved_at'])->setTimezone('UTC')
            : ($response['retrieved_at'] ?? \Carbon\Carbon::now('UTC'));
        $rawPayload = $response['raw_payload'] ?? null;

        $upsertCount = 0;

        foreach ($records as $item) {
            OutletWeatherForecast::updateOrCreate(
                [
                    'outlet_id' => $outlet->id,
                    'source' => $source,
                    'forecast_for_timestamp' => $item['forecast_for_timestamp'],
                    'retrieved_at' => $retrievedAt,
                    'is_forecast' => (bool) $item['is_forecast'],
                ],
                [
                    'forecast_published_at' => $publishedAt,
                    'latitude' => $item['latitude'],
                    'longitude' => $item['longitude'],
                    'temperature_c' => $item['temperature_c'],
                    'relative_humidity_pct' => $item['relative_humidity_pct'],
                    'precipitation_mm' => $item['precipitation_mm'],
                    'precipitation_probability_pct' => $item['precipitation_probability_pct'],
                    'rain_mm' => $item['rain_mm'],
                    'weather_code' => $item['weather_code'],
                    'weather_condition' => $item['weather_condition'],
                    'raw_payload' => $rawPayload,
                ]
            );

            $upsertCount++;
        }

        // Simpan cache ringkasan cuaca outlet untuk performa instan
        $this->cacheOutletWeatherSummary($outlet->id);

        return [
            'outlet_id' => $outlet->id,
            'status' => 'SUCCESS',
            'records_upserted' => $upsertCount,
            'source' => $source,
        ];
    }

    /**
     * Ingest batch untuk semua outlet aktif dengan koordinat valid.
     */
    public function ingestAllActiveOutlets(array $options = []): array
    {
        $outlets = Outlet::all();
        $summary = [
            'total_outlets' => $outlets->count(),
            'success_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'total_records_upserted' => 0,
            'details' => [],
        ];

        foreach ($outlets as $outlet) {
            $res = $this->ingestForOutlet($outlet, $options);
            $summary['details'][] = $res;

            if ($res['status'] === 'SUCCESS') {
                $summary['success_count']++;
                $summary['total_records_upserted'] += $res['records_upserted'];
            } elseif ($res['status'] === 'SKIPPED_INVALID_COORDINATES') {
                $summary['skipped_count']++;
            } else {
                $summary['failed_count']++;
            }
        }

        return $summary;
    }

    /**
     * Cache ringkasan cuaca outlet selama 120 menit.
     */
    protected function cacheOutletWeatherSummary(int $outletId): void
    {
        $cacheKey = "kaching:weather:outlet_{$outletId}:summary";

        $subQuery = OutletWeatherForecast::query()
            ->selectRaw('MAX(id) as max_id')
            ->where('outlet_id', $outletId)
            ->where('is_forecast', true)
            ->where('forecast_for_timestamp', '>=', now())
            ->groupBy('forecast_for_timestamp');

        $latestForecasts = OutletWeatherForecast::query()
            ->whereIn('id', $subQuery)
            ->orderBy('forecast_for_timestamp', 'asc')
            ->limit(24)
            ->get();

        if ($latestForecasts->isNotEmpty()) {
            Cache::put($cacheKey, [
                'outlet_id' => $outletId,
                'cached_at' => now()->toIso8601String(),
                'forecasts_count' => $latestForecasts->count(),
                'next_24h' => $latestForecasts->toArray(),
            ], now()->addMinutes(120));
        }
    }
}
