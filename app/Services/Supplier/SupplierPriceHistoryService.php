<?php

namespace App\Services\Supplier;

use App\Models\Item;
use App\Models\ItemSupplierPriceHistory;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierPriceHistoryService
{
    /**
     * Catat perubahan harga supplier untuk sebuah item secara idempotent.
     */
    public function recordPriceChange(
        int $itemId,
        int $supplierId,
        float $newPrice,
        ?float $oldPrice = null,
        string $changeReason = 'MANUAL_UPDATE',
        ?int $userId = null,
        ?Carbon $effectiveDate = null,
        ?int $recordedByUserId = null,
        ?array $metadata = null
    ): ?ItemSupplierPriceHistory {
        $effectiveDate = $effectiveDate ?? now();

        // Cari record histori harga terakhir untuk pasangan item & supplier ini
        $latestRecord = ItemSupplierPriceHistory::query()
            ->where('item_id', $itemId)
            ->where('supplier_id', $supplierId)
            ->orderBy('effective_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        // Idempotency Guard: Jika harga baru sama persis dengan harga terakhir yang tercatat, jangan buat duplikat
        if ($latestRecord && abs((float) $latestRecord->new_price - $newPrice) < 0.001) {
            return $latestRecord;
        }

        // Tentukan old price jika belum diberikan
        if ($oldPrice === null) {
            if ($latestRecord) {
                $oldPrice = (float) $latestRecord->new_price;
            } else {
                // Ambil dari pivot item_supplier eksisting jika ada
                $pivot = DB::table('item_supplier')
                    ->where('item_id', $itemId)
                    ->where('supplier_id', $supplierId)
                    ->first();

                $oldPrice = $pivot ? (float) $pivot->supplier_price : null;
            }
        }

        // Tentukan user_id tenant jika belum diberikan
        if ($userId === null) {
            $item = Item::with('outlet')->find($itemId);
            $userId = $item?->outlet?->user_id;
        }

        $history = ItemSupplierPriceHistory::create([
            'item_id' => $itemId,
            'supplier_id' => $supplierId,
            'user_id' => $userId,
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
            'currency' => 'IDR',
            'change_reason' => $changeReason,
            'effective_date' => $effectiveDate,
            'recorded_by_user_id' => $recordedByUserId,
            'metadata' => $metadata,
        ]);

        Log::info("[SupplierPriceHistoryService] Recorded price change for Item #{$itemId} Supplier #{$supplierId}", [
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
            'reason' => $changeReason,
        ]);

        return $history;
    }

    /**
     * Sinkronkan harga supplier pada pivot item_supplier sekaligus mencatat histori.
     */
    public function syncItemSupplierPrice(
        int $itemId,
        int $supplierId,
        float $supplierPrice,
        ?int $leadTimeDays = null,
        ?int $userId = null,
        string $changeReason = 'MANUAL_UPDATE'
    ): array {
        $pivot = DB::table('item_supplier')
            ->where('item_id', $itemId)
            ->where('supplier_id', $supplierId)
            ->first();

        $oldPrice = $pivot ? (float) $pivot->supplier_price : null;
        $priceChanged = ($pivot === null) || (abs((float) $pivot->supplier_price - $supplierPrice) >= 0.001);

        $updateData = [
            'supplier_price' => $supplierPrice,
            'updated_at' => now(),
        ];

        if ($leadTimeDays !== null) {
            $updateData['lead_time_days'] = $leadTimeDays;
        }

        if ($pivot) {
            DB::table('item_supplier')
                ->where('item_id', $itemId)
                ->where('supplier_id', $supplierId)
                ->update($updateData);
        } else {
            $updateData['item_id'] = $itemId;
            $updateData['supplier_id'] = $supplierId;
            $updateData['created_at'] = now();
            $updateData['lead_time_days'] = $leadTimeDays ?? 3;
            DB::table('item_supplier')->insert($updateData);
        }

        $history = null;
        if ($priceChanged) {
            $history = $this->recordPriceChange(
                itemId: $itemId,
                supplierId: $supplierId,
                newPrice: $supplierPrice,
                oldPrice: $oldPrice,
                changeReason: $changeReason,
                userId: $userId
            );
        }

        return [
            'item_id' => $itemId,
            'supplier_id' => $supplierId,
            'price_updated' => $priceChanged,
            'current_price' => $supplierPrice,
            'old_price' => $oldPrice,
            'history_id' => $history?->id,
        ];
    }

    /**
     * Ambil riwayat pergerakan harga supplier untuk suatu item.
     */
    public function getPriceHistory(int $itemId, ?int $supplierId = null, int $limit = 30): Collection
    {
        $query = ItemSupplierPriceHistory::query()
            ->with(['supplier:id,name', 'item:id,name,sku'])
            ->where('item_id', $itemId);

        if ($supplierId !== null) {
            $query->where('supplier_id', $supplierId);
        }

        return $query->orderBy('effective_date', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Hitung laju inflasi / perubahan harga supplier dalam kurun waktu tertentu (misal 30/90 hari).
     */
    public function calculatePriceTrend(int $itemId, int $supplierId, int $daysLookback = 90): array
    {
        $sinceDate = now()->subDays($daysLookback);

        $histories = ItemSupplierPriceHistory::query()
            ->where('item_id', $itemId)
            ->where('supplier_id', $supplierId)
            ->where('effective_date', '>=', $sinceDate)
            ->orderBy('effective_date', 'asc')
            ->get();

        if ($histories->count() < 2) {
            $latest = $histories->last();
            return [
                'has_trend' => false,
                'current_price' => $latest ? (float) $latest->new_price : 0,
                'pct_change' => 0.0,
                'observations_count' => $histories->count(),
            ];
        }

        $firstPrice = (float) $histories->first()->new_price;
        $latestPrice = (float) $histories->last()->new_price;

        $pctChange = $firstPrice > 0 ? round((($latestPrice - $firstPrice) / $firstPrice) * 100, 2) : 0.0;

        return [
            'has_trend' => true,
            'initial_price' => $firstPrice,
            'current_price' => $latestPrice,
            'pct_change' => $pctChange,
            'observations_count' => $histories->count(),
            'period_days' => $daysLookback,
        ];
    }
}
