<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\Item;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class StockOpnameController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = Auth::id();

        // Stats for cards - scoped to authenticated tenant
        $baseQuery = StockOpname::where('user_id', $tenantId);
        $totalAll = (clone $baseQuery)->count();
        $totalDraft = (clone $baseQuery)->where('status', 'draft')->count();
        $totalApproved = (clone $baseQuery)->where('status', 'approved')->count();
        $totalRejected = (clone $baseQuery)->where('status', 'rejected')->count();

        // Total adjustment value (sum of abs discrepancies for approved opnames of this tenant)
        $totalAdjustment = DB::table('stock_opname_items')
            ->join('stock_opnames', 'stock_opname_items.stock_opname_id', '=', 'stock_opnames.id')
            ->where('stock_opnames.user_id', $tenantId)
            ->where('stock_opnames.status', 'approved')
            ->sum(DB::raw('ABS(stock_opname_items.discrepancy_qty)'));

        $stats = [
            'total' => $totalAll,
            'draft' => $totalDraft,
            'approved' => $totalApproved,
            'rejected' => $totalRejected,
            'total_adjustment' => (float) $totalAdjustment,
        ];

        // Search by notes / pic_name - scoped to authenticated tenant
        $query = StockOpname::where('user_id', $tenantId)->withCount('items');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('notes', 'like', '%' . $search . '%')
                  ->orWhere('pic_name', 'like', '%' . $search . '%');
            });
        }

        // Status filter
        $statusFilter = $request->input('status');
        if (in_array($statusFilter, ['draft', 'approved', 'rejected'])) {
            $query->where('status', $statusFilter);
        }

        // Date range filter
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        if ($dateFrom) {
            $query->whereDate('date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('date', '<=', $dateTo);
        }

        $opnames = $query->orderBy('date', 'desc')
            ->paginate(15)
            ->withQueryString();

        return view('backoffice.inventory.opname', compact('opnames', 'stats'));
    }

    public function create()
    {
        $tenantId = Auth::id();
        $outletIds = DB::table('outlets')->where('user_id', $tenantId)->pluck('id');

        $onHandSub = DB::table('stock_movements')
            ->select('item_id', DB::raw("SUM(CASE WHEN type = 'incoming' THEN qty WHEN type = 'outgoing' THEN -qty ELSE qty END) as on_hand"))
            ->groupBy('item_id');

        $items = Item::leftJoinSub($onHandSub, 'oh', 'items.id', '=', 'oh.item_id')
            ->whereIn('items.outlet_id', $outletIds)
            ->whereNull('items.deleted_at')
            ->select('items.*', DB::raw('COALESCE(oh.on_hand, 0) as on_hand'))
            ->get();

        return view('backoffice.inventory.opname_create', compact('items'));
    }

    public function store(Request $request)
    {
        $tenantId = Auth::id();
        $outletIds = DB::table('outlets')->where('user_id', $tenantId)->pluck('id');

        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'pic_name' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer|exists:items,id',
            'items.*.actual_qty' => 'required|numeric|min:0',
            'items.*.reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        // Validate that all submitted items belong to the authenticated tenant's outlets
        $submittedItemIds = array_column($request->items, 'item_id');
        $validItemCount = Item::whereIn('outlet_id', $outletIds)
            ->whereIn('id', $submittedItemIds)
            ->count();

        if ($validItemCount !== count(array_unique($submittedItemIds))) {
            return redirect()->back()
                ->withErrors(['items' => 'Terdapat bahan baku yang tidak valid atau bukan milik outlet Anda.'])
                ->withInput();
        }

        return DB::transaction(function () use ($request, $tenantId) {
            $opname = StockOpname::create([
                'user_id' => $tenantId,
                'date' => $request->date,
                'pic_name' => $request->pic_name,
                'status' => 'draft',
                'notes' => $request->notes,
            ]);

            foreach ($request->items as $row) {
                $systemQty = DB::table('stock_movements')
                    ->where('item_id', $row['item_id'])
                    ->sum(DB::raw("CASE WHEN type = 'incoming' THEN qty WHEN type = 'outgoing' THEN -qty ELSE qty END"));

                $actualQty = (float) $row['actual_qty'];
                $discrepancy = $actualQty - (float) $systemQty;

                StockOpnameItem::create([
                    'stock_opname_id' => $opname->id,
                    'item_id' => $row['item_id'],
                    'system_qty' => $systemQty,
                    'actual_qty' => $actualQty,
                    'discrepancy_qty' => $discrepancy,
                    'reason' => $row['reason'] ?? null,
                ]);
            }

            return redirect()->route('inventory.opname')->with('success', 'Stock opname draft berhasil dibuat');
        });
    }

    public function show($id)
    {
        $tenantId = Auth::id();
        $opname = StockOpname::where('user_id', $tenantId)->with('items.item')->findOrFail($id);
        return view('backoffice.inventory.opname_show', compact('opname'));
    }

    public function finalize($id)
    {
        $tenantId = Auth::id();
        $opname = StockOpname::where('user_id', $tenantId)->with('items')->findOrFail($id);
        if ($opname->status === 'approved') {
            return redirect()->back()->with('error', 'Stock opname sudah di-approve');
        }

        return DB::transaction(function () use ($opname) {
            foreach ($opname->items as $row) {
                if ((float) $row->discrepancy_qty != 0) {
                    StockMovement::create([
                        'item_id' => $row->item_id,
                        'type' => 'adjustment',
                        'qty' => abs((float) $row->discrepancy_qty),
                        'reference_id' => (string) $opname->id,
                        'notes' => "Stock opname #{$opname->id}: " . ($row->discrepancy_qty > 0 ? "surplus" : "loss") . " ({$row->discrepancy_qty})",
                    ]);
                }
            }
            $opname->update(['status' => 'approved']);
            return redirect()->route('inventory.opname')->with('success', 'Stock opname di-approve. Stok sudah disesuaikan.');
        });
    }

    public function destroy($id)
    {
        $tenantId = Auth::id();
        $opname = StockOpname::where('user_id', $tenantId)->findOrFail($id);
        if ($opname->status === 'approved') {
            return response()->json(['error' => 'Opname yang sudah di-approve tidak bisa dihapus'], 403);
        }
        $opname->items()->delete();
        $opname->delete();
        return response()->json(['message' => 'Stock opname berhasil dihapus']);
    }
}
