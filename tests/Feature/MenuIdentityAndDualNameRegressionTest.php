<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuIdentityAndDualNameRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected $userA;
    protected $userB;
    protected $outletA1;
    protected $outletA2;
    protected $outletB;
    protected $menuA; // ID 411: Onion Ring / Makanan / 16000
    protected $menuB; // ID 542: Onion Ring / Shopee / 15000

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::forceCreate([
            'name' => 'Tenant A',
            'email' => 'tenant_a@kaching.id',
            'password' => bcrypt('password123'),
            'subscription_plan' => 'pro',
            'account_status' => 'active',
        ]);

        $this->userB = User::forceCreate([
            'name' => 'Tenant B',
            'email' => 'tenant_b@kaching.id',
            'password' => bcrypt('password123'),
            'subscription_plan' => 'pro',
            'account_status' => 'active',
        ]);

        $this->outletA1 = Outlet::create([
            'user_id' => $this->userA->id,
            'name' => 'Outlet A1',
            'address' => 'Test Address 1',
        ]);

        $this->outletA2 = Outlet::create([
            'user_id' => $this->userA->id,
            'name' => 'Outlet A2',
            'address' => 'Test Address 2',
        ]);

        $this->outletB = Outlet::create([
            'user_id' => $this->userB->id,
            'name' => 'Outlet B',
            'address' => 'Test Address B',
        ]);

        // Dual Identical Name under Tenant A
        $this->menuA = Menu::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'name' => 'Onion Ring',
            'category' => 'Makanan',
            'price' => 16000,
            'cost_price' => 8000,
            'stock' => 100,
            'is_active' => 1,
        ]);

        $this->menuB = Menu::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'name' => 'Onion Ring',
            'category' => 'Shopee',
            'price' => 15000,
            'cost_price' => 8000,
            'stock' => 100,
            'is_active' => 1,
        ]);
    }

    /**
     * TEST 1: Checkout Menu A (Rp16.000) menghasilkan menu_id A dan harga 16000
     */
    public function test_checkout_menu_a_preserves_canonical_id_and_price()
    {
        $payload = [
            'app_uuid' => 'uuid-test-a-' . uniqid(),
            'outlet_id' => $this->outletA1->id,
            'payment_method' => 'Tunai',
            'order_type' => 'Dine-in',
            'total_amount' => 16000,
            'pay_amount' => 16000,
            'created_at_device' => now()->format('Y-m-d H:i:s'),
            'items' => [
                [
                    'menu_id' => $this->menuA->id,
                    'name' => 'Onion Ring',
                    'qty' => 1,
                    'price' => 16000,
                    'note' => 'Original A',
                ],
            ],
        ];

        $response = $this->actingAs($this->userA)->postJson('/api/transactions', $payload);
        $response->assertStatus(201);

        $savedItem = TransactionItem::where('menu_id', $this->menuA->id)->first();
        $this->assertNotNull($savedItem);
        $this->assertEquals(16000, $savedItem->price);
        $this->assertEquals('Onion Ring', $savedItem->menu_name);
        $this->assertEquals($this->menuA->id, $savedItem->menu_id);
    }

    /**
     * TEST 2: Checkout Menu B (Rp15.000) menghasilkan menu_id B dan harga 15000
     */
    public function test_checkout_menu_b_preserves_canonical_id_and_price()
    {
        $payload = [
            'app_uuid' => 'uuid-test-b-' . uniqid(),
            'outlet_id' => $this->outletA1->id,
            'payment_method' => 'Tunai',
            'order_type' => 'Dine-in',
            'total_amount' => 15000,
            'pay_amount' => 15000,
            'created_at_device' => now()->format('Y-m-d H:i:s'),
            'items' => [
                [
                    'menu_id' => $this->menuB->id,
                    'name' => 'Onion Ring',
                    'qty' => 1,
                    'price' => 15000,
                    'note' => 'Original B',
                ],
            ],
        ];

        $response = $this->actingAs($this->userA)->postJson('/api/transactions', $payload);
        $response->assertStatus(201);

        $savedItem = TransactionItem::where('menu_id', $this->menuB->id)->first();
        $this->assertNotNull($savedItem);
        $this->assertEquals(15000, $savedItem->price);
        $this->assertEquals('Onion Ring', $savedItem->menu_name);
        $this->assertEquals($this->menuB->id, $savedItem->menu_id);
    }

    /**
     * TEST 3: Add A + B dalam transaksi yang sama menghasilkan 2 baris terpisah dengan id & harga presisi
     */
    public function test_checkout_both_identical_named_menus_in_same_transaction()
    {
        $payload = [
            'app_uuid' => 'uuid-test-ab-' . uniqid(),
            'outlet_id' => $this->outletA1->id,
            'payment_method' => 'Tunai',
            'order_type' => 'Dine-in',
            'total_amount' => 31000,
            'pay_amount' => 31000,
            'created_at_device' => now()->format('Y-m-d H:i:s'),
            'items' => [
                [
                    'menu_id' => $this->menuA->id,
                    'name' => 'Onion Ring',
                    'qty' => 1,
                    'price' => 16000,
                    'note' => 'Line 1 Makanan',
                ],
                [
                    'menu_id' => $this->menuB->id,
                    'name' => 'Onion Ring',
                    'qty' => 1,
                    'price' => 15000,
                    'note' => 'Line 2 Shopee',
                ],
            ],
        ];

        $response = $this->actingAs($this->userA)->postJson('/api/transactions', $payload);
        $response->assertStatus(201);

        $trx = Transaction::where('app_uuid', $payload['app_uuid'])->first();
        $this->assertNotNull($trx);
        $this->assertEquals(31000, $trx->total_amount);

        $items = TransactionItem::where('transaction_id', $trx->id)->orderBy('id', 'asc')->get();
        $this->assertCount(2, $items);

        $this->assertEquals($this->menuA->id, $items[0]->menu_id);
        $this->assertEquals(16000, $items[0]->price);

        $this->assertEquals($this->menuB->id, $items[1]->menu_id);
        $this->assertEquals(15000, $items[1]->price);
    }

    /**
     * TEST 4: Bulk sync offline transaction mempertahankan menu_id kanonikal
     */
    public function test_bulk_sync_preserves_canonical_menu_ids()
    {
        $payload = [
            'transactions' => [
                [
                    'app_uuid' => 'bulk-uuid-ab-' . uniqid(),
                    'outlet_id' => $this->outletA1->id,
                    'payment_method' => 'Tunai',
                    'order_type' => 'Dine-in',
                    'total_amount' => 31000,
                    'pay_amount' => 31000,
                    'status' => 'Selesai',
                    'created_at_device' => now()->format('Y-m-d H:i:s'),
                    'items' => [
                        [
                            'menu_id' => $this->menuA->id,
                            'name' => 'Onion Ring',
                            'qty' => 1,
                            'price' => 16000,
                        ],
                        [
                            'menu_id' => $this->menuB->id,
                            'name' => 'Onion Ring',
                            'qty' => 1,
                            'price' => 15000,
                        ],
                    ],
                ]
            ]
        ];

        $response = $this->actingAs($this->userA)->postJson('/api/transactions/bulk-sync', $payload);
        $response->assertStatus(202);

        // Process bulk sync job sync in test
        (new \App\Jobs\ProcessBulkTransactions($payload['transactions'], $this->userA->id, '1.3.34'))->handle();

        $trx = Transaction::where('app_uuid', $payload['transactions'][0]['app_uuid'])->first();
        $this->assertNotNull($trx);

        $items = TransactionItem::where('transaction_id', $trx->id)->orderBy('id', 'asc')->get();
        $this->assertCount(2, $items);
        $this->assertEquals($this->menuA->id, $items[0]->menu_id);
        $this->assertEquals(16000, $items[0]->price);
        $this->assertEquals($this->menuB->id, $items[1]->menu_id);
        $this->assertEquals(15000, $items[1]->price);
    }

    /**
     * TEST 5: Legacy Compatibility - Unique name resolves properly
     */
    public function test_legacy_payload_unique_name_resolves()
    {
        $uniqueMenu = Menu::create([
            'user_id' => $this->userA->id,
            'outlet_id' => $this->outletA1->id,
            'name' => 'Es Teh Manis Unik',
            'category' => 'Minuman',
            'price' => 5000,
            'cost_price' => 1500,
            'stock' => 100,
            'is_active' => 1,
        ]);

        $payload = [
            'app_uuid' => 'legacy-uuid-unique-' . uniqid(),
            'outlet_id' => $this->outletA1->id,
            'payment_method' => 'Tunai',
            'order_type' => 'Dine-in',
            'total_amount' => 5000,
            'pay_amount' => 5000,
            'created_at_device' => now()->format('Y-m-d H:i:s'),
            'items' => [
                [
                    // No menu_id sent (Legacy APK)
                    'name' => 'Es Teh Manis Unik',
                    'qty' => 1,
                    'price' => 5000,
                ],
            ],
        ];

        $response = $this->actingAs($this->userA)->postJson('/api/transactions', $payload);
        $response->assertStatus(201);

        $savedItem = TransactionItem::where('menu_name', 'Es Teh Manis Unik')->first();
        $this->assertNotNull($savedItem);
        $this->assertEquals($uniqueMenu->id, $savedItem->menu_id);
        $this->assertEquals(5000, $savedItem->price);
    }

    /**
     * TEST 6: Legacy Compatibility - Duplicate name without menu_id fails closed (422 Ambiguous)
     */
    public function test_legacy_payload_duplicate_name_fails_closed()
    {
        $payload = [
            'app_uuid' => 'legacy-uuid-duplicate-' . uniqid(),
            'outlet_id' => $this->outletA1->id,
            'payment_method' => 'Tunai',
            'order_type' => 'Dine-in',
            'total_amount' => 16000,
            'pay_amount' => 16000,
            'created_at_device' => now()->format('Y-m-d H:i:s'),
            'items' => [
                [
                    // Ambiguous duplicate name without menu_id
                    'name' => 'Onion Ring',
                    'qty' => 1,
                    'price' => 16000,
                ],
            ],
        ];

        $response = $this->actingAs($this->userA)->postJson('/api/transactions', $payload);
        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'error',
            'error_code' => 'AMBIGUOUS_MENU_IDENTITY',
        ]);
    }

    /**
     * TEST 7: Security Audit - Cross-tenant menu_id injection rejected (422 INVALID_MENU_ID)
     */
    public function test_cross_tenant_menu_id_injection_rejected()
    {
        $menuB_tenant = Menu::create([
            'user_id' => $this->userB->id,
            'outlet_id' => $this->outletB->id,
            'name' => 'Secret Burger Tenant B',
            'category' => 'Makanan',
            'price' => 50000,
            'stock' => 10,
            'is_active' => 1,
        ]);

        $payload = [
            'app_uuid' => 'uuid-tamper-' . uniqid(),
            'outlet_id' => $this->outletA1->id,
            'payment_method' => 'Tunai',
            'order_type' => 'Dine-in',
            'total_amount' => 50000,
            'pay_amount' => 50000,
            'created_at_device' => now()->format('Y-m-d H:i:s'),
            'items' => [
                [
                    'menu_id' => $menuB_tenant->id, // Menu ID milik Tenant B
                    'name' => 'Secret Burger Tenant B',
                    'qty' => 1,
                    'price' => 50000,
                ],
            ],
        ];

        $response = $this->actingAs($this->userA)->postJson('/api/transactions', $payload);
        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'error',
            'error_code' => 'INVALID_MENU_ID',
        ]);
    }
}
