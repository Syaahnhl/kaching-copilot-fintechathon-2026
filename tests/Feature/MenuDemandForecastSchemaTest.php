<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\MenuDemandForecast;
use App\Models\Outlet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuDemandForecastSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;
    protected User $userB;
    protected Outlet $outletA1;
    protected Outlet $outletA2;
    protected Outlet $outletB1;
    protected Menu $menuA1;
    protected Menu $menuA2;
    protected Menu $menuB1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create(['name' => 'Owner A', 'email' => 'owner.a@example.com']);
        $this->userB = User::factory()->create(['name' => 'Owner B', 'email' => 'owner.b@example.com']);

        $this->outletA1 = Outlet::create([
            'user_id' => $this->userA->id,
            'name' => 'Outlet A-1 Jakarta',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);

        $this->outletA2 = Outlet::create([
            'user_id' => $this->userA->id,
            'name' => 'Outlet A-2 Bandung',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);

        $this->outletB1 = Outlet::create([
            'user_id' => $this->userB->id,
            'name' => 'Outlet B-1 Surabaya',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);

        $this->menuA1 = Menu::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'name' => 'Nasi Goreng Spesial',
            'category' => 'Makanan Utama',
            'price' => 25000.0,
            'cost_price' => 12000.0,
            'unit' => 'Porsi',
        ]);

        $this->menuA2 = Menu::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'name' => 'Es Teh Manis',
            'category' => 'Minuman',
            'price' => 6000.0,
            'cost_price' => 2000.0,
            'unit' => 'Porsi',
        ]);

        $this->menuB1 = Menu::create([
            'user_id' => $this->userB->id,
            'outlet_id' => $this->outletB1->id,
            'name' => 'Kopi Tubruk',
            'category' => 'Minuman',
            'price' => 10000.0,
            'cost_price' => 3500.0,
            'unit' => 'Cangkir',
        ]);
    }

    /**
     * TEST 1: Unique key and duplicate handling via upsert vs raw insert
     */
    public function test_unique_key_and_duplicate_handling(): void
    {
        $payload = [
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'menu_id' => $this->menuA1->id,
            'target_date' => '2026-10-02',
            'cutoff_date' => '2026-10-01',
            'uom' => 'Porsi',
            'predicted_quantity' => 15.5,
            'lower_bound_p10' => 11.2,
            'upper_bound_p90' => 20.8,
            'model_version' => 'catboost_v1.0.0',
            'feature_version' => 'f37_native_v1',
            'serving_origin' => 'CATBOOST_BATCH',
            'history_status' => 'SUFFICIENT',
            'generated_at' => Carbon::now('UTC'),
        ];

        // First creation
        $forecast = MenuDemandForecast::create($payload);
        $this->assertNotNull($forecast->id);
        $this->assertEquals(15.5, $forecast->predicted_quantity);

        // Attempting raw duplicate insert must violate unique key
        $this->expectException(QueryException::class);
        MenuDemandForecast::create($payload);
    }

    /**
     * TEST 1b: Upsert idempotency (updateOrCreate updates existing row)
     */
    public function test_upsert_idempotency(): void
    {
        $attributes = [
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'menu_id' => $this->menuA1->id,
            'target_date' => '2026-10-02',
            'uom' => 'Porsi',
        ];

        // Initial run
        MenuDemandForecast::updateOrCreate($attributes, [
            'cutoff_date' => '2026-10-01',
            'predicted_quantity' => 15.5,
            'model_version' => 'catboost_v1.0.0',
            'feature_version' => 'f37_native_v1',
            'serving_origin' => 'CATBOOST_BATCH',
            'history_status' => 'SUFFICIENT',
            'generated_at' => '2026-10-02 03:00:00',
        ]);

        $this->assertEquals(1, MenuDemandForecast::count());

        // Second run (re-calculation with updated value)
        MenuDemandForecast::updateOrCreate($attributes, [
            'cutoff_date' => '2026-10-01',
            'predicted_quantity' => 18.0,
            'model_version' => 'catboost_v1.0.0',
            'feature_version' => 'f37_native_v1',
            'serving_origin' => 'CATBOOST_BATCH',
            'history_status' => 'SUFFICIENT',
            'generated_at' => '2026-10-02 03:30:00',
        ]);

        // Must still be 1 row, updated in-place
        $this->assertEquals(1, MenuDemandForecast::count());
        $updated = MenuDemandForecast::where($attributes)->first();
        $this->assertEquals(18.0, $updated->predicted_quantity);
    }

    /**
     * TEST 2: Tenant & Outlet Isolation
     */
    public function test_tenant_and_outlet_isolation(): void
    {
        // User A Outlet 1
        MenuDemandForecast::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'menu_id' => $this->menuA1->id,
            'target_date' => '2026-10-02',
            'cutoff_date' => '2026-10-01',
            'uom' => 'Porsi',
            'predicted_quantity' => 20.0,
        ]);

        // User A Outlet 2 (Same menu name, distinct menu/outlet ID)
        $menuA2Outlet = Menu::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA2->id,
            'name' => 'Nasi Goreng Spesial',
            'category' => 'Makanan Utama',
            'price' => 25000.0,
            'cost_price' => 12000.0,
            'unit' => 'Porsi',
        ]);

        MenuDemandForecast::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA2->id,
            'menu_id' => $menuA2Outlet->id,
            'target_date' => '2026-10-02',
            'cutoff_date' => '2026-10-01',
            'uom' => 'Porsi',
            'predicted_quantity' => 12.0,
        ]);

        // User B Outlet 1
        MenuDemandForecast::create([
            'user_id' => $this->userB->id,
            'outlet_id' => $this->outletB1->id,
            'menu_id' => $this->menuB1->id,
            'target_date' => '2026-10-02',
            'cutoff_date' => '2026-10-01',
            'uom' => 'Cangkir',
            'predicted_quantity' => 35.0,
        ]);

        $this->assertEquals(3, MenuDemandForecast::count());
        $this->assertEquals(2, MenuDemandForecast::where('user_id', $this->userA->id)->count());
        $this->assertEquals(1, MenuDemandForecast::where('user_id', $this->userB->id)->count());
        $this->assertEquals(1, MenuDemandForecast::where('outlet_id', $this->outletA1->id)->count());
        $this->assertEquals(1, MenuDemandForecast::where('outlet_id', $this->outletA2->id)->count());
        $this->assertEquals(1, MenuDemandForecast::where('outlet_id', $this->outletB1->id)->count());
    }

    /**
     * TEST 3: Pemisahan UOM (Dimensional Firewall)
     */
    public function test_uom_dimensional_firewall_separation(): void
    {
        $menuWagyu = Menu::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'name' => 'Daging Wagyu',
            'category' => 'Bahan Olahan',
            'price' => 500.0,
            'unit' => 'Gram',
        ]);

        MenuDemandForecast::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'menu_id' => $this->menuA1->id,
            'target_date' => '2026-10-02',
            'cutoff_date' => '2026-10-01',
            'uom' => 'Porsi',
            'predicted_quantity' => 25.0,
        ]);

        MenuDemandForecast::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'menu_id' => $menuWagyu->id,
            'target_date' => '2026-10-02',
            'cutoff_date' => '2026-10-01',
            'uom' => 'Gram',
            'predicted_quantity' => 1500.0,
        ]);

        $this->assertEquals(2, MenuDemandForecast::count());
        $porsiRow = MenuDemandForecast::where('uom', 'Porsi')->first();
        $this->assertEquals(25.0, $porsiRow->predicted_quantity);

        $gramRow = MenuDemandForecast::where('uom', 'Gram')->first();
        $this->assertEquals(1500.0, $gramRow->predicted_quantity);
    }

    /**
     * TEST 4: Pemisahan Target Date (Multi-Day Horizon)
     */
    public function test_multi_day_target_date_horizon(): void
    {
        $dates = [
            '2026-10-02' => 10.0,
            '2026-10-03' => 14.0,
            '2026-10-04' => 18.0,
            '2026-10-05' => 8.0,
            '2026-10-06' => 11.0,
            '2026-10-07' => 13.0,
            '2026-10-08' => 16.0,
        ];

        foreach ($dates as $targetDate => $predictedQty) {
            MenuDemandForecast::create([
                'user_id' => $this->userA->id,
                'outlet_id' => $this->outletA1->id,
                'menu_id' => $this->menuA1->id,
                'target_date' => $targetDate,
                'cutoff_date' => '2026-10-01',
                'uom' => 'Porsi',
                'predicted_quantity' => $predictedQty,
            ]);
        }

        $this->assertEquals(7, MenuDemandForecast::where('menu_id', $this->menuA1->id)->count());

        $horizonRange = MenuDemandForecast::where('outlet_id', $this->outletA1->id)
            ->whereBetween('target_date', ['2026-10-02', '2026-10-08'])
            ->orderBy('target_date', 'asc')
            ->get();

        $this->assertCount(7, $horizonRange);
        $this->assertEquals(10.0, $horizonRange[0]->predicted_quantity);
        $this->assertEquals(16.0, $horizonRange[6]->predicted_quantity);
    }

    /**
     * TEST 5: Model Version & Forecast Provenance
     */
    public function test_model_version_and_forecast_provenance(): void
    {
        $forecastBatch = MenuDemandForecast::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'menu_id' => $this->menuA1->id,
            'target_date' => '2026-10-02',
            'cutoff_date' => '2026-10-01',
            'uom' => 'Porsi',
            'predicted_quantity' => 22.0,
            'model_version' => 'catboost_v1.0.0',
            'feature_version' => 'f37_native_v1',
            'serving_origin' => 'CATBOOST_BATCH',
        ]);

        $this->assertEquals('catboost_v1.0.0', $forecastBatch->model_version);
        $this->assertEquals('f37_native_v1', $forecastBatch->feature_version);
        $this->assertEquals('CATBOOST_BATCH', $forecastBatch->serving_origin);

        $forecastFallback = MenuDemandForecast::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'menu_id' => $this->menuA2->id,
            'target_date' => '2026-10-02',
            'cutoff_date' => '2026-10-01',
            'uom' => 'Porsi',
            'predicted_quantity' => 15.0,
            'model_version' => 'statistical_dow_median_v1',
            'feature_version' => 'heuristic_baseline',
            'serving_origin' => 'STATISTICAL_FALLBACK',
            'history_status' => 'INSUFFICIENT_HISTORY',
        ]);

        $this->assertEquals('STATISTICAL_FALLBACK', $forecastFallback->serving_origin);
        $this->assertEquals('INSUFFICIENT_HISTORY', $forecastFallback->history_status);
    }

    /**
     * TEST 6: Metadata JSON Storage & Casts
     */
    public function test_metadata_json_storage_and_casts(): void
    {
        $meta = [
            'wma_7' => 14.2,
            'dow_median' => 16.0,
            'confidence_score' => 0.92,
            'inference_latency_ms' => 3.75,
        ];

        $forecast = MenuDemandForecast::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'menu_id' => $this->menuA1->id,
            'target_date' => '2026-10-02',
            'cutoff_date' => '2026-10-01',
            'uom' => 'Porsi',
            'predicted_quantity' => 17.5,
            'metadata' => $meta,
        ]);

        $retrieved = MenuDemandForecast::find($forecast->id);
        $this->assertIsArray($retrieved->metadata);
        $this->assertEquals(0.92, $retrieved->metadata['confidence_score']);
        $this->assertEquals(3.75, $retrieved->metadata['inference_latency_ms']);
    }

    /**
     * TEST 7: Nullability and Default Values
     */
    public function test_nullability_and_default_values(): void
    {
        $forecast = MenuDemandForecast::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'menu_id' => $this->menuA1->id,
            'target_date' => '2026-10-02',
            'cutoff_date' => '2026-10-01',
            'predicted_quantity' => 5.0,
        ]);

        $this->assertNull($forecast->lower_bound_p10);
        $this->assertNull($forecast->upper_bound_p90);
        $this->assertNull($forecast->metadata);
        $this->assertEquals('Porsi', $forecast->uom);
        $this->assertEquals('catboost_v1.0.0', $forecast->model_version);
        $this->assertEquals('f37_native_v1', $forecast->feature_version);
        $this->assertEquals('CATBOOST_BATCH', $forecast->serving_origin);
        $this->assertEquals('SUFFICIENT', $forecast->history_status);
    }

    /**
     * TEST 8: BelongsTo Relationships
     */
    public function test_relationships(): void
    {
        $forecast = MenuDemandForecast::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'menu_id' => $this->menuA1->id,
            'target_date' => '2026-10-02',
            'cutoff_date' => '2026-10-01',
            'predicted_quantity' => 8.0,
        ]);

        $this->assertEquals('Owner A', $forecast->user->name);
        $this->assertEquals('Outlet A-1 Jakarta', $forecast->outlet->name);
        $this->assertEquals('Nasi Goreng Spesial', $forecast->menu->name);
    }
}
