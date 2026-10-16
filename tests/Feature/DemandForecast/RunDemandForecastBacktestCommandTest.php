<?php

namespace Tests\Feature\DemandForecast;

use App\Models\DailyMenuSalesSummary;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\OutletWeatherForecast;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunDemandForecastBacktestCommandTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Outlet $outlet;
    protected Menu $menu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Owner Test', 'email' => 'owner.test@example.com']);
        $this->outlet = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Outlet Bukit Padangan',
            'latitude' => -7.150975,
            'longitude' => 110.140259,
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);
        $this->menu = Menu::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'name' => 'Ayam Bakar Madu',
            'category' => 'Makanan Utama',
            'price' => 30000,
            'cost_price' => 18000,
            'unit' => 'porsi',
            'is_available' => true,
        ]);
    }

    public function test_01_command_fails_gracefully_when_menu_is_missing_for_menu_qty_target(): void
    {
        $this->artisan('kaching:backtest-demand-forecast', [
            '--target' => 'menu_qty',
        ])
            ->expectsOutputToContain('Error: --menu=<id> is required when --target=menu_qty.')
            ->assertExitCode(1);
    }

    public function test_02_command_warns_when_blocked_by_data_gaps(): void
    {
        // Only 5 transactions across 3 days (less than min-train = 28)
        for ($i = 1; $i <= 3; $i++) {
            Transaction::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'transaction_number' => 'TRX-GAP-' . $i,
                'app_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'status' => 'completed',
                'total_amount' => 50000,
                'pay_amount' => 50000,
                'change_amount' => 0,
                'payment_method' => 'cash',
                'created_at' => Carbon::parse("2026-09-0{$i} 12:00:00", 'Asia/Jakarta')->setTimezone('UTC'),
            ]);
        }

        $this->artisan('kaching:backtest-demand-forecast', [
            '--outlet' => $this->outlet->id,
            '--target' => 'transaction_count',
            '--start' => '2026-09-01',
            '--end' => '2026-09-05',
            '--min-train' => 28,
        ])
            ->expectsOutputToContain('STATUS: BLOCKED BY DATA GAPS')
            ->assertExitCode(0);
    }

    public function test_03_command_runs_successfully_and_outputs_results(): void
    {
        // Seed 45 days of transaction data
        $startDate = Carbon::parse('2026-08-01', 'Asia/Jakarta');

        for ($i = 0; $i < 45; $i++) {
            $date = $startDate->copy()->addDays($i);
            $txCount = 10 + ($i % 5);

            for ($k = 0; $k < $txCount; $k++) {
                Transaction::create([
                    'user_id' => $this->user->id,
                    'outlet_id' => $this->outlet->id,
                    'transaction_number' => "TRX-{$i}-{$k}",
                    'app_uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'status' => 'completed',
                    'total_amount' => 50000,
                    'pay_amount' => 50000,
                    'change_amount' => 0,
                    'payment_method' => 'cash',
                    'created_at' => $date->copy()->setTime(12, 0, 0)->setTimezone('UTC'),
                ]);
            }

            // Seed weather
            OutletWeatherForecast::create([
                'outlet_id' => $this->outlet->id,
                'source' => 'OPEN_METEO_HISTORICAL_ARCHIVE',
                'forecast_published_at' => $date->copy()->startOfDay(),
                'retrieved_at' => $date->copy()->startOfDay(),
                'forecast_for_timestamp' => $date->copy()->setTime(12, 0, 0),
                'is_forecast' => false,
                'latitude' => -7.150975,
                'longitude' => 110.140259,
                'temperature_c' => 28.0 + ($i % 3),
                'precipitation_mm' => ($i % 4 === 0) ? 12.5 : 0.0,
                'precipitation_probability_pct' => ($i % 4 === 0) ? 80.0 : 10.0,
                'weather_code' => ($i % 4 === 0) ? 61 : 0,
            ]);
        }

        $this->artisan('kaching:backtest-demand-forecast', [
            '--outlet' => $this->outlet->id,
            '--target' => 'transaction_count',
            '--start' => '2026-08-01',
            '--end' => '2026-09-14',
            '--min-train' => 28,
            '--horizons' => '1,3,7',
        ])
            ->expectsOutputToContain('BACKTEST COMPLETED — ROLLING-ORIGIN EXPANDING WINDOW RESULTS')
            ->expectsOutputToContain('Horizon: 1 Day(s) Ahead')
            ->expectsOutputToContain('DOW MEDIAN')
            ->assertExitCode(0);
    }

    public function test_04_command_supports_json_output(): void
    {
        $startDate = Carbon::parse('2026-08-01', 'Asia/Jakarta');

        for ($i = 0; $i < 40; $i++) {
            $date = $startDate->copy()->addDays($i);
            DailyMenuSalesSummary::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'menu_id' => $this->menu->id,
                'business_date' => $date->toDateString(),
                'uom' => 'porsi',
                'total_qty' => 15.0 + ($i % 7),
                'total_revenue' => 450000,
                'total_cost' => 270000,
                'transaction_count' => 10,
                'avg_price' => 30000,
                'avg_cost' => 18000,
            ]);
        }

        $this->artisan('kaching:backtest-demand-forecast', [
            '--outlet' => $this->outlet->id,
            '--target' => 'menu_qty',
            '--menu' => $this->menu->id,
            '--start' => '2026-08-01',
            '--end' => '2026-09-09',
            '--min-train' => 28,
            '--horizons' => '1',
            '--json' => true,
        ])
            ->expectsOutputToContain('"status": "COMPLETED"')
            ->assertExitCode(0);
    }
}
