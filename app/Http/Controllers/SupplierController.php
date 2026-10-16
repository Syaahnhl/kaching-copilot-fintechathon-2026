<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Supplier;
use Illuminate\Support\Facades\Validator;

class SupplierController extends Controller
{
    public function index()
    {
        return view('backoffice.suppliers.index');
    }

    public function data()
    {
        $suppliers = Supplier::where('user_id', auth()->id())
            ->orderBy('name')
            ->get();
        return response()->json(['data' => $suppliers]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'category' => 'nullable|string|max:255',
            'moq' => 'nullable|string|max:255',
            'payment_terms' => 'nullable|string|max:255',
            'lead_time' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();
        $data['user_id'] = auth()->id();
        $data['is_active'] = $data['is_active'] ?? true;
        $supplier = Supplier::create($data);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['message' => 'Supplier berhasil ditambahkan', 'data' => $supplier], 201);
        }
        return redirect()->back()->with('success', 'Supplier berhasil ditambahkan');
    }

    public function update(Request $request, $id)
    {
        $supplier = Supplier::where('user_id', auth()->id())->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'category' => 'nullable|string|max:255',
            'moq' => 'nullable|string|max:255',
            'payment_terms' => 'nullable|string|max:255',
            'lead_time' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();
        unset($data['user_id']);
        $supplier->update($data);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['message' => 'Supplier berhasil diupdate']);
        }
        return redirect()->back()->with('success', 'Supplier berhasil diupdate');
    }

    public function destroy($id)
    {
        $supplier = Supplier::where('user_id', auth()->id())->findOrFail($id);
        $supplier->delete();
        return response()->json(['message' => 'Supplier berhasil dihapus']);
    }

    public function apiIndex()
    {
        $suppliers = Supplier::where('user_id', auth()->id())
            ->orderBy('name')
            ->get();
        return response()->json([
            'status' => 'success',
            'data' => $suppliers
        ]);
    }

    public function apiStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'category' => 'nullable|string|max:255',
            'moq' => 'nullable|string|max:255',
            'payment_terms' => 'nullable|string|max:255',
            'lead_time' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['user_id'] = auth()->id();
        $data['is_active'] = $data['is_active'] ?? true;
        
        $supplier = Supplier::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier berhasil ditambahkan',
            'data' => $supplier
        ], 201);
    }

    public function apiUpdate(Request $request, $id)
    {
        $supplier = Supplier::where('user_id', auth()->id())->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'category' => 'nullable|string|max:255',
            'moq' => 'nullable|string|max:255',
            'payment_terms' => 'nullable|string|max:255',
            'lead_time' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $supplier->update($validator->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier berhasil diupdate',
            'data' => $supplier
        ]);
    }

    public function apiDestroy($id)
    {
        $supplier = Supplier::where('user_id', auth()->id())->findOrFail($id);
        $supplier->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Supplier berhasil dihapus'
        ]);
    }
}
