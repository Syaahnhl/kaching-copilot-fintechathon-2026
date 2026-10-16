<?php

namespace App\Services\Financial;

use App\Models\Item;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Material Price Intelligence Service (Phase 3.6)
 *
 * Evaluates raw material procurement price trends, inflation pressure,
 * and cost trajectory.
 *
 * DATA INTEGRITY CONTRACT:
 * - Current `items.cost_price` is an in-place snapshot and MUST NOT be used
 *   as a historical price time series.
 * - If historical purchase order/invoice item series is missing, this engine
 *   strictly fails closed and returns PRICE_HISTORY_UNAVAILABLE.
 * - Zero fabricated price trends or synthetic supplier costs.
 */
class MaterialPriceIntelligenceService
{
    // Statuses
    public const STATUS_SUCCESS                 = 'SUCCESS';
    public const STATUS_DATA_BLOCKED            = 'DATA_BLOCKED';
    public const STATUS_PRICE_HISTORY_UNAVAILABLE = 'PRICE_HISTORY_UNAVAILABLE';
    public const STATUS_INSUFFICIENT_DATA       = 'INSUFFICIENT_PURCHASE_OBSERVATIONS';

    // Trend Classifications
    public const TREND_UP     = 'UP';
    public const TREND_STABLE = 'STABLE';
    public const TREND_DOWN   = 'DOWN';

    // Threshold: +/- 2.0% change over baseline
    public const TREND_THRESHOLD_PERCENT = 2.0;

    /**
     * Evaluate price trend for an item.
     *
     * @param int         $userId
     * @param int         $itemId
     * @param int|null    $outletId
     * @param array       $customObservations Optional inject for testing data contract
     * @return array
     */
    public function evaluateItemPriceTrend(
        int $userId,
        int $itemId,
        ?int $outletId = null,
        array $customObservations = []
    ): array {
        // 1. Verify item existence and tenant isolation via direct DB join on outlets
        $item = DB::table('items')
            ->join('outlets', 'items.outlet_id', '=', 'outlets.id')
            ->where('items.id', $itemId)
            ->where('outlets.user_id', $userId)
            ->when($outletId !== null, fn($q) => $q->where('items.outlet_id', $outletId))
            ->whereNull('items.deleted_at')
            ->select('items.id', 'items.name', 'items.base_uom', 'items.cost_price', 'items.outlet_id')
            ->first();

        if (!$item) {
            return [
                'status'  => 'ITEM_NOT_FOUND',
                'message' => 'Bahan baku/item tidak ditemukan atau berada di luar tenant/outlet.',
            ];
        }

        // 2. Audit Historical Purchase Data Availability
        $observations = !empty($customObservations) ? $customObservations : $this->queryHistoricalPurchaseSeries($itemId);

        // DATA-BLOCKED GATING:
        if (empty($observations)) {
            return [
                'status'         => self::STATUS_PRICE_HISTORY_UNAVAILABLE,
                'data_state'     => self::STATUS_DATA_BLOCKED,
                'item'           => [
                    'id'         => $item->id,
                    'name'       => $item->name,
                    'base_uom'   => $item->base_uom,
                    'current_snapshot_cost' => (float) $item->cost_price,
                ],
                'message'        => 'Data riwayat harga pembelian bahan baku belum tersedia dalam ledger.',
                'missing_data_dependencies' => [
                    'purchase_order_items' => 'Tabel transaksi invoice pembelian historis per item.',
                    'stock_movement_costs' => 'Kolom unit_cost pada stock_movements saat barang masuk (incoming).',
                ],
                'rejection_note' => 'Strict Invariant: items.cost_price adalah snapshot terkini dan dilarang difabrikasi sebagai deret waktu historis.',
            ];
        }

        // 3. Minimum Observation Gate (need >= 3 historical purchase records)
        if (count($observations) < 3) {
            return [
                'status'         => self::STATUS_INSUFFICIENT_DATA,
                'data_state'     => 'INSUFFICIENT_DATA',
                'observations_count' => count($observations),
                'message'        => 'Dibutuhkan minimal 3 observasi riwayat pembelian untuk menghitung tren harga deterministik.',
            ];
        }

        // 4. Calculate Deterministic Price Trend
        return $this->calculateDeterministicTrend($item, $observations);
    }

    /**
     * Query database for historical purchase series.
     * Audits whether a dedicated purchase ledger exists.
     */
    protected function queryHistoricalPurchaseSeries(int $itemId): array
    {
        // Check if purchase_items or purchase_orders exists in database
        if (Schema::hasTable('purchase_order_items')) {
            return DB::table('purchase_order_items')
                ->where('item_id', $itemId)
                ->whereNotNull('unit_price')
                ->orderBy('created_at', 'asc')
                ->get(['unit_price as price', 'qty', 'created_at as purchase_date'])
                ->toArray();
        }

        // Currently, Kaching schema records stock_movements without unit cost price,
        // and expenses is a lump-sum table without item breakdown.
        // Therefore, historical purchase price time series is absent.
        return [];
    }

    /**
     * Deterministic Trend Calculation via Least-Squares Linear Regression on historical purchase prices.
     */
    public function calculateDeterministicTrend(object $item, array $observations): array
    {
        $n = count($observations);
        $xVals = [];
        $yVals = [];

        $firstTimestamp = strtotime($observations[0]['purchase_date']);
        foreach ($observations as $obs) {
            $daysFromStart = (strtotime($obs['purchase_date']) - $firstTimestamp) / 86400.0;
            $xVals[] = $daysFromStart;
            $yVals[] = (float) $obs['price'];
        }

        $meanX = array_sum($xVals) / $n;
        $meanY = array_sum($yVals) / $n;

        $numerator = 0.0;
        $denominator = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $xVals[$i] - $meanX;
            $dy = $yVals[$i] - $meanY;
            $numerator += $dx * $dy;
            $denominator += $dx * $dx;
        }

        $slopePerDay = $denominator > 0.00001 ? ($numerator / $denominator) : 0.0;
        $baselinePrice = $yVals[0];
        $latestPrice = $yVals[$n - 1];

        // Overall percentage change from baseline
        $totalPctChange = $baselinePrice > 0 ? (($latestPrice - $baselinePrice) / $baselinePrice) * 100.0 : 0.0;

        // Classification
        $trend = self::TREND_STABLE;
        if ($totalPctChange > self::TREND_THRESHOLD_PERCENT) {
            $trend = self::TREND_UP;
        } elseif ($totalPctChange < -self::TREND_THRESHOLD_PERCENT) {
            $trend = self::TREND_DOWN;
        }

        // Project next 30 days price
        $lastDay = end($xVals);
        $projectedPrice30d = max(0.0, round($latestPrice + ($slopePerDay * 30.0), 2));

        return [
            'status'             => self::STATUS_SUCCESS,
            'item'               => [
                'id'             => $item->id,
                'name'           => $item->name,
                'base_uom'       => $item->base_uom,
            ],
            'trend'              => $trend,
            'metrics'            => [
                'observations_count' => $n,
                'baseline_price'     => round($baselinePrice, 2),
                'latest_price'       => round($latestPrice, 2),
                'percentage_change'  => round($totalPctChange, 2),
                'slope_per_day'      => round($slopePerDay, 4),
                'projected_price_30d'=> $projectedPrice30d,
            ],
            'evidence'           => [
                'first_purchase'     => $observations[0]['purchase_date'],
                'latest_purchase'    => $observations[$n - 1]['purchase_date'],
                'data_source'        => 'HISTORICAL_PURCHASE_SERIES',
            ],
            'limitations'        => 'Proyeksi linier mengasumsikan tren berkelanjutan dan tidak memperhitungkan guncangan pasokan makroekonomi.',
        ];
    }
}
