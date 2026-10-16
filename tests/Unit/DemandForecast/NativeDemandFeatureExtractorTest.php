<?php

namespace Tests\Unit\DemandForecast;

use App\Models\DailyMenuSalesSummary;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\User;
use App\Services\DemandForecast\NativeDemandFeatureExtractor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class NativeDemandFeatureExtractorTest extends TestCase
{
    use RefreshDatabase;

    protected NativeDemandFeatureExtractor $extractor;
    protected User $user;
    protected Outlet $outlet;
    protected Menu $menu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new NativeDemandFeatureExtractor();

        $this->user = User::factory()->create(['name' => 'Owner Test', 'email' => 'owner.test@example.com']);
        $this->outlet = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Outlet Test',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);
        $this->menu = Menu::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'name' => 'Nasi Goreng Ayam',
            'category' => 'Makanan Utama',
            'price' => 25000,
            'cost_price' => 15000,
            'unit' => 'porsi',
            'is_available' => true,
        ]);
    }

    public function test_01_feature_keys_and_contract_order(): void
    {
        $targetDate = '2026-10-03';
        $extracted = $this->extractor->extractForMenu($this->user, $this->outlet, $this->menu, $targetDate);

        $this->assertIsArray($extracted);
        $this->assertArrayHasKey('features', $extracted);
        $features = $extracted['features'];

        $this->assertCount(37, $features);
        $this->assertSame(NativeDemandFeatureExtractor::FEATURE_KEYS, array_keys($features));

        $this->assertEquals((string) $this->user->id, $features['f01_user_id']);
        $this->assertEquals((string) $this->outlet->id, $features['f02_outlet_id']);
        $this->assertEquals((string) $this->menu->id, $features['f03_menu_id']);
        $this->assertEquals('Makanan Utama', $features['f04_category']);
    }

    public function test_02_cutoff_date_causality_excludes_future_transactions(): void
    {
        $targetDate = '2026-10-03';
        $cutoffDate = '2026-10-02';

        // Summary on cutoff date (t)
        DailyMenuSalesSummary::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'menu_id' => $this->menu->id,
            'business_date' => $cutoffDate,
            'uom' => 'porsi',
            'total_qty' => 10.0,
            'total_revenue' => 250000,
            'total_cost' => 150000,
            'transaction_count' => 10,
            'avg_price' => 25000,
            'avg_cost' => 15000,
        ]);

        // Future transaction on targetDate (must be ignored)
        DailyMenuSalesSummary::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'menu_id' => $this->menu->id,
            'business_date' => $targetDate,
            'uom' => 'porsi',
            'total_qty' => 999.0,
            'total_revenue' => 24975000,
            'total_cost' => 14985000,
            'transaction_count' => 50,
            'avg_price' => 25000,
            'avg_cost' => 15000,
        ]);

        $extracted = $this->extractor->extractForMenu($this->user, $this->outlet, $this->menu, $targetDate);
        $features = $extracted['features'];

        // f05_lag_1 is cutoff date sales (10.0), NOT target date sales (999.0)
        $this->assertEquals(10.0, $features['f05_lag_1']);
        $this->assertEqualsWithDelta(10.0 / 7.0, $features['f10_rolling_mean_7'], 0.001);
    }

    public function test_03_uom_separation_does_not_mix_units(): void
    {
        $targetDate = '2026-10-03';
        $cutoffDate = '2026-10-02';

        // 5 porsi
        DailyMenuSalesSummary::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'menu_id' => $this->menu->id,
            'business_date' => $cutoffDate,
            'uom' => 'porsi',
            'total_qty' => 5.0,
            'total_revenue' => 125000,
            'total_cost' => 75000,
            'transaction_count' => 5,
        ]);

        // 500 gram (different UOM)
        DailyMenuSalesSummary::create([
            'user_id' => $this->user->id,
            'outlet_id' => $this->outlet->id,
            'menu_id' => $this->menu->id,
            'business_date' => $cutoffDate,
            'uom' => 'gram',
            'total_qty' => 500.0,
            'total_revenue' => 125000,
            'total_cost' => 75000,
            'transaction_count' => 1,
        ]);

        $extractedPorsi = $this->extractor->extractForMenu($this->user, $this->outlet, $this->menu, $targetDate, 'porsi');
        $extractedGram = $this->extractor->extractForMenu($this->user, $this->outlet, $this->menu, $targetDate, 'gram');

        $this->assertEquals(5.0, $extractedPorsi['features']['f05_lag_1']);
        $this->assertEquals(500.0, $extractedGram['features']['f05_lag_1']);
    }

    public function test_04_missing_history_yields_insufficient_history(): void
    {
        $targetDate = '2026-10-03';
        $cutoffDate = '2026-10-02';

        // Only 3 days of data
        for ($i = 0; $i < 3; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'menu_id' => $this->menu->id,
                'business_date' => $d,
                'uom' => 'porsi',
                'total_qty' => 5.0,
                'total_revenue' => 125000,
                'total_cost' => 75000,
                'transaction_count' => 5,
            ]);
        }

        $extracted = $this->extractor->extractForMenu($this->user, $this->outlet, $this->menu, $targetDate);

        $this->assertEquals('INSUFFICIENT_HISTORY', $extracted['history_status']);
    }

    public function test_05_true_zero_demand_classification(): void
    {
        $targetDate = '2026-10-03';
        $cutoffDate = '2026-10-02';

        // 28 days of zero sales summaries
        for ($i = 0; $i < 28; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'menu_id' => $this->menu->id,
                'business_date' => $d,
                'uom' => 'porsi',
                'total_qty' => 0.0,
                'total_revenue' => 0,
                'total_cost' => 0,
                'transaction_count' => 0,
            ]);
        }

        $extracted = $this->extractor->extractForMenu($this->user, $this->outlet, $this->menu, $targetDate);

        $this->assertEquals('ZERO_DEMAND', $extracted['history_status']);
        $this->assertEquals(0.0, $extracted['features']['f12_rolling_mean_28']);
        $this->assertEquals(28.0, $extracted['features']['f23_days_since_last_sale']);
        $this->assertEquals(28.0, $extracted['features']['f24_zero_sales_streak']);
    }

    public function test_06_sufficient_history_classification(): void
    {
        $targetDate = '2026-10-03';
        $cutoffDate = '2026-10-02';

        // 28 days of continuous positive sales
        for ($i = 0; $i < 28; $i++) {
            $d = Carbon::parse($cutoffDate)->subDays($i)->toDateString();
            DailyMenuSalesSummary::create([
                'user_id' => $this->user->id,
                'outlet_id' => $this->outlet->id,
                'menu_id' => $this->menu->id,
                'business_date' => $d,
                'uom' => 'porsi',
                'total_qty' => 10.0,
                'total_revenue' => 250000,
                'total_cost' => 150000,
                'transaction_count' => 5,
            ]);
        }

        $extracted = $this->extractor->extractForMenu($this->user, $this->outlet, $this->menu, $targetDate);

        $this->assertEquals('SUFFICIENT', $extracted['history_status']);
        $this->assertEquals(10.0, $extracted['features']['f10_rolling_mean_7']);
        $this->assertEquals(10.0, $extracted['features']['f11_rolling_mean_14']);
        $this->assertEquals(10.0, $extracted['features']['f12_rolling_mean_28']);
        $this->assertEquals(0.0, $extracted['features']['f13_rolling_std_7']);
        $this->assertEquals(1.0, $extracted['features']['f21_nonzero_ratio_7']);
        $this->assertEquals(1.0, $extracted['features']['f22_nonzero_ratio_28']);
        $this->assertEquals(0.0, $extracted['features']['f23_days_since_last_sale']);
        $this->assertEquals(0.0, $extracted['features']['f24_zero_sales_streak']);
    }

    public function test_07_cross_tenant_access_is_rejected(): void
    {
        $otherUser = User::factory()->create(['name' => 'Other User', 'email' => 'other@example.com']);
        $targetDate = '2026-10-03';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tenant isolation violation');

        // Other user attempts to extract features from our outlet
        $this->extractor->extractForMenu($otherUser, $this->outlet, $this->menu, $targetDate);
    }

    public function test_08_cross_outlet_menu_mismatch_is_rejected(): void
    {
        $otherOutlet = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Other Outlet',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);
        $targetDate = '2026-10-03';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Outlet isolation violation');

        // Extracting menu using mismatched outlet
        $this->extractor->extractForMenu($this->user, $otherOutlet, $this->menu, $targetDate);
    }
}
