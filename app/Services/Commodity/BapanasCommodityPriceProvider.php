<?php

namespace App\Services\Commodity;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BapanasCommodityPriceProvider implements CommodityPriceProviderInterface
{
    protected string $endpointUrl;
    protected int $timeoutSeconds;

    /**
     * Peta standardisasi komoditas strategis pangan F&B nasional
     */
    public const STRATEGIC_COMMODITIES = [
        'BERAS_PREMIUM' => ['name' => 'Beras Premium', 'category' => 'BAHAN_POKOK', 'raw_uom' => 'kg', 'std_uom' => 'kg', 'multiplier' => 1.0],
        'BERAS_MEDIUM' => ['name' => 'Beras Medium', 'category' => 'BAHAN_POKOK', 'raw_uom' => 'kg', 'std_uom' => 'kg', 'multiplier' => 1.0],
        'CABAI_RAWIT_MERAH' => ['name' => 'Cabai Rawit Merah', 'category' => 'BUMBU', 'raw_uom' => 'kg', 'std_uom' => 'kg', 'multiplier' => 1.0],
        'CABAI_MERAH_KERITING' => ['name' => 'Cabai Merah Keriting', 'category' => 'BUMBU', 'raw_uom' => 'kg', 'std_uom' => 'kg', 'multiplier' => 1.0],
        'BAWANG_MERAH' => ['name' => 'Bawang Merah', 'category' => 'BUMBU', 'raw_uom' => 'kg', 'std_uom' => 'kg', 'multiplier' => 1.0],
        'BAWANG_PUTIH_BONGGOL' => ['name' => 'Bawang Putih Bonggol', 'category' => 'BUMBU', 'raw_uom' => 'kg', 'std_uom' => 'kg', 'multiplier' => 1.0],
        'DAGING_AYAM_RAS' => ['name' => 'Daging Ayam Ras', 'category' => 'PROTEIN', 'raw_uom' => 'kg', 'std_uom' => 'kg', 'multiplier' => 1.0],
        'TELUR_AYAM_RAS' => ['name' => 'Telur Ayam Ras', 'category' => 'PROTEIN', 'raw_uom' => 'kg', 'std_uom' => 'kg', 'multiplier' => 1.0],
        'DAGING_SAPI_MURNI' => ['name' => 'Daging Sapi Murni', 'category' => 'PROTEIN', 'raw_uom' => 'kg', 'std_uom' => 'kg', 'multiplier' => 1.0],
        'MINYAK_GORENG_CURAH' => ['name' => 'Minyak Goreng Curah', 'category' => 'MINYAK_GULA', 'raw_uom' => 'liter', 'std_uom' => 'liter', 'multiplier' => 1.0],
        'MINYAK_GORENG_KEMASAN' => ['name' => 'Minyak Goreng Kemasan', 'category' => 'MINYAK_GULA', 'raw_uom' => 'liter', 'std_uom' => 'liter', 'multiplier' => 1.0],
        'GULA_KONSUMSI' => ['name' => 'Gula Pasir Konsumsi', 'category' => 'MINYAK_GULA', 'raw_uom' => 'kg', 'std_uom' => 'kg', 'multiplier' => 1.0],
        'TEPUNG_TERIGU_CURAH' => ['name' => 'Tepung Terigu Curah', 'category' => 'BAHAN_POKOK', 'raw_uom' => 'kg', 'std_uom' => 'kg', 'multiplier' => 1.0],
    ];

    public function __construct(
        string $endpointUrl = 'https://panelharga.badanpangan.go.id/api/harga-pangan-harian',
        int $timeoutSeconds = 3
    ) {
        $this->endpointUrl = config('services.bapanas.endpoint_url', $endpointUrl);
        $this->timeoutSeconds = (int) config('services.bapanas.timeout', $timeoutSeconds);
    }

    public function getProviderName(): string
    {
        return 'BAPANAS';
    }

    /**
     * Ambil data harga komoditas pangan dari API Bapanas
     */
    public function fetchPrices(array $options = []): array
    {
        $referenceDate = $options['reference_date'] ?? now()->toDateString();
        $regencyCity = strtoupper($options['regency_city'] ?? 'NASIONAL');
        $province = strtoupper($options['province'] ?? 'NASIONAL');
        $priceLevel = strtoupper($options['price_level'] ?? 'KONSUMEN');

        $queryParams = [
            'tanggal' => $referenceDate,
            'tingkat_harga' => $priceLevel,
            'kabupaten' => $regencyCity,
        ];

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->retry(1, 100, throw: false)
                ->get($this->endpointUrl, $queryParams);

            if (!$response->successful()) {
                Log::warning('[BapanasCommodityPriceProvider] Request failed', [
                    'status' => $response->status(),
                    'params' => $queryParams,
                ]);

                return [
                    'success' => false,
                    'provider' => $this->getProviderName(),
                    'error' => 'HTTP_' . $response->status(),
                    'data' => [],
                ];
            }

            $json = $response->json();
            $records = $this->transformPayload($json, $referenceDate, $regencyCity, $province, $priceLevel);

            return [
                'success' => true,
                'provider' => $this->getProviderName(),
                'reference_date' => $referenceDate,
                'retrieved_at' => now(),
                'data' => $records,
                'raw_payload' => $json,
            ];
        } catch (\Throwable $e) {
            Log::error('[BapanasCommodityPriceProvider] Exception during fetchPrices', [
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
     * Transform JSON response payload ke format standard Kaching
     */
    public function transformPayload(array $json, string $referenceDate, string $regencyCity, string $province, string $priceLevel): array
    {
        $rawItems = $json['data'] ?? $json['items'] ?? [];
        $records = [];

        // Jika response payload berupa list item harga
        if (is_array($rawItems)) {
            foreach ($rawItems as $item) {
                $code = strtoupper($item['commodity_code'] ?? $item['kode'] ?? '');
                $name = $item['commodity_name'] ?? $item['nama'] ?? '';
                $price = (float) ($item['price'] ?? $item['harga'] ?? 0);

                if (empty($code) || $price <= 0) {
                    continue;
                }

                $meta = self::STRATEGIC_COMMODITIES[$code] ?? [
                    'name' => $name ?: $code,
                    'category' => 'LAINNYA',
                    'raw_uom' => $item['uom'] ?? 'kg',
                    'std_uom' => 'kg',
                    'multiplier' => 1.0,
                ];

                $stdPrice = $price * ($meta['multiplier'] ?? 1.0);

                $records[] = [
                    'source' => $this->getProviderName(),
                    'commodity_code' => $code,
                    'commodity_name' => $meta['name'],
                    'category' => $meta['category'],
                    'province' => $province,
                    'regency_city' => $regencyCity,
                    'price_level' => $priceLevel,
                    'price' => $price,
                    'raw_uom' => $meta['raw_uom'],
                    'standard_uom' => $meta['std_uom'],
                    'standard_price_per_uom' => $stdPrice,
                    'reference_date' => $referenceDate,
                    'raw_payload' => $item,
                ];
            }
        }

        return $records;
    }
}
