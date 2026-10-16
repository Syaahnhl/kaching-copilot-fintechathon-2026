<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutletWeatherForecast extends Model
{
    use HasFactory;

    protected $table = 'outlet_weather_forecasts';

    protected $fillable = [
        'outlet_id',
        'source',
        'forecast_published_at',
        'retrieved_at',
        'forecast_for_timestamp',
        'is_forecast',
        'latitude',
        'longitude',
        'temperature_c',
        'relative_humidity_pct',
        'precipitation_mm',
        'precipitation_probability_pct',
        'rain_mm',
        'weather_code',
        'weather_condition',
        'raw_payload',
    ];

    protected $casts = [
        'forecast_published_at' => 'datetime',
        'retrieved_at' => 'datetime',
        'forecast_for_timestamp' => 'datetime',
        'is_forecast' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'temperature_c' => 'decimal:2',
        'relative_humidity_pct' => 'decimal:2',
        'precipitation_mm' => 'decimal:2',
        'precipitation_probability_pct' => 'decimal:2',
        'rain_mm' => 'decimal:2',
        'weather_code' => 'integer',
        'raw_payload' => 'array',
    ];

    /**
     * Relasi ke Outlet
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * Scope: Hanya observasi aktual/historis
     */
    public function scopeActuals($query)
    {
        return $query->where('is_forecast', false);
    }

    /**
     * Scope: Hanya data prakiraan masa depan
     */
    public function scopeForecasts($query)
    {
        return $query->where('is_forecast', true);
    }

    /**
     * Scope: Filter berdasarkan outlet
     */
    public function scopeForOutlet($query, int $outletId)
    {
        return $query->where('outlet_id', $outletId);
    }

    /**
     * Scope: Filter rentang waktu target valid
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('forecast_for_timestamp', [$startDate, $endDate]);
    }

    /**
     * Scope: Point-in-time eligibility filter
     * Memastikan hanya vintage yang sudah tersedia pada atau sebelum $asOfDate yang diambil.
     */
    public function scopeAvailableAsOf($query, $asOfDate)
    {
        return $query->where('retrieved_at', '<=', $asOfDate)
            ->where(function ($q) use ($asOfDate) {
                $q->whereNull('forecast_published_at')
                  ->orWhere('forecast_published_at', '<=', $asOfDate);
            });
    }
}
