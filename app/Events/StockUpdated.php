<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $userId;
    public int $outletId;
    public int $menuId;
    public float $currentStock;
    public int $version;
    public ?string $txUuid;
    public string $mutationType;
    public float $quantityDelta;
    public ?string $referenceType;
    public ?string $referenceId;
    public int $occurredAt;
    public array $items;

    public function __construct(
        int $userId,
        int $outletId,
        int $menuId,
        float $currentStock,
        int $version,
        ?string $txUuid = null,
        string $mutationType = 'ADJUSTMENT',
        float $quantityDelta = 0.0,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?int $occurredAt = null,
        array $items = []
    ) {
        $this->userId = $userId;
        $this->outletId = $outletId;
        $this->menuId = $menuId;
        $this->currentStock = $currentStock;
        $this->version = $version;
        $this->txUuid = $txUuid;
        $this->mutationType = $mutationType;
        $this->quantityDelta = $quantityDelta;
        $this->referenceType = $referenceType;
        $this->referenceId = $referenceId;
        $this->occurredAt = $occurredAt ?? (int) (microtime(true) * 1000);

        if (empty($items)) {
            $this->items = [
                [
                    'menu_id' => $menuId,
                    'current_stock' => $currentStock,
                    'version' => $version,
                    'quantity_delta' => $quantityDelta,
                ],
            ];
        } else {
            $this->items = $items;
        }
    }

    /**
     * Broadcast strictly scoped to user tenant store channel.
     * Android listens to store.{userId}
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('store.' . $this->userId),
        ];
    }

    /**
     * Explicit broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'stock.updated';
    }

    /**
     * Payload with complete audit & reconciliation metadata.
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'outlet_id' => $this->outletId,
            'menu_id' => $this->menuId,
            'current_stock' => $this->currentStock,
            'version' => $this->version,
            'tx_uuid' => $this->txUuid,
            'mutation_type' => $this->mutationType,
            'quantity_delta' => $this->quantityDelta,
            'reference_type' => $this->referenceType,
            'reference_id' => $this->referenceId,
            'occurred_at' => $this->occurredAt,
            'items' => $this->items,
        ];
    }
}
