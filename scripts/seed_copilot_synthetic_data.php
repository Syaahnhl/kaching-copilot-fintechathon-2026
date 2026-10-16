<?php

declare(strict_types=1);

/**
 * Synthetic Coffee Shop Testing Dataset Seeder for Kaching Copilot (Phase C)
 *
 * Scenarios:
 *   - Tenant 901 / Outlet 901: Healthy Baseline (Kopi Popoyo Senopati)
 *   - Tenant 902 / Outlet 902: Financial Stress (Kopi Popoyo Sudirman)
 *   - Tenant 903 / Outlet 903: Growth & Opportunity (Kopi Popoyo BSD)
 *
 * Scope: Strictly user_id IN (901, 902, 903) and outlet_id IN (901, 902, 903).
 * Zero mutation of any other user/outlet/production data.
 */

namespace Scripts;

require_once __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Helpers\TimezoneContract;

class CopilotSyntheticDataSeeder
{
    protected const SEED = 424242;

    public function run(): array
    {
        // Enforce deterministic random generation
        mt_srand(self::SEED);

        echo "========================================================\n";
        echo "KACHING COPILOT SYNTHETIC DATASET SEEDER (PHASE C)\n";
        echo "========================================================\n\n";

        // Step 1: Idempotent Rollback for Scoped Tenants
        $this->rollbackScopedTenants();

        // Step 2: Seed Users
        $this->seedUsers();

        // Step 3: Seed Outlets
        $this->seedOutlets();

        // Step 4: Seed Suppliers
        $this->seedSuppliers();

        // Step 5: Seed Items & Packaging
        $this->seedItems();

        // Step 6: Seed Item UOMs
        $this->seedItemUoms();

        // Step 7: Seed Item Suppliers
        $this->seedItemSuppliers();

        // Step 8: Seed Menus
        $this->seedMenus();

        // Step 9: Seed Menu Ingredients (BOM)
        $this->seedMenuIngredients();

        // Step 10: Seed 60-Day Time-Series Transactions & Transaction Items
        $trxStats = $this->seedTransactionsAndItems();

        // Step 11: Seed Expenses
        $expenseStats = $this->seedExpenses();

        // Step 12: Seed Shifts
        $shiftStats = $this->seedShifts();

        // Step 13: Seed Stock Movements (Opening, Restocks, Consumption)
        $stockStats = $this->seedStockMovements();

        echo "\n========================================================\n";
        echo "SEEDING COMPLETED SUCCESSFULLY!\n";
        echo "========================================================\n";

        return [
            'transactions' => $trxStats,
            'expenses'     => $expenseStats,
            'shifts'       => $shiftStats,
            'stock'        => $stockStats,
        ];
    }

    public function rollbackScopedTenants(): void
    {
        echo "[1/13] Executing Idempotent Rollback for Tenants 901, 902, 903...\n";

        DB::transaction(function () {
            DB::table('stock_movements')
                ->whereIn('outlet_id', [901, 902, 903])
                ->orWhereIn('user_id', [901, 902, 903])
                ->delete();

            DB::table('transaction_items')
                ->whereIn('user_id', [901, 902, 903])
                ->delete();

            DB::table('transactions')
                ->whereIn('user_id', [901, 902, 903])
                ->delete();

            DB::table('shifts')
                ->whereIn('user_id', [901, 902, 903])
                ->delete();

            DB::table('expenses')
                ->whereIn('user_id', [901, 902, 903])
                ->delete();

            DB::table('menu_ingredients')
                ->whereIn('outlet_id', [901, 902, 903])
                ->delete();

            DB::table('menus')
                ->whereIn('user_id', [901, 902, 903])
                ->delete();

            $itemIds = DB::table('items')
                ->whereIn('outlet_id', [901, 902, 903])
                ->pluck('id')
                ->toArray();

            if (!empty($itemIds)) {
                DB::table('item_supplier')->whereIn('item_id', $itemIds)->delete();
                DB::table('item_uoms')->whereIn('item_id', $itemIds)->delete();
            }

            DB::table('items')->whereIn('outlet_id', [901, 902, 903])->delete();

            // Only delete synthetic suppliers (901-904)
            DB::table('suppliers')->whereIn('id', [901, 902, 903, 904])->delete();

            DB::table('outlets')->whereIn('id', [901, 902, 903])->orWhereIn('user_id', [901, 902, 903])->delete();

            DB::table('users')->whereIn('id', [901, 902, 903])->delete();
        });

        echo "  -> Cleaned existing scoped test data.\n";
    }

    protected function seedUsers(): void
    {
        echo "[2/13] Seeding Users (901, 902, 903)...\n";
        $now = '2026-08-01 00:00:00';
        $users = [
            [
                'id'         => 901,
                'name'       => 'Popoyo Coffee Senopati (Owner)',
                'email'      => 'test.senopati@kaching.local',
                'password'   => Hash::make('Secret123!'),
                'role'       => 'owner',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 902,
                'name'       => 'Popoyo Coffee Sudirman (Owner)',
                'email'      => 'test.sudirman@kaching.local',
                'password'   => Hash::make('Secret123!'),
                'role'       => 'owner',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 903,
                'name'       => 'Popoyo Coffee BSD (Owner)',
                'email'      => 'test.bsd@kaching.local',
                'password'   => Hash::make('Secret123!'),
                'role'       => 'owner',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('users')->insert($users);
    }

    protected function seedOutlets(): void
    {
        echo "[3/13] Seeding Outlets (901, 902, 903)...\n";
        $now = '2026-08-01 00:00:00';
        $outlets = [
            [
                'id'         => 901,
                'user_id'    => 901,
                'name'       => 'Kopi Popoyo Senopati',
                'address'    => 'Jl. Senopati No. 45, Kebayoran Baru, Jakarta Selatan',
                'phone'      => '0812-9011-0001',
                'timezone'   => 'Asia/Jakarta',
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 902,
                'user_id'    => 902,
                'name'       => 'Kopi Popoyo Sudirman',
                'address'    => 'Stasiun MRT Sudirman Dukuh Atas, Jakarta Pusat',
                'phone'      => '0812-9022-0002',
                'timezone'   => 'Asia/Jakarta',
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 903,
                'user_id'    => 903,
                'name'       => 'Kopi Popoyo BSD',
                'address'    => 'The Breeze BSD City, Sampora, Tangerang',
                'phone'      => '0812-9033-0003',
                'timezone'   => 'Asia/Jakarta',
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('outlets')->insert($outlets);
    }

    protected function seedSuppliers(): void
    {
        echo "[4/13] Seeding Suppliers (901..904)...\n";
        $now = '2026-08-01 00:00:00';
        $suppliers = [
            [
                'id'             => 901,
                'name'           => 'CV Roastery Kopi Nusantara',
                'contact_person' => 'Hendra Wijaya',
                'phone'          => '0812-9988-7711',
                'email'          => 'order@roasterynusantara.test',
                'address'        => 'Kawasan Industri Pulogadung, Jakarta Timur',
                'is_active'      => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'id'             => 902,
                'name'           => 'PT Sumber Dairy Segar',
                'contact_person' => 'Ratna Sari',
                'phone'          => '0813-2233-4455',
                'email'          => 'supply@sumberdairy.test',
                'address'        => 'Jl. Raya Bogor KM 28, Cimanggis, Depok',
                'is_active'      => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'id'             => 903,
                'name'           => 'CV Surya Packaging & Flavor',
                'contact_person' => 'Budi Hartono',
                'phone'          => '0811-5566-7788',
                'email'          => 'sales@suryapack.test',
                'address'        => 'Sentra Pergudangan Dadap, Tangerang',
                'is_active'      => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'id'             => 904,
                'name'           => 'Artisan Pastry Frozen Bake',
                'contact_person' => 'Chef David',
                'phone'          => '0818-4455-6677',
                'email'          => 'bakery@artisanfrozen.test',
                'address'        => 'Bintaro Jaya Sektor 7, Tangerang Selatan',
                'is_active'      => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
        ];

        DB::table('suppliers')->insert($suppliers);
    }

    protected function getBaseItemDefinitions(): array
    {
        return [
            1  => ['sku' => 'RAW-COF-01', 'name' => 'Biji Kopi House Blend (70:30)', 'type' => 'raw_material', 'base_uom' => 'g',       'cost_price' => 180.0,   'sales_price' => 0.0, 'reorder_point' => 2500.0],
            2  => ['sku' => 'RAW-MLK-01', 'name' => 'Fresh Milk UHT Full Cream',      'type' => 'raw_material', 'base_uom' => 'ml',      'cost_price' => 22.0,    'sales_price' => 0.0, 'reorder_point' => 12000.0],
            3  => ['sku' => 'RAW-SWT-01', 'name' => 'Gula Aren Cair Organik',         'type' => 'raw_material', 'base_uom' => 'ml',      'cost_price' => 35.0,    'sales_price' => 0.0, 'reorder_point' => 2000.0],
            4  => ['sku' => 'RAW-SYR-01', 'name' => 'Sirup Karamel Gourmet',          'type' => 'raw_material', 'base_uom' => 'ml',      'cost_price' => 120.0,   'sales_price' => 0.0, 'reorder_point' => 750.0],
            5  => ['sku' => 'RAW-MAT-01', 'name' => 'Pure Matcha Uji Powder',         'type' => 'raw_material', 'base_uom' => 'g',       'cost_price' => 300.0,   'sales_price' => 0.0, 'reorder_point' => 500.0],
            6  => ['sku' => 'RAW-CHO-01', 'name' => 'Dark Chocolate Couverture',      'type' => 'raw_material', 'base_uom' => 'g',       'cost_price' => 250.0,   'sales_price' => 0.0, 'reorder_point' => 800.0],
            7  => ['sku' => 'RAW-TEA-01', 'name' => 'Daun Teh Earl Grey Import',      'type' => 'raw_material', 'base_uom' => 'g',       'cost_price' => 150.0,   'sales_price' => 0.0, 'reorder_point' => 300.0],
            8  => ['sku' => 'PKG-CUP-12', 'name' => 'Paper Cup Cold 12oz + Lid',      'type' => 'raw_material', 'base_uom' => 'pcs',     'cost_price' => 1100.0,  'sales_price' => 0.0, 'reorder_point' => 150.0],
            9  => ['sku' => 'PKG-CUP-08', 'name' => 'Paper Cup Hot 8oz + Lid',        'type' => 'raw_material', 'base_uom' => 'pcs',     'cost_price' => 950.0,   'sales_price' => 0.0, 'reorder_point' => 100.0],
            10 => ['sku' => 'PKG-STR-01', 'name' => 'Sedotan Steril Oxo-Biodegradable','type' => 'raw_material', 'base_uom' => 'pcs',    'cost_price' => 120.0,   'sales_price' => 0.0, 'reorder_point' => 200.0],
            11 => ['sku' => 'FZN-PAS-01', 'name' => 'Adonan Beku Butter Croissant',   'type' => 'finished_good','base_uom' => 'pcs',     'cost_price' => 14000.0, 'sales_price' => 0.0, 'reorder_point' => 20.0],
            12 => ['sku' => 'RAW-BEEF-01','name' => 'Daging Sapi Iris + Saus Teriyaki','type' => 'raw_material', 'base_uom' => 'portion', 'cost_price' => 18000.0, 'sales_price' => 0.0, 'reorder_point' => 10.0],
            13 => ['sku' => 'RAW-RIC-01', 'name' => 'Beras Pulen Premium',            'type' => 'raw_material', 'base_uom' => 'g',       'cost_price' => 20.0,    'sales_price' => 0.0, 'reorder_point' => 3000.0],
        ];
    }

    protected function seedItems(): void
    {
        echo "[5/13] Seeding Items (13 items x 3 outlets = 39 items)...\n";
        $now = '2026-08-01 00:00:00';
        $baseItems = $this->getBaseItemDefinitions();

        $rows = [];
        foreach ([901, 902, 903] as $outletId) {
            $prefix = $outletId * 100; // 90100, 90200, 90300
            foreach ($baseItems as $num => $def) {
                $itemId = $prefix + $num; // 90101..90113, 90201..90213, 90301..90313
                $rows[] = [
                    'id'            => $itemId,
                    'outlet_id'     => $outletId,
                    'sku'           => $def['sku'] . "-O{$outletId}",
                    'name'          => $def['name'],
                    'type'          => $def['type'],
                    'base_uom'      => $def['base_uom'],
                    'cost_price'    => $def['cost_price'],
                    'sales_price'   => $def['sales_price'],
                    'reorder_point' => $def['reorder_point'],
                    'is_active'     => 1,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }
        }

        DB::table('items')->insert($rows);
    }

    protected function seedItemUoms(): void
    {
        echo "[6/13] Seeding Item UOMs (Packaging conversions)...\n";
        $now = '2026-08-01 00:00:00';
        $conversions = [
            1  => ['uom_name' => 'pack_kg',    'conversion_factor' => 1000.0],
            2  => ['uom_name' => 'karton_12L', 'conversion_factor' => 12000.0],
            3  => ['uom_name' => 'botol_1L',   'conversion_factor' => 1000.0],
            4  => ['uom_name' => 'botol_750ml','conversion_factor' => 750.0],
            8  => ['uom_name' => 'dus_500pcs', 'conversion_factor' => 500.0],
            11 => ['uom_name' => 'box_50pcs',  'conversion_factor' => 50.0],
        ];

        $rows = [];
        foreach ([901, 902, 903] as $outletId) {
            $prefix = $outletId * 100;
            foreach ($conversions as $itemNum => $conv) {
                $itemId = $prefix + $itemNum;
                $rows[] = [
                    'item_id'           => $itemId,
                    'uom_name'          => $conv['uom_name'],
                    'conversion_factor' => $conv['conversion_factor'],
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];
            }
        }

        DB::table('item_uoms')->insert($rows);
    }

    protected function seedItemSuppliers(): void
    {
        echo "[7/13] Seeding Item Supplier mappings...\n";
        $now = '2026-08-01 00:00:00';
        $mapping = [
            1  => ['supplier_id' => 901, 'supplier_price' => 180000.0, 'lead_time_days' => 3], // Biji Kopi
            2  => ['supplier_id' => 902, 'supplier_price' => 22000.0,  'lead_time_days' => 1], // Fresh Milk
            3  => ['supplier_id' => 903, 'supplier_price' => 35000.0,  'lead_time_days' => 2], // Gula Aren
            4  => ['supplier_id' => 903, 'supplier_price' => 90000.0,  'lead_time_days' => 2], // Sirup Karamel
            5  => ['supplier_id' => 903, 'supplier_price' => 300000.0, 'lead_time_days' => 3], // Matcha
            6  => ['supplier_id' => 903, 'supplier_price' => 250000.0, 'lead_time_days' => 3], // Cokelat
            7  => ['supplier_id' => 903, 'supplier_price' => 150000.0, 'lead_time_days' => 3], // Teh
            8  => ['supplier_id' => 903, 'supplier_price' => 550000.0, 'lead_time_days' => 2], // Cup 12oz
            9  => ['supplier_id' => 903, 'supplier_price' => 475000.0, 'lead_time_days' => 2], // Cup 8oz
            10 => ['supplier_id' => 903, 'supplier_price' => 60000.0,  'lead_time_days' => 2], // Sedotan
            11 => ['supplier_id' => 904, 'supplier_price' => 700000.0, 'lead_time_days' => 4], // Croissant
            12 => ['supplier_id' => 903, 'supplier_price' => 18000.0,  'lead_time_days' => 2], // Daging Sapi
            13 => ['supplier_id' => 903, 'supplier_price' => 20000.0,  'lead_time_days' => 2], // Beras
        ];

        $rows = [];
        foreach ([901, 902, 903] as $outletId) {
            $prefix = $outletId * 100;
            foreach ($mapping as $itemNum => $map) {
                $itemId = $prefix + $itemNum;
                $rows[] = [
                    'item_id'        => $itemId,
                    'supplier_id'    => $map['supplier_id'],
                    'supplier_price' => $map['supplier_price'],
                    'lead_time_days' => $map['lead_time_days'],
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }
        }

        DB::table('item_supplier')->insert($rows);
    }

    protected function getBaseMenuDefinitions(): array
    {
        return [
            1 => ['name' => 'Kopi Susu Gula Aren',    'category' => 'Kopi Susu',             'price' => 22000.0, 'cost_price' => 7360.0,  'is_kds' => 1, 'unit' => 'cup'],
            2 => ['name' => 'Cafe Latte 12oz',        'category' => 'Kopi Susu',             'price' => 25000.0, 'cost_price' => 8420.0,  'is_kds' => 1, 'unit' => 'cup'],
            3 => ['name' => 'Espresso Double Shot',    'category' => 'Manual Brew & Espresso','price' => 16000.0, 'cost_price' => 4190.0,  'is_kds' => 1, 'unit' => 'cup'],
            4 => ['name' => 'Caramel Macchiato',       'category' => 'Kopi Susu',             'price' => 28000.0, 'cost_price' => 10760.0, 'is_kds' => 1, 'unit' => 'cup'],
            5 => ['name' => 'Matcha Latte',            'category' => 'Non-Kopi',              'price' => 26000.0, 'cost_price' => 9240.0,  'is_kds' => 1, 'unit' => 'cup'],
            6 => ['name' => 'Artisan Chocolate Ice',   'category' => 'Non-Kopi',              'price' => 25000.0, 'cost_price' => 13490.0, 'is_kds' => 1, 'unit' => 'cup'],
            7 => ['name' => 'Earl Grey Pure Tea',      'category' => 'Teh',                   'price' => 18000.0, 'cost_price' => 1700.0,  'is_kds' => 0, 'unit' => 'cup'],
            8 => ['name' => 'Butter Croissant',        'category' => 'Makanan & Pastry',      'price' => 24000.0, 'cost_price' => 14000.0, 'is_kds' => 0, 'unit' => 'pcs'],
            9 => ['name' => 'Beef Teriyaki Rice Bowl', 'category' => 'Makanan & Pastry',      'price' => 38000.0, 'cost_price' => 21000.0, 'is_kds' => 1, 'unit' => 'portion'],
        ];
    }

    protected function seedMenus(): void
    {
        echo "[8/13] Seeding Menus (9 menus x 3 outlets = 27 menus)...\n";
        $now = '2026-08-01 00:00:00';
        $baseMenus = $this->getBaseMenuDefinitions();

        $rows = [];
        foreach ([901, 902, 903] as $outletId) {
            $userId = $outletId;
            $prefix = $outletId * 100 + 50;
            foreach ($baseMenus as $num => $def) {
                $menuId = $prefix + $num; // 90151..90159, 90251..90259, 90351..90359
                $rows[] = [
                    'id'           => $menuId,
                    'user_id'      => $userId,
                    'outlet_id'    => $outletId,
                    'name'         => $def['name'],
                    'category'     => $def['category'],
                    'price'        => $def['price'],
                    'cost_price'   => $def['cost_price'],
                    'stock'        => 999.0, // stock tracked via BOM
                    'unit'         => $def['unit'],
                    'is_kds'       => $def['is_kds'],
                    'is_available' => 1,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ];
            }
        }

        DB::table('menus')->insert($rows);
    }

    protected function seedMenuIngredients(): void
    {
        echo "[9/13] Seeding Menu Ingredients (BOM: 24 recipes x 3 = 72 rows)...\n";
        $now = '2026-08-01 00:00:00';
        // Base BOM Recipes: menuNum => [[itemNum, qty], ...]
        $bomRecipes = [
            1 => [[1, 18.0], [2, 100.0], [3, 20.0], [8, 1.0], [10, 1.0]],   // M01: Susu Gula Aren
            2 => [[1, 18.0], [2, 180.0], [8, 1.0], [10, 1.0]],               // M02: Cafe Latte
            3 => [[1, 18.0], [9, 1.0]],                                       // M03: Espresso Double
            4 => [[1, 18.0], [2, 150.0], [4, 25.0], [8, 1.0], [10, 1.0]],   // M04: Caramel Macchiato
            5 => [[5, 15.0], [2, 160.0], [8, 1.0], [10, 1.0]],               // M05: Matcha Latte
            6 => [[6, 35.0], [2, 160.0], [8, 1.0], [10, 1.0]],               // M06: Artisan Chocolate
            7 => [[7, 5.0],  [9, 1.0]],                                       // M07: Earl Grey Tea
            8 => [[11, 1.0]],                                                 // M08: Butter Croissant
            9 => [[12, 1.0], [13, 150.0]],                                    // M09: Beef Teriyaki Rice Bowl
        ];

        $rows = [];
        foreach ([901, 902, 903] as $outletId) {
            $menuPrefix = $outletId * 100 + 50;
            $itemPrefix = $outletId * 100;
            foreach ($bomRecipes as $menuNum => $ingredients) {
                $menuId = $menuPrefix + $menuNum;
                foreach ($ingredients as $ing) {
                    [$itemNum, $qty] = $ing;
                    $itemId = $itemPrefix + $itemNum;
                    $rows[] = [
                        'menu_id'    => $menuId,
                        'outlet_id'  => $outletId,
                        'item_id'    => $itemId,
                        'qty'        => $qty,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        DB::table('menu_ingredients')->insert($rows);
    }

    protected function seedTransactionsAndItems(): array
    {
        echo "[10/13] Seeding 60-Day Transactions & Line Items (2026-08-06 .. 2026-10-04)...\n";

        $startDate = Carbon::parse('2026-08-06', 'Asia/Jakarta');
        $endDate   = Carbon::parse('2026-10-04', 'Asia/Jakarta');

        $baseMenus = $this->getBaseMenuDefinitions();

        $txCounter = 900000;
        $tiCounter = 9000000;

        $totalTransactions = 0;
        $totalItems = 0;

        $chunkSize = 500;
        $txBuffer = [];
        $tiBuffer = [];

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $dayIndex = (int) $startDate->diffInDays($date) + 1; // 1 to 60
            $dateStr = $date->format('Y-m-d');
            $dow = (int) $date->dayOfWeek; // 0=Sun, 6=Sat
            $isWeekend = ($dow === 0 || $dow === 5 || $dow === 6); // Fri, Sat, Sun

            foreach ([901, 902, 903] as $outletId) {
                $userId = $outletId;
                $prefix = $outletId * 100 + 50;

                // Determine target transactions count for this day & scenario
                $count = 0;
                $stressMode = false;
                $growthMode = false;

                if ($outletId === 901) {
                    // Healthy Baseline: ~65 weekdays, ~90 weekends (+-5 random)
                    $baseCount = $isWeekend ? 88 : 64;
                    $count = $baseCount + (($dayIndex % 7) - 3);
                } elseif ($outletId === 902) {
                    // Financial Stress:
                    // Days 1-52 (Normal Baseline): ~75 trx/day
                    // Days 53-60 (Sharp Stress Drop): ~32 trx/day (-55% decline, triggers REVENUE_DECLINE >25%)
                    if ($dayIndex <= 52) {
                        $baseCount = $isWeekend ? 82 : 68;
                        $count = $baseCount + (($dayIndex % 5) - 2);
                    } else {
                        $stressMode = true;
                        $baseCount = $isWeekend ? 36 : 30;
                        $count = $baseCount + (($dayIndex % 5) - 2);
                    }
                } elseif ($outletId === 903) {
                    // Growth & Opportunity:
                    // Days 1-30: ~50-55 trx/day
                    // Days 31-60: ~105-120 trx/day (+114% growth!)
                    if ($dayIndex <= 30) {
                        $baseCount = $isWeekend ? 60 : 50;
                        $count = $baseCount + (($dayIndex % 5) - 2);
                    } else {
                        $growthMode = true;
                        $baseCount = $isWeekend ? 122 : 104;
                        $count = $baseCount + (($dayIndex % 7) - 3);
                    }
                }

                // Generate transactions for this outlet on this date
                for ($i = 0; $i < $count; $i++) {
                    $txCounter++;
                    $txId = $txCounter;

                    // Distribute hours across day: 08:00 to 21:30 WIB
                    $hour = 8 + (int) (($i / max(1, $count)) * 13);
                    $min  = ($i * 7) % 60;
                    $sec  = ($i * 13) % 60;

                    $wibTime = sprintf('%s %02d:%02d:%02d', $dateStr, $hour, $min, $sec);
                    // Timezone conversion to UTC
                    $utcTime = Carbon::parse($wibTime, 'Asia/Jakarta')->timezone('UTC')->format('Y-m-d H:i:s');

                    // Determine transaction status: Selesai vs Cancelled/Batal
                    $status = 'Selesai';

                    // Controlled Voids:
                    // For Scenario B (Outlet 902), days 53-60 have ~8-10% void rate (status 'Batal' matching CANCEL_STATUSES)
                    if ($outletId === 902 && $dayIndex >= 53 && ($i % 12 === 0)) {
                        $status = 'Batal';
                    } elseif ($outletId === 901 && ($i === 15 && $dayIndex % 3 === 0)) {
                        // Healthy: very rare voids (~0.5%)
                        $status = 'Batal';
                    }

                    // Payment method: Cash (60%), QRIS (30%), Transfer/Debit (10%)
                    $paymentMethod = ($i % 10 < 6) ? 'Cash' : (($i % 10 < 9) ? 'QRIS' : 'Transfer');

                    // Pick 1-3 menu items
                    $itemsInOrder = [];
                    // Probability distribution: M01 is star product
                    if ($growthMode) {
                        // High demand for M01 Kopi Susu Aren
                        $itemsInOrder[] = 1;
                        if ($i % 2 === 0) $itemsInOrder[] = 1; // Double M01
                        if ($i % 3 === 0) $itemsInOrder[] = 2; // Cafe Latte
                        if ($i % 4 === 0) $itemsInOrder[] = 8; // Croissant
                    } else {
                        $p = ($i * 3 + $dayIndex) % 100;
                        if ($p < 40) {
                            $itemsInOrder[] = 1; // M01
                        } elseif ($p < 65) {
                            $itemsInOrder[] = 2; // M02
                        } elseif ($p < 75) {
                            $itemsInOrder[] = 4; // M04
                        } elseif ($p < 85) {
                            $itemsInOrder[] = 5; // M05
                        } elseif ($p < 92) {
                            $itemsInOrder[] = 6; // M06
                        } elseif ($p < 96) {
                            $itemsInOrder[] = 3; // M03
                        } else {
                            $itemsInOrder[] = 7; // M07
                        }

                        // Add food or pastry companion
                        if ($i % 5 === 0) {
                            $itemsInOrder[] = 8; // Croissant
                        } elseif ($i % 14 === 0) {
                            $itemsInOrder[] = 9; // Rice bowl
                        }
                    }

                    // Calculate totals
                    $subtotal = 0.0;
                    $orderItemsData = [];

                    foreach ($itemsInOrder as $mNum) {
                        $tiCounter++;
                        $menuDef = $baseMenus[$mNum];
                        $menuId  = $prefix + $mNum;
                        $price   = $menuDef['price'];
                        $cost    = $menuDef['cost_price'];

                        // In Scenario B (Stress) Days 54-60: Margin compression via higher cost price snapshot
                        if ($outletId === 902 && $dayIndex >= 54) {
                            // HPP spike due to expensive substitute ingredients
                            $cost = round($cost * 1.25, 2);
                        }

                        $subtotal += $price;

                        $orderItemsData[] = [
                            'id'                => $tiCounter,
                            'user_id'           => $userId,
                            'transaction_id'    => $txId,
                            'menu_id'           => $menuId,
                            'menu_name'         => $menuDef['name'],
                            'qty'               => 1,
                            'price'             => $price,
                            'cost_price'        => $cost,
                            'status'            => $status,
                            'is_sent_to_kds'    => $menuDef['is_kds'],
                            'kds_status'        => $status === 'Selesai' ? 'DONE' : 'CANCELLED',
                            'qty_done'          => $status === 'Selesai' ? 1 : 0,
                            'category_snapshot' => $menuDef['category'],
                            'note'              => null,
                            'created_at'        => $utcTime,
                            'updated_at'        => $utcTime,
                        ];
                    }

                    $discountAmount = 0.0;
                    // Scenario B Days 54-60: occasional heavy discounting (10-15%) reducing gross margin
                    if ($outletId === 902 && $dayIndex >= 54 && ($i % 3 === 0)) {
                        $discountAmount = round($subtotal * 0.15, 0);
                    }

                    $totalAmount = max(0.0, $subtotal - $discountAmount);
                    $payAmount   = $status === 'Selesai' ? $totalAmount : 0.0;

                    $txBuffer[] = [
                        'id'                        => $txId,
                        'user_id'                   => $userId,
                        'staff_id'                  => null,
                        'outlet_id'                 => $outletId,
                        'app_uuid'                  => "uuid-tx-{$txId}",
                        'official_invoice_number'   => sprintf('INV/%s/%03d/%06d', $date->format('ymd'), $outletId, $i + 1),
                        'total_amount'              => $totalAmount,
                        'pay_amount'                => $payAmount,
                        'payment_method'            => $paymentMethod,
                        'order_type'                => 'Dine In',
                        'customer_name'             => 'Customer ' . ($i + 1),
                        'status'                    => $status,
                        'cashier_name'              => 'Barista Popoyo',
                        'table_number'              => (string) (($i % 15) + 1),
                        'created_at_device'         => $utcTime,
                        'created_at'                => $utcTime,
                        'updated_at'                => $utcTime,
                        'discount_amount'           => $discountAmount,
                        'ppn_percentage'            => 0.0,
                        'ppn_amount'                => 0.0,
                        'service_charge_percentage' => 0.0,
                        'service_charge_amount'     => 0.0,
                        'subtotal'                  => $subtotal,
                    ];

                    foreach ($orderItemsData as $oid) {
                        $tiBuffer[] = $oid;
                    }

                    // Flush buffer when chunk reached
                    if (count($txBuffer) >= $chunkSize) {
                        DB::table('transactions')->insert($txBuffer);
                        DB::table('transaction_items')->insert($tiBuffer);
                        $totalTransactions += count($txBuffer);
                        $totalItems += count($tiBuffer);
                        $txBuffer = [];
                        $tiBuffer = [];
                    }
                }
            }
        }

        // Final flush
        if (!empty($txBuffer)) {
            DB::table('transactions')->insert($txBuffer);
            DB::table('transaction_items')->insert($tiBuffer);
            $totalTransactions += count($txBuffer);
            $totalItems += count($tiBuffer);
        }

        echo "  -> Seeded {$totalTransactions} transactions and {$totalItems} transaction items.\n";

        return [
            'transactions_count' => $totalTransactions,
            'items_count'        => $totalItems,
        ];
    }

    protected function seedExpenses(): array
    {
        echo "[11/13] Seeding Expenses...\n";

        $rows = [];
        $expenseId = 9000;

        foreach ([901, 902, 903] as $outletId) {
            $userId = $outletId;

            // Monthly Fixed Expenses:
            // Rent: Aug 1 & Sep 1
            $rows[] = ['id' => ++$expenseId, 'user_id' => $userId, 'outlet_id' => $outletId, 'name' => 'Sewa Tempat Agustus 2026', 'amount' => 6000000.0, 'category' => 'Sewa',     'date' => '2026-08-01', 'note' => 'Sewa ruko bulanan', 'supplier' => 'Pengelola Properti', 'created_at' => '2026-08-01 08:00:00', 'updated_at' => '2026-08-01 08:00:00'];
            $rows[] = ['id' => ++$expenseId, 'user_id' => $userId, 'outlet_id' => $outletId, 'name' => 'Sewa Tempat September 2026','amount' => 6000000.0, 'category' => 'Sewa',     'date' => '2026-09-01', 'note' => 'Sewa ruko bulanan', 'supplier' => 'Pengelola Properti', 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00'];

            // Utilities: Aug 10 & Sep 10
            $utilAmount = ($outletId === 903) ? 2400000.0 : 1850000.0;
            $rows[] = ['id' => ++$expenseId, 'user_id' => $userId, 'outlet_id' => $outletId, 'name' => 'Listrik & PDAM Agustus 2026', 'amount' => $utilAmount, 'category' => 'Utilitas', 'date' => '2026-08-10', 'note' => 'Tagihan listrik & air', 'supplier' => 'PLN / PDAM', 'created_at' => '2026-08-10 09:00:00', 'updated_at' => '2026-08-10 09:00:00'];
            $rows[] = ['id' => ++$expenseId, 'user_id' => $userId, 'outlet_id' => $outletId, 'name' => 'Listrik & PDAM September 2026','amount' => $utilAmount, 'category' => 'Utilitas', 'date' => '2026-09-10', 'note' => 'Tagihan listrik & air', 'supplier' => 'PLN / PDAM', 'created_at' => '2026-09-10 09:00:00', 'updated_at' => '2026-09-10 09:00:00'];

            // Salaries: Aug 25 & Sep 25 (paid before end-of-month window)
            $salaryAmount = ($outletId === 903) ? 7500000.0 : (($outletId === 902) ? 4500000.0 : 6000000.0);
            $rows[] = ['id' => ++$expenseId, 'user_id' => $userId, 'outlet_id' => $outletId, 'name' => 'Gaji Karyawan Agustus 2026',  'amount' => $salaryAmount, 'category' => 'Gaji',     'date' => '2026-08-25', 'note' => 'Gaji 2 barista & staff', 'supplier' => 'Payroll', 'created_at' => '2026-08-25 17:00:00', 'updated_at' => '2026-08-25 17:00:00'];
            $rows[] = ['id' => ++$expenseId, 'user_id' => $userId, 'outlet_id' => $outletId, 'name' => 'Gaji Karyawan September 2026','amount' => $salaryAmount, 'category' => 'Gaji',     'date' => '2026-09-25', 'note' => 'Gaji 2 barista & staff', 'supplier' => 'Payroll', 'created_at' => '2026-09-25 17:00:00', 'updated_at' => '2026-09-25 17:00:00'];

            // Daily Operational Ice (every day)
            $icePrice = ($outletId === 903) ? 40000.0 : (($outletId === 902) ? 20000.0 : 25000.0);
            $startDate = Carbon::parse('2026-08-06');
            $endDate   = Carbon::parse('2026-10-04');
            for ($d = $startDate->copy(); $d->lte($endDate); $d->addDay()) {
                $curDate = $d->format('Y-m-d');
                $rows[] = [
                    'id'         => ++$expenseId,
                    'user_id'    => $userId,
                    'outlet_id'  => $outletId,
                    'name'       => 'Es Batu Kristal Higienis',
                    'amount'     => $icePrice,
                    'category'   => 'Operasional',
                    'date'       => $curDate,
                    'note'       => 'Es batu harian',
                    'supplier'   => 'Depo Es Segar',
                    'created_at' => "{$curDate} 07:30:00",
                    'updated_at' => "{$curDate} 07:30:00",
                ];
            }

            // Scenario Specific Expenses:
            if ($outletId === 901) {
                // Minor maintenance
                $rows[] = ['id' => ++$expenseId, 'user_id' => $userId, 'outlet_id' => $outletId, 'name' => 'Cuci AC & Servis Rutin', 'amount' => 350000.0, 'category' => 'Pemeliharaan', 'date' => '2026-08-20', 'note' => 'Perawatan rutin AC', 'supplier' => 'Teknisi AC Mandiri', 'created_at' => '2026-08-20 11:00:00', 'updated_at' => '2026-08-20 11:00:00'];
            } elseif ($outletId === 902) {
                // EXPENSE SPIKE: Oct 01 (Day 57) - Servis Mesin Espresso Rp 5.500.000 (Spike > 1.5x)
                $rows[] = [
                    'id'         => ++$expenseId,
                    'user_id'    => $userId,
                    'outlet_id'  => $outletId,
                    'name'       => 'Servis Mesin Espresso & Ganti Boiler',
                    'amount'     => 5500000.0,
                    'category'   => 'Pemeliharaan',
                    'date'       => '2026-10-01',
                    'note'       => 'Kerusakan boiler mesin utama (darurat)',
                    'supplier'   => 'CV Teknik Kopi Presisi',
                    'created_at' => '2026-10-01 14:00:00',
                    'updated_at' => '2026-10-01 14:00:00',
                ];
            } elseif ($outletId === 903) {
                // Growth marketing promo
                $rows[] = ['id' => ++$expenseId, 'user_id' => $userId, 'outlet_id' => $outletId, 'name' => 'Pemasangan Banner & Ads Sosmed', 'amount' => 800000.0, 'category' => 'Pemasaran', 'date' => '2026-09-15', 'note' => 'Promosi menu seasonal', 'supplier' => 'Meta Ads & Percetakan', 'created_at' => '2026-09-15 10:00:00', 'updated_at' => '2026-09-15 10:00:00'];
            }
        }

        DB::table('expenses')->insert($rows);
        echo "  -> Seeded " . count($rows) . " expense records.\n";

        return ['expenses_count' => count($rows)];
    }

    protected function seedShifts(): array
    {
        echo "[12/13] Seeding Daily Shifts (60 days x 3 outlets = 180 shifts)...\n";

        $startDate = Carbon::parse('2026-08-06', 'Asia/Jakarta');
        $endDate   = Carbon::parse('2026-10-04', 'Asia/Jakarta');

        $rows = [];
        $shiftId = 9000;

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $dayIndex = (int) $startDate->diffInDays($date) + 1;
            $dateStr = $date->format('Y-m-d');

            foreach ([901, 902, 903] as $outletId) {
                $userId = $outletId;
                $shiftId++;

                // Compute exact cash sales from transactions table for this date & outlet
                [$startUtc, $endUtc] = TimezoneContract::businessDateToUtcRange($dateStr, 'Asia/Jakarta');

                $cashSales = (float) DB::table('transactions')
                    ->where('user_id', $userId)
                    ->where('outlet_id', $outletId)
                    ->where('status', 'Selesai')
                    ->where('payment_method', 'Cash')
                    ->where('created_at_device', '>=', $startUtc)
                    ->where('created_at_device', '<', $endUtc)
                    ->sum('total_amount');

                $startCash = 300000.0; // Modal awal tetap Rp 300.000
                $expectedCash = $startCash + $cashSales;
                $endCash = $expectedCash;
                $diff = 0.0;

                // DRAWER DEFICIT TRIGGER: Scenario B (Outlet 902) on Oct 04 (latest shift)
                // -Rp 130.000 deficit (-8.96% of cash sales)
                if ($outletId === 902 && $dateStr === '2026-10-04') {
                    $diff = -130000.0;
                    $endCash = $expectedCash + $diff;
                }

                $startTime = "{$dateStr} 07:30:00";
                $endTime   = "{$dateStr} 22:30:00";

                $rows[] = [
                    'id'                  => $shiftId,
                    'user_id'             => $userId,
                    'staff_id'            => null,
                    'outlet_id'           => $outletId,
                    'app_uuid'            => "shift-uuid-{$shiftId}",
                    'cashier_name'        => 'Barista Popoyo',
                    'start_time'          => $startTime,
                    'end_time'            => $endTime,
                    'start_cash'          => $startCash,
                    'end_cash'            => $endCash,
                    'total_cash_sales'    => $cashSales,
                    'total_expense'       => 0.0,
                    'cash_drop'           => 0.0,
                    'total_cash_refunded' => 0.0,
                    'expected_cash'       => $expectedCash,
                    'difference'          => $diff,
                    'payment_details'     => json_encode(['cash' => $cashSales]),
                    'status'              => 'closed',
                    'created_at'          => $startTime,
                    'updated_at'          => $endTime,
                ];
            }
        }

        DB::table('shifts')->insert($rows);
        echo "  -> Seeded " . count($rows) . " closed shifts.\n";

        return ['shifts_count' => count($rows)];
    }

    protected function seedStockMovements(): array
    {
        echo "[13/13] Seeding Stock Movements (Opening Stock, Restocks, and Consumption)...\n";

        $rows = [];
        $smId = 90000;
        $now = '2026-08-01 00:00:00';

        // Calculate actual consumption per item per outlet over 60 days
        foreach ([901, 902, 903] as $outletId) {
            $userId = $outletId;
            $prefix = $outletId * 100;

            // Fetch BOM consumption per menu item
            $bomMap = [];
            $boms = DB::table('menu_ingredients')
                ->where('outlet_id', $outletId)
                ->get();
            foreach ($boms as $b) {
                $bomMap[$b->menu_id][] = ['item_id' => $b->item_id, 'qty' => (float)$b->qty];
            }

            // Total sales quantity per menu for this outlet
            $menuSales = DB::table('transaction_items')
                ->where('user_id', $userId)
                ->where('status', 'Selesai')
                ->select('menu_id', DB::raw('SUM(qty) as total_qty'))
                ->groupBy('menu_id')
                ->pluck('total_qty', 'menu_id')
                ->toArray();

            // Calculate total consumption per item
            $consumedPerItem = [];
            foreach ($menuSales as $menuId => $qtySold) {
                if (isset($bomMap[$menuId])) {
                    foreach ($bomMap[$menuId] as $ing) {
                        $itemId = $ing['item_id'];
                        $consumedPerItem[$itemId] = ($consumedPerItem[$itemId] ?? 0.0) + ($ing['qty'] * (float)$qtySold);
                    }
                }
            }

            // Seed Initial Opening Stock + Restocks so ending balance is realistic
            $baseItems = $this->getBaseItemDefinitions();
            foreach ($baseItems as $num => $def) {
                $itemId = $prefix + $num;
                $consumed = $consumedPerItem[$itemId] ?? 0.0;

                // Determine target ending stock:
                $targetEndingStock = 0.0;

                if ($outletId === 902 && $num === 2) {
                    // Scenario B: ITM02 (Fresh Milk) has CRITICAL STOCKOUT RISK
                    // Ending stock = 2,500 ml (less than 1 day consumption!)
                    $targetEndingStock = 2500.0;
                } elseif ($outletId === 903 && $num === 2) {
                    // Scenario C: Fresh Milk under pressure due to explosive growth
                    $targetEndingStock = 15000.0; // ~1 day buffer
                } else {
                    // Healthy comfortable stock
                    $targetEndingStock = $def['reorder_point'] * 3.0;
                }

                $totalIncomingRequired = $consumed + $targetEndingStock;

                // 1. Opening Balance on 2026-08-01
                $openingPortion = round($totalIncomingRequired * 0.4, 2);
                $restockPortion = round($totalIncomingRequired - $openingPortion, 2);

                $rows[] = [
                    'id'             => ++$smId,
                    'menu_id'        => null,
                    'outlet_id'      => $outletId,
                    'user_id'        => $userId,
                    'staff_id'       => null,
                    'device_id'      => 'POS-O' . $outletId,
                    'tx_uuid'        => 'init-' . $itemId,
                    'item_id'        => $itemId,
                    'type'           => 'incoming',
                    'qty'            => $openingPortion,
                    'quantity_delta' => $openingPortion,
                    'balance_before' => 0.0,
                    'balance_after'  => $openingPortion,
                    'reference_id'   => 'INIT',
                    'reference_type' => 'opening_balance',
                    'notes'          => 'Saldo awal persediaan per 1 Agustus 2026',
                    'created_at'     => '2026-08-01 00:00:00',
                    'updated_at'     => '2026-08-01 00:00:00',
                ];

                // 2. Mid-period Restocks on 2026-09-01
                $rows[] = [
                    'id'             => ++$smId,
                    'menu_id'        => null,
                    'outlet_id'      => $outletId,
                    'user_id'        => $userId,
                    'staff_id'       => null,
                    'device_id'      => 'POS-O' . $outletId,
                    'tx_uuid'        => 'restock-' . $itemId,
                    'item_id'        => $itemId,
                    'type'           => 'incoming',
                    'qty'            => $restockPortion,
                    'quantity_delta' => $restockPortion,
                    'balance_before' => $openingPortion,
                    'balance_after'  => $totalIncomingRequired,
                    'reference_id'   => 'PO-20260901',
                    'reference_type' => 'purchase_order',
                    'notes'          => 'Penerimaan stok berkala supplier',
                    'created_at'     => '2026-09-01 06:00:00',
                    'updated_at'     => '2026-09-01 06:00:00',
                ];

                // 3. Outgoing consumption ledger
                if ($consumed > 0) {
                    $rows[] = [
                        'id'             => ++$smId,
                        'menu_id'        => null,
                        'outlet_id'      => $outletId,
                        'user_id'        => $userId,
                        'staff_id'       => null,
                        'device_id'      => 'POS-O' . $outletId,
                        'tx_uuid'        => 'sales-cons-' . $itemId,
                        'item_id'        => $itemId,
                        'type'           => 'outgoing',
                        'qty'            => $consumed,
                        'quantity_delta' => -$consumed,
                        'balance_before' => $totalIncomingRequired,
                        'balance_after'  => $targetEndingStock,
                        'reference_id'   => 'SALES-60D',
                        'reference_type' => 'sales_consumption',
                        'notes'          => 'Akumulasi konsumsi penjualan menu 60 hari',
                        'created_at'     => '2026-10-04 12:00:00',
                        'updated_at'     => '2026-10-04 12:00:00',
                    ];
                }
            }
        }

        DB::table('stock_movements')->insert($rows);
        echo "  -> Seeded " . count($rows) . " stock movements.\n";

        return ['stock_movements_count' => count($rows)];
    }
}

// Execute Runner
$seeder = new CopilotSyntheticDataSeeder();
$results = $seeder->run();
echo "\nExecution payload summary:\n" . json_encode($results, JSON_PRETTY_PRINT) . PHP_EOL;
