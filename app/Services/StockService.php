<?php

namespace App\Services;

use App\Events\StockUpdated;
use App\Models\Menu;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class StockService
{
    // Mutation Types
    public const TYPE_INCREMENT = 'increment';
    public const TYPE_DECREMENT = 'decrement';
    public const TYPE_SALE = 'sale';
    public const TYPE_REFUND = 'refund';
    public const TYPE_VOID = 'void';
    public const TYPE_ADJUST = 'adjustment';
    public const TYPE_INITIAL = 'initial';
    public const TYPE_OPNAME = 'opname';

    // Status Codes
    public const CODE_APPLIED = 'APPLIED';
    public const CODE_DUPLICATE_BYPASS = 'DUPLICATE_BYPASS';
    public const CODE_NOOP_UNLIMITED = 'NOOP_UNLIMITED';
    public const CODE_CONFLICT = 'CONFLICT';

    /**
     * Canonical entry point untuk mutasi delta stock.
     * Mendukung idempotency via tx_uuid (Database Unique Constraint & Atomic Locking).
     */
    public function mutateDelta(
        int $menuId,
        int $userId,
        int $outletId,
        float $quantityDelta,
        string $type,
        ?string $txUuid = null,
        ?int $staffId = null,
        ?string $deviceId = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $notes = null,
        bool $shouldBroadcast = true
    ): array {
        if ($quantityDelta == 0.0) {
            throw ValidationException::withMessages([
                'quantity_delta' => ['Quantity delta cannot be zero.'],
            ]);
        }

        // 1. Cek Idempotency jika tx_uuid diberikan
        if ($txUuid) {
            $existingMovement = StockMovement::where('tx_uuid', $txUuid)->first();
            if ($existingMovement) {
                $menu = Menu::where('id', $menuId)
                    ->where('user_id', $userId)
                    ->where('outlet_id', $outletId)
                    ->first();

                return [
                    'status' => 'success',
                    'code' => self::CODE_DUPLICATE_BYPASS,
                    'message' => 'Duplicate mutation detected (Idempotency bypass).',
                    'data' => [
                        'menu_id' => $menuId,
                        'outlet_id' => $outletId,
                        'applied_delta' => (float)$existingMovement->quantity_delta,
                        'balance_before' => (float)$existingMovement->balance_before,
                        'balance_after' => (float)$existingMovement->balance_after,
                        'version' => $menu ? (int)$menu->version : 1,
                        'tx_uuid' => $txUuid,
                    ],
                ];
            }
        }

        // 2. Eksekusi Atomic Transaction dengan Row-Level Locking (SELECT FOR UPDATE)
        return DB::transaction(function () use (
            $menuId,
            $userId,
            $outletId,
            $quantityDelta,
            $type,
            $txUuid,
            $staffId,
            $deviceId,
            $referenceType,
            $referenceId,
            $notes,
            $shouldBroadcast
        ) {
            // Re-check idempotency di dalam transaksi (Double-Checked Locking against race conditions)
            if ($txUuid) {
                $existing = DB::table('stock_movements')->where('tx_uuid', $txUuid)->first();
                if ($existing) {
                    $menu = DB::table('menus')->where('id', $menuId)->first();
                    return [
                        'status' => 'success',
                        'code' => self::CODE_DUPLICATE_BYPASS,
                        'message' => 'Duplicate mutation detected (Idempotency bypass).',
                        'data' => [
                            'menu_id' => $menuId,
                            'outlet_id' => $outletId,
                            'applied_delta' => (float)($existing->quantity_delta ?? 0),
                            'balance_before' => (float)($existing->balance_before ?? 0),
                            'balance_after' => (float)($existing->balance_after ?? 0),
                            'version' => $menu ? (int)$menu->version : 1,
                            'tx_uuid' => $txUuid,
                        ],
                    ];
                }
            }

            // Lock row menu dengan scoping user_id dan outlet_id yang ketat
            $menu = Menu::where('id', $menuId)
                ->where('user_id', $userId)
                ->where('outlet_id', $outletId)
                ->lockForUpdate()
                ->first();

            if (!$menu) {
                throw ValidationException::withMessages([
                    'menu_id' => ["Menu ID {$menuId} not found for Outlet {$outletId} or unauthorized."],
                ]);
            }

            $currentStock = (float)$menu->stock;

            // Handle stok unlimited (-1)
            if ($currentStock == -1.0) {
                // Catat audit ledger bila ada tx_uuid tanpa mengubah stok unlimited
                if ($txUuid) {
                    try {
                        StockMovement::create([
                            'menu_id' => $menuId,
                            'outlet_id' => $outletId,
                            'user_id' => $userId,
                            'staff_id' => $staffId,
                            'device_id' => $deviceId,
                            'type' => $type,
                            'qty' => abs($quantityDelta),
                            'quantity_delta' => $quantityDelta,
                            'balance_before' => -1.0,
                            'balance_after' => -1.0,
                            'reference_type' => $referenceType,
                            'reference_id' => $referenceId,
                            'tx_uuid' => $txUuid,
                            'notes' => $notes ?: 'Unlimited stock mutation audit',
                        ]);
                    } catch (\Illuminate\Database\UniqueConstraintViolationException | \Illuminate\Database\QueryException $e) {
                        if (!self::isTxUuidDuplicateException($e, $txUuid)) {
                            throw $e;
                        }
                        // Idempotent duplicate catch
                    }
                }

                return [
                    'status' => 'success',
                    'code' => self::CODE_NOOP_UNLIMITED,
                    'message' => 'Stock is unlimited (-1). No delta applied.',
                    'data' => [
                        'menu_id' => $menuId,
                        'outlet_id' => $outletId,
                        'applied_delta' => 0.0,
                        'balance_before' => -1.0,
                        'balance_after' => -1.0,
                        'version' => (int)$menu->version,
                        'tx_uuid' => $txUuid,
                    ],
                ];
            }

            $balanceBefore = $currentStock;
            $balanceAfter = $balanceBefore + $quantityDelta;

            // Optional: stock tidak boleh negatif jika bukan unconstrained adjustment
            // if ($balanceAfter < 0 && !in_array($type, [self::TYPE_ADJUST])) { ... }

            // 1. Insert ke Stock Movement Ledger
            try {
                $movement = StockMovement::create([
                    'menu_id' => $menuId,
                    'outlet_id' => $outletId,
                    'user_id' => $userId,
                    'staff_id' => $staffId,
                    'device_id' => $deviceId,
                    'type' => $type,
                    'qty' => abs($quantityDelta),
                    'quantity_delta' => $quantityDelta,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'tx_uuid' => $txUuid,
                    'notes' => $notes,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException | \Illuminate\Database\QueryException $e) {
                if (self::isTxUuidDuplicateException($e, $txUuid)) {
                    Log::info("[StockService] Idempotent duplicate collision caught on tx_uuid={$txUuid}, treating as already applied.");

                    $freshMenu = DB::table('menus')->where('id', $menuId)->first();
                    $existingMovement = $txUuid ? DB::table('stock_movements')->where('tx_uuid', $txUuid)->first() : null;

                    return [
                        'status' => 'success',
                        'code' => self::CODE_DUPLICATE_BYPASS,
                        'message' => 'Duplicate mutation detected (Idempotency bypass).',
                        'data' => [
                            'menu_id' => $menuId,
                            'outlet_id' => $outletId,
                            'applied_delta' => $existingMovement ? (float)$existingMovement->quantity_delta : 0.0,
                            'balance_before' => $existingMovement ? (float)$existingMovement->balance_before : ($freshMenu ? (float)$freshMenu->stock : (float)$balanceBefore),
                            'balance_after' => $existingMovement ? (float)$existingMovement->balance_after : ($freshMenu ? (float)$freshMenu->stock : (float)$balanceBefore),
                            'version' => $freshMenu ? (int)$freshMenu->version : (int)$menu->version,
                            'tx_uuid' => $txUuid,
                        ],
                    ];
                }
                throw $e;
            }

            // 2. Update saldo & increment version di master menus
            $newVersion = ((int)$menu->version) + 1;
            $menu->stock = $balanceAfter;
            $menu->version = $newVersion;
            $menu->save();

            // 3. Dispatch Real-time Broadcast Event (ShouldBroadcastNow / Synchronous commit)
            if ($shouldBroadcast) {
                try {
                    event(new StockUpdated(
                        userId: (int)$userId,
                        outletId: (int)$outletId,
                        menuId: (int)$menuId,
                        currentStock: (float)$balanceAfter,
                        version: (int)$newVersion,
                        txUuid: $txUuid,
                        mutationType: (string)$type,
                        quantityDelta: (float)$quantityDelta,
                        referenceType: $referenceType,
                        referenceId: $referenceId
                    ));
                } catch (\Throwable $broadcastException) {
                    Log::warning("Failed to broadcast StockUpdated event: " . $broadcastException->getMessage(), [
                        'menu_id' => $menuId,
                        'tx_uuid' => $txUuid,
                    ]);
                }
            }

            return [
                'status' => 'success',
                'code' => self::CODE_APPLIED,
                'message' => 'Stock mutation successfully applied.',
                'data' => [
                    'menu_id' => $menuId,
                    'outlet_id' => $outletId,
                    'applied_delta' => $quantityDelta,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'version' => $newVersion,
                    'tx_uuid' => $txUuid,
                ],
            ];
        });
    }

    /**
     * Entry point umum untuk mutasi delta (adjust).
     * Menerima array $payload atau parameter individual.
     */
    public function adjust(
        int|array $menuIdOrPayload,
        ?int $userId = null,
        ?int $outletId = null,
        ?float $quantityDelta = null,
        ?string $type = null,
        ?string $txUuid = null,
        ?int $staffId = null,
        ?string $deviceId = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $notes = null,
        bool $shouldBroadcast = true
    ): array {
        if (is_array($menuIdOrPayload)) {
            $payload = $menuIdOrPayload;
            $menuId = (int)($payload['menu_id'] ?? 0);
            $userId = (int)($payload['user_id'] ?? 0);
            $outletId = (int)($payload['outlet_id'] ?? 0);
            $quantityDelta = (float)($payload['quantity_delta'] ?? 0.0);
            $type = (string)($payload['type'] ?? self::TYPE_ADJUSTMENT);
            $txUuid = $payload['tx_uuid'] ?? null;
            $staffId = isset($payload['staff_id']) ? (int)$payload['staff_id'] : null;
            $deviceId = $payload['device_id'] ?? null;
            $referenceType = $payload['reference_type'] ?? null;
            $referenceId = $payload['reference_id'] ?? null;
            $notes = $payload['notes'] ?? null;
            $shouldBroadcast = isset($payload['should_broadcast']) ? (bool)$payload['should_broadcast'] : $shouldBroadcast;
        } else {
            $menuId = $menuIdOrPayload;
            $type = $type ?? self::TYPE_ADJUSTMENT;
        }

        return $this->mutateDelta(
            $menuId,
            $userId,
            $outletId,
            $quantityDelta,
            $type,
            $txUuid,
            $staffId,
            $deviceId,
            $referenceType,
            $referenceId,
            $notes,
            $shouldBroadcast
        );
    }

    /**
     * Helper mutasi Increment.
     * Menerima array $payload atau parameter individual.
     */
    public function increment(
        int|array $menuIdOrPayload,
        ?int $userId = null,
        ?int $outletId = null,
        ?float $delta = null,
        ?string $txUuid = null,
        ?int $staffId = null,
        ?string $deviceId = null,
        ?string $notes = null,
        bool $shouldBroadcast = true
    ): array {
        if (is_array($menuIdOrPayload)) {
            $p = $menuIdOrPayload;
            $menuId = (int)($p['menu_id'] ?? 0);
            $userId = (int)($p['user_id'] ?? 0);
            $outletId = (int)($p['outlet_id'] ?? 0);
            $delta = (float)($p['quantity_delta'] ?? $p['quantity'] ?? $p['qty'] ?? $p['delta'] ?? 0.0);
            $txUuid = $p['tx_uuid'] ?? null;
            $staffId = isset($p['staff_id']) ? (int)$p['staff_id'] : null;
            $deviceId = $p['device_id'] ?? null;
            $notes = $p['notes'] ?? null;
            $shouldBroadcast = isset($p['should_broadcast']) ? (bool)$p['should_broadcast'] : $shouldBroadcast;
        } else {
            $menuId = $menuIdOrPayload;
        }

        return $this->mutateDelta(
            $menuId,
            $userId,
            $outletId,
            abs($delta),
            self::TYPE_INCREMENT,
            $txUuid,
            $staffId,
            $deviceId,
            'manual',
            null,
            $notes,
            $shouldBroadcast
        );
    }

    /**
     * Helper mutasi Decrement.
     * Menerima array $payload atau parameter individual.
     */
    public function decrement(
        int|array $menuIdOrPayload,
        ?int $userId = null,
        ?int $outletId = null,
        ?float $delta = null,
        ?string $txUuid = null,
        ?int $staffId = null,
        ?string $deviceId = null,
        ?string $notes = null,
        bool $shouldBroadcast = true
    ): array {
        if (is_array($menuIdOrPayload)) {
            $p = $menuIdOrPayload;
            $menuId = (int)($p['menu_id'] ?? 0);
            $userId = (int)($p['user_id'] ?? 0);
            $outletId = (int)($p['outlet_id'] ?? 0);
            $delta = (float)($p['quantity_delta'] ?? $p['quantity'] ?? $p['qty'] ?? $p['delta'] ?? 0.0);
            $txUuid = $p['tx_uuid'] ?? null;
            $staffId = isset($p['staff_id']) ? (int)$p['staff_id'] : null;
            $deviceId = $p['device_id'] ?? null;
            $notes = $p['notes'] ?? null;
            $shouldBroadcast = isset($p['should_broadcast']) ? (bool)$p['should_broadcast'] : $shouldBroadcast;
        } else {
            $menuId = $menuIdOrPayload;
        }

        return $this->mutateDelta(
            $menuId,
            $userId,
            $outletId,
            -abs($delta),
            self::TYPE_DECREMENT,
            $txUuid,
            $staffId,
            $deviceId,
            'manual',
            null,
            $notes,
            $shouldBroadcast
        );
    }

    /**
     * Helper mutasi Sale (Penjualan).
     * Menerima array $payload atau parameter individual.
     */
    public function sale(
        int|array $menuIdOrPayload,
        ?int $userId = null,
        ?int $outletId = null,
        ?float $qty = null,
        ?string $txUuid = null,
        ?int $staffId = null,
        ?string $deviceId = null,
        ?string $transactionId = null,
        ?string $notes = null,
        bool $shouldBroadcast = true
    ): array {
        if (is_array($menuIdOrPayload)) {
            $p = $menuIdOrPayload;
            $menuId = (int)($p['menu_id'] ?? 0);
            $userId = (int)($p['user_id'] ?? 0);
            $outletId = (int)($p['outlet_id'] ?? 0);
            $qty = (float)($p['quantity'] ?? $p['quantity_delta'] ?? $p['qty'] ?? 0.0);
            $txUuid = $p['tx_uuid'] ?? null;
            $staffId = isset($p['staff_id']) ? (int)$p['staff_id'] : null;
            $deviceId = $p['device_id'] ?? null;
            $transactionId = $p['reference_id'] ?? $p['transaction_id'] ?? null;
            $notes = $p['notes'] ?? null;
            $shouldBroadcast = isset($p['should_broadcast']) ? (bool)$p['should_broadcast'] : $shouldBroadcast;
        } else {
            $menuId = $menuIdOrPayload;
        }

        return $this->mutateDelta(
            $menuId,
            $userId,
            $outletId,
            -abs($qty),
            self::TYPE_SALE,
            $txUuid,
            $staffId,
            $deviceId,
            'transaction',
            $transactionId,
            $notes,
            $shouldBroadcast
        );
    }

    /**
     * Helper mutasi Refund.
     * Menerima array $payload atau parameter individual.
     */
    public function refund(
        int|array $menuIdOrPayload,
        ?int $userId = null,
        ?int $outletId = null,
        ?float $qty = null,
        ?string $txUuid = null,
        ?int $staffId = null,
        ?string $deviceId = null,
        ?string $transactionId = null,
        ?string $notes = null,
        bool $shouldBroadcast = true
    ): array {
        if (is_array($menuIdOrPayload)) {
            $p = $menuIdOrPayload;
            $menuId = (int)($p['menu_id'] ?? 0);
            $userId = (int)($p['user_id'] ?? 0);
            $outletId = (int)($p['outlet_id'] ?? 0);
            $qty = (float)($p['quantity'] ?? $p['quantity_delta'] ?? $p['qty'] ?? 0.0);
            $txUuid = $p['tx_uuid'] ?? null;
            $staffId = isset($p['staff_id']) ? (int)$p['staff_id'] : null;
            $deviceId = $p['device_id'] ?? null;
            $transactionId = $p['reference_id'] ?? $p['transaction_id'] ?? null;
            $notes = $p['notes'] ?? null;
            $shouldBroadcast = isset($p['should_broadcast']) ? (bool)$p['should_broadcast'] : $shouldBroadcast;
        } else {
            $menuId = $menuIdOrPayload;
        }

        return $this->mutateDelta(
            $menuId,
            $userId,
            $outletId,
            abs($qty),
            self::TYPE_REFUND,
            $txUuid,
            $staffId,
            $deviceId,
            'refund',
            $transactionId,
            $notes,
            $shouldBroadcast
        );
    }

    /**
     * Helper mutasi Void.
     * Menerima array $payload atau parameter individual.
     */
    public function void(
        int|array $menuIdOrPayload,
        ?int $userId = null,
        ?int $outletId = null,
        ?float $qty = null,
        ?string $txUuid = null,
        ?int $staffId = null,
        ?string $deviceId = null,
        ?string $transactionId = null,
        ?string $notes = null,
        bool $shouldBroadcast = true
    ): array {
        if (is_array($menuIdOrPayload)) {
            $p = $menuIdOrPayload;
            $menuId = (int)($p['menu_id'] ?? 0);
            $userId = (int)($p['user_id'] ?? 0);
            $outletId = (int)($p['outlet_id'] ?? 0);
            $qty = (float)($p['quantity'] ?? $p['quantity_delta'] ?? $p['qty'] ?? 0.0);
            $txUuid = $p['tx_uuid'] ?? null;
            $staffId = isset($p['staff_id']) ? (int)$p['staff_id'] : null;
            $deviceId = $p['device_id'] ?? null;
            $transactionId = $p['reference_id'] ?? $p['transaction_id'] ?? null;
            $notes = $p['notes'] ?? null;
            $shouldBroadcast = isset($p['should_broadcast']) ? (bool)$p['should_broadcast'] : $shouldBroadcast;
        } else {
            $menuId = $menuIdOrPayload;
        }

        return $this->mutateDelta(
            $menuId,
            $userId,
            $outletId,
            abs($qty),
            self::TYPE_VOID,
            $txUuid,
            $staffId,
            $deviceId,
            'void',
            $transactionId,
            $notes,
            $shouldBroadcast
        );
    }

    /**
     * Operasi Absolut: Stock Opname / Penyesuaian Fisik dengan Version Guard.
     * Menerima array $payload atau parameter individual.
     */
    public function stockOpname(
        int|array $menuIdOrPayload,
        ?int $userId = null,
        ?int $outletId = null,
        ?float $physicalStock = null,
        ?int $expectedVersion = null,
        ?string $txUuid = null,
        ?int $staffId = null,
        ?string $deviceId = null,
        ?string $notes = null
    ): array {
        if (is_array($menuIdOrPayload)) {
            $payload = $menuIdOrPayload;
            $menuId = (int)($payload['menu_id'] ?? 0);
            $userId = (int)($payload['user_id'] ?? 0);
            $outletId = (int)($payload['outlet_id'] ?? 0);
            $physicalStock = (float)($payload['physical_stock'] ?? 0.0);
            $expectedVersion = isset($payload['expected_version']) ? (int)$payload['expected_version'] : null;
            $txUuid = $payload['tx_uuid'] ?? null;
            $staffId = isset($payload['staff_id']) ? (int)$payload['staff_id'] : null;
            $deviceId = $payload['device_id'] ?? null;
            $notes = $payload['notes'] ?? 'Stock opname';
        } else {
            $menuId = $menuIdOrPayload;
        }
        // Cek Idempotency jika tx_uuid diberikan
        if ($txUuid) {
            $existingMovement = StockMovement::where('tx_uuid', $txUuid)->first();
            if ($existingMovement) {
                $menu = Menu::where('id', $menuId)->where('user_id', $userId)->where('outlet_id', $outletId)->first();
                return [
                    'status' => 'success',
                    'code' => self::CODE_DUPLICATE_BYPASS,
                    'message' => 'Duplicate opname detected (Idempotency bypass).',
                    'data' => [
                        'menu_id' => $menuId,
                        'outlet_id' => $outletId,
                        'applied_delta' => (float)$existingMovement->quantity_delta,
                        'balance_before' => (float)$existingMovement->balance_before,
                        'balance_after' => (float)$existingMovement->balance_after,
                        'version' => $menu ? (int)$menu->version : 1,
                        'tx_uuid' => $txUuid,
                    ],
                ];
            }
        }

        return DB::transaction(function () use (
            $menuId,
            $userId,
            $outletId,
            $physicalStock,
            $expectedVersion,
            $txUuid,
            $staffId,
            $deviceId,
            $notes
        ) {
            $menu = Menu::where('id', $menuId)
                ->where('user_id', $userId)
                ->where('outlet_id', $outletId)
                ->lockForUpdate()
                ->first();

            if (!$menu) {
                throw ValidationException::withMessages([
                    'menu_id' => ["Menu ID {$menuId} not found for Outlet {$outletId} or unauthorized."],
                ]);
            }

            // Version Guard
            if ($expectedVersion !== null && (int)$menu->version !== (int)$expectedVersion) {
                throw ValidationException::withMessages([
                    'version' => ["Stock Opname conflict: expected version {$expectedVersion}, current version {$menu->version}."],
                ]);
            }

            $currentStock = (float)$menu->stock;
            $newStock = $physicalStock >= 9999999 ? -1.0 : $physicalStock;
            $delta = ($currentStock == -1.0 || $newStock == -1.0) ? 0.0 : ($newStock - $currentStock);

            $balanceBefore = $currentStock;
            $balanceAfter = $newStock;

            // 1. Insert Movement Ledger
            StockMovement::create([
                'menu_id' => $menuId,
                'outlet_id' => $outletId,
                'user_id' => $userId,
                'staff_id' => $staffId,
                'device_id' => $deviceId,
                'type' => self::TYPE_OPNAME,
                'qty' => abs($delta),
                'quantity_delta' => $delta,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_type' => 'opname',
                'reference_id' => null,
                'tx_uuid' => $txUuid,
                'notes' => $notes ?: "Stock opname to {$newStock}",
            ]);

            // 2. Update stock & increment version
            $newVersion = ((int)$menu->version) + 1;
            $menu->stock = $newStock;
            $menu->version = $newVersion;
            $menu->save();

            // 3. Dispatch Real-time Broadcast Event
            try {
                event(new StockUpdated(
                    userId: (int)$userId,
                    outletId: (int)$outletId,
                    menuId: (int)$menuId,
                    currentStock: (float)$newStock,
                    version: (int)$newVersion,
                    txUuid: $txUuid,
                    mutationType: self::TYPE_OPNAME,
                    quantityDelta: (float)$delta,
                    referenceType: 'opname',
                    referenceId: null
                ));
            } catch (\Throwable $broadcastException) {
                Log::warning("Failed to broadcast StockUpdated event on opname: " . $broadcastException->getMessage(), [
                    'menu_id' => $menuId,
                    'tx_uuid' => $txUuid,
                ]);
            }

            return [
                'status' => 'success',
                'code' => self::CODE_APPLIED,
                'message' => 'Stock opname successfully applied.',
                'data' => [
                    'menu_id' => $menuId,
                    'outlet_id' => $outletId,
                    'applied_delta' => $delta,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'version' => $newVersion,
                    'tx_uuid' => $txUuid,
                ],
            ];
        });
    }

    /**
     * Broadcast 1 consolidated StockUpdated event untuk sekumpulan mutasi item dalam satu transaksi/batch.
     * Mencegah pemancaran berulang (N messages) untuk transaksi multi-item.
     *
     * @param int $userId Tenant/store owner ID
     * @param int $outletId Outlet ID
     * @param array $mutatedItems Daftar mutasi item [['menu_id' => ..., 'balance_after' => ..., 'version' => ..., 'applied_delta' => ...], ...]
     * @param string|null $txUuid UUID transaksi induk
     * @param string $mutationType Tipe mutasi ('sale', 'refund', dsb)
     * @param string|null $referenceType
     * @param string|null $referenceId
     */
    public function broadcastConsolidated(
        int $userId,
        int $outletId,
        array $mutatedItems,
        ?string $txUuid = null,
        string $mutationType = 'sale',
        ?string $referenceType = null,
        ?string $referenceId = null
    ): void {
        if (empty($mutatedItems)) {
            return;
        }

        // Deduplikasi mutasi per menu_id jika dalam 1 transaksi menu yang sama muncul berkali-kali
        $consolidatedByMenu = [];
        foreach ($mutatedItems as $item) {
            $menuId = (int)($item['menu_id'] ?? 0);
            if ($menuId <= 0) {
                continue;
            }

            $currentStock = (float)($item['balance_after'] ?? ($item['current_stock'] ?? 0.0));
            $version = (int)($item['version'] ?? 1);
            $delta = (float)($item['applied_delta'] ?? ($item['quantity_delta'] ?? 0.0));

            if (!isset($consolidatedByMenu[$menuId])) {
                $consolidatedByMenu[$menuId] = [
                    'menu_id' => $menuId,
                    'current_stock' => $currentStock,
                    'version' => $version,
                    'quantity_delta' => $delta,
                ];
            } else {
                $consolidatedByMenu[$menuId]['current_stock'] = $currentStock;
                $consolidatedByMenu[$menuId]['version'] = max($consolidatedByMenu[$menuId]['version'], $version);
                $consolidatedByMenu[$menuId]['quantity_delta'] += $delta;
            }
        }

        if (empty($consolidatedByMenu)) {
            return;
        }

        $formattedItems = array_values($consolidatedByMenu);
        $first = $formattedItems[0];

        try {
            event(new StockUpdated(
                userId: (int)$userId,
                outletId: (int)$outletId,
                menuId: (int)$first['menu_id'],
                currentStock: (float)$first['current_stock'],
                version: (int)$first['version'],
                txUuid: $txUuid,
                mutationType: $mutationType,
                quantityDelta: (float)$first['quantity_delta'],
                referenceType: $referenceType,
                referenceId: $referenceId,
                occurredAt: null,
                items: $formattedItems
            ));
        } catch (\Throwable $broadcastException) {
            Log::warning("Failed to broadcast consolidated StockUpdated event: " . $broadcastException->getMessage(), [
                'user_id' => $userId,
                'outlet_id' => $outletId,
                'tx_uuid' => $txUuid,
                'items_count' => count($formattedItems),
            ]);
        }
    }

    /**
     * Verifikasi ketat apakah exception merupakan tabrakan unique constraint
     * pada stock_movements_tx_uuid_unique untuk tx_uuid yang sama persis.
     */
    public static function isTxUuidDuplicateException(\Throwable $e, ?string $txUuid): bool
    {
        if (empty($txUuid)) {
            return false;
        }

        $isUniqueException = ($e instanceof \Illuminate\Database\UniqueConstraintViolationException);
        if (!$isUniqueException && ($e instanceof \Illuminate\Database\QueryException)) {
            $sqlState = $e->errorInfo[0] ?? (string)$e->getCode();
            $driverCode = $e->errorInfo[1] ?? null;
            $isUniqueException = ($sqlState === '23000' && (in_array((int)$driverCode, [1062, 19, 2067], true) || str_contains($e->getMessage(), '1062')));
        }

        if (!$isUniqueException) {
            return false;
        }

        $message = $e->getMessage();

        // 1. Pastikan exception berasal dari unique key stock_movements.tx_uuid
        $isTxUuidKey = str_contains($message, 'stock_movements_tx_uuid_unique')
            || str_contains($message, 'stock_movements.tx_uuid')
            || str_contains($message, 'stock_movements.stock_movements_tx_uuid_unique');

        if (!$isTxUuidKey) {
            return false;
        }

        // 2. Jika pesan memuat "Duplicate entry '<value>' for key", validasi kesesuaian nilai tx_uuid
        if (preg_match("/Duplicate entry '([^']+)' for key/i", $message, $matches)) {
            $collidedValue = $matches[1];
            return ($collidedValue === $txUuid);
        }

        // 3. Fallback driver (mis. SQLite): pastikan baris dengan tx_uuid yang sedang diproses memang ada di database
        try {
            return DB::table('stock_movements')->where('tx_uuid', $txUuid)->exists();
        } catch (\Throwable $dbEx) {
            return false;
        }
    }
}
