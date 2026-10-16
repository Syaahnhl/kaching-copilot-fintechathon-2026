<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\DemandForecastApiController;
use App\Models\Menu;
use App\Models\Outlet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * DemandForecastWebController
 *
 * Web Backoffice Controller untuk Kaching Native Demand Forecasting.
 * Mengintegrasikan API Read-Only Demand Forecast (TASK-08.9/08.10) ke UI Backoffice.
 * 
 * Invariants:
 * - Strictly read-only (zero mutations)
 * - Strict tenant & outlet isolation
 * - Reuses DemandForecastApiController for deterministic contract & validation
 */
class DemandForecastWebController extends Controller
{
    /**
     * Render UI Demand Forecast Backoffice.
     */
    public function index(Request $request): View
    {
        $user = Auth::user();
        if (!$user) {
            abort(401);
        }

        if (!$this->isTestingAccount($user)) {
            abort(403, 'Akses terbatas: Fitur Kaching Intelligence saat ini hanya tersedia untuk akun pengujian (Testing Preview).');
        }

        $userId = (int) $user->id;
        $outlets = Outlet::where('user_id', $userId)->orderBy('id', 'asc')->get();

        $requestedOutlet = $request->query('outlet_id') ?: session('active_outlet_id');
        if ($user->role === 'kasir' && $user->outlet_id) {
            $requestedOutlet = $user->outlet_id;
        }

        $activeOutlet = null;
        if ($requestedOutlet) {
            $activeOutlet = $outlets->firstWhere('id', (int) $requestedOutlet);
            if (!$activeOutlet) {
                abort(403, 'Akses ditolak: Outlet tidak ditemukan atau bukan milik akun Anda.');
            }
        } else {
            $activeOutlet = $outlets->first();
        }

        $outletId = $activeOutlet ? (int) $activeOutlet->id : null;
        $menus = collect();

        if ($outletId) {
            $menus = Menu::where('user_id', $userId)
                ->where('outlet_id', $outletId)
                ->orderBy('name', 'asc')
                ->get(['id', 'name', 'category', 'unit']);
        }

        return view('backoffice.intelligence.demand_forecast', compact(
            'user',
            'outlets',
            'activeOutlet',
            'menus'
        ));
    }

    /**
     * JSON Data Endpoint untuk AJAX Web Backoffice.
     * Meneruskan request ke DemandForecastApiController untuk menjamin integritas kontrak.
     */
    public function data(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Sesi habis, silakan login kembali.',
            ], 401);
        }

        if (!$this->isTestingAccount($user)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Akses terbatas: Fitur Kaching Intelligence saat ini hanya tersedia untuk akun pengujian (Testing Preview).',
            ], 403);
        }

        $outletId = $request->query('outlet_id', $request->input('outlet_id'));
        if (!$outletId) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Parameter outlet_id wajib disertakan.',
            ], 422);
        }

        /** @var DemandForecastApiController $apiController */
        $apiController = app(DemandForecastApiController::class);

        return $apiController->getOutletForecast($request, (string) $outletId);
    }

    /**
     * Helper identifikasi akun pengujian (Testing Account).
     */
    public function isTestingAccount($user): bool
    {
        if (!$user) {
            return false;
        }
        $email = strtolower($user->email ?? '');

        return str_contains($email, 'test')
            || str_contains($email, 'testing')
            || ($user->id == env('RMI_DEMO_USER_ID'));
    }
}
