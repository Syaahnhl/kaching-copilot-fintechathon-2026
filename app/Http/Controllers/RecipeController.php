<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Menu;
use App\Models\MenuIngredient;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class RecipeController extends Controller
{
    /**
     * Display recipe/BOM for a menu.
     *
     * @param  \App\Models\Menu|int|string  $menu
     * @return \Illuminate\View\View|\Illuminate\Http\JsonResponse
     */
    public function index($menu)
    {
        $menuId = $menu instanceof Menu ? $menu->id : $menu;
        $menuModel = Menu::where('user_id', Auth::id())->findOrFail($menuId);

        $ingredients = MenuIngredient::where('menu_id', $menuModel->id)
            ->with('item')
            ->get();

        $userOutletIds = Outlet::where('user_id', Auth::id())->pluck('id');
        $itemsQuery = Item::whereIn('outlet_id', $userOutletIds);

        if ($menuModel->outlet_id > 0) {
            $itemsQuery->where('outlet_id', $menuModel->outlet_id);
        }

        $availableItems = $itemsQuery->where(function ($q) {
            $q->where('is_active', true)->orWhereNull('is_active');
        })->orderBy('name')->get();

        $totalRecipeCost = $ingredients->sum(function ($ing) {
            $cost = $ing->item ? (float) $ing->item->cost_price : 0.0;
            return (float) ($ing->qty * $cost);
        });

        if (request()->wantsJson()) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'menu' => $menuModel,
                    'ingredients' => $ingredients,
                    'total_recipe_cost' => $totalRecipeCost,
                ]
            ]);
        }

        return view('backoffice.menus.recipe', [
            'menu' => $menuModel,
            'ingredients' => $ingredients,
            'availableItems' => $availableItems,
            'totalRecipeCost' => $totalRecipeCost,
        ]);
    }

    /**
     * Add an ingredient to menu recipe/BOM.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Menu|int|string  $menu
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function store(Request $request, $menu)
    {
        $menuId = $menu instanceof Menu ? $menu->id : $menu;
        $menuModel = Menu::where('user_id', Auth::id())->findOrFail($menuId);

        $validator = Validator::make($request->all(), [
            'item_id' => 'required|integer',
            'qty' => 'required|numeric|gt:0',
        ], [
            'item_id.required' => 'Bahan baku wajib dipilih.',
            'item_id.integer' => 'Bahan baku tidak valid.',
            'qty.required' => 'Jumlah/takaran bahan baku wajib diisi.',
            'qty.numeric' => 'Jumlah/takaran harus berupa angka.',
            'qty.gt' => 'Jumlah/takaran harus lebih besar dari 0.',
        ]);

        if ($validator->fails()) {
            if ($request->wantsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }
            return back()->withErrors($validator)->withInput();
        }

        $userOutletIds = Outlet::where('user_id', Auth::id())->pluck('id');
        $item = Item::whereIn('outlet_id', $userOutletIds)->find($request->item_id);

        if (!$item) {
            if ($request->wantsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bahan baku tidak ditemukan atau tidak memiliki akses.'
                ], 404);
            }
            abort(404, 'Bahan baku tidak ditemukan.');
        }

        // Outlet alignment check: foreign outlet item is blocked
        if ($menuModel->outlet_id > 0 && $item->outlet_id > 0 && (int) $menuModel->outlet_id !== (int) $item->outlet_id) {
            if ($request->wantsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bahan baku berasal dari outlet berbeda dengan menu.',
                    'errors' => ['item_id' => ['Bahan baku berasal dari outlet berbeda dengan menu.']]
                ], 422);
            }
            return back()->withErrors(['item_id' => 'Bahan baku berasal dari outlet berbeda dengan menu.'])->withInput();
        }

        // Reject duplicate ingredient in the same menu
        $exists = MenuIngredient::where('menu_id', $menuModel->id)
            ->where('item_id', $item->id)
            ->exists();

        if ($exists) {
            if ($request->wantsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bahan baku sudah terdaftar dalam resep menu ini.',
                    'errors' => ['item_id' => ['Bahan baku sudah terdaftar dalam resep menu ini.']]
                ], 422);
            }
            return back()->withErrors(['item_id' => 'Bahan baku sudah terdaftar dalam resep menu ini.'])->withInput();
        }

        $ingredient = new MenuIngredient();
        $ingredient->menu_id = $menuModel->id;
        $ingredient->item_id = $item->id;
        $ingredient->qty = $request->qty;
        if (Schema::hasColumn('menu_ingredients', 'outlet_id')) {
            $ingredient->outlet_id = $menuModel->outlet_id ?: $item->outlet_id;
        }
        $ingredient->save();

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Bahan baku berhasil ditambahkan ke resep.',
                'data' => $ingredient->load('item')
            ], 201);
        }

        return redirect()->route('menus.recipe.index', $menuModel->id)
            ->with('success', 'Bahan baku berhasil ditambahkan ke resep.');
    }

    /**
     * Update quantity of an ingredient in menu recipe/BOM.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Menu|int|string  $menu
     * @param  \App\Models\MenuIngredient|int|string  $ingredient
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $menu, $ingredient)
    {
        $menuId = $menu instanceof Menu ? $menu->id : $menu;
        $menuModel = Menu::where('user_id', Auth::id())->findOrFail($menuId);

        $ingredientId = $ingredient instanceof MenuIngredient ? $ingredient->id : $ingredient;
        $menuIngredient = MenuIngredient::where('id', $ingredientId)
            ->where('menu_id', $menuModel->id)
            ->first();

        if (!$menuIngredient) {
            if ($request->wantsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bahan baku tidak ditemukan dalam resep menu ini.'
                ], 404);
            }
            abort(404, 'Bahan baku tidak ditemukan dalam resep menu ini.');
        }

        $validator = Validator::make($request->all(), [
            'qty' => 'required|numeric|gt:0',
        ], [
            'qty.required' => 'Jumlah/takaran bahan baku wajib diisi.',
            'qty.numeric' => 'Jumlah/takaran harus berupa angka.',
            'qty.gt' => 'Jumlah/takaran harus lebih besar dari 0.',
        ]);

        if ($validator->fails()) {
            if ($request->wantsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }
            return back()->withErrors($validator)->withInput();
        }

        $menuIngredient->qty = $request->qty;
        $menuIngredient->save();

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Takaran bahan baku berhasil diperbarui.',
                'data' => $menuIngredient->load('item')
            ], 200);
        }

        return redirect()->route('menus.recipe.index', $menuModel->id)
            ->with('success', 'Takaran bahan baku berhasil diperbarui.');
    }

    /**
     * Remove an ingredient from menu recipe/BOM.
     *
     * @param  \App\Models\Menu|int|string  $menu
     * @param  \App\Models\MenuIngredient|int|string  $ingredient
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function destroy($menu, $ingredient)
    {
        $menuId = $menu instanceof Menu ? $menu->id : $menu;
        $menuModel = Menu::where('user_id', Auth::id())->findOrFail($menuId);

        $ingredientId = $ingredient instanceof MenuIngredient ? $ingredient->id : $ingredient;
        $menuIngredient = MenuIngredient::where('id', $ingredientId)
            ->where('menu_id', $menuModel->id)
            ->first();

        if (!$menuIngredient) {
            if (request()->wantsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bahan baku tidak ditemukan dalam resep menu ini.'
                ], 404);
            }
            abort(404, 'Bahan baku tidak ditemukan dalam resep menu ini.');
        }

        $menuIngredient->delete();

        if (request()->wantsJson()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Bahan baku berhasil dihapus dari resep.'
            ], 200);
        }

        return redirect()->route('menus.recipe.index', $menuModel->id)
            ->with('success', 'Bahan baku berhasil dihapus dari resep.');
    }
}
