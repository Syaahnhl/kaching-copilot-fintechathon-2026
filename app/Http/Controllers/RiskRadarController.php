<?php

namespace App\Http\Controllers;

use App\Models\Outlet;
use App\Services\Financial\FinancialActionRecommendationService;
use App\Services\Financial\FinancialEvidenceService;
use App\Services\Financial\FinancialWarningService;
use App\Services\Financial\FinancingReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Controller untuk Kaching Risk Radar & Early Warning System.
 *
 * ARSITEKTUR & BOUNDARY:
 * - Mengonsumsi SSOT FinancialWarningService, FinancialEvidenceService, dan FinancingReadinessService
 * - ZERO kalkulasi finansial di controller atau JavaScript
 * - ZERO kueri langsung ke tabel transaksi / beban / shift (100% melalui service)
 * - ZERO mutasi database (100% read-only)
 * - Strict tenant & outlet isolation (fail-closed)
 */
class RiskRadarController extends Controller
{
    /**
     * Tampilkan halaman utama Kaching Risk Radar.
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

        /** @var FinancialWarningService $warningService */
        $warningService = app(FinancialWarningService::class);
        $warnings = $warningService->evaluateAllWarnings($userId, $outletId);

        /** @var FinancialEvidenceService $evidenceService */
        $evidenceService = app(FinancialEvidenceService::class);
        $evidence = $evidenceService->wrapAllWarnings($warnings);

        /** @var FinancingReadinessService $readinessService */
        $readinessService = app(FinancingReadinessService::class);
        $financingReadiness = $readinessService->calculate($userId, $outletId);

        /** @var FinancialActionRecommendationService $recommendationService */
        $recommendationService = app(FinancialActionRecommendationService::class);
        $recommendations = $recommendationService->generateRecommendations($userId, $outletId, [
            'warnings'            => $warnings,
            'financing_readiness' => $financingReadiness,
        ]);

        return view('backoffice.intelligence.risk_radar', compact(
            'user',
            'outlets',
            'activeOutlet',
            'warnings',
            'evidence',
            'financingReadiness',
            'recommendations'
        ));
    }

    /**
     * Kembalikan evaluasi deterministik Risk Radar via JSON.
     */
    public function evaluate(Request $request): JsonResponse
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

        // Defense-in-depth: tolak parameter terlarang / manipulasi identitas tenant
        $forbiddenKeys = ['user_id', 'sql', 'query', 'formula', 'threshold', 'select', 'drop', 'insert', 'update', 'delete', 'raw', 'where'];
        foreach ($forbiddenKeys as $forbiddenKey) {
            if ($request->has($forbiddenKey)) {
                return response()->json([
                    'status'  => 'ERROR',
                    'code'    => 'FORBIDDEN_PARAMETER',
                    'message' => "Parameter '{$forbiddenKey}' dilarang dalam evaluasi risk radar.",
                ], 422);
            }
        }

        // Resolusi dan validasi kepemilikan outlet (fail-closed)
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

        try {
            /** @var FinancialWarningService $warningService */
            $warningService = app(FinancialWarningService::class);
            $warnings = $warningService->evaluateAllWarnings($userId, $outletId);

            /** @var FinancialEvidenceService $evidenceService */
            $evidenceService = app(FinancialEvidenceService::class);
            $evidence = $evidenceService->wrapAllWarnings($warnings);

            /** @var FinancingReadinessService $readinessService */
            $readinessService = app(FinancingReadinessService::class);
            $financingReadiness = $readinessService->calculate($userId, $outletId);

            /** @var FinancialActionRecommendationService $recommendationService */
            $recommendationService = app(FinancialActionRecommendationService::class);
            $recommendations = $recommendationService->generateRecommendations($userId, $outletId, [
                'warnings'            => $warnings,
                'financing_readiness' => $financingReadiness,
            ]);

            return response()->json([
                'status'              => 'SUCCESS',
                'outlet_id'           => $outletId,
                'warnings'            => $warnings,
                'evidence'            => $evidence,
                'financing_readiness' => $financingReadiness,
                'recommendations'     => $recommendations,
            ]);
        } catch (\Throwable $e) {
            Log::error('RiskRadarController evaluation error: ' . $e->getMessage(), [
                'user_id'   => $userId,
                'outlet_id' => $outletId,
            ]);

            return response()->json([
                'status'  => 'ERROR',
                'code'    => 'EVALUATION_ERROR',
                'message' => 'Terjadi kesalahan saat mengevaluasi sinyal risiko: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper identifikasi akun pengujian (Testing Account).
     */
    private function isTestingAccount($user): bool
    {
        if (app()->environment('testing')) {
            return true;
        }
        if (!$user) {
            return false;
        }
        $email = strtolower($user->email ?? '');
        return $email === 'testing@gmail.com'
            || str_contains($email, 'testing')
            || str_contains($email, 'test')
            || ($user->id == env('RMI_DEMO_USER_ID'));
    }
}
