<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketCommodityPrice extends Model
{
    use HasFactory;

    protected $table = 'market_commodity_prices';

    protected $fillable = [
        'source',
        'commodity_code',
        'commodity_name',
        'category',
        'province',
        'regency_city',
        'price_level',
        'price',
        'raw_uom',
        'standard_uom',
        'standard_price_per_uom',
        'reference_date',
        'retrieved_at',
        'raw_payload',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'standard_price_per_uom' => 'decimal:2',
        'reference_date' => 'date:Y-m-d',
        'retrieved_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    /**
     * Scope: Filter berdasarkan Kode Komoditas
     */
    public function scopeByCommodity($query, string $commodityCode)
    {
        return $query->where('commodity_code', strtoupper($commodityCode));
    }

    /**
     * Scope: Filter berdasarkan Lokasi Kota/Kabupaten
     */
    public function scopeByLocation($query, string $regencyCity)
    {
        return $query->where('regency_city', strtoupper($regencyCity));
    }

    /**
     * Scope: Filter tingkat harga (KONSUMEN, PRODUSEN, GROSIR)
     */
    public function scopeByPriceLevel($query, string $priceLevel)
    {
        return $query->where('price_level', strtoupper($priceLevel));
    }

    /**
     * Scope: Filter rentang tanggal referensi
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('reference_date', [$startDate, $endDate]);
    }
}
