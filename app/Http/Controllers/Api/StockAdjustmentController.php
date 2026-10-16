<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StockAdjustmentController extends Controller
{
    protected StockService $stockService;

    public function __construct(StockService $stockService)
    {
        $this->stockService = $stockService;
    }

    /**
     * Canonical Endpoint: POST /api/stock/adjust
     */
    public function adjust(Request $request)
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 401);
        }

        // 🛡️ Normalisasi type untuk interoperabilitas client (misal: MANUAL_INCREMENT -> increment)
        if ($request->has('type')) {
            $rawType = strtoupper((string)$request->input('type'));
            $normalizedType = match ($rawType) {
                'MANUAL_INCREMENT' => 'increment',
                'MANUAL_DECREMENT' => 'decrement',
                'MANUAL_SET' => 'adjustment',
                'SALE' => 'sale',
                'REFUND' => 'refund',
                'VOID' => 'void',
                'OPNAME' => 'opname',
                default => strtolower((string)$request->input('type')),
            };
            $request->merge(['type' => $normalizedType]);
        }

        $validator = Validator::make($request->all(), [
            'tx_uuid' => 'required|string|max:64',
            'menu_id' => 'required|integer',
            'quantity_delta' => 'required|numeric',
            'type' => 'required|string|in:increment,decrement,sale,refund,void,adjustment,opname',
            'outlet_id' => 'nullable|integer',
            'device_id' => 'nullable|string|max:100',
            'staff_id' => 'nullable|integer',
            'reference_type' => 'nullable|string|max:50',
            'reference_id' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'expected_version' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $outletId = (int)$request->header('Outlet-ID', $request->input('outlet_id'));
        if (!$outletId) {
            $menuRecord = Menu::where('id', (int)$request->input('menu_id'))
                ->where('user_id', $userId)
                ->first();
            if ($menuRecord && $menuRecord->outlet_id > 0) {
                $outletId = (int)$menuRecord->outlet_id;
            } else {
                $firstOutlet = \App\Models\Outlet::where('user_id', $userId)->first();
                $outletId = $firstOutlet ? $firstOutlet->id : 0;
            }
        }

        $menuId = (int)$request->input('menu_id');
        $txUuid = (string)$request->input('tx_uuid');
        $quantityDelta = (float)$request->input('quantity_delta');
        $type = (string)$request->input('type');
        $deviceId = $request->input('device_id');
        $staffId = $request->input('staff_id');
        $refType = $request->input('reference_type');
        $refId = $request->input('reference_id');
        $notes = $request->input('notes');

        // Pastikan menu ada dan merupakan milik user/outlet tersebut
        $menu = Menu::where('id', $menuId)
            ->where('user_id', $userId)
            ->when($outletId > 0, function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId);
            })
            ->first();

        if (!$menu) {
            return response()->json([
                'status' => 'error',
                'message' => "Menu ID {$menuId} not found or unauthorized for Outlet {$outletId}.",
            ], 404);
        }

        try {
            if ($type === StockService::TYPE_OPNAME) {
                $physicalStock = (float)$request->input('physical_stock', $quantityDelta);
                $expectedVersion = $request->has('expected_version') ? (int)$request->input('expected_version') : null;
                $result = $this->stockService->stockOpname(
                    $menuId,
                    $userId,
                    $menu->outlet_id ?: $outletId,
                    $physicalStock,
                    $expectedVersion,
                    $txUuid,
                    $staffId,
                    $deviceId,
                    $notes
                );
            } else {
                $result = $this->stockService->mutateDelta(
                    $menuId,
                    $userId,
                    $menu->outlet_id ?: $outletId,
                    $quantityDelta,
                    $type,
                    $txUuid,
                    $staffId,
                    $deviceId,
                    $refType,
                    $refId,
                    $notes
                );
            }

            return response()->json($result, 200);
        } catch (HttpException $e) {
            return response()->json([
                'status' => 'error',
                'code' => StockService::CODE_CONFLICT,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Operation Lookup Endpoint: GET /api/stock/operations/{tx_uuid}
     * Digunakan oleh Android untuk verifikasi status mutasi saat terjadi network timeout / disconnect.
     */
    public function getOperationStatus(Request $request, string $txUuid)
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 401);
        }

        $movement = \App\Models\StockMovement::where('tx_uuid', $txUuid)
            ->where('user_id', $userId)
            ->first();

        if (!$movement) {
            return response()->json([
                'status' => 'not_found',
                'code' => 'NOT_FOUND',
                'message' => "Stock mutation operation with tx_uuid {$txUuid} not found or not committed.",
                'data' => null
            ], 404);
        }

        $menu = Menu::where('id', $movement->menu_id)->first();

        return response()->json([
            'status' => 'success',
            'code' => 'COMMITTED',
            'message' => 'Operation was successfully committed.',
            'data' => [
                'tx_uuid' => $txUuid,
                'menu_id' => $movement->menu_id,
                'outlet_id' => $movement->outlet_id,
                'type' => $movement->type,
                'quantity_delta' => (float)$movement->quantity_delta,
                'balance_before' => (float)$movement->balance_before,
                'balance_after' => (float)$movement->balance_after,
                'current_stock' => $menu ? (float)$menu->stock : (float)$movement->balance_after,
                'version' => $menu ? (int)$menu->version : 1,
                'created_at' => $movement->created_at ? $movement->created_at->toIso8601String() : null,
            ]
        ], 200);
    }
}
