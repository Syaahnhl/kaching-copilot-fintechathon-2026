<?php

namespace App\Services\Weather;

use Carbon\Carbon;

interface WeatherProviderInterface
{
    /**
     * Ambil data cuaca saat ini dan prakiraan (forecast) untuk koordinat tertentu.
     *
     * @param float $latitude
     * @param float $longitude
     * @param array $options
     * @return array Array berisi data observasi/prakiraan terstruktur
     */
    public function fetchForecast(float $latitude, float $longitude, array $options = []): array;

    /**
     * Ambil data observasi cuaca historis (actuals) tanpa lookahead leak.
     *
     * @param float $latitude
     * @param float $longitude
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function fetchHistoricalActual(float $latitude, float $longitude, Carbon $startDate, Carbon $endDate): array;

    /**
     * Nama identitas provider (e.g. 'open_meteo', 'bmkg')
     */
    public function getProviderName(): string;
}
