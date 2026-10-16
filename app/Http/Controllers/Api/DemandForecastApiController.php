<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuDemandForecast;
use App\Models\Outlet;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * 📈 DemandForecastApiController
 *
 * Controller Read-Only untuk menyajikan data peramalan kebutuhan menu (Native Demand Forecasting)
 * kepada konsumen API internal (Web Backoffice, POS Android, Mobile App).
 *
 * Prinsip Keselamatan & Desain:
 * 1. Murni Read-Only (0 mutasi database, 0 inferensi background, 0 cache lock creation).
 * 2. Tenant Isolation Ketat: Query selalu diskop terhadap authenticated user_id & outlet_id.
 * 3. Privacy Preservation: Mengembalikan 404 jika outlet bukan milik user untuk mencegah ID enumeration.
 * 4. Anti N+1 Query: Eager loading relasi menu hanya pada kolom yang dibutuhkan.
 */
class DemandForecastApiController extends Controller
{
    /**
     * Mengambil daftar hasil peramalan demand untuk outlet tertentu.
     *
     * GET /api/v1/demand-forecast/outlets/{outletId}
     * GET /api/demand-forecast/outlets/{outletId}
     *
     * @param Request $request
     * @param int|string $outletId
     * @return JsonResponse
     */
    public function getOutletForecast(Request $request, $outletId): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Sesi habis atau tidak terautentikasi.',
            ], 401);
        }

        $userId = (int) $user->id;

        // 🛡️ 1. Validasi Kepemilikan & Keberadaan Outlet (Tenant Isolation)
        $outlet = Outlet::where('id', (int) $outletId)
            ->where('user_id', $userId)
            ->first();

        if (!$outlet) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Outlet tidak ditemukan atau Anda tidak memiliki akses ke cabang ini.',
            ], 404);
        }

        // 🛡️ 2. Validasi Parameter Query Filter & Pagination
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'menu_id'    => 'nullable|integer',
            'per_page'   => 'nullable|integer|min:1|max:200',
        ], [
            'start_date.date_format'      => 'Format start_date harus YYYY-MM-DD.',
            'end_date.date_format'        => 'Format end_date harus YYYY-MM-DD.',
            'end_date.after_or_equal'     => 'Parameter end_date harus sama dengan atau setelah start_date.',
            'menu_id.integer'             => 'Parameter menu_id harus berupa bilangan bulat.',
            'per_page.min'                => 'Parameter per_page minimal 1.',
            'per_page.max'                => 'Parameter per_page maksimal 200.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Parameter query tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // 🛡️ 3. Validasi Rentang Tanggal Maksimal (Maksimal 90 Hari untuk Menjaga Performa Query)
        $startDate = $request->input('start_date');
        $endDate   = $request->input('end_date');

        if ($startDate && $endDate) {
            $startCarbon = Carbon::parse($startDate);
            $endCarbon   = Carbon::parse($endDate);

            if ($startCarbon->diffInDays($endCarbon) > 90) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Rentang tanggal maksimal yang diizinkan adalah 90 hari.',
                    'errors'  => [
                        'end_date' => ['Rentang antara start_date dan end_date tidak boleh melebihi 90 hari.'],
                    ],
                ], 422);
            }
        }

        // 🛡️ 4. Bangun Query Read-Only dengan Eager Loading Anti-N+1
        $query = MenuDemandForecast::query()
            ->where('user_id', $userId)
            ->where('outlet_id', $outlet->id)
            ->with(['menu' => function ($q) {
                $q->select('id', 'name', 'category');
            }]);

        if ($startDate) {
            $query->whereDate('target_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('target_date', '<=', $endDate);
        }

        if ($request->filled('menu_id')) {
            $query->where('menu_id', (int) $request->input('menu_id'));
        }

        $query->orderBy('target_date', 'asc')
              ->orderBy('menu_id', 'asc');

        $perPage = (int) $request->input('per_page', 50);
        $paginator = $query->paginate($perPage);

        // 🛡️ 5. Transformasi Data Respons (Sanitasi Kolom Internal)
        $formattedForecasts = collect($paginator->items())->map(function (MenuDemandForecast $forecast) {
            return [
                'id'                 => (int) $forecast->id,
                'menu_id'            => (int) $forecast->menu_id,
                'menu_name'          => $forecast->menu?->name,
                'menu_category'      => $forecast->menu?->category,
                'target_date'        => $forecast->target_date ? Carbon::parse($forecast->target_date)->format('Y-m-d') : null,
                'cutoff_date'        => $forecast->cutoff_date ? Carbon::parse($forecast->cutoff_date)->format('Y-m-d') : null,
                'predicted_quantity' => (float) $forecast->predicted_quantity,
                'lower_bound_p10'    => (float) $forecast->lower_bound_p10,
                'upper_bound_p90'    => (float) $forecast->upper_bound_p90,
                'uom'                => $forecast->uom ?? 'Porsi',
                'model_version'      => $forecast->model_version,
                'feature_version'    => $forecast->feature_version,
                'serving_origin'     => $forecast->serving_origin,
                'history_status'     => $forecast->history_status,
                'generated_at'       => $forecast->generated_at ? Carbon::parse($forecast->generated_at)->toIso8601String() : null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data'   => [
                'outlet' => [
                    'id'   => (int) $outlet->id,
                    'name' => $outlet->name,
                ],
                'forecasts' => $formattedForecasts,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'last_page'    => $paginator->lastPage(),
                    'from'         => $paginator->firstItem(),
                    'to'           => $paginator->lastItem(),
                ],
            ],
        ], 200);
    }
}
