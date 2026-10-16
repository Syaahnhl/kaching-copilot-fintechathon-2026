<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\StockMovement;
use App\Models\ItemBatch;
use App\Models\Supplier;
use App\Services\Supplier\SupplierRestockMessageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ProcurementController extends Controller
{
    public function __construct(
        protected ?SupplierRestockMessageService $restockMessageService = null
    ) {
        $this->restockMessageService = $restockMessageService ?? app(SupplierRestockMessageService::class);
    }

    public function index(Request $request)
    {
        $userId = Auth::id();
        $outlets = Outlet::where('user_id', $userId)->orderBy('name')->get();
        $activeOutletId = $request->get('outlet_id') ?: session('active_outlet_id', optional($outlets->first())->id);

        $suppliers = Supplier::where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('backoffice.inventory.procurement', compact('suppliers', 'outlets', 'activeOutletId'));
    }

    /**
     * Mengambil rekomendasi restock bahan baku kritis milik tenant dengan relasi supplier aktif.
     */
    public function getRestockRecommendations(Request $request)
    {
        $userId = Auth::id();
        $outletIds = Outlet::where('user_id', $userId)->pluck('id');

        $selectedOutletId = $request->get('outlet_id');
        if ($selectedOutletId && $outletIds->contains($selectedOutletId)) {
            $effectiveOutletIds = [(int) $selectedOutletId];
        } else {
            $effectiveOutletIds = $outletIds->toArray();
        }

        // Subquery kalkulasi stok on-hand dari stock_movements
        $onHandSub = DB::table('stock_movements')
            ->select('item_id', DB::raw("SUM(CASE WHEN type = 'incoming' THEN qty WHEN type = 'outgoing' THEN -qty ELSE qty END) as on_hand"))
            ->groupBy('item_id');

        $query = Item::query()
            ->whereNull('items.deleted_at')
            ->whereIn('items.outlet_id', $effectiveOutletIds)
            ->leftJoinSub($onHandSub, 'oh', 'items.id', '=', 'oh.item_id')
            ->select('items.*', DB::raw('COALESCE(oh.on_hand, 0) as current_stock'));

        // Filter bahan yang berada di bawah atau sama dengan reorder point
        $items = $query->with(['suppliers' => function ($q) use ($userId) {
            $q->where('suppliers.user_id', $userId)
              ->where('suppliers.is_active', true)
              ->orderBy('item_supplier.lead_time_days', 'asc');
        }])
        ->orderByRaw('COALESCE(oh.on_hand, 0) - items.reorder_point ASC')
        ->get()
        ->map(function ($item) {
            $currentStock = (float) $item->current_stock;
            $reorderPoint = (float) $item->reorder_point;

            // Rekomendasi kuantitas restock deterministik
            $deficit = max(0.0, $reorderPoint - $currentStock);
            $suggestedQty = ($deficit > 0) ? $deficit : ($reorderPoint > 0 ? $reorderPoint : 10.0);

            // Severity risk
            if ($currentStock <= 0) {
                $riskLevel = 'CRITICAL';
                $riskLabel = 'Habis (Stockout)';
            } elseif ($currentStock <= $reorderPoint) {
                $riskLevel = 'WARNING';
                $riskLabel = 'Di Bawah Minimum';
            } else {
                $riskLevel = 'NORMAL';
                $riskLabel = 'Aman';
            }

            return [
                'id'                   => (int) $item->id,
                'name'                 => $item->name,
                'sku'                  => $item->sku,
                'base_uom'             => $item->base_uom ?? 'pcs',
                'current_stock'        => $currentStock,
                'reorder_point'        => $reorderPoint,
                'suggested_quantity'   => (float) $suggestedQty,
                'risk_level'           => $riskLevel,
                'risk_label'           => $riskLabel,
                'cost_price'           => (float) ($item->cost_price ?? 0),
                'suppliers'            => $item->suppliers->map(function ($sup) {
                    return [
                        'id'             => (int) $sup->id,
                        'name'           => $sup->name,
                        'contact_person' => $sup->contact_person,
                        'phone'          => $sup->phone,
                        'price'          => (float) ($sup->pivot->supplier_price ?? 0),
                        'lead_time_days' => (int) ($sup->pivot->lead_time_days ?? 0),
                    ];
                }),
            ];
        });

        // Supplier aktif tenant untuk dropdown pemilihan
        $tenantSuppliers = Supplier::where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'contact_person', 'phone']);

        return response()->json([
            'status'    => 'SUCCESS',
            'items'     => $items,
            'suppliers' => $tenantSuppliers,
        ]);
    }

    /**
     * Generate draf teks pesan restock dan deep link WhatsApp untuk supplier dengan STRICT TENANT ISOLATION.
     */
    public function generateRestockMessage(Request $request)
    {
        $request->validate([
            'supplier_id'         => 'required|integer',
            'items'               => 'required|array|min:1',
            'items.*.item_name'   => 'nullable|string',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.unit'        => 'nullable|string',
            'items.*.item_id'     => 'nullable|integer',
        ]);

        $userId = (int) Auth::id();
        $supplierId = (int) $request->input('supplier_id');
        $items = $request->input('items', []);

        $result = $this->restockMessageService->generateForSupplier($userId, $supplierId, $items);

        if (!($result['success'] ?? false)) {
            return response()->json($result, 403);
        }

        return response()->json($result);
    }

    public function data()
    {
        // Step 1: Calculate on_hand per item via raw SQL subquery to avoid leftJoin+with() conflict
        $onHandSub = DB::table('stock_movements')
            ->select('item_id', DB::raw("SUM(CASE WHEN type = 'incoming' THEN qty WHEN type = 'outgoing' THEN -qty ELSE qty END) as on_hand"))
            ->groupBy('item_id');

        // Step 2: Join items with the on_hand subquery, filter those at or below reorder_point
        $outletIds = DB::table('outlets')->where('user_id', Auth::id())->pluck('id');
        $itemIds = DB::table('items')
            ->whereNull('items.deleted_at')
            ->whereIn('items.outlet_id', $outletIds)
            ->leftJoinSub($onHandSub, 'oh', 'items.id', '=', 'oh.item_id')
            ->whereRaw('COALESCE(oh.on_hand, 0) <= items.reorder_point')
            ->pluck('items.id');

        // Step 3: Fetch full Eloquent models with eager-loaded suppliers (sorted by lead time, tenant-scoped)
        $items = Item::with(['suppliers' => function ($q) {
            $q->where('suppliers.user_id', Auth::id())
              ->orderBy('item_supplier.lead_time_days', 'asc');
        }])
            ->whereIn('id', $itemIds)
            ->get()
            ->map(function ($item) {
                // Append on_hand calculated value
                $item->on_hand = DB::table('stock_movements')
                    ->where('item_id', $item->id)
                    ->sum(DB::raw("CASE WHEN type = 'incoming' THEN qty WHEN type = 'outgoing' THEN -qty ELSE qty END"));
                return $item;
            });

        return response()->json(['data' => $items]);
    }

    public function storeIncomingGoods(Request $request)
    {
        $request->validate([
            'item_id' => 'required|exists:items,id,deleted_at,NULL',
            'supplier_id' => 'required|exists:suppliers,id',
            'qty' => 'required|numeric|min:0.01',
            'cost_price' => 'required|numeric|min:0',
            'expired_date' => 'nullable|date',
            'notes' => 'nullable|string'
        ]);

        $supplier = Supplier::where('user_id', Auth::id())->find($request->supplier_id);
        if (!$supplier) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'supplier_id' => ['Supplier tidak ditemukan atau bukan milik tenant Anda.'],
            ]);
        }

        $outletIds = DB::table('outlets')->where('user_id', Auth::id())->pluck('id');
        $item = Item::whereIn('outlet_id', $outletIds)->find($request->item_id);
        if (!$item) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'item_id' => ['Item tidak ditemukan atau sudah dihapus.'],
            ]);
        }

        DB::transaction(function() use ($request, $item, $supplier) {
            // 1. Create Stock Movement (Incoming)
            StockMovement::forceCreate([
                'item_id' => $item->id,
                'type' => 'incoming',
                'qty' => $request->qty,
                'reference_id' => 'GR-' . time() . '-' . rand(100, 999),
                'notes' => 'Dari Supplier: ' . $supplier->name . ' (ID: ' . $supplier->id . '). ' . $request->notes,
            ]);

            // 2. Manage Batches if expired_date is provided
            if ($request->filled('expired_date')) {
                ItemBatch::forceCreate([
                    'item_id' => $item->id,
                    'batch_number' => 'B-' . date('Ymd') . '-' . rand(100, 999),
                    'expired_date' => $request->expired_date,
                    'current_qty' => $request->qty,
                ]);
            }

            // 3. Update Cost Price if it changed
            if ($item->cost_price != $request->cost_price) {
                $item->cost_price = $request->cost_price;
                $item->save();
            }
        });

        return redirect()->back()->with('success', 'Barang masuk berhasil dicatat. Stok dan HPP telah diperbarui.');
    }
}
