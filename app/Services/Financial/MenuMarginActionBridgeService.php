<?php

namespace App\Services\Financial;

use App\Models\Menu;
use App\Services\Policy\ActionPolicyService;
use Illuminate\Support\Facades\Log;

/**
 * MenuMarginActionBridgeService
 *
 * Provides a surgical, deterministic bridge from MENU_MARGIN_REVIEW recommendations
 * to the ActionPolicyService proposal pipeline WITHOUT introducing any automatic
 * target-margin policy or automatic price calculations.
 *
 * Hard Invariants:
 * 1. Zero automatic target margin or target price invention.
 * 2. Exactly ONE explicitly selected menu per bridge operation.
 * 3. Canonical DB server is the sole SSOT for tenant ownership, current_price, and cost_price.
 * 4. Owner explicitly selects/enters new_price.
 * 5. Generates PENDING proposal in transient cache only. Zero direct execution.
 */
class MenuMarginActionBridgeService
{
    public const CODE_REQUIRES_MENU_SELECTION = 'REQUIRES_MENU_SELECTION';
    public const CODE_MENU_NOT_FOUND          = 'MENU_NOT_FOUND';
    public const CODE_UNAUTHORIZED_MENU       = 'UNAUTHORIZED_MENU';
    public const CODE_INVALID_MENU_DATA       = 'INVALID_MENU_DATA';
    public const CODE_FORBIDDEN_PARAMETER     = 'FORBIDDEN_PARAMETER';

    /**
     * Forbidden input keys to prevent LLM/client parameter injection or privilege escalation.
     */
    public const FORBIDDEN_INPUT_KEYS = [
        'user_id',
        'outlet_id',
        'tenant_id',
        'role',
        'user_role',
        'required_role',
        'approval_required',
        'approval_status',
        'policy_decision',
        'risk_level',
        'sql',
        'query',
        'formula',
        'target_margin',
        'target_margin_pct',
        'threshold',
        'select',
        'drop',
        'insert',
        'update',
        'delete',
        'url',
        'uri',
        'class',
        'method',
        'controller',
    ];

    protected ActionPolicyService $policyService;
    protected ?FinancialScenarioService $scenarioService;

    public function __construct(
        ?ActionPolicyService $policyService = null,
        ?FinancialScenarioService $scenarioService = null
    ) {
        $this->policyService   = $policyService ?? app(ActionPolicyService::class);
        $this->scenarioService = $scenarioService ?? (app()->bound(FinancialScenarioService::class) ? app(FinancialScenarioService::class) : null);
    }

    /**
     * Retrieve menu-level diagnostic candidates suffering from compressed/low margins.
     * Pure read-only query against the canonical Menu model for the authenticated tenant.
     *
     * @param int $userId Tenant/Owner user ID
     * @param int|null $outletId Specific outlet filter if applicable
     * @param int $limit Maximum candidates to return
     * @return array<int, array{menu_id: int, name: string, category: string|null, current_price: float, cost_price: float, current_margin_pct: float}>
     */
    public function getDiagnosticCandidates(int $userId, ?int $outletId = null, int $limit = 10): array
    {
        $query = Menu::query()
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->where('price', '>', 0)
            ->where('cost_price', '>', 0);

        if ($outletId !== null) {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)
                  ->orWhereNull('outlet_id');
            });
        }

        $menus = $query->get();

        $candidates = [];
        foreach ($menus as $menu) {
            $price = (float) $menu->price;
            $cost  = (float) $menu->cost_price;
            $marginPct = round((($price - $cost) / $price) * 100, 2);

            $candidates[] = [
                'menu_id'            => (int) $menu->id,
                'name'               => (string) $menu->name,
                'category'           => $menu->category ? (string) $menu->category : null,
                'current_price'      => $price,
                'cost_price'         => $cost,
                'current_margin_pct' => $marginPct,
            ];
        }

        // Sort ascending: menus with lowest gross margins first
        usort($candidates, fn ($a, $b) => $a['current_margin_pct'] <=> $b['current_margin_pct']);

        return array_slice($candidates, 0, max(1, $limit));
    }

    /**
     * Resolve and validate exactly one selected menu, calculate deterministic margin metrics
     * based on owner-selected new_price, and optionally simulate macro impact.
     *
     * @param int $userId Authenticated tenant user ID
     * @param int|null $outletId Active outlet context
     * @param array $input Untrusted client/LLM payload
     * @return array
     */
    public function resolveAndSimulate(int $userId, ?int $outletId, array $input): array
    {
        // 1. Parameter injection / forbidden keys check
        foreach (self::FORBIDDEN_INPUT_KEYS as $forbidden) {
            if (array_key_exists($forbidden, $input)) {
                return [
                    'status'  => 'ERROR',
                    'code'    => self::CODE_FORBIDDEN_PARAMETER,
                    'message' => "Parameter terlarang terdeteksi: {$forbidden}.",
                ];
            }
        }

        // 2. Validate menu selection (Must be EXACTLY ONE menu)
        $rawMenuId = $input['selected_menu_id'] ?? $input['menu_id'] ?? null;

        if ($rawMenuId === null || $rawMenuId === '') {
            return [
                'status'  => 'ERROR',
                'code'    => self::CODE_REQUIRES_MENU_SELECTION,
                'message' => 'Silakan pilih tepat satu menu untuk ditinjau.',
            ];
        }

        // Multiple menus selected rejection
        if (is_array($rawMenuId)) {
            return [
                'status'  => 'ERROR',
                'code'    => self::CODE_REQUIRES_MENU_SELECTION,
                'message' => 'Hanya satu menu yang boleh dipilih dalam satu waktu.',
            ];
        }

        if (!is_numeric($rawMenuId) || (int) $rawMenuId <= 0) {
            return [
                'status'  => 'ERROR',
                'code'    => self::CODE_REQUIRES_MENU_SELECTION,
                'message' => 'ID Menu yang dipilih tidak valid.',
            ];
        }

        $menuId = (int) $rawMenuId;

        // 3. Query canonical Menu record (Never trust client/LLM for record state)
        $menu = Menu::withTrashed()->find($menuId);

        if (!$menu) {
            return [
                'status'  => 'ERROR',
                'code'    => self::CODE_MENU_NOT_FOUND,
                'message' => "Menu dengan ID {$menuId} tidak ditemukan.",
            ];
        }

        // Tenant boundary check
        if ((int) $menu->user_id !== $userId) {
            return [
                'status'  => 'ERROR',
                'code'    => self::CODE_UNAUTHORIZED_MENU,
                'message' => 'Menu bukan milik merchant yang terautentikasi.',
            ];
        }

        // Outlet boundary check (if menu is outlet-specific and outlet context provided)
        if ($outletId !== null && $menu->outlet_id !== null && (int) $menu->outlet_id !== $outletId) {
            return [
                'status'  => 'ERROR',
                'code'    => self::CODE_UNAUTHORIZED_MENU,
                'message' => 'Menu bukan milik outlet yang aktif.',
            ];
        }

        // Trashed / deleted check
        if ($menu->trashed()) {
            return [
                'status'  => 'ERROR',
                'code'    => self::CODE_INVALID_MENU_DATA,
                'message' => 'Menu telah dihapus dan tidak dapat disesuaikan harganya.',
            ];
        }

        // Selling price validity check
        $currentPrice = (float) $menu->price;
        if ($currentPrice <= 0) {
            return [
                'status'  => 'ERROR',
                'code'    => self::CODE_INVALID_MENU_DATA,
                'message' => 'Harga jual menu saat ini tidak valid (harus lebih besar dari 0).',
            ];
        }

        // Cost / HPP validity check
        $costPrice = (float) $menu->cost_price;
        if ($costPrice <= 0) {
            return [
                'status'  => 'ERROR',
                'code'    => self::CODE_INVALID_MENU_DATA,
                'message' => 'Menu belum memiliki HPP (cost_price) yang valid untuk simulasi margin.',
            ];
        }

        $currentMarginPct = round((($currentPrice - $costPrice) / $currentPrice) * 100, 2);

        // 4. Handle owner-selected new_price
        $hasNewPrice = array_key_exists('new_price', $input) && $input['new_price'] !== null && $input['new_price'] !== '';

        if (!$hasNewPrice) {
            // Diagnostic inspection mode (no new price entered yet)
            return [
                'status'               => 'SUCCESS',
                'menu_id'              => $menu->id,
                'menu_name'            => $menu->name,
                'category'             => $menu->category,
                'current_price'        => $currentPrice,
                'cost_price'           => $costPrice,
                'current_margin_pct'   => $currentMarginPct,
                'requires_price_input' => true,
                'message'              => 'Data menu valid. Silakan tentukan harga baru yang ingin Anda simulasikan atau terapkan.',
            ];
        }

        // Validate owner-selected new_price
        if (!is_numeric($input['new_price']) || (float) $input['new_price'] <= 0) {
            return [
                'status'  => 'ERROR',
                'code'    => self::CODE_INVALID_MENU_DATA,
                'message' => 'Harga baru yang dipilih harus berupa angka positif.',
            ];
        }

        $newPrice = (float) $input['new_price'];
        $priceDelta = round($newPrice - $currentPrice, 2);
        $priceChangePct = round((($newPrice - $currentPrice) / $currentPrice) * 100, 2);
        $resultingGrossMarginPct = round((($newPrice - $costPrice) / $newPrice) * 100, 2);
        $marginDeltaPp = round($resultingGrossMarginPct - $currentMarginPct, 2);

        // 5. Macro scenario simulation (reusing existing FinancialScenarioService if applicable)
        $macroSimulation = null;
        if ($this->scenarioService && $priceChangePct >= -50.0 && $priceChangePct <= 100.0) {
            try {
                $simResult = $this->scenarioService->simulate($userId, $outletId, [
                    'scenario_type'        => 'PRICE_CHANGE',
                    'price_change_percent' => $priceChangePct,
                ]);

                if (($simResult['status'] ?? '') === 'SUCCESS') {
                    $macroSimulation = [
                        'base_revenue'         => $simResult['baseline']['revenue'] ?? null,
                        'simulated_revenue'    => $simResult['simulated']['revenue'] ?? null,
                        'revenue_delta'        => $simResult['delta']['revenue'] ?? null,
                        'simulated_net_margin' => $simResult['simulated']['net_margin_pct'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('Macro scenario simulation skipped during menu margin bridge: ' . $e->getMessage());
            }
        }

        return [
            'status'                    => 'SUCCESS',
            'menu_id'                   => $menu->id,
            'menu_name'                 => $menu->name,
            'category'                  => $menu->category,
            'current_price'             => $currentPrice,
            'cost_price'                => $costPrice,
            'current_margin_pct'        => $currentMarginPct,
            'owner_selected_new_price'  => $newPrice,
            'new_price'                 => $newPrice,
            'price_delta'               => $priceDelta,
            'price_change_percent'      => $priceChangePct,
            'resulting_margin_pct'      => $resultingGrossMarginPct,
            'margin_delta_pp'           => $marginDeltaPp,
            'macro_simulation'          => $macroSimulation,
            'requires_price_input'      => false,
        ];
    }

    /**
     * Create a PENDING proposal for ActionPolicyService based on verified menu & owner price.
     * ZERO direct database modification. Proposal is stored in transient cache pending PIN approval.
     *
     * @param int $userId Authenticated tenant user ID
     * @param int|null $outletId Active outlet context
     * @param array $input Untrusted input payload
     * @param string $userRole Role of the executing user (defaults to OWNER)
     * @return array
     */
    public function createProposal(int $userId, ?int $outletId, array $input, string $userRole = 'OWNER'): array
    {
        // Must pass full server-side parameter resolution
        $resolved = $this->resolveAndSimulate($userId, $outletId, $input);

        if (($resolved['status'] ?? '') !== 'SUCCESS') {
            return $resolved;
        }

        if (empty($resolved['owner_selected_new_price'])) {
            return [
                'status'  => 'ERROR',
                'code'    => self::CODE_INVALID_MENU_DATA,
                'message' => 'Harga baru harus ditentukan oleh pemilik sebelum membuat proposal perubahan harga.',
            ];
        }

        // Canonical parameters matching ActionPolicyService 'menu.price_change' contract
        $policyParams = [
            'menu_id'   => $resolved['menu_id'],
            'new_price' => $resolved['owner_selected_new_price'],
            'reason'    => (string) ($input['reason'] ?? "Penyesuaian harga menu {$resolved['menu_name']} pasca review margin"),
        ];

        // Evaluate proposal via existing ActionPolicyService
        $proposalResult = $this->policyService->evaluateProposal(
            $userId,
            $outletId,
            'menu.price_change',
            $policyParams,
            $userRole
        );

        // Augment return payload with deterministic margin diagnostic context
        $proposalResult['status'] = 'SUCCESS';
        $proposalResult['proposal_status'] = 'PENDING';
        $proposalResult['requires_pin'] = true;
        $proposalResult['semantic_label'] = 'Proposal Perubahan Harga Menu (Menunggu Persetujuan Owner)';
        $proposalResult['menu_id'] = $resolved['menu_id'];
        $proposalResult['menu_name'] = $resolved['menu_name'];
        $proposalResult['current_price'] = $resolved['current_price'];
        $proposalResult['new_price'] = $resolved['owner_selected_new_price'];
        $proposalResult['price_delta'] = $resolved['price_delta'];
        $proposalResult['price_change_pct'] = $resolved['price_change_percent'];
        $proposalResult['resulting_margin_pct'] = $resolved['resulting_margin_pct'];
        $proposalResult['margin_delta_pp'] = $resolved['margin_delta_pp'];
        $proposalResult['is_executed'] = false;
        $proposalResult['execution_receipt'] = null;
        $proposalResult['menu_diagnostic'] = [
            'menu_id'              => $resolved['menu_id'],
            'menu_name'            => $resolved['menu_name'],
            'current_price'        => $resolved['current_price'],
            'cost_price'           => $resolved['cost_price'],
            'current_margin_pct'   => $resolved['current_margin_pct'],
            'resulting_margin_pct' => $resolved['resulting_margin_pct'],
            'margin_delta_pp'      => $resolved['margin_delta_pp'],
            'price_change_percent' => $resolved['price_change_percent'],
        ];
        $proposalResult['source_recommendation'] = FinancialActionRecommendationService::ACTION_MENU_MARGIN_REVIEW;

        return $proposalResult;
    }
}
