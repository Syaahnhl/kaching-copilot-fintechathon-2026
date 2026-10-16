<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class InventoryController extends Controller
{
    public function index()
    {
        return view('backoffice.inventory.index');
    }

    public function data()
    {
        $onHandSub = DB::table('stock_movements')
            ->select('item_id', DB::raw("SUM(CASE WHEN type = 'incoming' THEN qty WHEN type = 'outgoing' THEN -qty ELSE qty END) as on_hand"))
            ->groupBy('item_id');

        $outletIds = DB::table('outlets')->where('user_id', Auth::id())->pluck('id');
        $items = Item::leftJoinSub($onHandSub, 'oh', 'items.id', '=', 'oh.item_id')
            ->whereIn('items.outlet_id', $outletIds)
            ->select('items.*', DB::raw('COALESCE(oh.on_hand, 0) as on_hand'))
            ->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'sku' => 'nullable|unique:items,sku',
            'name' => 'required|string',
            'type' => 'required|in:raw_material,finished_good,asset',
            'base_uom' => 'required|string',
            'cost_price' => 'nullable|numeric',
            'sales_price' => 'nullable|numeric',
            'reorder_point' => 'nullable|numeric'
        ]);

        $data = $request->only(['sku', 'name', 'type', 'base_uom', 'cost_price', 'sales_price', 'reorder_point']);

        // [HERMES-2026-06-18] Fix: items table has outlet_id, not user_id
        $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));
        if (!$requestedOutlet || $requestedOutlet == 0) {
            $defaultOutlet = DB::table('outlets')->where('user_id', Auth::id())->orderBy('id', 'asc')->first();
            $requestedOutlet = $defaultOutlet ? $defaultOutlet->id : 0;
        }
        $data['outlet_id'] = $requestedOutlet;
        $data['is_active'] = true;

        Item::forceCreate($data);
        return redirect()->back()->with('success', 'Item berhasil ditambahkan');
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'sku' => 'nullable|string|max:255|unique:items,sku,' . $id,
            'name' => 'required|string|max:255',
            'type' => 'required|in:raw_material,finished_good,asset',
            'base_uom' => 'required|string|max:50',
            'cost_price' => 'nullable|numeric|min:0',
            'sales_price' => 'nullable|numeric|min:0',
            'reorder_point' => 'nullable|numeric|min:0',
        ]);

        $outletIds = DB::table('outlets')->where('user_id', Auth::id())->pluck('id');
        $item = Item::whereIn('outlet_id', $outletIds)->findOrFail($id);

        $data = $request->only(['sku', 'name', 'type', 'base_uom', 'cost_price', 'sales_price', 'reorder_point']);

        $item->update($data);

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Item berhasil diperbarui', 'data' => $item]);
        }

        return redirect()->back()->with('success', 'Item berhasil diperbarui');
    }

    public function destroy($id)
    {
        // [HERMES-2026-06-18] Fix: items table has outlet_id, not user_id
        $outletIds = DB::table('outlets')->where('user_id', Auth::id())->pluck('id');
        $item = Item::whereIn('outlet_id', $outletIds)->findOrFail($id);
        $item->delete();
        return redirect()->back()->with('success', 'Item berhasil dihapus');
    }
}
