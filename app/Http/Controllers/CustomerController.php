<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index()
    {
        return view('backoffice.customers.index');
    }

    public function data(Request $request)
    {
        $query = Customer::where('user_id', Auth::id());
        if ($request->search) {
            $s = '%' . $request->search . '%';
            $query->where(function($q) use ($s) {
                $q->where('name', 'like', $s)->orWhere('phone', 'like', $s)->orWhere('email', 'like', $s);
            });
        }
        $customers = $query->orderBy('name')->limit(200)->get();
        return response()->json(['data' => $customers]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'gender' => 'nullable|in:L,P',
            'birthdate' => 'nullable|date',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();
        $data['user_id'] = Auth::id();
        $data['is_active'] = true;
        $customer = Customer::create($data);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['message' => 'Customer berhasil ditambahkan', 'data' => $customer], 201);
        }
        return redirect()->back()->with('success', 'Customer berhasil ditambahkan');
    }

    public function update(Request $request, $id)
    {
        $customer = Customer::where('user_id', Auth::id())->findOrFail($id);
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'gender' => 'nullable|in:L,P',
            'birthdate' => 'nullable|date',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $customer->update($validator->validated());

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['message' => 'Customer berhasil diupdate']);
        }
        return redirect()->back()->with('success', 'Customer berhasil diupdate');
    }

    public function destroy($id)
    {
        $customer = Customer::where('user_id', Auth::id())->findOrFail($id);
        $customer->delete();
        return response()->json(['message' => 'Customer berhasil dihapus']);
    }

    /**
     * API list customers (CQRS Offline-First Sync)
     * Defensive: customers table has no uuid column — synthesize stable fallback.
     */
    public function apiIndex(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 100), 1), 200);
        $page = max((int) $request->query('page', 1), 1);

        $customers = Customer::where('user_id', Auth::id())
            ->orderBy('name')
            ->paginate($perPage, ['id', 'name', 'phone', 'loyalty_points'], 'page', $page);

        $data = collect($customers->items())->map(function ($c) {
            return [
                'id' => (int) $c->id,
                'name' => (string) ($c->name ?? ''),
                'phone' => (string) ($c->phone ?? ''),
                'points' => (int) ($c->loyalty_points ?? 0),
                // No uuid column in DB — keep Android contract stable
                'uuid' => 'customer_' . $c->id,
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => $data,
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    /**
     * API bulk sync customers (Android Offline-First Sync)
     * Matches Android contract POST /api/customers/bulk-sync
     */
    public function apiBulkSync(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customers' => 'present|array',
            'customers.*.name' => 'required|string|max:255',
            'customers.*.phone' => 'nullable|string|max:20',
            'customers.*.loyalty_points' => 'nullable|integer',
            'customers.*.uuid' => 'nullable|string|max:255',
            'customers.*.join_date' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'committed' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = Auth::id();
        $items = $request->input('customers', []);

        DB::transaction(function () use ($items, $userId) {
            foreach ($items as $item) {
                $name = trim($item['name'] ?? '');
                $phone = !empty($item['phone']) ? trim($item['phone']) : null;
                $points = isset($item['loyalty_points']) ? (int) $item['loyalty_points'] : 0;

                // Idempotent upsert: match by phone within tenant if phone is present,
                // otherwise match by exact name with empty/null phone within tenant.
                $customer = null;
                if ($phone) {
                    $customer = Customer::where('user_id', $userId)
                        ->where('phone', $phone)
                        ->first();
                } else {
                    $customer = Customer::where('user_id', $userId)
                        ->where('name', $name)
                        ->where(function ($q) {
                            $q->whereNull('phone')->orWhere('phone', '');
                        })
                        ->first();
                }

                if ($customer) {
                    $customer->name = $name;
                    if ($points > 0 && $points > $customer->loyalty_points) {
                        $customer->loyalty_points = $points;
                    }
                    $customer->is_active = true;
                    $customer->save();
                } else {
                    Customer::create([
                        'user_id' => $userId,
                        'name' => $name,
                        'phone' => $phone,
                        'loyalty_points' => $points,
                        'is_active' => true,
                    ]);
                }
            }
        });

        return response()->json([
            'status' => 'success',
            'committed' => true,
            'message' => 'Bulk customers synchronized successfully',
        ]);
    }

    /**
     * Search customer by phone or name (untuk lookup saat transaksi)
     */
    public function search(Request $request)
    {
        $q = trim($request->query('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['data' => []]);
        }
        $customers = Customer::where('user_id', Auth::id())
            ->where(function($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                      ->orWhere('phone', 'like', "%{$q}%");
            })
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'phone', 'loyalty_points']);
        return response()->json(['data' => $customers]);
    }
}
