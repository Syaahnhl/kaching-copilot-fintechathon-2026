<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Item;
use App\Services\Financial\MaterialPriceIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MaterialPriceIntelligenceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenantA;
    protected User $tenantB;
    protected Outlet $outletA;
    protected MaterialPriceIntelligenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = User::factory()->create([
            'email' => 'tenant_price_a@kaching.test',
        ]);

        $this->tenantB = User::factory()->create([
            'email' => 'tenant_price_b@kaching.test',
        ]);

        $this->outletA = Outlet::create([
            'user_id' => $this->tenantA->id,
            'name'    => 'Outlet Price A',
        ]);

        $this->service = new MaterialPriceIntelligenceService();
    }

    public function test_fails_closed_when_purchase_history_is_absent(): void
    {
        $item = Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Minyak Goreng Sawit',
            'type'       => 'raw_material',
            'base_uom'   => 'liter',
            'cost_price' => 18000,
        ]);

        $res = $this->service->evaluateItemPriceTrend($this->tenantA->id, $item->id, $this->outletA->id);

        $this->assertSame(MaterialPriceIntelligenceService::STATUS_PRICE_HISTORY_UNAVAILABLE, $res['status']);
        $this->assertSame(MaterialPriceIntelligenceService::STATUS_DATA_BLOCKED, $res['data_state']);
        $this->assertArrayHasKey('missing_data_dependencies', $res);
        $this->assertStringContainsString('items.cost_price adalah snapshot', $res['rejection_note']);
    }

    public function test_evaluates_upward_trend_when_purchase_series_is_provided(): void
    {
        $item = Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Gula Pasir Kristal',
            'type'       => 'raw_material',
            'base_uom'   => 'kg',
            'cost_price' => 16000,
        ]);

        // Purchase history showing inflation from 15,000 to 18,000 (+20%)
        $observations = [
            ['purchase_date' => '2026-08-01', 'price' => 15000, 'qty' => 10],
            ['purchase_date' => '2026-08-15', 'price' => 16500, 'qty' => 10],
            ['purchase_date' => '2026-09-01', 'price' => 18000, 'qty' => 10],
        ];

        $res = $this->service->evaluateItemPriceTrend($this->tenantA->id, $item->id, $this->outletA->id, $observations);

        $this->assertSame(MaterialPriceIntelligenceService::STATUS_SUCCESS, $res['status']);
        $this->assertSame(MaterialPriceIntelligenceService::TREND_UP, $res['trend']);
        $this->assertEquals(20.0, $res['metrics']['percentage_change']);
        $this->assertGreaterThan(18000, $res['metrics']['projected_price_30d']);
    }

    public function test_evaluates_downward_trend_when_purchase_series_decreases(): void
    {
        $item = Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Cabai Rawit Merah',
            'type'       => 'raw_material',
            'base_uom'   => 'kg',
            'cost_price' => 45000,
        ]);

        // Price falling from 60,000 to 45,000 (-25%)
        $observations = [
            ['purchase_date' => '2026-08-01', 'price' => 60000, 'qty' => 5],
            ['purchase_date' => '2026-08-15', 'price' => 52000, 'qty' => 5],
            ['purchase_date' => '2026-09-01', 'price' => 45000, 'qty' => 5],
        ];

        $res = $this->service->evaluateItemPriceTrend($this->tenantA->id, $item->id, $this->outletA->id, $observations);

        $this->assertSame(MaterialPriceIntelligenceService::STATUS_SUCCESS, $res['status']);
        $this->assertSame(MaterialPriceIntelligenceService::TREND_DOWN, $res['trend']);
        $this->assertEquals(-25.0, $res['metrics']['percentage_change']);
    }

    public function test_evaluates_stable_trend_within_threshold(): void
    {
        $item = Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Tepung Terigu Segitiga',
            'type'       => 'raw_material',
            'base_uom'   => 'kg',
            'cost_price' => 12000,
        ]);

        // Price stable (12,000 -> 12,100 -> 12,050: +0.42%)
        $observations = [
            ['purchase_date' => '2026-08-01', 'price' => 12000, 'qty' => 20],
            ['purchase_date' => '2026-08-15', 'price' => 12100, 'qty' => 20],
            ['purchase_date' => '2026-09-01', 'price' => 12050, 'qty' => 20],
        ];

        $res = $this->service->evaluateItemPriceTrend($this->tenantA->id, $item->id, $this->outletA->id, $observations);

        $this->assertSame(MaterialPriceIntelligenceService::STATUS_SUCCESS, $res['status']);
        $this->assertSame(MaterialPriceIntelligenceService::TREND_STABLE, $res['trend']);
    }

    public function test_insufficient_observations_fails_closed(): void
    {
        $item = Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Bawang Merah',
            'type'       => 'raw_material',
            'base_uom'   => 'kg',
            'cost_price' => 30000,
        ]);

        // Only 2 observations
        $observations = [
            ['purchase_date' => '2026-08-01', 'price' => 30000, 'qty' => 5],
            ['purchase_date' => '2026-09-01', 'price' => 32000, 'qty' => 5],
        ];

        $res = $this->service->evaluateItemPriceTrend($this->tenantA->id, $item->id, $this->outletA->id, $observations);

        $this->assertSame(MaterialPriceIntelligenceService::STATUS_INSUFFICIENT_DATA, $res['status']);
    }

    public function test_tenant_isolation_rejects_cross_tenant_item(): void
    {
        $item = Item::create([
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Bahan Tenant A',
            'type'       => 'raw_material',
            'base_uom'   => 'kg',
            'cost_price' => 10000,
        ]);

        $res = $this->service->evaluateItemPriceTrend($this->tenantB->id, $item->id, 999);

        $this->assertSame('ITEM_NOT_FOUND', $res['status']);
    }
}
