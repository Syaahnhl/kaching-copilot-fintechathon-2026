<?php

namespace Tests\Feature;

use App\Jobs\FetchMarketCommodityPricesJob;
use App\Jobs\FetchOutletWeatherJob;
use App\Models\Item;
use App\Models\ItemSupplierPriceHistory;
use App\Models\MarketCommodityPrice;
use App\Models\Outlet;
use App\Models\OutletWeatherForecast;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Commodity\MarketCommodityPriceIngestionService;
use App\Services\Weather\OutletWeatherIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DemandWeatherPriceIsolationAndIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'integration_merchant@test.com',
        ]);

        $this->outlet = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Kaching Grand Indonesia',
            'address' => 'Jl. M.H. Thamrin No. 1, Jakarta Pusat',
            'latitude' => -6.1950000,
            'longitude' => 106.8230000,
            'attendance_radius' => 100,
        ]);
    }

    public function test_artisan_ingest_weather_command_runs_successfully_with_mocks()
    {
        $fakeWeatherResponse = [
            'hourly' => [
                'time' => [
                    now()->addHours(1)->format('Y-m-d\TH:00'),
                    now()->addHours(2)->format('Y-m-d\TH:00'),
                ],
                'temperature_2m' => [29.0, 30.5],
                'relative_humidity_2m' => [75.0, 70.0],
                'precipitation_probability' => [30, 60],
                'precipitation' => [0.0, 4.2],
                'rain' => [0.0, 4.2],
                'weather_code' => [2, 61],
            ],
        ];

        Http::fake([
            'api.open-meteo.com/*' => Http::response($fakeWeatherResponse, 200),
        ]);

        $this->artisan('kaching:ingest-weather', ['--outlet' => $this->outlet->id])
            ->assertExitCode(0);

        $this->assertEquals(2, OutletWeatherForecast::where('outlet_id', $this->outlet->id)->count());

        $firstForecast = OutletWeatherForecast::where('outlet_id', $this->outlet->id)->first();
        $this->assertEquals('open_meteo', $firstForecast->source);
        $this->assertTrue($firstForecast->is_forecast);
    }

    public function test_artisan_ingest_commodity_prices_command_runs_successfully_with_mocks()
    {
        $fakeCommodityResponse = [
            'status' => 'success',
            'data' => [
                [
                    'commodity_code' => 'BERAS_PREMIUM',
                    'commodity_name' => 'Beras Premium',
                    'price' => 15500,
                    'uom' => 'kg',
                ],
                [
                    'commodity_code' => 'CABAI_RAWIT_MERAH',
                    'commodity_name' => 'Cabai Rawit Merah',
                    'price' => 72000,
                    'uom' => 'kg',
                ],
            ],
        ];

        Http::fake([
            'panelharga.badanpangan.go.id/*' => Http::response($fakeCommodityResponse, 200),
        ]);

        $this->artisan('kaching:ingest-commodity-prices', [
            '--date' => '2026-10-04',
            '--city' => 'KOTA_JAKARTA_PUSAT',
            '--level' => 'KONSUMEN',
        ])->assertExitCode(0);

        $this->assertEquals(2, MarketCommodityPrice::where('regency_city', 'KOTA_JAKARTA_PUSAT')->count());
        $this->assertEquals(1, MarketCommodityPrice::byCommodity('CABAI_RAWIT_MERAH')->count());
    }

    public function test_queued_jobs_execute_weather_and_commodity_ingestion()
    {
        $fakeWeatherResponse = [
            'hourly' => [
                'time' => [now()->addHours(1)->format('Y-m-d\TH:00')],
                'temperature_2m' => [28.0],
                'relative_humidity_2m' => [80.0],
                'precipitation_probability' => [10],
                'precipitation' => [0.0],
                'rain' => [0.0],
                'weather_code' => [0],
            ],
        ];

        $fakeCommodityResponse = [
            'status' => 'success',
            'data' => [
                [
                    'commodity_code' => 'GULA_KONSUMSI',
                    'commodity_name' => 'Gula Pasir Konsumsi',
                    'price' => 17500,
                    'uom' => 'kg',
                ],
            ],
        ];

        Http::fake([
            'api.open-meteo.com/*' => Http::response($fakeWeatherResponse, 200),
            'panelharga.badanpangan.go.id/*' => Http::response($fakeCommodityResponse, 200),
        ]);

        // 1. Dispatch & Handle Weather Job
        $weatherJob = new FetchOutletWeatherJob($this->outlet->id);
        $weatherJob->handle(app(OutletWeatherIngestionService::class));
        $this->assertEquals(1, OutletWeatherForecast::where('outlet_id', $this->outlet->id)->count());

        // 2. Dispatch & Handle Commodity Job
        $commodityJob = new FetchMarketCommodityPricesJob([
            'reference_date' => '2026-10-04',
            'regency_city' => 'NASIONAL',
            'price_level' => 'KONSUMEN',
        ]);
        $commodityJob->handle(app(MarketCommodityPriceIngestionService::class));
        $this->assertEquals(1, MarketCommodityPrice::byCommodity('GULA_KONSUMSI')->count());
    }

    public function test_pos_transaction_flow_has_zero_dependency_on_external_data_services()
    {
        // Pastikan alur transaksi POS kasir beroperasi normal dan instan secara independen
        // bahkan ketika seluruh HTTP external provider down / diblokir total
        Http::fake([
            '*' => Http::response('EXTERNAL_NETWORK_DOWN', 500),
        ]);

        $trx = Transaction::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'app_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'order_type' => 'Dine-in',
            'customer_name' => 'Tuan Tamu',
            'subtotal' => 50000.0,
            'discount_amount' => 0.0,
            'tax_amount' => 5500.0,
            'service_charge' => 0.0,
            'total_amount' => 55500.0,
            'pay_amount' => 60000.0,
            'change_amount' => 4500.0,
            'payment_method' => 'cash',
            'status' => 'Selesai',
            'created_at_device' => now()->toDateTimeString(),
        ]);

        $this->assertNotNull($trx->id);
        $this->assertEquals('Selesai', $trx->status);
        $this->assertEquals(55500.0, (float) $trx->total_amount);
    }
}
