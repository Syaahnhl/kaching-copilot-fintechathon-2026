<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Menu;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MenuController extends Controller
{
    private function buildDuplicateKey(int $userId, int $outletId, string $name, string $category, float $price): string
    {
        return implode('|', [
            $userId,
            $outletId,
            strtolower(preg_replace('/\s+/', ' ', trim($name))),
            strtolower(preg_replace('/\s+/', ' ', trim($category))),
            number_format($price, 2, '.', ''),
        ]);
    }

    private function findDuplicateMenu(int $userId, int $outletId, string $name, string $category, float $price, ?int $ignoreId = null): ?Menu
    {
        $duplicateKey = $this->buildDuplicateKey($userId, $outletId, $name, $category, $price);

        $query = Menu::withTrashed() // Include soft-deleted rows to prevent duplicate key constraint crashes on restore/recreate
            ->where('user_id', $userId)
            ->where('outlet_id', $outletId)
            ->where(function ($q) use ($duplicateKey, $name, $category, $price) {
                $q->where('duplicate_key', $duplicateKey)
                    ->orWhere(function ($legacy) use ($name, $category, $price) {
                        $legacy->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($name))])
                            ->whereRaw('LOWER(TRIM(category)) = ?', [strtolower(trim($category))])
                            ->where('price', $price);
                    });
            });

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->orderBy('id', 'asc')->first();
    }

    // 1. TAMPILKAN SEMUA MENU (FIXED)
    public function index(Request $request)
    {
        $userId = Auth::id();

        if (!$userId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized. Token tidak valid.'], 401);
        }

        // Hanya ambil menu milik user_id yang bersangkutan
        $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));

        // [HERMES-2026-08-24] Delta Sync: Support soft-deleted menus for offline synchronization
        $query = ($request->has('include_deleted') || $request->is('api/*')) 
            ? Menu::withTrashed()->where('user_id', $userId) 
            : Menu::where('user_id', $userId);

        if ($requestedOutlet > 0) {
            $query->where('outlet_id', $requestedOutlet);
        }

        // [HERMES-2026-06-18] Server-side category filter
        $validCategories = ['Makanan', 'Minuman', 'Camilan', 'Lainnya'];
        $category = $request->input('category');
        if ($category && in_array($category, $validCategories)) {
            $query->where('category', $category);
        }

        // [HERMES-2026-06-18] Search bar - by name, label_title
        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('label_title', 'like', '%' . $search . '%')
                  ->orWhere('label', 'like', '%' . $search . '%');
            });
        }

        // [HERMES-2026-06-18] Stock status filter
        $stockFilter = $request->input('stock_status');
        if ($stockFilter === 'habis') {
            $query->where('stock', 0);
        } elseif ($stockFilter === 'low') {
            $query->where('stock', '>0')->where('stock', '<=15');
        } elseif ($stockFilter === 'safe') {
            $query->where('stock', '>15');
        } elseif ($stockFilter === 'unlimited') {
            $query->where('stock', -1);
        }

        // [HERMES-2026-06-18] Available filter
        if ($request->input('available') === 'tersedia') {
            $query->where('is_available', true);
        } elseif ($request->input('available') === 'tidak') {
            $query->where('is_available', false);
        }

        // [HERMES-2026-06-18] Sort
        $sort = $request->input('sort', 'category');
        $dir = strtolower($request->input('dir', 'asc')) === 'asc' ? 'asc' : 'desc';
        $validSorts = ['name', 'price', 'stock', 'category', 'created_at', 'cost_price'];
        if (!in_array($sort, $validSorts)) {
            $sort = 'category';
        }
        if ($sort === 'category') {
            $query->orderBy('category', 'asc')->orderBy('name', 'asc');
        } else {
            $query->orderBy($sort, $dir);
        }

        // [HERMES-2026-07-23] Android POS sync expects FLAT list in data[], not Laravel paginator object.
        // Client calls GET /api/menus?per_page=all — cast (int)"all" becomes 0 and previously
        // forced back to 15, while response body was paginator JSON → Gson parse fail → empty Room.
        $rawPerPage = $request->input('per_page', 15);
        $wantsAll = is_string($rawPerPage) && strtolower(trim($rawPerPage)) === 'all';

        $transformMenu = function ($menu) {
            if ($menu->image_url && !str_starts_with($menu->image_url, 'http')) {
                $menu->image_url = url('storage/' . $menu->image_url);
            }
            return $menu;
        };

        if ($request->wantsJson() || $request->is('api/*')) {
            if ($wantsAll) {
                $menus = $query->get()->map($transformMenu)->values();
            } else {
                $perPage = (int) $rawPerPage;
                if ($perPage < 5 || $perPage > 500) {
                    $perPage = 100; // safer default for mobile sync than 15
                }
                $menus = $query->limit($perPage)->get()->map($transformMenu)->values();
            }

            return response()->json(['status' => 'success', 'data' => $menus], 200);
        }

        // Web backoffice keeps paginated LengthAwarePaginator
        $perPage = (int) $rawPerPage;
        if ($perPage < 5 || $perPage > 100) {
            $perPage = 15;
        }
        $menus = $query->paginate($perPage)->withQueryString();
        $menus->getCollection()->transform($transformMenu);

        return view('backoffice.menus', compact('menus'));
    }

    // [HERMES-2026-06-18] Toggle available per menu
    public function toggleAvailable(Request $request, $id)
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 401);
        }

        $menu = Menu::where('id', $id)->where('user_id', $userId)->first();
        if (!$menu) {
            return response()->json(['status' => 'error', 'message' => 'Menu tidak ditemukan'], 404);
        }

        $menu->is_available = !$menu->is_available;
        $menu->save();

        return response()->json([
            'status' => 'success',
            'message' => $menu->is_available ? 'Menu diaktifkan' : 'Menu dinonaktifkan',
            'data' => ['id' => $menu->id, 'is_available' => $menu->is_available]
        ], 200);
    }

    // 2. SIMPAN MENU BARU (FIXED)
    public function store(Request $request)
    {
        $userId = Auth::id();

        if (!$userId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'category' => 'required|string',
            'unit' => 'required|string',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'required|numeric|min:0',
            'stock' => 'required|numeric|min:-1',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            // [BARU] Tambahkan validasi untuk label
            'label' => 'nullable|string',
            'label_title' => 'nullable|string|max:100',
            'price_gofood' => 'nullable|integer|min:0',
            'price_grabfood' => 'nullable|integer|min:0',
            'price_shopeefood' => 'nullable|integer|min:0'
        ], [
            'name.required' => 'Nama menu wajib diisi.',
            'category.required' => 'Kategori menu wajib dipilih.',
            'unit.required' => 'Satuan (Unit) wajib diisi.',
            'price.required' => 'Harga jual wajib diisi.',
            'price.numeric' => 'Harga jual harus berupa angka.',
            'cost_price.required' => 'Harga modal (COGS) wajib diisi.',
            'stock.required' => 'Stok awal wajib diisi.',
            'image.image' => 'File harus berupa gambar.',
            'image.max' => 'Ukuran gambar maksimal adalah 2MB.'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('menus', 'public');
        }

        $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));
        if (!$requestedOutlet || $requestedOutlet == 0) {
            $defaultOutlet = DB::table('outlets')->where('user_id', $userId)->orderBy('id', 'asc')->first();
            $requestedOutlet = $defaultOutlet ? $defaultOutlet->id : 0;
        }

        $name = trim((string)$request->name);
        $category = trim((string)$request->category);
        $price = (float)$request->price;

        $duplicateKey = $this->buildDuplicateKey($userId, (int)$requestedOutlet, $name, $category, $price);
        $existingMenu = $this->findDuplicateMenu($userId, (int)$requestedOutlet, $name, $category, $price);
        if ($existingMenu) {
            // Restore if it was soft-deleted to reuse the same slot
            if ($existingMenu->trashed()) {
                $existingMenu->restore();
                $existingMenu->update([
                    'cost_price' => $request->cost_price,
                    'stock' => $request->stock >= 9999999 ? -1 : $request->stock,
                    'has_variant' => filter_var($request->has_variant, FILTER_VALIDATE_BOOLEAN),
                    'unit' => $request->unit,
                    'is_kds' => filter_var($request->input('is_kds', true), FILTER_VALIDATE_BOOLEAN),
                    'description' => $request->description,
                    'is_available' => true,
                    'label' => $request->label,
                    'label_title' => $request->label_title,
                    'price_gofood' => (int)($request->input('price_gofood') ?: 0),
                    'price_grabfood' => (int)($request->input('price_grabfood') ?: 0),
                    'price_shopeefood' => (int)($request->input('price_shopeefood') ?: 0),
                    'variant_config' => $request->input('variant_config'),
                ]);
            }

            if ($request->wantsJson()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Menu sudah ada, memakai data yang tersedia.',
                    'data' => $existingMenu,
                    'duplicate_prevented' => true,
                ], 200);
            }

            return redirect()->route('menus.index')->with('info', 'Menu sudah ada, tidak dibuat duplikat.');
        }

        $menu = Menu::forceCreate([
            'user_id' => $userId,
            'outlet_id' => $requestedOutlet,
            'name' => $name,
            'category' => $category,
            'price' => $request->price,
            'cost_price' => $request->cost_price,
            'stock' => $request->stock >= 9999999 ? -1 : $request->stock,
            'has_variant' => filter_var($request->has_variant, FILTER_VALIDATE_BOOLEAN),
            'unit' => $request->unit,
            'is_kds' => filter_var($request->input('is_kds', true), FILTER_VALIDATE_BOOLEAN),
            'description' => $request->description,
            'image_url' => $imagePath,
            'is_available' => true,
            // [BARU] Simpan Label dan Judul Kustom
            'label' => $request->label,
            'label_title' => $request->label_title,
            'price_gofood' => (int)($request->input('price_gofood') ?: 0),
            'price_grabfood' => (int)($request->input('price_grabfood') ?: 0),
            'price_shopeefood' => (int)($request->input('price_shopeefood') ?: 0),
            'duplicate_key' => $duplicateKey,
            'variant_config' => $request->input('variant_config'),
        ]);

        if ($menu->image_url) {
            $menu->image_url = asset('storage/' . $menu->image_url);
        }

        if ($request->wantsJson()) {
            return response()->json(['status' => 'success', 'message' => 'Menu berhasil ditambahkan', 'data' => $menu], 201);
        }

        return redirect()->route('menus.index')->with('success', 'Menu baru berhasil ditambahkan!');
    }

    // 3. UPDATE DETAIL MENU (FIXED)
    public function update(Request $request, $id)
    {
        $userId = Auth::id();

        if (!$userId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 401);
        }

        $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));
        $query = Menu::where('id', $id)->where('user_id', $userId);

        if ($requestedOutlet > 0) {
            $query->where('outlet_id', $requestedOutlet);
        }

        $menu = $query->first();

        if (!$menu) {
            return response()->json(['status' => 'error', 'message' => 'Menu tidak ditemukan atau bukan milik Anda.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'category' => 'required|string',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'required|numeric|min:0',
            'stock' => 'required|numeric|min:-1',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048', // 🛡️ PROTEKSI WEB SHELL
            // [BARU] Tambahkan validasi untuk label agar bisa diedit
            'label' => 'nullable|string',
            'label_title' => 'nullable|string|max:100',
            'price_gofood' => 'nullable|integer|min:0',
            'price_grabfood' => 'nullable|integer|min:0',
            'price_shopeefood' => 'nullable|integer|min:0'
        ], [
            'name.required' => 'Nama menu wajib diisi.',
            'category.required' => 'Kategori menu wajib dipilih.',
            'price.required' => 'Harga jual wajib diisi.',
            'price.numeric' => 'Harga jual harus berupa angka.',
            'cost_price.required' => 'Harga modal wajib diisi.',
            'stock.required' => 'Stok wajib diisi.'
        ]);

        if ($validator->fails()) {
            if ($request->wantsJson()) {
                return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
            }
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $currentPath = $menu->getRawOriginal('image_url');
        if ($request->hasFile('image')) {
            if ($currentPath && Storage::disk('public')->exists($currentPath)) {
                Storage::disk('public')->delete($currentPath);
            }
            $currentPath = $request->file('image')->store('menus', 'public');
        }

        $name = trim((string)$request->name);
        $category = trim((string)$request->category);
        $price = (float)$request->price;
        $duplicateKey = $this->buildDuplicateKey($userId, (int)($menu->outlet_id ?? 0), $name, $category, $price);

        $existingMenu = $this->findDuplicateMenu($userId, (int)($menu->outlet_id ?? 0), $name, $category, $price, (int)$menu->id);
        if ($existingMenu) {
            if ($request->wantsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Menu dengan nama, kategori, dan harga yang sama sudah ada.',
                    'duplicate_menu_id' => $existingMenu->id,
                ], 409);
            }

            return redirect()->back()->withErrors(['name' => 'Menu dengan nama, kategori, dan harga yang sama sudah ada.'])->withInput();
        }

        $updateData = [
            'name' => $name,
            'category' => $category,
            'price' => $request->price,
            'cost_price' => $request->cost_price,
            'unit' => $request->unit,
            'has_variant' => filter_var($request->input('has_variant'), FILTER_VALIDATE_BOOLEAN),
            'is_kds' => filter_var($request->input('is_kds'), FILTER_VALIDATE_BOOLEAN),
            'description' => $request->description,
            'image_url' => $currentPath,
            'price_gofood' => (int)($request->input('price_gofood') ?: 0),
            'price_grabfood' => (int)($request->input('price_grabfood') ?: 0),
            'price_shopeefood' => (int)($request->input('price_shopeefood') ?: 0),
            'duplicate_key' => $duplicateKey,
            'variant_config' => $request->input('variant_config'),
        ];

        // 🛡️ [PROTEKSI STOK OVERWRITE] Jangan biarkan API client Android (via API route)
        // menimpa stok server dengan cache lokal mereka saat sync menu detail.
        // Update stok dari POS hanya diizinkan via endpoint khusus /stock.
        if (!$request->is('api/*') && !$request->wantsJson()) {
            $updateData['stock'] = $request->stock >= 9999999 ? -1 : $request->stock;
        }

        $menu->update($updateData);

        if ($menu->image_url && !str_starts_with($menu->image_url, 'http')) {
            $menu->image_url = asset('storage/' . $menu->image_url);
        }

        if ($request->wantsJson()) {
            return response()->json(['status' => 'success', 'data' => $menu], 200);
        }

        return redirect()->route('menus.index')->with('success', 'Menu berhasil diperbarui!');
    }

    // 4. HAPUS MENU TOTAL (FIXED)
    public function destroy(Request $request, $id)
    {
        $userId = Auth::id();

        if (!$userId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 401);
        }

        $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));
        $query = Menu::where('id', $id)->where('user_id', $userId);
        if ($requestedOutlet > 0) {
            $query->where('outlet_id', $requestedOutlet);
        }
        $menu = $query->first();

        if (!$menu) {
            if ($request->wantsJson()) {
                return response()->json(['status' => 'error', 'message' => 'Menu tidak ditemukan'], 404);
            }
            return abort(404);
        }

        // Double protection
        $menu->is_available = false;
        $menu->save();

        $menu->delete();

        if ($request->wantsJson()) {
            return response()->json(['status' => 'success', 'message' => 'Menu berhasil dihapus'], 200);
        }

        return redirect()->route('menus.index')->with('success', 'Menu berhasil dihapus!');
    }

    // 5. QUICK UPDATE STOK SAJA (FIXED)
    public function updateStock(Request $request, $id)
    {
        $userId = Auth::id();

        if (!$userId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'stock' => 'required_without:deduct|numeric',
            'deduct' => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()], 422);
        }

        $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));
        $query = Menu::where('id', $id)->where('user_id', $userId);
        if ($requestedOutlet > 0) {
            $query->where('outlet_id', $requestedOutlet);
        }
        $menu = $query->first();

        if (!$menu) {
            return response()->json(['status' => 'error', 'message' => 'Menu tidak ditemukan'], 404);
        }

        // 🛡️ [PHASE 4 CANONICAL STOCK INTEGRITY REFACTOR]
        // Reroute seluruh update stok (delta & absolut) ke App\Services\StockService sebagai single source of truth.
        $stockService = app(\App\Services\StockService::class);
        $outletId = $menu->outlet_id ?: ($requestedOutlet > 0 ? (int)$requestedOutlet : 0);
        if (!$outletId) {
            $firstOutlet = \App\Models\Outlet::where('user_id', $userId)->first();
            $outletId = $firstOutlet ? $firstOutlet->id : 0;
        }

        $txUuid = $request->input('tx_uuid');
        $deviceId = $request->input('device_id');
        $staffId = $request->input('staff_id');

        if ($request->has('deduct')) {
            $deductVal = (float)$request->deduct;
            $res = $stockService->decrement(
                $menu->id,
                $userId,
                $outletId,
                $deductVal,
                $txUuid,
                $staffId,
                $deviceId,
                'Legacy decrement API call'
            );
            $menu->refresh();
            return response()->json([
                'status' => 'success',
                'code' => $res['code'],
                'message' => $res['message'],
                'data' => [
                    'id' => $menu->id,
                    'new_stock' => (float)$menu->stock,
                    'version' => (int)$menu->version,
                ]
            ], 200);
        }

        // Fallback: update stok absolut (untuk kebutuhan edit manual dari dashboard admin / legacy web)
        $validator = Validator::make($request->all(), [
            'stock' => 'required|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()], 422);
        }

        $targetStock = (float)$request->stock;
        $res = $stockService->stockOpname(
            $menu->id,
            $userId,
            $outletId,
            $targetStock,
            null,
            $txUuid,
            $staffId,
            $deviceId,
            'Legacy absolute stock update'
        );
        $menu->refresh();

        return response()->json([
            'status' => 'success',
            'code' => $res['code'],
            'message' => 'Stok berhasil diupdate',
            'data' => [
                'id' => $menu->id,
                'new_stock' => (float)$menu->stock,
                'version' => (int)$menu->version,
            ]
        ], 200);
    }

    // 6. HALAMAN PENGELOLA SECTION (BULK)
    public function sectionManager()
    {
        $userId = Auth::id();
        $menus = Menu::where('user_id', $userId)->orderBy('name', 'asc')->get();

        return view('backoffice.section_manager', compact('menus'));
    }

    // 7. PROSES UPDATE MASSAL LABEL
    public function updateSections(Request $request)
    {
        $userId = Auth::id();

        // Validasi Payload JSON
        if (!$request->has('sections') || empty($request->sections)) {
            if ($request->wantsJson()) {
                return response()->json(['status' => 'error', 'message' => 'Data section tidak valid atau kosong.'], 400);
            }
            return redirect()->back()->withErrors(['msg' => 'Data section tidak valid.']);
        }

        $sections = json_decode($request->sections, true);
        if ($sections === null && json_last_error() !== JSON_ERROR_NONE) {
            if ($request->wantsJson()) {
                return response()->json(['status' => 'error', 'message' => 'Format data section salah.'], 400);
            }
            return redirect()->back()->withErrors(['msg' => 'Format data tidak valid.']);
        }

        try {
            \DB::beginTransaction();

            // 1. Reset semua label menu milik user menjadi null
            Menu::where('user_id', $userId)->update([
                'label' => null,
                'label_title' => null,
                'label_emoji' => null,
                'label_color' => null
            ]);

            // 2. Looping JSON payload -> Update label
            if (is_array($sections)) {
                foreach ($sections as $index => $section) {
                    if (!empty($section['menu_ids']) && is_array($section['menu_ids'])) {
                        $labelKey = 'section_' . $index; // Unique label per section

                        Menu::whereIn('id', $section['menu_ids'])->where('user_id', $userId)
                            ->update([
                                'label' => $labelKey,
                                'label_title' => $section['title'] ?: 'Section ' . ($index + 1),
                                'label_emoji' => $section['emoji'] ?: '🌟',
                                'label_color' => $section['color'] ?: '#10b981'
                            ]);
                    }
                }
            }

            \DB::commit();

            if ($request->wantsJson()) {
                return response()->json(['status' => 'success', 'message' => 'Tampilan Menu Utama Berhasil Diperbarui!']);
            }
            return redirect()->back()->with('success', 'Tampilan Menu Utama Berhasil Diperbarui!');
        } catch (\Exception $e) {
            \DB::rollBack();
            if ($request->wantsJson()) {
                return response()->json(['status' => 'error', 'message' => 'Gagal memperbarui: ' . $e->getMessage()], 500);
            }
            return redirect()->back()->withErrors(['msg' => 'Gagal memperbarui: ' . $e->getMessage()]);
        }
    }

    public function create()
    {
        return view('backoffice.create');
    }

    public function edit($id)
    {
        $userId = Auth::id();

        // 1. Ambil 1 menu spesifik yang mau diedit
        $menu = Menu::where('id', $id)->where('user_id', $userId)->firstOrFail();

        // 2. [HERMES-2026-06-18] Ambil menu list (paginated, like index)
        $menus = Menu::where('user_id', $userId)
            ->orderBy('category', 'asc')
            ->orderBy('name', 'asc')
            ->paginate(15)
            ->withQueryString();

        $menus->getCollection()->transform(function ($m) {
            if ($m->image_url && !str_starts_with($m->image_url, 'http')) {
                $m->image_url = url('storage/' . $m->image_url);
            }
            return $m;
        });

        // 3. Bawa kedua datanya ke view
        return view('backoffice.menus', compact('menu', 'menus'));
    }
}
