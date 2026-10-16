<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemSupplierPriceHistory;
use App\Models\Outlet;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Supplier\SupplierPriceHistoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierPriceHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Outlet $outlet;
    protected Item $item;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'merchant_supplier@test.com',
        ]);

        $this->outlet = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Kaching Kitchen Blok M',
        ]);

        $this->item = Item::create([
            'outlet_id' => $this->outlet->id,
            'sku' => 'RAW-AYAM-01',
            'name' => 'Daging Ayam Fillet',
            'type' => 'raw_material',
            'base_uom' => 'kg',
            'cost_price' => 38000.0,
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'name' => 'PT Unggas Prima Nusantara',
            'phone' => '081122334455',
            'is_active' => true,
        ]);
    }

    public function test_record_price_change_creates_history_record()
    {
        $service = new SupplierPriceHistoryService();

        $history = $service->recordPriceChange(
            itemId: $this->item->id,
            supplierId: $this->supplier->id,
            newPrice: 40000.0,
            oldPrice: 38000.0,
            changeReason: 'MANUAL_UPDATE',
            userId: $this->user->id
        );

        $this->assertNotNull($history);
        $this->assertEquals(38000.0, (float) $history->old_price);
        $this->assertEquals(40000.0, (float) $history->new_price);
        $this->assertEquals('MANUAL_UPDATE', $history->change_reason);
        $this->assertEquals($this->user->id, $history->user_id);
        $this->assertEquals(1, ItemSupplierPriceHistory::count());
    }

    public function test_idempotency_guard_prevents_duplicate_identical_price_entries()
    {
        $service = new SupplierPriceHistoryService();

        // Rekam pertama
        $h1 = $service->recordPriceChange(
            itemId: $this->item->id,
            supplierId: $this->supplier->id,
            newPrice: 42000.0,
            oldPrice: 40000.0
        );

        // Rekam kedua dengan harga sama
        $h2 = $service->recordPriceChange(
            itemId: $this->item->id,
            supplierId: $this->supplier->id,
            newPrice: 42000.0
        );

        $this->assertEquals($h1->id, $h2->id);
        $this->assertEquals(1, ItemSupplierPriceHistory::count());
    }

    public function test_sync_item_supplier_price_updates_pivot_and_logs_history()
    {
        $service = new SupplierPriceHistoryService();

        // 1. Inisialisasi harga pertama kali (Rp 38.000)
        $res1 = $service->syncItemSupplierPrice(
            itemId: $this->item->id,
            supplierId: $this->supplier->id,
            supplierPrice: 38000.0,
            leadTimeDays: 2,
            userId: $this->user->id
        );

        $this->assertTrue($res1['price_updated']);
        $this->assertEquals(38000.0, $res1['current_price']);

        $pivot1 = DB::table('item_supplier')
            ->where('item_id', $this->item->id)
            ->where('supplier_id', $this->supplier->id)
            ->first();

        $this->assertNotNull($pivot1);
        $this->assertEquals(38000.0, (float) $pivot1->supplier_price);
        $this->assertEquals(2, $pivot1->lead_time_days);
        $this->assertEquals(1, ItemSupplierPriceHistory::count());

        // 2. Update dengan harga baru (Rp 41.500)
        $res2 = $service->syncItemSupplierPrice(
            itemId: $this->item->id,
            supplierId: $this->supplier->id,
            supplierPrice: 41500.0,
            leadTimeDays: 2,
            userId: $this->user->id,
            changeReason: 'CATALOG_SYNC'
        );

        $this->assertTrue($res2['price_updated']);
        $this->assertEquals(41500.0, $res2['current_price']);
        $this->assertEquals(38000.0, $res2['old_price']);

        $pivot2 = DB::table('item_supplier')
            ->where('item_id', $this->item->id)
            ->where('supplier_id', $this->supplier->id)
            ->first();

        $this->assertEquals(41500.0, (float) $pivot2->supplier_price);
        $this->assertEquals(2, ItemSupplierPriceHistory::count());

        // 3. Update dengan harga sama (Rp 41.500) -> tidak menambah log histori
        $res3 = $service->syncItemSupplierPrice(
            itemId: $this->item->id,
            supplierId: $this->supplier->id,
            supplierPrice: 41500.0,
            leadTimeDays: 3,
            userId: $this->user->id
        );

        $this->assertFalse($res3['price_updated']);
        $this->assertEquals(2, ItemSupplierPriceHistory::count());
    }

    public function test_price_trend_and_inflation_calculation()
    {
        $service = new SupplierPriceHistoryService();

        // Buat histori harga berurutan
        ItemSupplierPriceHistory::create([
            'item_id' => $this->item->id,
            'supplier_id' => $this->supplier->id,
            'user_id' => $this->user->id,
            'old_price' => 35000.0,
            'new_price' => 35000.0,
            'effective_date' => now()->subDays(60),
            'change_reason' => 'INITIAL_IMPORT',
        ]);

        ItemSupplierPriceHistory::create([
            'item_id' => $this->item->id,
            'supplier_id' => $this->supplier->id,
            'user_id' => $this->user->id,
            'old_price' => 35000.0,
            'new_price' => 38500.0,
            'effective_date' => now()->subDays(20),
            'change_reason' => 'CATALOG_SYNC',
        ]);

        ItemSupplierPriceHistory::create([
            'item_id' => $this->item->id,
            'supplier_id' => $this->supplier->id,
            'user_id' => $this->user->id,
            'old_price' => 38500.0,
            'new_price' => 42000.0,
            'effective_date' => now()->subDays(2),
            'change_reason' => 'MANUAL_UPDATE',
        ]);

        $trend = $service->calculatePriceTrend(
            itemId: $this->item->id,
            supplierId: $this->supplier->id,
            daysLookback: 90
        );

        $this->assertTrue($trend['has_trend']);
        $this->assertEquals(35000.0, $trend['initial_price']);
        $this->assertEquals(42000.0, $trend['current_price']);
        // +20.00% ((42000 - 35000) / 35000 * 100)
        $this->assertEquals(20.0, $trend['pct_change']);
        $this->assertEquals(3, $trend['observations_count']);
    }

    public function test_tenant_isolation_in_price_history_query()
    {
        // Merchant lain
        $otherUser = User::factory()->create(['email' => 'other_merchant@test.com']);
        $otherSupplier = Supplier::create([
            'name' => 'Supplier Luar',
        ]);

        ItemSupplierPriceHistory::create([
            'item_id' => $this->item->id,
            'supplier_id' => $this->supplier->id,
            'user_id' => $this->user->id,
            'new_price' => 40000.0,
            'effective_date' => now(),
        ]);

        ItemSupplierPriceHistory::create([
            'item_id' => $this->item->id,
            'supplier_id' => $otherSupplier->id,
            'user_id' => $otherUser->id,
            'new_price' => 50000.0,
            'effective_date' => now(),
        ]);

        $this->assertEquals(1, ItemSupplierPriceHistory::forUser($this->user->id)->count());
        $this->assertEquals(1, ItemSupplierPriceHistory::forUser($otherUser->id)->count());
    }
}
