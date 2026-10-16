<?php

namespace App\Services\Financial;

use App\Models\Menu;
use App\Models\Item;
use App\Models\MenuIngredient;
use App\Helpers\TimezoneContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

/**
 * Stockout Intelligence Service (Phase 3.5)
 *
 * Deterministic stock-risk and inventory depletion engine.
 * Maps demand forecast to recipe (BOM) ingredient consumption,
 * calculates run-rate, lead-time horizon, and classifies risk levels.
 *
 * Fail-Closed Rule:
 * If BOM is missing or data is incomplete, the engine NEVER invents or infers
 * ingredient consumption. It returns UNSUPPORTED or INSUFFICIENT_DATA.
 */
class StockoutIntelligenceService
{
    // Stock Risk Statuses
    public const STATUS_NORMAL         = 'NORMAL';
    public const STATUS_WATCH          = 'WATCH';
    public const STATUS_STOCKOUT_RISK  = 'STOCKOUT_RISK';
    public const STATUS_STOCKOUT       = 'STOCKOUT';
    public const STATUS_UNSUPPORTED    = 'UNSUPPORTED';
    public const STATUS_INSUFFICIENT   = 'INSUFFICIENT_DATA';

    // Unsupported / Failure Reasons
    public const REASON_MISSING_BOM                 = 'MISSING_BOM';
    public const REASON_UNTRACKED_STOCK             = 'UNTRACKED_STOCK';
    public const REASON_ANOMALOUS_STOCK             = 'ANOMALOUS_STOCK';
    public const REASON_ZERO_DEMAND                 = 'ZERO_DEMAND';
    public const REASON_INCOMPLETE_ITEM             = 'INCOMPLETE_ITEM_RECORD';
    public const REASON_UNIT_CONVERSION_UNAVAILABLE = 'UNIT_CONVERSION_UNAVAILABLE';
    public const REASON_UNIT_DIMENSION_MISMATCH     = 'UNIT_DIMENSION_MISMATCH';
    public const REASON_UNKNOWN_UNIT                = 'UNKNOWN_UNIT';
    public const REASON_MISSING_ITEM_CONVERSION     = 'MISSING_ITEM_CONVERSION';
    public const REASON_INVALID_CONVERSION_FACTOR   = 'INVALID_CONVERSION_FACTOR';
    public const REASON_INVENTORY_SCHEMA_UNAVAILABLE = 'INVENTORY_SCHEMA_UNAVAILABLE';

    // Schema existence caches
    protected array $tableExistsCache = [];
    protected array $columnExistsCache = [];

    public function __construct(
        protected MenuDemandForecastService $demandService,
        protected ?UnitOfMeasureService $uomService = null
    ) {
        $this->uomService = $uomService ?? app(UnitOfMeasureService::class);
    }

    /**
     * Check if table exists in database schema (cached).
     */
    protected function tableExists(string $table): bool
    {
        if (!isset($this->tableExistsCache[$table])) {
            try {
                $this->tableExistsCache[$table] = Schema::hasTable($table);
            } catch (\Throwable) {
                $this->tableExistsCache[$table] = false;
            }
        }

        return $this->tableExistsCache[$table];
    }

    /**
     * Check if column exists in table (cached).
     */
    protected function hasColumn(string $table, string $column): bool
    {
        $key = "{$table}.{$column}";
        if (!isset($this->columnExistsCache[$key])) {
            try {
                $this->columnExistsCache[$key] = $this->tableExists($table) && Schema::hasColumn($table, $column);
            } catch (\Throwable) {
                $this->columnExistsCache[$key] = false;
            }
        }

        return $this->columnExistsCache[$key];
    }

    /**
     * Manual override for table availability (useful for testing schema absence).
     */
    public function setTableAvailability(string $table, bool $available): void
    {
        $this->tableExistsCache[$table] = $available;
    }

    /**
     * Clear memoized schema caches.
     */
    public function clearSchemaCache(): void
    {
        $this->tableExistsCache = [];
        $this->columnExistsCache = [];
    }

    /**
     * Evaluate stockout risk for a single menu.
     *
     * @param int         $userId
     * @param int         $menuId
     * @param int|null    $outletId
     * @param string|null $anchorDate
     * @param array       $options
     * @return array
     */
    public function evaluateMenuStockRisk(
        int $userId,
        int $menuId,
        ?int $outletId = null,
        ?string $anchorDate = null,
        array $options = []
    ): array {
        // 1. Fetch menu with tenant isolation
        $menu = Menu::where('user_id', $userId)
            ->where('id', $menuId)
            ->when($outletId !== null, function ($q) use ($outletId) {
                $q->where(function ($sub) use ($outletId) {
                    $sub->where('outlet_id', $outletId)
                        ->orWhereNull('outlet_id')
                        ->orWhere('outlet_id', 0);
                });
            })
            ->first();

        if (!$menu) {
            return [
                'status'  => self::STATUS_UNSUPPORTED,
                'reason'  => 'MENU_NOT_FOUND',
                'message' => 'Menu tidak ditemukan atau berada di luar tenant/outlet.',
            ];
        }

        // 2. Fetch forecasted demand for this menu
        $demandForecast = $this->demandService->forecastMenu($userId, $menuId, $outletId, $anchorDate, $options);

        // FAIL-CLOSED CHECK: Demand Forecast Unit Firewall & Data Validity Gate
        if (
            isset($demandForecast['status']) &&
            in_array($demandForecast['status'], [
                UnitOfMeasureService::STATUS_UNSUPPORTED_DATA,
                UnitOfMeasureService::STATUS_UNIT_DIMENSION_MISMATCH,
                UnitOfMeasureService::STATUS_MISSING_METADATA,
                UnitOfMeasureService::STATUS_CONVERSION_UNAVAILABLE,
            ], true)
        ) {
            $reason = $demandForecast['history_classification']['reason']
                ?? ($demandForecast['unit_resolution']['failure_reason'] ?? self::REASON_INSUFFICIENT_HISTORY);
            return [
                'status'            => self::STATUS_UNSUPPORTED,
                'reason'            => $demandForecast['status'],
                'message'           => 'Perhitungan stockout ditolak karena demand forecast tidak kompatibel: ' . $reason,
                'menu'              => [
                    'id'            => $menu->id,
                    'name'          => $menu->name,
                    'unit'          => $menu->unit ?? null,
                    'current_stock' => (float) ($menu->stock ?? -1.0),
                    'stock_mode'    => ((float) ($menu->stock ?? -1.0) == -1.0) ? 'UNTRACKED' : 'TRACKED',
                ],
                'forecasted_demand' => [
                    'daily_qty'     => null,
                    'horizon_days'  => (int) ($demandForecast['window']['horizon_days'] ?? 7),
                ],
                'ingredients_count' => 0,
                'ingredients'       => [],
            ];
        }

        $dailyDemand = (float) ($demandForecast['forecast']['forecast_qty'] ?? 0.0);
        $horizonDays = (int) ($demandForecast['window']['horizon_days'] ?? 7);

        // 3. Inspect BOM (menu_ingredients)
        // SAFE TABLE GUARD: Verify required BOM tables exist before querying
        $missingBOMTables = [];
        if (!$this->tableExists('menu_ingredients')) {
            $missingBOMTables[] = 'menu_ingredients';
        }
        if (!$this->tableExists('items')) {
            $missingBOMTables[] = 'items';
        }

        if (!empty($missingBOMTables)) {
            $currentMenuStock = (float) ($menu->stock ?? -1.0);
            if ($currentMenuStock < 0.0) {
                return [
                    'status'            => self::STATUS_UNSUPPORTED,
                    'reason'            => self::REASON_INVENTORY_SCHEMA_UNAVAILABLE,
                    'message'           => 'Tabel inventori/resep (' . implode(', ', $missingBOMTables) . ') belum tersedia pada skema basis data.',
                    'menu'              => [
                        'id'            => $menu->id,
                        'name'          => $menu->name,
                        'unit'          => $menu->unit ?? null,
                        'current_stock' => $currentMenuStock,
                        'stock_mode'    => 'UNTRACKED',
                    ],
                    'forecasted_demand' => [
                        'daily_qty'     => $dailyDemand,
                        'horizon_days'  => $horizonDays,
                    ],
                    'ingredients_count' => 0,
                    'ingredients'       => [],
                ];
            }

            // Direct finished-goods stock tracking fallback (preserves existing behavior)
            return $this->evaluateDirectMenuStockRisk($menu, $dailyDemand, $horizonDays);
        }

        try {
            $ingredients = DB::table('menu_ingredients as mi')
                ->join('items as i', 'mi.item_id', '=', 'i.id')
                ->where('mi.menu_id', $menuId)
                ->whereNull('i.deleted_at')
                ->select('mi.id as bom_id', 'mi.item_id', 'mi.qty as recipe_qty', 'i.name as item_name', 'i.base_uom', 'i.reorder_point', 'i.outlet_id')
                ->get();
        } catch (\Throwable $e) {
            $currentMenuStock = (float) ($menu->stock ?? -1.0);
            if ($currentMenuStock < 0.0) {
                return [
                    'status'            => self::STATUS_UNSUPPORTED,
                    'reason'            => self::REASON_INVENTORY_SCHEMA_UNAVAILABLE,
                    'message'           => 'Gagal mengakses data resep/inventori: ' . $e->getMessage(),
                    'menu'              => [
                        'id'            => $menu->id,
                        'name'          => $menu->name,
                        'unit'          => $menu->unit ?? null,
                        'current_stock' => $currentMenuStock,
                        'stock_mode'    => 'UNTRACKED',
                    ],
                    'forecasted_demand' => [
                        'daily_qty'     => $dailyDemand,
                        'horizon_days'  => $horizonDays,
                    ],
                    'ingredients_count' => 0,
                    'ingredients'       => [],
                ];
            }

            return $this->evaluateDirectMenuStockRisk($menu, $dailyDemand, $horizonDays);
        }

        // FAIL-CLOSED CHECK: If no BOM is attached
        if ($ingredients->isEmpty()) {
            // Direct finished-goods stock tracking fallback
            $currentMenuStock = (float) $menu->stock;

            if ($currentMenuStock == -1.0) {
                return [
                    'status'            => self::STATUS_UNSUPPORTED,
                    'reason'            => self::REASON_MISSING_BOM,
                    'message'           => 'Menu tidak memiliki resep (BOM) terdaftar dan stok barang jadi tidak dilacak.',
                    'menu'              => [
                        'id'            => $menu->id,
                        'name'          => $menu->name,
                        'current_stock' => -1.0,
                        'stock_mode'    => 'UNTRACKED',
                    ],
                    'forecasted_demand' => [
                        'daily_qty'     => $dailyDemand,
                        'horizon_days'  => $horizonDays,
                    ],
                    'ingredients_count' => 0,
                    'ingredients'       => [],
                ];
            }

            // Direct finished-goods stock risk
            return $this->evaluateDirectMenuStockRisk($menu, $dailyDemand, $horizonDays);
        }

        // SAFE TABLE GUARD: Verify stock_movements table exists for BOM stock calculation
        if (!$this->tableExists('stock_movements')) {
            return [
                'status'            => self::STATUS_UNSUPPORTED,
                'reason'            => self::REASON_INVENTORY_SCHEMA_UNAVAILABLE,
                'message'           => 'Tabel buku besar stok (stock_movements) belum tersedia pada skema basis data.',
                'menu'              => [
                    'id'            => $menu->id,
                    'name'          => $menu->name,
                    'unit'          => $menu->unit ?? null,
                    'current_stock' => (float) ($menu->stock ?? -1.0),
                    'stock_mode'    => 'BOM_RECIPE_TRACKED',
                ],
                'forecasted_demand' => [
                    'daily_qty'     => $dailyDemand,
                    'horizon_days'  => $horizonDays,
                ],
                'ingredients_count' => $ingredients->count(),
                'ingredients'       => [],
            ];
        }

        // 4. Evaluate each ingredient in BOM
        $ingredientDetails = [];
        $overallStatus = self::STATUS_NORMAL;
        $bottleneckIngredient = null;
        $minDaysRemaining = PHP_FLOAT_MAX;

        foreach ($ingredients as $ing) {
            $itemId = (int) $ing->item_id;
            $rawRecipeQty = (float) $ing->recipe_qty;
            $stockUom = $ing->base_uom;

            // Determine recipe unit:
            // 1. Explicit option override if provided: $options['recipe_uom'][$itemId] ?? $options['recipe_uom'][$ing->bom_id]
            // 2. Dynamic property if set on ingredient: $ing->recipe_uom ?? $ing->unit
            // 3. Fallback to $stockUom (the base Kaching architecture where recipe is recorded in base_uom)
            $recipeUom = $options['recipe_uom'][$itemId]
                ?? ($options['recipe_uom'][$ing->bom_id] ?? ($ing->recipe_uom ?? ($ing->unit ?? $stockUom)));

            // Resolve effective outlet ID: explicit parameter > menu outlet > ingredient item outlet
            $effectiveOutletId = $outletId ?? ($menu->outlet_id ?? ($ing->outlet_id ?? null));

            // Current / Historical stock on hand from stock_movements ledger
            $stockQuery = DB::table('stock_movements')
                ->where('item_id', $itemId);

            // TASK 3: Outlet isolation
            if ($this->hasColumn('stock_movements', 'outlet_id') && $effectiveOutletId !== null && (int)$effectiveOutletId > 0) {
                $stockQuery->where(function ($sub) use ($effectiveOutletId, $ing) {
                    $sub->where('outlet_id', $effectiveOutletId);
                    // Include legacy movements with NULL outlet_id ONLY if item itself belongs to this outlet
                    $itemOutletId = $ing->outlet_id ?? null;
                    if ($itemOutletId !== null && (int)$itemOutletId === (int)$effectiveOutletId) {
                        $sub->orWhereNull('outlet_id');
                    }
                });
            }

            // TASK 4: Point-in-time temporal cutoff support
            $cutoffUtc = $options['cutoff_utc'] ?? null;
            if ($cutoffUtc !== null) {
                $stockQuery->where('created_at', '<=', $cutoffUtc);
            } elseif ($anchorDate !== null) {
                $timezone = $options['timezone'] ?? TimezoneContract::resolveOutletTimezone($effectiveOutletId);
                [, $nextDayStartUtc] = TimezoneContract::businessDateToUtcRange($anchorDate, $timezone);
                $stockQuery->where('created_at', '<', $nextDayStartUtc);
            }

            $stockOnHand = (float) ($stockQuery
                ->selectRaw("SUM(CASE WHEN type = 'incoming' THEN qty WHEN type = 'outgoing' THEN -qty ELSE qty END) as on_hand")
                ->value('on_hand') ?? 0.0);

            // TASK 6: Lead time from item_supplier with safe table guard
            $leadTimeDays = 0;
            if ($this->tableExists('item_supplier')) {
                try {
                    $leadTimeDays = (int) (DB::table('item_supplier')
                        ->where('item_id', $itemId)
                        ->min('lead_time_days') ?? 0);
                } catch (\Throwable) {
                    $leadTimeDays = 0;
                }
            }

            $reorderPoint = (float) ($ing->reorder_point ?? 0.0);

            // Perform deterministic UOM conversion and dimensional safety check
            $uomResult = $this->uomService->convert(
                $rawRecipeQty,
                $recipeUom,
                $stockUom,
                $itemId,
                $outletId
            );

            // If UOM conversion fails (dimension mismatch, missing conversion, unknown unit, invalid factor)
            if (!$uomResult['success']) {
                $ingStatus = self::STATUS_UNSUPPORTED;
                $statusReason = $uomResult['reason'] ?? self::REASON_UNIT_CONVERSION_UNAVAILABLE;
                $daysRemaining = null;

                // Escalate overall status according to severity
                $overallStatus = $this->escalateRiskStatus($overallStatus, $ingStatus);

                $ingredientDetails[] = [
                    'item_id'                 => $itemId,
                    'item_name'               => $ing->item_name,
                    'base_uom'                => $stockUom,
                    'recipe_uom'              => $recipeUom,
                    'recipe_qty_per_portion'  => $rawRecipeQty,
                    'normalized_recipe_qty'   => null,
                    'current_stock_on_hand'   => $stockOnHand,
                    'reorder_point'           => $reorderPoint,
                    'lead_time_days'          => $leadTimeDays,
                    'daily_ingredient_demand' => null,
                    'horizon_demand'          => null,
                    'days_until_stockout'     => null,
                    'status'                  => $ingStatus,
                    'reason'                  => $statusReason,
                ];
                continue;
            }

            $recipeQty = (float) $uomResult['converted_qty'];

            // Dimensional check: Discrete menu demand (portions/pcs) x recipe base_uom is standard F&B BOM.
            // Continuous demand or dimension mismatch without explicit conversion must fail closed.
            if (isset($demandForecast['unit_resolution']) && empty($demandForecast['unit_resolution']['is_discrete'])) {
                $overallStatus = self::STATUS_UNSUPPORTED;
                $ingredientDetails[] = [
                    'item_id'                => $itemId,
                    'item_name'              => $ing->item_name,
                    'status'                 => self::STATUS_UNSUPPORTED,
                    'unsupported_reason'     => UnitOfMeasureService::REASON_UNIT_DIMENSION_MISMATCH,
                    'note'                   => 'Perhitungan kebutuhan bahan baku ditolak karena kuantitas menu tidak berada dalam dimensi diskrit (porsi/pcs).',
                    'stock_on_hand'          => $stockOnHand,
                    'stock_uom'              => $stockUom,
                    'raw_recipe_qty'         => $rawRecipeQty,
                    'recipe_uom'             => $recipeUom,
                    'normalized_recipe_qty'  => null,
                    'daily_demand'           => null,
                    'horizon_demand'         => null,
                    'days_until_out'         => null,
                    'critical_date'          => null,
                    'reorder_point'          => $reorderPoint,
                    'breaches_reorder_point' => false,
                ];
                continue;
            }

            // Daily ingredient consumption = menu daily demand * normalized recipe qty
            $dailyIngredientDemand = round($dailyDemand * $recipeQty, 4);
            $horizonIngredientDemand = round($dailyIngredientDemand * $horizonDays, 4);

            // Compute days until depletion
            $daysRemaining = null;
            $ingStatus = self::STATUS_NORMAL;
            $statusReason = 'STOCK_SUFFICIENT';

            if ($stockOnHand < 0) {
                $ingStatus = self::STATUS_STOCKOUT;
                $statusReason = self::REASON_ANOMALOUS_STOCK;
                $daysRemaining = 0.0;
            } elseif ($stockOnHand == 0.0) {
                $ingStatus = self::STATUS_STOCKOUT;
                $statusReason = 'STOCK_ZERO';
                $daysRemaining = 0.0;
            } elseif ($dailyIngredientDemand <= 0.0001) {
                $ingStatus = self::STATUS_NORMAL;
                $statusReason = self::REASON_ZERO_DEMAND;
                $daysRemaining = null;
            } else {
                $daysRemaining = round($stockOnHand / $dailyIngredientDemand, 1);

                if ($daysRemaining <= max(1, $leadTimeDays)) {
                    $ingStatus = self::STATUS_STOCKOUT_RISK;
                    $statusReason = 'DEPLETION_WITHIN_LEAD_TIME';
                } elseif ($daysRemaining <= ($leadTimeDays + 3) || ($reorderPoint > 0 && $stockOnHand <= $reorderPoint)) {
                    $ingStatus = self::STATUS_WATCH;
                    $statusReason = 'APPROACHING_REORDER_POINT';
                } else {
                    $ingStatus = self::STATUS_NORMAL;
                    $statusReason = 'STOCK_HEALTHY';
                }
            }

            if ($daysRemaining !== null && $daysRemaining < $minDaysRemaining) {
                $minDaysRemaining = $daysRemaining;
                $bottleneckIngredient = $ing->item_name;
            }

            // Escalate overall status according to severity
            $overallStatus = $this->escalateRiskStatus($overallStatus, $ingStatus);

            $ingredientDetails[] = [
                'item_id'                 => $itemId,
                'item_name'               => $ing->item_name,
                'base_uom'                => $stockUom,
                'recipe_uom'              => $recipeUom,
                'recipe_qty_per_portion'  => $rawRecipeQty,
                'normalized_recipe_qty'   => $recipeQty,
                'current_stock_on_hand'   => $stockOnHand,
                'reorder_point'           => $reorderPoint,
                'lead_time_days'          => $leadTimeDays,
                'daily_ingredient_demand' => $dailyIngredientDemand,
                'horizon_demand'          => $horizonIngredientDemand,
                'days_until_stockout'     => $daysRemaining,
                'status'                  => $ingStatus,
                'reason'                  => $statusReason,
            ];
        }

        $finalDaysRemaining = ($overallStatus === self::STATUS_UNSUPPORTED || $minDaysRemaining === PHP_FLOAT_MAX)
            ? null
            : $minDaysRemaining;

        return [
            'status'                => $overallStatus,
            'menu'                  => [
                'id'                => $menu->id,
                'name'              => $menu->name,
                'stock_mode'        => 'BOM_RECIPE_TRACKED',
            ],
            'forecasted_demand'     => [
                'daily_qty'         => $dailyDemand,
                'horizon_days'      => $horizonDays,
            ],
            'bottleneck_ingredient' => $bottleneckIngredient,
            'min_days_until_out'    => $finalDaysRemaining,
            'ingredients_count'     => count($ingredientDetails),
            'ingredients'           => $ingredientDetails,
            'limitations'           => 'Analisis stok bahan baku bergantung pada akurasi mutasi stok barang masuk/keluar dan resep BOM.',
        ];
    }

    /**
     * Direct Finished-Goods Stock Risk Evaluation (when no BOM is configured but menu.stock is tracked).
     */
    protected function evaluateDirectMenuStockRisk(Menu $menu, float $dailyDemand, int $horizonDays): array
    {
        $currentStock = (float) $menu->stock;
        $status = self::STATUS_NORMAL;
        $reason = 'STOCK_HEALTHY';
        $daysRemaining = null;

        if ($currentStock < 0) {
            $status = self::STATUS_STOCKOUT;
            $reason = self::REASON_ANOMALOUS_STOCK;
            $daysRemaining = 0.0;
        } elseif ($currentStock == 0.0) {
            $status = self::STATUS_STOCKOUT;
            $reason = 'STOCK_ZERO';
            $daysRemaining = 0.0;
        } elseif ($dailyDemand <= 0.0001) {
            $status = self::STATUS_NORMAL;
            $reason = self::REASON_ZERO_DEMAND;
            $daysRemaining = null;
        } else {
            $daysRemaining = round($currentStock / $dailyDemand, 1);

            if ($daysRemaining <= 2.0) {
                $status = self::STATUS_STOCKOUT_RISK;
                $reason = 'DEPLETION_IMMINENT';
            } elseif ($daysRemaining <= 5.0) {
                $status = self::STATUS_WATCH;
                $reason = 'DEPLETION_WITHIN_5_DAYS';
            } else {
                $status = self::STATUS_NORMAL;
                $reason = 'STOCK_HEALTHY';
            }
        }

        return [
            'status'            => $status,
            'reason'            => $reason,
            'menu'              => [
                'id'            => $menu->id,
                'name'          => $menu->name,
                'current_stock' => $currentStock,
                'stock_mode'    => 'DIRECT_FINISHED_GOOD',
            ],
            'forecasted_demand' => [
                'daily_qty'     => $dailyDemand,
                'horizon_days'  => $horizonDays,
            ],
            'days_until_stockout' => $daysRemaining,
            'ingredients_count'   => 0,
            'ingredients'         => [],
            'limitations'         => 'Evaluasi menggunakan stok barang jadi menu langsung tanpa rincian bahan mentah.',
        ];
    }

    /**
     * Evaluate all menus for stockout risk in batch.
     */
    public function evaluateAllMenusStockRisk(
        int $userId,
        ?int $outletId = null,
        ?string $anchorDate = null,
        array $options = []
    ): array {
        $menus = Menu::where('user_id', $userId)
            ->when($outletId !== null, function ($q) use ($outletId) {
                $q->where(function ($sub) use ($outletId) {
                    $sub->where('outlet_id', $outletId)
                        ->orWhereNull('outlet_id')
                        ->orWhere('outlet_id', 0);
                });
            })
            ->get(['id', 'name', 'stock']);

        $summary = [
            'total_menus'   => $menus->count(),
            'stockout'      => 0,
            'stockout_risk' => 0,
            'watch'         => 0,
            'normal'        => 0,
            'unsupported'   => 0,
        ];

        $results = [];
        foreach ($menus as $m) {
            $risk = $this->evaluateMenuStockRisk($userId, $m->id, $outletId, $anchorDate, $options);
            $st = $risk['status'];

            if ($st === self::STATUS_STOCKOUT) {
                $summary['stockout']++;
            } elseif ($st === self::STATUS_STOCKOUT_RISK) {
                $summary['stockout_risk']++;
            } elseif ($st === self::STATUS_WATCH) {
                $summary['watch']++;
            } elseif ($st === self::STATUS_NORMAL) {
                $summary['normal']++;
            } else {
                $summary['unsupported']++;
            }

            $results[] = $risk;
        }

        return [
            'status'  => 'SUCCESS',
            'summary' => $summary,
            'menus'   => $results,
        ];
    }

    /**
     * Escalate risk status according to hierarchy:
     * UNSUPPORTED > STOCKOUT > STOCKOUT_RISK > WATCH > NORMAL.
     */
    protected function escalateRiskStatus(string $current, string $candidate): string
    {
        $severity = [
            self::STATUS_NORMAL        => 1,
            self::STATUS_WATCH         => 2,
            self::STATUS_STOCKOUT_RISK => 3,
            self::STATUS_STOCKOUT      => 4,
            self::STATUS_UNSUPPORTED   => 5,
        ];

        $currentScore = $severity[$current] ?? 0;
        $candidateScore = $severity[$candidate] ?? 0;

        return $candidateScore > $currentScore ? $candidate : $current;
    }
}
