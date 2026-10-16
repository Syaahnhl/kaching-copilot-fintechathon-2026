<?php

namespace App\Services\Financial;

use App\Models\Item;
use App\Models\Outlet;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SmartRestockOrchestratorService
 *
 * Deterministic Restock Recommendation & Supplier Matching Engine.
 * Combines Stockout Intelligence, BOM Consumption, Supplier Catalogs,
 * Unit of Measure Normalization, and Cash Flow Projections into actionable
 * read-only recommendations and WhatsApp draft quotation links.
 *
 * GOVERNANCE:
 * - Read-only analytical service; performs ZERO database mutations, expense creations, or PO creations.
 * - Does not send automatic WhatsApp messages (generates https://wa.me/ draft links only).
 * - Enforces strict tenant isolation per user_id and active outlet_id.
 */
class SmartRestockOrchestratorService
{
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_NO_RESTOCK_NEEDED = 'NO_RESTOCK_NEEDED';
    public const STATUS_UNAUTHORIZED_OUTLET = 'UNAUTHORIZED_OUTLET';
    public const STATUS_UNSUPPORTED = 'UNSUPPORTED';
    public const STATUS_DATA_INCOMPLETE = 'DATA_INCOMPLETE';

    public const CASHFLOW_STATUS_AFFORDABLE = 'AFFORDABLE';
    public const CASHFLOW_STATUS_POTENTIAL_DEFICIT = 'POTENTIAL_DEFICIT';
    public const CASHFLOW_STATUS_UNAVAILABLE = 'UNAVAILABLE';

    public const PRICE_SOURCE_SUPPLIER_CATALOG = 'SUPPLIER_CATALOG_PRICE';
    public const PRICE_SOURCE_ITEM_COST_FALLBACK = 'ITEM_COST_PRICE_FALLBACK';
    public const PRICE_SOURCE_UNAVAILABLE = 'UNAVAILABLE';

    public function __construct(
        protected StockoutIntelligenceService $stockoutService,
        protected CashFlowForecastService $cashFlowService,
        protected UnitOfMeasureService $uomService
    ) {}

    /**
     * Generate deterministic restock recommendations for a tenant and outlet.
     */
    public function getRestockRecommendations(int $userId, ?int $outletId = null, array $options = []): array
    {
        // 1. Tenant & Outlet Validation (Fail-Closed)
        $outletName = 'Outlet Kaching';
        if ($outletId !== null) {
            $outlet = Outlet::where('id', $outletId)->where('user_id', $userId)->first();
            if (!$outlet) {
                return [
                    'status'          => self::STATUS_UNAUTHORIZED_OUTLET,
                    'message'         => 'Outlet tidak ditemukan atau di luar hak akses tenant.',
                    'recommendations' => [],
                    'supplier_orders' => [],
                    'summary'         => [
                        'total_items_analyzed'        => 0,
                        'items_needing_restock'       => 0,
                        'total_estimated_cost'        => 0.0,
                        'cashflow_status'             => self::CASHFLOW_STATUS_UNAVAILABLE,
                    ],
                    'explainability'  => $this->buildExplainabilityPayload($userId, $outletId, 0, 'Akses outlet ditolak (tenant isolation fail-closed).'),
                ];
            }
            $outletName = $outlet->name;
        } else {
            $firstOutlet = Outlet::where('user_id', $userId)->first();
            if ($firstOutlet) {
                $outletName = $firstOutlet->name;
            }
        }

        $horizonDays = max(1, (int) ($options['horizon_days'] ?? 7));
        $limit = max(1, min(50, (int) ($options['limit'] ?? 20)));
        $anchorDate = $options['anchor_date'] ?? null;

        // 2. Fetch Stockout Intelligence across menus
        try {
            $stockRiskData = $this->stockoutService->evaluateAllMenusStockRisk($userId, $outletId, $anchorDate, $options);
        } catch (\Throwable $e) {
            return [
                'status'          => self::STATUS_UNSUPPORTED,
                'message'         => 'Gagal mengevaluasi risiko stok: ' . $e->getMessage(),
                'recommendations' => [],
                'supplier_orders' => [],
                'summary'         => [
                    'total_items_analyzed'  => 0,
                    'items_needing_restock' => 0,
                    'total_estimated_cost'  => 0.0,
                    'cashflow_status'       => self::CASHFLOW_STATUS_UNAVAILABLE,
                ],
                'explainability'  => $this->buildExplainabilityPayload($userId, $outletId, $horizonDays, 'Evaluasi stok mengalami kegagalan sistem.'),
            ];
        }

        if (isset($stockRiskData['status']) && $stockRiskData['status'] === StockoutIntelligenceService::STATUS_UNSUPPORTED) {
            return [
                'status'          => self::STATUS_UNSUPPORTED,
                'message'         => $stockRiskData['message'] ?? 'Perhitungan restock tidak dapat dijalankan karena skema atau data inventori belum lengkap.',
                'recommendations' => [],
                'supplier_orders' => [],
                'summary'         => [
                    'total_items_analyzed'  => 0,
                    'items_needing_restock' => 0,
                    'total_estimated_cost'  => 0.0,
                    'cashflow_status'       => self::CASHFLOW_STATUS_UNAVAILABLE,
                ],
                'explainability'  => $this->buildExplainabilityPayload($userId, $outletId, $horizonDays, 'Data inventori/skema tidak mendukung analisis stockout.'),
            ];
        }

        // 3. Aggregate ingredient consumption across all evaluated menus
        $aggregatedIngredients = [];
        $menus = $stockRiskData['menus'] ?? [];

        foreach ($menus as $menuRisk) {
            $menuName = $menuRisk['menu']['name'] ?? 'Menu';
            $ingredients = $menuRisk['ingredients'] ?? [];

            foreach ($ingredients as $ing) {
                $itemId = (int) ($ing['item_id'] ?? 0);
                if ($itemId <= 0) {
                    continue;
                }

                if (!isset($aggregatedIngredients[$itemId])) {
                    $aggregatedIngredients[$itemId] = [
                        'item_id'               => $itemId,
                        'item_name'             => (string) ($ing['item_name'] ?? 'Bahan #' . $itemId),
                        'base_uom'              => (string) ($ing['base_uom'] ?? 'pcs'),
                        'current_stock_on_hand' => (float) ($ing['current_stock_on_hand'] ?? 0.0),
                        'reorder_point'         => (float) ($ing['reorder_point'] ?? 0.0),
                        'lead_time_days'        => (int) ($ing['lead_time_days'] ?? 3),
                        'total_daily_demand'    => 0.0,
                        'total_horizon_demand'  => 0.0,
                        'associated_menus'      => [],
                        'statuses'              => [],
                    ];
                }

                $aggregatedIngredients[$itemId]['total_daily_demand'] += (float) ($ing['daily_ingredient_demand'] ?? 0.0);
                $aggregatedIngredients[$itemId]['total_horizon_demand'] += (float) ($ing['horizon_demand'] ?? 0.0);
                $aggregatedIngredients[$itemId]['associated_menus'][] = $menuName;
                if (!empty($ing['status'])) {
                    $aggregatedIngredients[$itemId]['statuses'][] = $ing['status'];
                }
            }
        }

        // 4. Also inspect standalone raw materials not attached to BOM if they are low on stock
        $scopedOutletIds = ($outletId !== null)
            ? [(int) $outletId]
            : Outlet::where('user_id', $userId)->pluck('id')->toArray();

        if (Schema::hasTable('items')) {
            $standaloneQuery = Item::query()
                ->whereNull('deleted_at')
                ->where(function ($q) use ($scopedOutletIds) {
                    if (!empty($scopedOutletIds)) {
                        $q->whereIn('outlet_id', $scopedOutletIds)
                            ->orWhereNull('outlet_id')
                            ->orWhere('outlet_id', 0);
                    }
                });

            if (Schema::hasColumn('items', 'is_active')) {
                $standaloneQuery->where('is_active', true);
            }

            $standaloneItems = $standaloneQuery->get();

            foreach ($standaloneItems as $stItem) {
                if (!isset($aggregatedIngredients[$stItem->id])) {
                    $stockOnHand = 0.0;
                    if (Schema::hasTable('stock_movements')) {
                        $incoming = (float) DB::table('stock_movements')->where('item_id', $stItem->id)->where('type', 'incoming')->sum('qty');
                        $outgoing = (float) DB::table('stock_movements')->where('item_id', $stItem->id)->where('type', 'outgoing')->sum('qty');
                        $stockOnHand = round($incoming - $outgoing, 4);
                    }

                    $reorderPt = (float) ($stItem->reorder_point ?? 0.0);

                    // Include if below reorder point or non-positive stock
                    if (($reorderPt > 0 && $stockOnHand <= $reorderPt) || $stockOnHand <= 0) {
                        $aggregatedIngredients[$stItem->id] = [
                            'item_id'               => (int) $stItem->id,
                            'item_name'             => (string) $stItem->name,
                            'base_uom'              => (string) ($stItem->base_uom ?? 'pcs'),
                            'current_stock_on_hand' => $stockOnHand,
                            'reorder_point'         => $reorderPt,
                            'lead_time_days'        => 3,
                            'total_daily_demand'    => 0.0,
                            'total_horizon_demand'  => 0.0,
                            'associated_menus'      => [],
                            'statuses'              => [($stockOnHand <= 0) ? StockoutIntelligenceService::STATUS_STOCKOUT : StockoutIntelligenceService::STATUS_WATCH],
                        ];
                    }
                }
            }
        }

        // 5. Evaluate restock requirement and supplier matching per item
        $recommendations = [];
        $totalEstimatedCost = 0.0;
        $hasUnpricedItems = false;
        $stockoutCount = 0;
        $riskCount = 0;
        $watchCount = 0;

        foreach ($aggregatedIngredients as $itemId => $agg) {
            $currentStock = $agg['current_stock_on_hand'];
            $reorderPoint = $agg['reorder_point'];
            $dailyDemand = round($agg['total_daily_demand'], 4);
            $horizonDemand = round($dailyDemand * $horizonDays, 4);
            $leadTimeDays = max(1, (int) $agg['lead_time_days']);

            // Determine Days until Stockout & Risk Status
            $daysUntilStockout = null;
            if ($currentStock < 0) {
                $riskStatus = StockoutIntelligenceService::STATUS_STOCKOUT;
                $reason = StockoutIntelligenceService::REASON_ANOMALOUS_STOCK;
                $daysUntilStockout = 0.0;
            } elseif ($currentStock == 0.0) {
                $riskStatus = StockoutIntelligenceService::STATUS_STOCKOUT;
                $reason = 'STOCK_ZERO';
                $daysUntilStockout = 0.0;
            } elseif ($dailyDemand > 0.0001) {
                $daysUntilStockout = round($currentStock / $dailyDemand, 1);
                if ($daysUntilStockout <= $leadTimeDays) {
                    $riskStatus = StockoutIntelligenceService::STATUS_STOCKOUT_RISK;
                    $reason = 'DEPLETION_WITHIN_LEAD_TIME';
                } elseif ($daysUntilStockout <= ($leadTimeDays + 3) || ($reorderPoint > 0 && $currentStock <= $reorderPoint)) {
                    $riskStatus = StockoutIntelligenceService::STATUS_WATCH;
                    $reason = 'APPROACHING_REORDER_POINT';
                } else {
                    $riskStatus = StockoutIntelligenceService::STATUS_NORMAL;
                    $reason = 'STOCK_HEALTHY';
                }
            } else {
                if ($reorderPoint > 0 && $currentStock <= $reorderPoint) {
                    $riskStatus = StockoutIntelligenceService::STATUS_WATCH;
                    $reason = 'BELOW_REORDER_POINT_NO_FORECAST';
                } else {
                    $riskStatus = StockoutIntelligenceService::STATUS_NORMAL;
                    $reason = 'STOCK_HEALTHY_ZERO_DEMAND';
                }
            }

            // Calculate Target Stock & Suggested Quantity
            $effectiveHorizonDays = max($leadTimeDays, $horizonDays);
            $leadTimeDemand = round($dailyDemand * $effectiveHorizonDays, 4);
            $safetyBuffer = ($reorderPoint > 0) ? $reorderPoint : round($dailyDemand * 2, 4);
            $targetStock = max($reorderPoint, $leadTimeDemand + $safetyBuffer);

            // Bounded suggested quantity: Normal items with plenty stock get 0 qty
            if ($riskStatus === StockoutIntelligenceService::STATUS_NORMAL && $currentStock > $reorderPoint && ($daysUntilStockout === null || $daysUntilStockout > $effectiveHorizonDays)) {
                $suggestedQty = 0.0;
                $needsRestock = false;
            } else {
                $deficiency = $targetStock - $currentStock;
                $suggestedQty = max(0.0, ceil($deficiency * 100) / 100);
                $needsRestock = ($suggestedQty > 0.0);
            }

            // Count severities
            if ($riskStatus === StockoutIntelligenceService::STATUS_STOCKOUT) {
                $stockoutCount++;
            } elseif ($riskStatus === StockoutIntelligenceService::STATUS_STOCKOUT_RISK) {
                $riskCount++;
            } elseif ($riskStatus === StockoutIntelligenceService::STATUS_WATCH) {
                $watchCount++;
            }

            // 6. Match Connected Suppliers from `item_supplier` pivot
            $matchedSuppliers = [];
            if (Schema::hasTable('item_supplier') && Schema::hasTable('suppliers')) {
                $supQuery = Supplier::query()
                    ->join('item_supplier as isup', 'suppliers.id', '=', 'isup.supplier_id')
                    ->where('isup.item_id', $itemId)
                    ->whereNull('suppliers.deleted_at');

                if (Schema::hasColumn('suppliers', 'is_active')) {
                    $supQuery->where('suppliers.is_active', true);
                }

                if (Schema::hasColumn('suppliers', 'user_id')) {
                    $supQuery->where('suppliers.user_id', $userId);
                }

                $matchedSuppliers = $supQuery->select(
                    'suppliers.id as supplier_id',
                    'suppliers.name as supplier_name',
                    'suppliers.contact_person',
                    'suppliers.phone',
                    'suppliers.email',
                    'suppliers.address',
                    'isup.supplier_price',
                    'isup.lead_time_days'
                )->orderBy('isup.supplier_price', 'asc')->get();
            }

            $itemModel = Item::find($itemId);
            $masterCostPrice = ($itemModel && $itemModel->cost_price > 0) ? (float) $itemModel->cost_price : null;

            $supplierOptions = [];
            if ($matchedSuppliers->isNotEmpty()) {
                foreach ($matchedSuppliers as $sup) {
                    $supPrice = ($sup->supplier_price !== null && $sup->supplier_price > 0) ? (float) $sup->supplier_price : null;
                    
                    if ($supPrice !== null) {
                        $supCost = round($suggestedQty * $supPrice, 2);
                        $supPriceSource = self::PRICE_SOURCE_SUPPLIER_CATALOG;
                        $supUnitPrice = $supPrice;
                        $supPriceNote = 'Harga penawaran resmi dari katalog supplier.';
                    } elseif ($masterCostPrice !== null) {
                        $supCost = round($suggestedQty * $masterCostPrice, 2);
                        $supPriceSource = self::PRICE_SOURCE_ITEM_COST_FALLBACK;
                        $supUnitPrice = $masterCostPrice;
                        $supPriceNote = 'Harga supplier belum tercatat; menggunakan estimasi master HPP bahan.';
                    } else {
                        $supCost = null;
                        $supPriceSource = self::PRICE_SOURCE_UNAVAILABLE;
                        $supUnitPrice = null;
                        $supPriceNote = 'Harga supplier dan master HPP tidak tersedia.';
                    }

                    $waDraft = $this->buildWhatsAppDraftUrl(
                        $sup->phone,
                        $outletName,
                        $sup->supplier_name,
                        $agg['item_name'],
                        $suggestedQty,
                        $agg['base_uom'],
                        $supUnitPrice,
                        $supPriceSource
                    );

                    $supplierOptions[] = [
                        'supplier_id'      => (int) $sup->supplier_id,
                        'supplier_name'    => (string) $sup->supplier_name,
                        'contact_person'   => $sup->contact_person,
                        'phone'            => $sup->phone,
                        'clean_phone'      => $waDraft['clean_phone'] ?? null,
                        'lead_time_days'   => $sup->lead_time_days ?? $leadTimeDays,
                        'supplier_price'   => $supPrice,
                        'unit_price_used'  => $supUnitPrice,
                        'estimated_cost'   => $supCost,
                        'price_source'     => $supPriceSource,
                        'price_note'       => $supPriceNote,
                        'whatsapp_draft'   => $waDraft,
                    ];
                }
            }

            // Primary supplier resolution (best available price or first connected supplier)
            $primarySupplier = !empty($supplierOptions) ? $supplierOptions[0] : null;

            if ($primarySupplier) {
                $effectiveUnitPrice = $primarySupplier['unit_price_used'];
                $effectiveEstimatedCost = $primarySupplier['estimated_cost'];
                $effectivePriceSource = $primarySupplier['price_source'];
                $effectivePriceNote = $primarySupplier['price_note'];
            } else {
                if ($masterCostPrice !== null) {
                    $effectiveUnitPrice = $masterCostPrice;
                    $effectiveEstimatedCost = round($suggestedQty * $masterCostPrice, 2);
                    $effectivePriceSource = self::PRICE_SOURCE_ITEM_COST_FALLBACK;
                    $effectivePriceNote = 'Tidak ada supplier terhubung; menggunakan estimasi master HPP bahan.';
                } else {
                    $effectiveUnitPrice = null;
                    $effectiveEstimatedCost = null;
                    $effectivePriceSource = self::PRICE_SOURCE_UNAVAILABLE;
                    $effectivePriceNote = 'Tidak ada supplier terhubung dan master HPP tidak tersedia.';
                }
            }

            if ($needsRestock) {
                if ($effectiveEstimatedCost !== null) {
                    $totalEstimatedCost += $effectiveEstimatedCost;
                } else {
                    $hasUnpricedItems = true;
                }
            }

            $recommendations[] = [
                'item_id'                   => $itemId,
                'item_name'                 => $agg['item_name'],
                'base_uom'                  => $agg['base_uom'],
                'current_stock_on_hand'     => $currentStock,
                'reorder_point'             => $reorderPoint,
                'risk_status'               => $riskStatus,
                'status_reason'             => $reason,
                'days_until_stockout'       => $daysUntilStockout,
                'daily_demand'              => $dailyDemand,
                'horizon_demand'            => $horizonDemand,
                'horizon_days'              => $horizonDays,
                'needs_restock'             => $needsRestock,
                'suggested_quantity'        => $suggestedQty,
                'unit_price'                => $effectiveUnitPrice,
                'estimated_cost'            => $effectiveEstimatedCost,
                'price_source'              => $effectivePriceSource,
                'price_note'                => $effectivePriceNote,
                'connected_suppliers_count' => count($supplierOptions),
                'supplier_options'          => $supplierOptions,
                'primary_supplier'          => $primarySupplier,
                'associated_menus'          => array_values(array_unique(array_filter($agg['associated_menus']))),
                'rationale'                 => $this->buildItemRationale(
                    $agg['item_name'],
                    $riskStatus,
                    $currentStock,
                    $agg['base_uom'],
                    $daysUntilStockout,
                    $suggestedQty,
                    $reorderPoint
                ),
            ];
        }

        // 7. Sort recommendations: Critical items first, then by days until stockout ascending
        usort($recommendations, function ($a, $b) {
            $severity = [
                StockoutIntelligenceService::STATUS_STOCKOUT      => 1,
                StockoutIntelligenceService::STATUS_STOCKOUT_RISK => 2,
                StockoutIntelligenceService::STATUS_WATCH         => 3,
                StockoutIntelligenceService::STATUS_NORMAL        => 4,
            ];

            $sevA = $severity[$a['risk_status']] ?? 5;
            $sevB = $severity[$b['risk_status']] ?? 5;

            if ($sevA !== $sevB) {
                return $sevA <=> $sevB;
            }

            $daysA = $a['days_until_stockout'] ?? 999.0;
            $daysB = $b['days_until_stockout'] ?? 999.0;

            return $daysA <=> $daysB;
        });

        // Slice items needing restock for primary output
        $itemsNeedingRestock = array_values(array_filter($recommendations, fn($r) => $r['needs_restock'] === true));
        $limitedRecommendations = array_slice($itemsNeedingRestock, 0, $limit);

        // 8. Build Supplier Batch PO Drafts
        $supplierOrders = $this->buildSupplierBatchOrders($limitedRecommendations, $outletName);

        // 9. Evaluate Cash Flow Impact
        $cashflowImpact = $this->evaluateCashFlowImpact($userId, $outletId, $totalEstimatedCost, $horizonDays);

        // 10. Assemble Final Structured Payload
        $status = count($itemsNeedingRestock) > 0 ? self::STATUS_SUCCESS : self::STATUS_NO_RESTOCK_NEEDED;
        $message = count($itemsNeedingRestock) > 0
            ? sprintf('Ditemukan %d bahan baku yang memerlukan restock pembelian.', count($itemsNeedingRestock))
            : 'Seluruh stok bahan baku berada pada batas aman.';

        return [
            'status'          => $status,
            'message'         => $message,
            'summary'         => [
                'total_items_analyzed'        => count($aggregatedIngredients),
                'items_needing_restock'       => count($itemsNeedingRestock),
                'stockout_count'              => $stockoutCount,
                'stockout_risk_count'         => $riskCount,
                'watch_count'                 => $watchCount,
                'total_estimated_cost'        => round($totalEstimatedCost, 2),
                'has_unpriced_items'          => $hasUnpricedItems,
                'cashflow_impact'             => $cashflowImpact,
            ],
            'recommendations' => $limitedRecommendations,
            'all_analyzed'    => $recommendations,
            'supplier_orders' => $supplierOrders,
            'explainability'  => $this->buildExplainabilityPayload($userId, $outletId, $horizonDays, 'Evaluasi kebutuhan restock deterministik berbasis BOM & peramalan konsumsi.'),
        ];
    }

    /**
     * Build WhatsApp Draft Quotation Link safely.
     * Generates a wa.me URL only if phone is valid. Never sends messages automatically.
     */
    public function buildWhatsAppDraftUrl(
        ?string $phone,
        string $outletName,
        string $supplierName,
        string $itemName,
        float $qty,
        string $uom,
        ?float $unitPrice = null,
        ?string $priceSource = null
    ): array {
        $cleanPhone = $this->normalizePhoneNumber($phone);

        if (!$cleanPhone) {
            return [
                'is_valid'        => false,
                'raw_phone'       => $phone,
                'clean_phone'     => null,
                'url'             => null,
                'message_preview' => null,
                'error'           => 'Nomor telepon supplier tidak valid atau belum diisi.',
            ];
        }

        $formattedQty = (fmod($qty, 1.0) == 0.0)
            ? number_format($qty, 0, ',', '.')
            : rtrim(rtrim(number_format($qty, 2, ',', '.'), '0'), ',');
        $priceText = '';
        if ($unitPrice !== null && $unitPrice > 0) {
            $priceLabel = ($priceSource === self::PRICE_SOURCE_SUPPLIER_CATALOG) ? 'Harga terdaftar' : 'Estimasi HPP';
            $priceText = sprintf(' (%s: Rp %s/%s)', $priceLabel, number_format($unitPrice, 0, ',', '.'), $uom);
        }

        $message = "Halo {$supplierName},\n\n"
            . "Kami dari *{$outletName}* ingin menanyakan ketersediaan stok dan konfirmasi penawaran harga untuk kebutuhan bahan berikut:\n\n"
            . "• *{$itemName}*: {$formattedQty} {$uom}{$priceText}\n\n"
            . "Mohon informasi ketersediaan barang dan konfirmasi harga terbarunya.\n"
            . "Terima kasih.";

        $url = 'https://wa.me/' . $cleanPhone . '?text=' . rawurlencode($message);

        return [
            'is_valid'        => true,
            'raw_phone'       => $phone,
            'clean_phone'     => $cleanPhone,
            'url'             => $url,
            'message_preview' => $message,
            'error'           => null,
        ];
    }

    /**
     * Group items needing restock by connected supplier to generate multi-item PO WhatsApp drafts.
     */
    protected function buildSupplierBatchOrders(array $recommendations, string $outletName): array
    {
        $ordersBySupplier = [];

        foreach ($recommendations as $rec) {
            $primary = $rec['primary_supplier'] ?? null;
            if (!$primary) {
                continue;
            }

            $supId = $primary['supplier_id'];
            if (!isset($ordersBySupplier[$supId])) {
                $ordersBySupplier[$supId] = [
                    'supplier_id'    => $supId,
                    'supplier_name'  => $primary['supplier_name'],
                    'contact_person' => $primary['contact_person'],
                    'phone'          => $primary['phone'],
                    'clean_phone'    => $primary['clean_phone'],
                    'lead_time_days' => $primary['lead_time_days'],
                    'items'          => [],
                    'total_cost'     => 0.0,
                    'has_unpriced'   => false,
                ];
            }

            $ordersBySupplier[$supId]['items'][] = [
                'item_id'        => $rec['item_id'],
                'item_name'      => $rec['item_name'],
                'suggested_qty'  => $rec['suggested_quantity'],
                'base_uom'       => $rec['base_uom'],
                'unit_price'     => $primary['unit_price_used'],
                'estimated_cost' => $primary['estimated_cost'],
                'price_source'   => $primary['price_source'],
            ];

            if ($primary['estimated_cost'] !== null) {
                $ordersBySupplier[$supId]['total_cost'] += $primary['estimated_cost'];
            } else {
                $ordersBySupplier[$supId]['has_unpriced'] = true;
            }
        }

        // Generate batch WhatsApp links
        $batchOrders = [];
        foreach ($ordersBySupplier as $supId => $order) {
            $cleanPhone = $this->normalizePhoneNumber($order['phone']);
            $waUrl = null;
            $messagePreview = null;

            if ($cleanPhone) {
                $itemListText = '';
                foreach ($order['items'] as $it) {
                    $qtyStr = (fmod($it['suggested_qty'], 1.0) == 0.0)
                        ? number_format($it['suggested_qty'], 0, ',', '.')
                        : rtrim(rtrim(number_format($it['suggested_qty'], 2, ',', '.'), '0'), ',');
                    
                    $priceStr = '';
                    if ($it['unit_price'] !== null && $it['unit_price'] > 0) {
                        $priceStr = sprintf(' (Rp %s/%s)', number_format($it['unit_price'], 0, ',', '.'), $it['base_uom']);
                    }
                    $itemListText .= "• *{$it['item_name']}*: {$qtyStr} {$it['base_uom']}{$priceStr}\n";
                }

                $msg = "Halo {$order['supplier_name']},\n\n"
                    . "Kami dari *{$outletName}* ingin menanyakan ketersediaan stok dan konfirmasi harga untuk daftar kebutuhan restock berikut:\n\n"
                    . $itemListText . "\n"
                    . "Mohon informasi ketersediaan barang serta total penawaran harga terbarunya.\n"
                    . "Terima kasih.";

                $waUrl = 'https://wa.me/' . $cleanPhone . '?text=' . rawurlencode($msg);
                $messagePreview = $msg;
            }

            $order['total_cost'] = round($order['total_cost'], 2);
            $order['whatsapp_draft'] = [
                'is_valid'        => (bool) $cleanPhone,
                'clean_phone'     => $cleanPhone,
                'url'             => $waUrl,
                'message_preview' => $messagePreview,
                'error'           => $cleanPhone ? null : 'Nomor telepon supplier tidak valid atau belum diisi.',
            ];

            $batchOrders[] = $order;
        }

        return $batchOrders;
    }

    /**
     * Evaluate Cash Flow Impact deterministically using CashFlowForecastService.
     */
    protected function evaluateCashFlowImpact(int $userId, ?int $outletId, float $totalEstimatedCost, int $horizonDays): array
    {
        try {
            $cashFlow = $this->cashFlowService->forecast($userId, $outletId, null, [
                'horizon_days' => $horizonDays,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('SmartRestock cashflow evaluation exception', [
                'user_id'   => $userId,
                'outlet_id' => $outletId,
                'error'     => $e->getMessage(),
            ]);

            return [
                'status'                            => self::CASHFLOW_STATUS_UNAVAILABLE,
                'assessment'                        => 'Analisis arus kas sementara belum dapat diproses.',
                'projected_net_operating_cash_flow' => null,
                'total_restock_cost'                => round($totalEstimatedCost, 2),
                'cashflow_service_status'           => 'EXCEPTION',
            ];
        }

        $cfStatus = $cashFlow['status'] ?? 'DATA_INSUFFICIENT';

        if ($cfStatus !== 'SUCCESS' || !isset($cashFlow['projected_net_operating_cash_flow']) || !is_numeric($cashFlow['projected_net_operating_cash_flow'])) {
            return [
                'status'                            => self::CASHFLOW_STATUS_UNAVAILABLE,
                'assessment'                        => 'Data historis proyeksi arus kas operasional belum mencukupi untuk mengevaluasi kelayakan belanja.',
                'projected_net_operating_cash_flow' => null,
                'total_restock_cost'                => round($totalEstimatedCost, 2),
                'cashflow_service_status'           => $cfStatus,
            ];
        }

        $projectedNetCash = (float) $cashFlow['projected_net_operating_cash_flow'];

        if ($totalEstimatedCost <= 0.0) {
            $status = self::CASHFLOW_STATUS_AFFORDABLE;
            $assessment = 'Tidak ada biaya restock yang teridentifikasi atau estimasi biaya Rp 0.';
        } elseif ($projectedNetCash >= $totalEstimatedCost && $projectedNetCash > 0.0) {
            $status = self::CASHFLOW_STATUS_AFFORDABLE;
            $assessment = sprintf(
                'Estimasi biaya restock total (Rp %s) tampak terjangkau berdasarkan proyeksi arus kas operasional %d hari ke depan (Rp %s).',
                number_format($totalEstimatedCost, 0, ',', '.'),
                $horizonDays,
                number_format($projectedNetCash, 0, ',', '.')
            );
        } else {
            $status = self::CASHFLOW_STATUS_POTENTIAL_DEFICIT;
            $assessment = sprintf(
                'Estimasi biaya restock total (Rp %s) berpotensi menimbulkan defisit kas operasional (Proyeksi arus kas bersih %d hari: Rp %s). Disarankan memprioritaskan bahan paling kritis atau mengajukan tempo pembayaran ke supplier.',
                number_format($totalEstimatedCost, 0, ',', '.'),
                $horizonDays,
                number_format($projectedNetCash, 0, ',', '.')
            );
        }

        return [
            'status'                            => $status,
            'assessment'                        => $assessment,
            'projected_net_operating_cash_flow' => $projectedNetCash,
            'total_restock_cost'                => round($totalEstimatedCost, 2),
            'cashflow_service_status'           => $cfStatus,
        ];
    }

    /**
     * Normalize Indonesian/International phone number for WhatsApp link.
     */
    protected function normalizePhoneNumber(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        // Remove all non-numeric characters
        $digits = preg_replace('/\D+/', '', $phone);

        if (empty($digits) || strlen($digits) < 9 || strlen($digits) > 16) {
            return null;
        }

        // Convert leading 0 to 62 (Indonesian country code standard)
        if (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62' . $digits;
        }

        return $digits;
    }

    /**
     * Build natural-language rationale explaining restock recommendation.
     */
    protected function buildItemRationale(
        string $itemName,
        string $riskStatus,
        float $currentStock,
        string $baseUom,
        ?float $daysUntilStockout,
        float $suggestedQty,
        float $reorderPoint
    ): string {
        $formattedStock = (fmod($currentStock, 1.0) == 0.0) ? number_format($currentStock, 0, ',', '.') : number_format($currentStock, 2, ',', '.');
        $formattedQty = (fmod($suggestedQty, 1.0) == 0.0) ? number_format($suggestedQty, 0, ',', '.') : number_format($suggestedQty, 2, ',', '.');

        switch ($riskStatus) {
            case StockoutIntelligenceService::STATUS_STOCKOUT:
                return "Stok {$itemName} saat ini telah habis ({$formattedStock} {$baseUom}). Disarankan segera melakukan pembelian restock sebanyak {$formattedQty} {$baseUom}.";
            case StockoutIntelligenceService::STATUS_STOCKOUT_RISK:
                return "Stok {$itemName} tersisa {$formattedStock} {$baseUom} (estimasi habis dalam {$daysUntilStockout} hari, di bawah lead time pemesanan). Rekomendasi restock: {$formattedQty} {$baseUom}.";
            case StockoutIntelligenceService::STATUS_WATCH:
                return "Stok {$itemName} ({$formattedStock} {$baseUom}) mendekati titik reorder ({$reorderPoint} {$baseUom}). Direkomendasikan menambah stok {$formattedQty} {$baseUom}.";
            default:
                return "Stok {$itemName} ({$formattedStock} {$baseUom}) berada pada batas aman.";
        }
    }

    /**
     * Build explainability payload for LLM/Copilot governance contract.
     */
    protected function buildExplainabilityPayload(int $userId, ?int $outletId, int $horizonDays, string $rationale): array
    {
        return [
            'what'        => 'Rekomendasi kebutuhan restock bahan baku kritis, pencocokan supplier, dan draf pemesanan.',
            'why'         => $rationale,
            'calculation' => 'Kebutuhan restock dihitung dari target stok aman (estimasi kebutuhan penjualan dan stok pengaman atau batas minimum) dikurangi stok yang tersedia saat ini.',
            'evidence'    => [
                'user_id'      => $userId,
                'outlet_id'    => $outletId,
                'horizon_days' => $horizonDays,
            ],
            'limitations' => 'Draf pesan WhatsApp dan estimasi biaya adalah instrumen konfirmasi ke supplier; bukan Purchase Order (PO) otomatis dan tidak melakukan mutasi kas.',
            'source'      => 'Sistem Manajemen Restock Kaching POS',
            'time_period' => sprintf('%d hari ke depan', $horizonDays),
        ];
    }
}
