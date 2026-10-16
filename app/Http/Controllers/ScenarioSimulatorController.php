<?php

namespace App\Http\Controllers;

use App\Models\Outlet;
use App\Services\Financial\FinancialEvidenceService;
use App\Services\Financial\FinancialScenarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * ScenarioSimulatorController
 *
 * Thin controller adapter exposing FinancialScenarioService to the Web Backoffice.
 * Strictly adheres to SSOT:
 * - ZERO financial formulas in controller
 * - ZERO direct queries on transactions/expenses/shifts
 * - ZERO database mutations (100% read-only)
 * - Strict tenant & outlet isolation
 */
class ScenarioSimulatorController extends Controller
{
    /**
     * Render standalone Web Backoffice Scenario Simulator view.
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

        $initialSimulation = null;
        if ($outletId) {
            /** @var FinancialScenarioService $scenarioService */
            $scenarioService = app(FinancialScenarioService::class);
            $initialSimulation = $scenarioService->simulate($userId, $outletId, [
                'scenario_type' => 'PRICE_CHANGE',
                'price_change_percent' => 0.0,
                'baseline_period' => '30_DAYS',
            ]);

            if (($initialSimulation['status'] ?? '') === 'SUCCESS') {
                /** @var FinancialEvidenceService $evidenceService */
                $evidenceService = app(FinancialEvidenceService::class);
                $initialSimulation['evidence'] = $evidenceService->wrapScenario($initialSimulation);
            }
        }

        return view('backoffice.intelligence.scenario', compact('user', 'outlets', 'activeOutlet', 'initialSimulation'));
    }

    /**
     * Handle deterministic AJAX simulation request.
     */
    public function simulate(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'status'  => 'ERROR',
                'code'    => 'UNAUTHENTICATED',
                'message' => 'User belum terautentikasi.',
            ], 401);
        }

        if (!$this->isTestingAccount($user)) {
            return response()->json([
                'status'  => 'ERROR',
                'code'    => 'FORBIDDEN',
                'message' => 'Akses terbatas: Fitur Kaching Intelligence saat ini hanya tersedia untuk akun pengujian (Testing Preview).',
            ], 403);
        }

        $userId = (int) $user->id;

        // Defense-in-depth: tolak parameter terlarang / percobaan injeksi
        $forbiddenKeys = ['user_id', 'sql', 'query', 'formula', 'threshold', 'select', 'drop', 'insert', 'update', 'delete', 'raw', 'where'];
        foreach ($forbiddenKeys as $forbiddenKey) {
            if ($request->has($forbiddenKey)) {
                return response()->json([
                    'status'  => 'ERROR',
                    'code'    => 'FORBIDDEN_PARAMETER',
                    'message' => "Parameter '{$forbiddenKey}' dilarang dalam input scenario.",
                ], 422);
            }
        }

        // Resolusi dan validasi kepemilikan outlet
        $outletId = null;
        $requestedOutlet = $request->input('outlet_id') ?: ($request->header('Outlet-ID') ?: session('active_outlet_id'));
        if ($user->role === 'kasir' && $user->outlet_id) {
            $requestedOutlet = $user->outlet_id;
        }

        if (!empty($requestedOutlet) && is_numeric($requestedOutlet)) {
            $candidateId = (int) $requestedOutlet;
            $outletExists = Outlet::where('user_id', $userId)->where('id', $candidateId)->exists();
            if (!$outletExists) {
                return response()->json([
                    'status'  => 'ERROR',
                    'code'    => 'UNAUTHORIZED_OUTLET',
                    'message' => 'Outlet tidak ditemukan atau bukan milik akun Anda.',
                ], 403);
            }
            $outletId = $candidateId;
        } else {
            $firstOutlet = Outlet::where('user_id', $userId)->first();
            if ($firstOutlet) {
                $outletId = (int) $firstOutlet->id;
            }
        }

        if ($outletId === null) {
            return response()->json([
                'status'  => 'ERROR',
                'code'    => 'MISSING_ACTIVE_OUTLET',
                'message' => 'Outlet aktif tidak ditemukan untuk akun Anda.',
            ], 422);
        }

        $scenarioType = $request->input('scenario_type');
        if (empty($scenarioType) || !is_string($scenarioType)) {
            return response()->json([
                'status'  => 'ERROR',
                'code'    => 'MISSING_SCENARIO_TYPE',
                'message' => "Parameter 'scenario_type' wajib diisi.",
            ], 422);
        }

        $allowedKeys = [
            'scenario_type',
            'price_change_percent',
            'cost_change_percent',
            'revenue_change_percent',
            'expense_change_amount',
            'expense_change_percent',
            'baseline_period',
        ];

        $scenarioInput = $request->only($allowedKeys);

        $customStartDate = null;
        $customEndDate = null;
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $customStartDate = (string) $request->input('start_date');
            $customEndDate = (string) $request->input('end_date');
        }

        try {
            /** @var FinancialScenarioService $scenarioService */
            $scenarioService = app(FinancialScenarioService::class);
            $result = $scenarioService->simulate($userId, $outletId, $scenarioInput, $customStartDate, $customEndDate);

            if (($result['status'] ?? '') === 'ERROR') {
                $statusCode = match ($result['code'] ?? '') {
                    'UNAUTHENTICATED' => 401,
                    'UNAUTHORIZED_OUTLET' => 403,
                    'INSUFFICIENT_DATA' => 422,
                    'FORBIDDEN_PARAMETER', 'MISSING_REQUIRED_PARAMETER', 'INVALID_PARAMETER_VALUE', 'PARAMETER_OUT_OF_BOUNDS', 'INVALID_SCENARIO_TYPE', 'INVALID_EXPENSE_RESULT', 'MISSING_SCENARIO_TYPE' => 422,
                    default => 400,
                };
                return response()->json($result, $statusCode);
            }

            /** @var FinancialEvidenceService $evidenceService */
            $evidenceService = app(FinancialEvidenceService::class);
            $result['evidence'] = $evidenceService->wrapScenario($result);

            return response()->json($result, 200);
        } catch (\Throwable $e) {
            Log::error('ScenarioSimulatorController: simulate error', [
                'user_id' => $userId,
                'outlet_id' => $outletId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'ERROR',
                'code'    => 'SERVICE_EXCEPTION',
                'message' => 'Simulasi tidak dapat dijalankan dengan parameter tersebut.',
            ], 500);
        }
    }

    /**
     * Testing account gate matching Kaching Intelligence convention.
     */
    protected function isTestingAccount($user): bool
    {
        if (app()->environment('testing')) {
            return true;
        }
        if (!$user) {
            return false;
        }
        $email = strtolower($user->email ?? '');
        return str_contains($email, 'test')
            || str_contains($email, 'testing')
            || ($user->id == env('RMI_DEMO_USER_ID'));
    }
}
