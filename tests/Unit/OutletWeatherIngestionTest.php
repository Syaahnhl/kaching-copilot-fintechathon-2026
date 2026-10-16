<?php

namespace Tests\Unit;

use App\Models\Outlet;
use App\Models\OutletWeatherForecast;
use App\Models\User;
use App\Services\Weather\OpenMeteoWeatherProvider;
use App\Services\Weather\OutletWeatherIngestionService;
use App\Services\Weather\WeatherProviderInterface;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutletWeatherIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'merchant_weather@test.com',
        ]);

        $this->outlet = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Kaching Cafe Blok M',
            'address' => 'Jl. Bulungan No. 10, Jakarta Selatan',
            'phone' => '081234567890',
            'latitude' => -6.2435000,
            'longitude' => 106.7995000,
            'attendance_radius' => 100,
        ]);
    }

    public function test_coordinate_validation_accepts_valid_coordinates()
    {
        $service = new OutletWeatherIngestionService();
        $this->assertTrue($service->hasValidCoordinates($this->outlet));
    }

    public function test_coordinate_validation_rejects_null_island_and_out_of_bounds()
    {
        $service = new OutletWeatherIngestionService();

        $nullOutlet = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Invalid Outlet 1',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
        $this->assertFalse($service->hasValidCoordinates($nullOutlet));

        $missingCoordsOutlet = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Invalid Outlet 2',
            'latitude' => null,
            'longitude' => null,
        ]);
        $this->assertFalse($service->hasValidCoordinates($missingCoordsOutlet));

        $outOfBoundsOutlet = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Invalid Outlet 3',
            'latitude' => 195.0,
            'longitude' => 45.0,
        ]);
        $this->assertFalse($service->hasValidCoordinates($outOfBoundsOutlet));
    }

    public function test_wmo_code_translation_maps_correctly()
    {
        $provider = new OpenMeteoWeatherProvider();

        $this->assertEquals('CLEAR', $provider->translateWmoCode(0));
        $this->assertEquals('PARTLY_CLOUDY', $provider->translateWmoCode(2));
        $this->assertEquals('FOG', $provider->translateWmoCode(45));
        $this->assertEquals('DRIZZLE', $provider->translateWmoCode(53));
        $this->assertEquals('RAIN', $provider->translateWmoCode(61));
        $this->assertEquals('RAIN_SHOWER', $provider->translateWmoCode(80));
        $this->assertEquals('THUNDERSTORM', $provider->translateWmoCode(95));
        $this->assertEquals('OVERCAST', $provider->translateWmoCode(10));
        $this->assertEquals('UNKNOWN', $provider->translateWmoCode(null));
    }

    public function test_open_meteo_provider_parses_forecast_and_historical_slices()
    {
        $fakeTime = Carbon::parse('2026-10-04 12:00:00');
        Carbon::setTestNow($fakeTime);

        $fakePayload = [
            'hourly' => [
                'time' => [
                    '2026-10-04T08:00', // Past observation (is_forecast = false)
                    '2026-10-04T12:00', // Current (is_forecast = true)
                    '2026-10-04T18:00', // Future forecast (is_forecast = true)
                ],
                'temperature_2m' => [28.5, 31.0, 27.2],
                'relative_humidity_2m' => [80.0, 70.0, 85.0],
                'precipitation_probability' => [10, 45, 90],
                'precipitation' => [0.0, 1.5, 12.0],
                'rain' => [0.0, 1.5, 12.0],
                'weather_code' => [1, 61, 95],
            ],
        ];

        Http::fake([
            'api.open-meteo.com/*' => Http::response($fakePayload, 200),
        ]);

        $provider = new OpenMeteoWeatherProvider();
        $res = $provider->fetchForecast(-6.2435, 106.7995);

        $this->assertTrue($res['success']);
        $this->assertCount(3, $res['data']);

        // Sumbu masa lalu: observasi aktual
        $this->assertFalse($res['data'][0]['is_forecast']);
        $this->assertEquals(28.5, $res['data'][0]['temperature_c']);
        $this->assertEquals('PARTLY_CLOUDY', $res['data'][0]['weather_condition']);

        // Sumbu masa depan: prakiraan
        $this->assertTrue($res['data'][1]['is_forecast']);
        $this->assertEquals(31.0, $res['data'][1]['temperature_c']);
        $this->assertEquals('RAIN', $res['data'][1]['weather_condition']);

        $this->assertTrue($res['data'][2]['is_forecast']);
        $this->assertEquals(12.0, $res['data'][2]['precipitation_mm']);
        $this->assertEquals('THUNDERSTORM', $res['data'][2]['weather_condition']);

        Carbon::setTestNow();
    }

    public function test_ingestion_service_upserts_weather_idempotently()
    {
        $mockProvider = $this->createMock(WeatherProviderInterface::class);
        $mockProvider->method('getProviderName')->willReturn('open_meteo');
        $mockProvider->method('fetchForecast')->willReturn([
            'success' => true,
            'provider' => 'open_meteo',
            'published_at' => Carbon::parse('2026-10-04 06:00:00'),
            'retrieved_at' => Carbon::parse('2026-10-04 06:05:00'),
            'data' => [
                [
                    'forecast_for_timestamp' => '2026-10-04 14:00:00',
                    'is_forecast' => true,
                    'latitude' => -6.2435,
                    'longitude' => 106.7995,
                    'temperature_c' => 32.5,
                    'relative_humidity_pct' => 65.0,
                    'precipitation_mm' => 0.0,
                    'precipitation_probability_pct' => 20.0,
                    'rain_mm' => 0.0,
                    'weather_code' => 0,
                    'weather_condition' => 'CLEAR',
                ],
                [
                    'forecast_for_timestamp' => '2026-10-04 15:00:00',
                    'is_forecast' => true,
                    'latitude' => -6.2435,
                    'longitude' => 106.7995,
                    'temperature_c' => 30.0,
                    'relative_humidity_pct' => 80.0,
                    'precipitation_mm' => 8.5,
                    'precipitation_probability_pct' => 85.0,
                    'rain_mm' => 8.5,
                    'weather_code' => 61,
                    'weather_condition' => 'RAIN',
                ],
            ],
            'raw_payload' => ['sample' => true],
        ]);

        $service = new OutletWeatherIngestionService($mockProvider);

        // First Ingestion
        $res1 = $service->ingestForOutlet($this->outlet);
        $this->assertEquals('SUCCESS', $res1['status']);
        $this->assertEquals(2, $res1['records_upserted']);
        $this->assertEquals(2, OutletWeatherForecast::where('outlet_id', $this->outlet->id)->count());

        // Second Ingestion (Idempotent Test)
        $res2 = $service->ingestForOutlet($this->outlet);
        $this->assertEquals('SUCCESS', $res2['status']);
        $this->assertEquals(2, $res2['records_upserted']);
        // Pastikan jumlah baris TIDAK menduplikasi
        $this->assertEquals(2, OutletWeatherForecast::where('outlet_id', $this->outlet->id)->count());

        // Verifikasi isi record
        $rainRecord = OutletWeatherForecast::where('outlet_id', $this->outlet->id)
            ->where('forecast_for_timestamp', '2026-10-04 15:00:00')
            ->first();

        $this->assertNotNull($rainRecord);
        $this->assertEquals(8.5, (float) $rainRecord->precipitation_mm);
        $this->assertEquals('RAIN', $rainRecord->weather_condition);
        $this->assertTrue($rainRecord->is_forecast);
    }

    public function test_ingestion_service_handles_provider_failure_gracefully()
    {
        $mockProvider = $this->createMock(WeatherProviderInterface::class);
        $mockProvider->method('getProviderName')->willReturn('open_meteo');
        $mockProvider->method('fetchForecast')->willReturn([
            'success' => false,
            'provider' => 'open_meteo',
            'error' => 'HTTP_503_SERVICE_UNAVAILABLE',
            'data' => [],
        ]);

        $service = new OutletWeatherIngestionService($mockProvider);
        $res = $service->ingestForOutlet($this->outlet);

        $this->assertEquals('FETCH_FAILED', $res['status']);
        $this->assertEquals(0, $res['records_upserted']);
        $this->assertEquals(0, OutletWeatherForecast::where('outlet_id', $this->outlet->id)->count());
    }

    public function test_multiple_forecast_vintages_for_same_target_coexist_without_overwriting()
    {
        $mockProvider = $this->createMock(WeatherProviderInterface::class);
        $mockProvider->method('getProviderName')->willReturn('open_meteo');
        
        $service = new OutletWeatherIngestionService($mockProvider);

        // Run 1: Ingestion at 2026-10-04 06:00:00 UTC for Target 2026-10-06 12:00:00 UTC (Horizon = 2 days)
        $mockProvider->expects($this->exactly(2))
            ->method('fetchForecast')
            ->willReturnOnConsecutiveCalls(
                [
                    'success' => true,
                    'provider' => 'open_meteo',
                    'published_at' => null,
                    'retrieved_at' => \Carbon\Carbon::parse('2026-10-04 06:00:00', 'UTC'),
                    'data' => [
                        [
                            'forecast_for_timestamp' => '2026-10-06 12:00:00',
                            'is_forecast' => true,
                            'latitude' => -6.2435,
                            'longitude' => 106.7995,
                            'temperature_c' => 31.0,
                            'relative_humidity_pct' => 70.0,
                            'precipitation_mm' => 2.0,
                            'precipitation_probability_pct' => 40.0,
                            'rain_mm' => 2.0,
                            'weather_code' => 61,
                            'weather_condition' => 'RAIN',
                        ],
                    ],
                ],
                [
                    'success' => true,
                    'provider' => 'open_meteo',
                    'published_at' => null,
                    'retrieved_at' => \Carbon\Carbon::parse('2026-10-05 06:00:00', 'UTC'),
                    'data' => [
                        [
                            'forecast_for_timestamp' => '2026-10-06 12:00:00',
                            'is_forecast' => true,
                            'latitude' => -6.2435,
                            'longitude' => 106.7995,
                            'temperature_c' => 33.0,
                            'relative_humidity_pct' => 60.0,
                            'precipitation_mm' => 0.0,
                            'precipitation_probability_pct' => 10.0,
                            'rain_mm' => 0.0,
                            'weather_code' => 0,
                            'weather_condition' => 'CLEAR',
                        ],
                    ],
                ]
            );

        // First Ingestion (Vintage Day 1)
        $res1 = $service->ingestForOutlet($this->outlet, ['retrieved_at' => '2026-10-04 06:00:00']);
        $this->assertEquals('SUCCESS', $res1['status']);
        $this->assertEquals(1, $res1['records_upserted']);

        // Second Ingestion 24h later (Vintage Day 2 for the exact same target timestamp)
        $res2 = $service->ingestForOutlet($this->outlet, ['retrieved_at' => '2026-10-05 06:00:00']);
        $this->assertEquals('SUCCESS', $res2['status']);
        $this->assertEquals(1, $res2['records_upserted']);

        // Assert: BOTH vintages must coexist in database! Total records = 2
        $vintages = OutletWeatherForecast::where('outlet_id', $this->outlet->id)
            ->where('forecast_for_timestamp', '2026-10-06 12:00:00')
            ->orderBy('retrieved_at', 'asc')
            ->get();

        $this->assertCount(2, $vintages);

        // Check Vintage 1
        $this->assertEquals('2026-10-04 06:00:00', $vintages[0]->retrieved_at->toDateTimeString());
        $this->assertEquals(31.0, (float) $vintages[0]->temperature_c);
        $this->assertEquals(2.0, (float) $vintages[0]->precipitation_mm);

        // Check Vintage 2
        $this->assertEquals('2026-10-05 06:00:00', $vintages[1]->retrieved_at->toDateTimeString());
        $this->assertEquals(33.0, (float) $vintages[1]->temperature_c);
        $this->assertEquals(0.0, (float) $vintages[1]->precipitation_mm);
    }

    public function test_partial_provider_failure_preserves_previously_persisted_vintages()
    {
        $mockProvider = $this->createMock(WeatherProviderInterface::class);
        $mockProvider->method('getProviderName')->willReturn('open_meteo');

        $service = new OutletWeatherIngestionService($mockProvider);

        // Step 1: Successful initial ingestion
        $mockProvider->expects($this->exactly(2))
            ->method('fetchForecast')
            ->willReturnOnConsecutiveCalls(
                [
                    'success' => true,
                    'provider' => 'open_meteo',
                    'retrieved_at' => \Carbon\Carbon::parse('2026-10-04 06:00:00', 'UTC'),
                    'data' => [
                        [
                            'forecast_for_timestamp' => '2026-10-05 12:00:00',
                            'is_forecast' => true,
                            'latitude' => -6.2435,
                            'longitude' => 106.7995,
                            'temperature_c' => 29.5,
                            'relative_humidity_pct' => 75.0,
                            'precipitation_mm' => 5.0,
                            'precipitation_probability_pct' => 80.0,
                            'rain_mm' => 5.0,
                            'weather_code' => 61,
                            'weather_condition' => 'RAIN',
                        ],
                    ],
                ],
                [
                    'success' => false,
                    'provider' => 'open_meteo',
                    'error' => 'HTTP_500_INTERNAL_SERVER_ERROR',
                    'data' => [],
                ]
            );

        $res1 = $service->ingestForOutlet($this->outlet, ['retrieved_at' => '2026-10-04 06:00:00']);
        $this->assertEquals('SUCCESS', $res1['status']);
        $this->assertEquals(1, OutletWeatherForecast::where('outlet_id', $this->outlet->id)->count());

        // Step 2: Failed subsequent ingestion attempt
        $res2 = $service->ingestForOutlet($this->outlet, ['retrieved_at' => '2026-10-04 09:00:00']);
        $this->assertEquals('FETCH_FAILED', $res2['status']);

        // Assert: The original vintage is completely intact and not corrupted
        $existing = OutletWeatherForecast::where('outlet_id', $this->outlet->id)->first();
        $this->assertNotNull($existing);
        $this->assertEquals(29.5, (float) $existing->temperature_c);
        $this->assertEquals('2026-10-04 06:00:00', $existing->retrieved_at->toDateTimeString());
    }
}
