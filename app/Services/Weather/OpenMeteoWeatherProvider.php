<?php

namespace App\Services\Weather;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenMeteoWeatherProvider implements WeatherProviderInterface
{
    protected string $baseUrl;
    protected string $archiveUrl;
    protected int $timeoutSeconds;

    public function __construct(
        string $baseUrl = 'https://api.open-meteo.com/v1/forecast',
        string $archiveUrl = 'https://archive-api.open-meteo.com/v1/archive',
        int $timeoutSeconds = 3
    ) {
        $this->baseUrl = config('services.open_meteo.base_url', $baseUrl);
        $this->archiveUrl = config('services.open_meteo.archive_url', $archiveUrl);
        $this->timeoutSeconds = (int) config('services.open_meteo.timeout', $timeoutSeconds);
    }

    public function getProviderName(): string
    {
        return 'open_meteo';
    }

    /**
     * Ambil data forecast dan current weather dari Open-Meteo API.
     */
    public function fetchForecast(float $latitude, float $longitude, array $options = []): array
    {
        $timezone = $options['timezone'] ?? 'Asia/Jakarta';
        $forecastDays = $options['forecast_days'] ?? 7;

        $queryParams = [
            'latitude' => round($latitude, 6),
            'longitude' => round($longitude, 6),
            'hourly' => 'temperature_2m,relative_humidity_2m,precipitation_probability,precipitation,rain,weather_code',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max',
            'timezone' => $timezone,
            'forecast_days' => $forecastDays,
        ];

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->retry(1, 100, throw: false)
                ->get($this->baseUrl, $queryParams);

            if (!$response->successful()) {
                Log::warning('[OpenMeteoWeatherProvider] Request failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 300),
                    'lat' => $latitude,
                    'lng' => $longitude,
                ]);

                return [
                    'success' => false,
                    'provider' => $this->getProviderName(),
                    'error' => 'HTTP_' . $response->status(),
                    'data' => [],
                ];
            }

            $json = $response->json();
            $parsedData = $this->transformHourlyForecastPayload($json, $latitude, $longitude);

            return [
                'success' => true,
                'provider' => $this->getProviderName(),
                'published_at' => null, // Open-Meteo free endpoint does not provide NWP model cycle timestamp
                'retrieved_at' => Carbon::now('UTC'),
                'data' => $parsedData,
                'raw_payload' => $json,
            ];
        } catch (\Throwable $e) {
            Log::error('[OpenMeteoWeatherProvider] Exception during fetchForecast', [
                'message' => $e->getMessage(),
                'lat' => $latitude,
                'lng' => $longitude,
            ]);

            return [
                'success' => false,
                'provider' => $this->getProviderName(),
                'error' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    /**
     * Ambil data cuaca historis (actuals) dari Open-Meteo Archive API.
     */
    public function fetchHistoricalActual(float $latitude, float $longitude, Carbon $startDate, Carbon $endDate): array
    {
        $queryParams = [
            'latitude' => round($latitude, 6),
            'longitude' => round($longitude, 6),
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'hourly' => 'temperature_2m,relative_humidity_2m,precipitation,rain,weather_code',
            'timezone' => 'Asia/Jakarta',
        ];

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->retry(1, 100, throw: false)
                ->get($this->archiveUrl, $queryParams);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'provider' => $this->getProviderName(),
                    'error' => 'HTTP_' . $response->status(),
                    'data' => [],
                ];
            }

            $json = $response->json();
            $parsedData = $this->transformHourlyForecastPayload($json, $latitude, $longitude, forceHistorical: true);

            return [
                'success' => true,
                'provider' => $this->getProviderName(),
                'published_at' => null, // Open-Meteo free endpoint does not provide NWP model cycle timestamp
                'retrieved_at' => Carbon::now('UTC'),
                'data' => $parsedData,
                'raw_payload' => $json,
            ];
        } catch (\Throwable $e) {
            Log::error('[OpenMeteoWeatherProvider] Exception during fetchHistoricalActual', [
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'provider' => $this->getProviderName(),
                'error' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    /**
     * Transform payload Open-Meteo hourly ke structured records
     */
    protected function transformHourlyForecastPayload(array $json, float $latitude, float $longitude, bool $forceHistorical = false): array
    {
        $hourly = $json['hourly'] ?? [];
        $times = $hourly['time'] ?? [];
        $temperatures = $hourly['temperature_2m'] ?? [];
        $humidities = $hourly['relative_humidity_2m'] ?? [];
        $precipProbs = $hourly['precipitation_probability'] ?? [];
        $precipitations = $hourly['precipitation'] ?? [];
        $rains = $hourly['rain'] ?? [];
        $weatherCodes = $hourly['weather_code'] ?? [];

        $now = now();
        $records = [];

        foreach ($times as $index => $timeStr) {
            $timestamp = Carbon::parse($timeStr);
            $isForecast = $forceHistorical ? false : $timestamp->greaterThanOrEqualTo($now->copy()->startOfHour());

            $wmoCode = isset($weatherCodes[$index]) ? (int) $weatherCodes[$index] : null;
            $condition = $this->translateWmoCode($wmoCode);

            $records[] = [
                'forecast_for_timestamp' => $timestamp->toDateTimeString(),
                'is_forecast' => $isForecast,
                'latitude' => round($latitude, 6),
                'longitude' => round($longitude, 6),
                'temperature_c' => isset($temperatures[$index]) ? (float) $temperatures[$index] : null,
                'relative_humidity_pct' => isset($humidities[$index]) ? (float) $humidities[$index] : null,
                'precipitation_mm' => isset($precipitations[$index]) ? (float) $precipitations[$index] : null,
                'precipitation_probability_pct' => isset($precipProbs[$index]) ? (float) $precipProbs[$index] : null,
                'rain_mm' => isset($rains[$index]) ? (float) $rains[$index] : null,
                'weather_code' => $wmoCode,
                'weather_condition' => $condition,
            ];
        }

        return $records;
    }

    /**
     * Terjemahkan kode WMO Open-Meteo ke label kondisi terstandarisasi.
     */
    public function translateWmoCode(?int $code): string
    {
        if ($code === null) {
            return 'UNKNOWN';
        }

        return match (true) {
            $code === 0 => 'CLEAR',
            $code >= 1 && $code <= 3 => 'PARTLY_CLOUDY',
            $code === 45 || $code === 48 => 'FOG',
            $code >= 51 && $code <= 55 => 'DRIZZLE',
            $code >= 61 && $code <= 65 => 'RAIN',
            $code >= 80 && $code <= 82 => 'RAIN_SHOWER',
            $code >= 95 && $code <= 99 => 'THUNDERSTORM',
            default => 'OVERCAST',
        };
    }
}
