<?php

namespace Tests\Unit;

use App\Models\MarketCommodityPrice;
use App\Services\Commodity\BapanasCommodityPriceProvider;
use App\Services\Commodity\CommodityPriceProviderInterface;
use App\Services\Commodity\MarketCommodityPriceIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketCommodityPriceIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_bapanas_provider_transforms_payload_and_normalizes_uom()
    {
        $fakeApiResponse = [
            'status' => 'success',
            'data' => [
                [
                    'commodity_code' => 'CABAI_RAWIT_MERAH',
                    'commodity_name' => 'Cabai Rawit Merah',
                    'price' => 75000,
                    'uom' => 'kg',
                ],
                [
                    'commodity_code' => 'MINYAK_GORENG_CURAH',
                    'commodity_name' => 'Minyak Goreng Curah',
                    'price' => 16500,
                    'uom' => 'liter',
                ],
                [
                    'commodity_code' => 'DAGING_AYAM_RAS',
                    'commodity_name' => 'Daging Ayam Ras',
                    'price' => 38000,
                    'uom' => 'kg',
                ],
            ],
        ];

        Http::fake([
            'panelharga.badanpangan.go.id/*' => Http::response($fakeApiResponse, 200),
        ]);

        $provider = new BapanasCommodityPriceProvider();
        $result = $provider->fetchPrices([
            'reference_date' => '2026-10-04',
            'regency_city' => 'KOTA_JAKARTA_SELATAN',
            'price_level' => 'KONSUMEN',
        ]);

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['data']);

        $cabai = $result['data'][0];
        $this->assertEquals('CABAI_RAWIT_MERAH', $cabai['commodity_code']);
        $this->assertEquals('Cabai Rawit Merah', $cabai['commodity_name']);
        $this->assertEquals('BUMBU', $cabai['category']);
        $this->assertEquals(75000.0, $cabai['price']);
        $this->assertEquals('kg', $cabai['standard_uom']);
        $this->assertEquals(75000.0, $cabai['standard_price_per_uom']);

        $minyak = $result['data'][1];
        $this->assertEquals('MINYAK_GORENG_CURAH', $minyak['commodity_code']);
        $this->assertEquals('liter', $minyak['standard_uom']);
        $this->assertEquals(16500.0, $minyak['standard_price_per_uom']);
    }

    public function test_commodity_ingestion_service_upserts_idempotently()
    {
        $mockProvider = $this->createMock(CommodityPriceProviderInterface::class);
        $mockProvider->method('getProviderName')->willReturn('BAPANAS');
        $mockProvider->method('fetchPrices')->willReturn([
            'success' => true,
            'provider' => 'BAPANAS',
            'reference_date' => '2026-10-04',
            'retrieved_at' => now(),
            'data' => [
                [
                    'source' => 'BAPANAS',
                    'commodity_code' => 'BERAS_PREMIUM',
                    'commodity_name' => 'Beras Premium',
                    'category' => 'BAHAN_POKOK',
                    'province' => 'DKI_JAKARTA',
                    'regency_city' => 'KOTA_JAKARTA_SELATAN',
                    'price_level' => 'KONSUMEN',
                    'price' => 15500.0,
                    'raw_uom' => 'kg',
                    'standard_uom' => 'kg',
                    'standard_price_per_uom' => 15500.0,
                    'reference_date' => '2026-10-04',
                    'raw_payload' => ['test' => true],
                ],
                [
                    'source' => 'BAPANAS',
                    'commodity_code' => 'TELUR_AYAM_RAS',
                    'commodity_name' => 'Telur Ayam Ras',
                    'category' => 'PROTEIN',
                    'province' => 'DKI_JAKARTA',
                    'regency_city' => 'KOTA_JAKARTA_SELATAN',
                    'price_level' => 'KONSUMEN',
                    'price' => 29000.0,
                    'raw_uom' => 'kg',
                    'standard_uom' => 'kg',
                    'standard_price_per_uom' => 29000.0,
                    'reference_date' => '2026-10-04',
                    'raw_payload' => ['test' => true],
                ],
            ],
        ]);

        $service = new MarketCommodityPriceIngestionService($mockProvider);

        // First Ingestion
        $res1 = $service->ingestPrices(['reference_date' => '2026-10-04']);
        $this->assertEquals('SUCCESS', $res1['status']);
        $this->assertEquals(2, $res1['records_upserted']);
        $this->assertEquals(2, MarketCommodityPrice::count());

        // Second Ingestion (Idempotency check)
        $res2 = $service->ingestPrices(['reference_date' => '2026-10-04']);
        $this->assertEquals('SUCCESS', $res2['status']);
        $this->assertEquals(2, $res2['records_upserted']);
        $this->assertEquals(2, MarketCommodityPrice::count()); // Zero duplicate rows
    }

    public function test_different_dates_locations_and_price_levels_do_not_collide()
    {
        // 1. Jakarta Selatan Konsumen 2026-10-04
        MarketCommodityPrice::create([
            'source' => 'BAPANAS',
            'commodity_code' => 'CABAI_RAWIT_MERAH',
            'commodity_name' => 'Cabai Rawit Merah',
            'category' => 'BUMBU',
            'province' => 'DKI_JAKARTA',
            'regency_city' => 'KOTA_JAKARTA_SELATAN',
            'price_level' => 'KONSUMEN',
            'price' => 75000.0,
            'raw_uom' => 'kg',
            'standard_uom' => 'kg',
            'standard_price_per_uom' => 75000.0,
            'reference_date' => '2026-10-04',
            'retrieved_at' => now(),
        ]);

        // 2. Surabaya Konsumen 2026-10-04 (Different City)
        MarketCommodityPrice::create([
            'source' => 'BAPANAS',
            'commodity_code' => 'CABAI_RAWIT_MERAH',
            'commodity_name' => 'Cabai Rawit Merah',
            'category' => 'BUMBU',
            'province' => 'JAWA_TIMUR',
            'regency_city' => 'KOTA_SURABAYA',
            'price_level' => 'KONSUMEN',
            'price' => 68000.0,
            'raw_uom' => 'kg',
            'standard_uom' => 'kg',
            'standard_price_per_uom' => 68000.0,
            'reference_date' => '2026-10-04',
            'retrieved_at' => now(),
        ]);

        // 3. Jakarta Selatan Produsen 2026-10-04 (Different Price Level)
        MarketCommodityPrice::create([
            'source' => 'BAPANAS',
            'commodity_code' => 'CABAI_RAWIT_MERAH',
            'commodity_name' => 'Cabai Rawit Merah',
            'category' => 'BUMBU',
            'province' => 'DKI_JAKARTA',
            'regency_city' => 'KOTA_JAKARTA_SELATAN',
            'price_level' => 'PRODUSEN',
            'price' => 55000.0,
            'raw_uom' => 'kg',
            'standard_uom' => 'kg',
            'standard_price_per_uom' => 55000.0,
            'reference_date' => '2026-10-04',
            'retrieved_at' => now(),
        ]);

        // 4. Jakarta Selatan Konsumen 2026-10-03 (Different Date)
        MarketCommodityPrice::create([
            'source' => 'BAPANAS',
            'commodity_code' => 'CABAI_RAWIT_MERAH',
            'commodity_name' => 'Cabai Rawit Merah',
            'category' => 'BUMBU',
            'province' => 'DKI_JAKARTA',
            'regency_city' => 'KOTA_JAKARTA_SELATAN',
            'price_level' => 'KONSUMEN',
            'price' => 72000.0,
            'raw_uom' => 'kg',
            'standard_uom' => 'kg',
            'standard_price_per_uom' => 72000.0,
            'reference_date' => '2026-10-03',
            'retrieved_at' => now(),
        ]);

        $this->assertEquals(4, MarketCommodityPrice::count());
        $this->assertEquals(1, MarketCommodityPrice::byLocation('KOTA_SURABAYA')->count());
        $this->assertEquals(1, MarketCommodityPrice::byPriceLevel('PRODUSEN')->count());
        $this->assertEquals(3, MarketCommodityPrice::byLocation('KOTA_JAKARTA_SELATAN')->count());
    }

    public function test_commodity_ingestion_service_handles_provider_failure()
    {
        $mockProvider = $this->createMock(CommodityPriceProviderInterface::class);
        $mockProvider->method('getProviderName')->willReturn('BAPANAS');
        $mockProvider->method('fetchPrices')->willReturn([
            'success' => false,
            'provider' => 'BAPANAS',
            'error' => 'HTTP_500_INTERNAL_SERVER_ERROR',
            'data' => [],
        ]);

        $service = new MarketCommodityPriceIngestionService($mockProvider);
        $res = $service->ingestPrices();

        $this->assertEquals('FAILED', $res['status']);
        $this->assertEquals(0, $res['records_upserted']);
    }
}
