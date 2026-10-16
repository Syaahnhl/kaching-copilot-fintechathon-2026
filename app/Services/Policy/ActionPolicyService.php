<?php

namespace App\Services\Policy;

use App\Models\Outlet;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 🛡️ [B.8] ActionPolicyService — Kaching Copilot Deterministic Policy & Approval Firewall
 *
 * Prinsip Utama:
 * 1. AI bertindak sebagai PROPOSER, bukan EXECUTOR.
 * 2. Seluruh mutasi bisnis wajib melalui Policy Engine server-side dan membutuhkan otorisasi manusia.
 * 3. Keputusan policy (ALLOW, DENY, REQUIRES_APPROVAL, UNSUPPORTED) bersifat deterministik dan server-authoritative.
 * 4. "ALLOW" HANYA menandakan proposal secara policy diizinkan diajukan — BUKAN eksekusi mutasi langsung.
 * 5. State proposal disimpan secara stateless/transient di Cache layer (TTL 15 menit) tanpa migrasi database.
 */
class ActionPolicyService
{
    public const DECISION_ALLOW = 'ALLOW';
    public const DECISION_DENY = 'DENY';
    public const DECISION_REQUIRES_APPROVAL = 'REQUIRES_APPROVAL';
    public const DECISION_UNSUPPORTED = 'UNSUPPORTED';

    public const RISK_LOW = 'LOW';
    public const RISK_MEDIUM = 'MEDIUM';
    public const RISK_HIGH = 'HIGH';
    public const RISK_CRITICAL = 'CRITICAL';

    public const REVERSIBILITY_REVERSIBLE = 'REVERSIBLE';
    public const REVERSIBILITY_PARTIALLY = 'PARTIALLY_REVERSIBLE';
    public const REVERSIBILITY_IRREVERSIBLE = 'IRREVERSIBLE';

    public const CACHE_PREFIX = 'copilot:proposal:';
    public const CACHE_TTL_MINUTES = 15;

    /**
     * Forbidden parameter injection patterns.
     */
    protected const FORBIDDEN_PARAMETERS = [
        'user_id',
        'outlet_id',
        'tenant_id',
        'role',
        'required_role',
        'approval_required',
        'approval_status',
        'policy_decision',
        'sql',
        'query',
        'formula',
        'threshold',
        'select',
        'drop',
        'insert',
        'update',
        'delete',
        'url',
        'uri',
        'fetch',
        'class',
        'method',
        'controller',
    ];

    /**
     * Hardcoded Whitelist of Supported Business Actions and Invariants.
     */
    protected array $actionWhitelist = [
        'expense.create' => [
            'risk_level' => self::RISK_MEDIUM,
            'required_role' => 'MANAGER',
            'approval_required' => true,
            'requires_pin' => true,
            'reversibility' => self::REVERSIBILITY_REVERSIBLE,
            'allowed_params'    => ['name', 'amount', 'category', 'expense_date', 'date', 'payment_method', 'notes', 'note'],
            'required_params' => ['name', 'amount'],
            'description' => 'Pencatatan beban pengeluaran operasional outlet',
        ],
        'menu.price_change' => [
            'risk_level' => self::RISK_HIGH,
            'required_role' => 'OWNER',
            'approval_required' => true,
            'requires_pin' => true,
            'reversibility' => self::REVERSIBILITY_REVERSIBLE,
            'allowed_params' => ['menu_id', 'new_price', 'reason'],
            'required_params' => ['menu_id', 'new_price'],
            'description' => 'Penyesuaian harga jual menu katalog',
        ],
        'stock.adjust' => [
            'risk_level' => self::RISK_HIGH,
            'required_role' => 'MANAGER',
            'approval_required' => true,
            'requires_pin' => true,
            'reversibility' => self::REVERSIBILITY_REVERSIBLE,
            'allowed_params' => ['menu_id', 'quantity', 'type', 'reason'],
            'required_params' => ['menu_id', 'quantity', 'type'],
            'description' => 'Penyesuaian stok opname atau koreksi inventori menu',
        ],
        'transaction.cancel' => [
            'risk_level' => self::RISK_CRITICAL,
            'required_role' => 'OWNER',
            'approval_required' => true,
            'requires_pin' => true,
            'reversibility' => self::REVERSIBILITY_IRREVERSIBLE,
            'allowed_params' => ['transaction_id', 'reason'],
            'required_params' => ['transaction_id', 'reason'],
            'description' => 'Pembatalan transaksi penjualan yang telah tercatat',
        ],
    ];

    /**
     * Evaluasi proposal aksi bisnis dari Copilot.
     *
     * @param int $userId Authenticated merchant owner ID
     * @param int|null $outletId Validated outlet context
     * @param string $action Nama aksi bisnis
     * @param array $parameters Parameter input yang diusulkan
     * @return array Canonical proposal contract
     */
    public function evaluateProposal(
        int $userId,
        ?int $outletId,
        string $action,
        array $parameters
    ): array {
        // 1. Validasi Whitelist Aksi
        if (!array_key_exists($action, $this->actionWhitelist)) {
            return [
                'proposal_id' => null,
                'decision' => self::DECISION_UNSUPPORTED,
                'action' => $action,
                'risk_level' => 'UNKNOWN',
                'approval_required' => true,
                'required_role' => 'OWNER',
                'reversibility' => 'UNKNOWN',
                'reason' => "Aksi '{$action}' belum didukung oleh Copilot Action Firewall.",
                'expires_at' => null,
                'parameters' => [],
            ];
        }

        $config = $this->actionWhitelist[$action];

        // 2. Defense-in-depth: Cek forbidden parameters (injeksi SQL / privilege escalation)
        foreach (self::FORBIDDEN_PARAMETERS as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $parameters)) {
                return [
                    'proposal_id' => null,
                    'decision' => self::DECISION_DENY,
                    'action' => $action,
                    'risk_level' => $config['risk_level'],
                    'approval_required' => true,
                    'required_role' => $config['required_role'],
                    'reversibility' => $config['reversibility'],
                    'reason' => "Parameter '{$forbiddenKey}' dilarang demi keamanan sistem.",
                    'expires_at' => null,
                    'parameters' => [],
                ];
            }
        }

        // 3. Validasi Parameter Wajib
        foreach ($config['required_params'] as $reqKey) {
            if (!array_key_exists($reqKey, $parameters) || $parameters[$reqKey] === null || $parameters[$reqKey] === '') {
                return [
                    'proposal_id' => null,
                    'decision' => self::DECISION_DENY,
                    'action' => $action,
                    'risk_level' => $config['risk_level'],
                    'approval_required' => true,
                    'required_role' => $config['required_role'],
                    'reversibility' => $config['reversibility'],
                    'reason' => "Parameter wajib '{$reqKey}' tidak disertakan dalam proposal.",
                    'expires_at' => null,
                    'parameters' => [],
                ];
            }
        }

        // 4. Validasi Domain Parameter Spesifik
        $domainValidationError = $this->validateActionParameters($action, $parameters);
        if ($domainValidationError !== null) {
            return [
                'proposal_id' => null,
                'decision' => self::DECISION_DENY,
                'action' => $action,
                'risk_level' => $config['risk_level'],
                'approval_required' => true,
                'required_role' => $config['required_role'],
                'reversibility' => $config['reversibility'],
                'reason' => $domainValidationError,
                'expires_at' => null,
                'parameters' => [],
            ];
        }

        // 5. Validasi Kepemilikan Outlet (Tenant Isolation)
        if ($outletId !== null) {
            $outletExists = Outlet::where('user_id', $userId)
                ->where('id', $outletId)
                ->exists();

            if (!$outletExists) {
                return [
                    'proposal_id' => null,
                    'decision' => self::DECISION_DENY,
                    'action' => $action,
                    'risk_level' => $config['risk_level'],
                    'approval_required' => true,
                    'required_role' => $config['required_role'],
                    'reversibility' => $config['reversibility'],
                    'reason' => "Outlet ID {$outletId} tidak valid atau bukan milik merchant ini.",
                    'expires_at' => null,
                    'parameters' => [],
                ];
            }
        }

        // 6. Sanitasi Parameter (Hanya izinkan parameter whitelist)
        $sanitizedParameters = [];
        foreach ($config['allowed_params'] as $allowedKey) {
            if (array_key_exists($allowedKey, $parameters)) {
                $sanitizedParameters[$allowedKey] = $parameters[$allowedKey];
            }
        }

        // 7. Evaluasi Keputusan Policy
        $approvalRequired = (bool) $config['approval_required'];
        $decision = $approvalRequired ? self::DECISION_REQUIRES_APPROVAL : self::DECISION_ALLOW;

        // 8. Pembuatan ID Proposal Kriptografis Unpredictable
        $proposalId = 'prop_' . bin2hex(random_bytes(16));
        $expiresAt = now()->addMinutes(self::CACHE_TTL_MINUTES);

        // 9. Simpan Proposal ke Cache Transient (TTL 15 Menit)
        $proposalPayload = [
            'proposal_id' => $proposalId,
            'user_id' => $userId,
            'outlet_id' => $outletId,
            'action' => $action,
            'decision' => $decision,
            'approval_required' => $approvalRequired,
            'required_role' => $config['required_role'],
            'requires_pin' => (bool) $config['requires_pin'],
            'risk_level' => $config['risk_level'],
            'reversibility' => $config['reversibility'],
            'parameters' => $sanitizedParameters,
            'created_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        Cache::put(self::CACHE_PREFIX . $proposalId, $proposalPayload, $expiresAt);

        // 10. Kembalikan Kontrak Kanonikal
        return [
            'proposal_id' => $proposalId,
            'decision' => $decision,
            'action' => $action,
            'risk_level' => $config['risk_level'],
            'approval_required' => $approvalRequired,
            'required_role' => $config['required_role'],
            'reversibility' => $config['reversibility'],
            'reason' => $this->generateDecisionReason($action, $decision, $config),
            'expires_at' => $expiresAt->toIso8601String(),
            'parameters' => $sanitizedParameters,
        ];
    }

    /**
     * Validasi domain spesifik parameter aksi.
     */
    protected function validateActionParameters(string $action, array $params): ?string
    {
        switch ($action) {
            case 'expense.create':
                if (!is_numeric($params['amount']) || (float) $params['amount'] <= 0) {
                    return 'Nominal pengeluaran (amount) harus berupa angka positif lebih dari 0.';
                }
                break;

            case 'menu.price_change':
                if (!is_numeric($params['new_price']) || (float) $params['new_price'] < 0) {
                    return 'Harga baru menu (new_price) harus berupa angka non-negatif.';
                }
                if (!is_numeric($params['menu_id']) || (int) $params['menu_id'] <= 0) {
                    return 'ID Menu tidak valid.';
                }
                break;

            case 'stock.adjust':
                if (!is_numeric($params['quantity'])) {
                    return 'Kuantitas penyesuaian stok harus berupa angka.';
                }
                $allowedTypes = ['increment', 'decrement', 'adjustment', 'opname'];
                if (!in_array($params['type'], $allowedTypes, true)) {
                    return "Tipe penyesuaian stok tidak valid. Gunakan salah satu dari: " . implode(', ', $allowedTypes);
                }
                if (!is_numeric($params['menu_id']) || (int) $params['menu_id'] <= 0) {
                    return 'ID Menu tidak valid.';
                }
                break;

            case 'transaction.cancel':
                if (!is_numeric($params['transaction_id']) || (int) $params['transaction_id'] <= 0) {
                    return 'ID Transaksi yang dibatalkan tidak valid.';
                }
                if (empty(trim((string) ($params['reason'] ?? '')))) {
                    return 'Alasan pembatalan transaksi wajib diisi.';
                }
                break;
        }

        return null;
    }

    /**
     * Generate penjelasan alasan keputusan policy.
     */
    protected function generateDecisionReason(string $action, string $decision, array $config): string
    {
        if ($decision === self::DECISION_REQUIRES_APPROVAL) {
            return "Aksi '{$action}' tergolong risiko {$config['risk_level']} dan membutuhkan persetujuan resmi manusia dengan peran minimal {$config['required_role']}.";
        }

        return "Proposal aksi '{$action}' diizinkan untuk diajukan tanpa perubahan database.";
    }

    /**
     * Ambil proposal tersimpan dari cache.
     */
    public function getProposal(string $proposalId): ?array
    {
        return Cache::get(self::CACHE_PREFIX . $proposalId);
    }

    /**
     * Invalidate proposal dari cache setelah eksekusi atau reject.
     */
    public function invalidateProposal(string $proposalId): void
    {
        Cache::forget(self::CACHE_PREFIX . $proposalId);
    }

    /**
     * Dapatkan konfigurasi whitelist untuk inspeksi/testing.
     */
    public function getActionWhitelist(): array
    {
        return $this->actionWhitelist;
    }

    /**
     * Membangun teks format proposal aksi kanonikal (Phase 5.16-C).
     *
     * Format:
     * ACTION PROPOSAL
     * [Title/Action Name]
     * Current: [Current Value]
     * Proposed: [Proposed Value]
     * Estimated impact: [Estimated Impact]
     * Evidence:
     * - [Evidence item]
     * Risk: [Risk explanation]
     * [REVIEW] [APPROVE]
     *
     * Invariant: Proposal tidak mengeksekusi mutasi apapun ke database.
     */
    public function formatCanonicalProposal(array $proposalData): string
    {
        $title = $proposalData['title'] ?? ($proposalData['action'] ?? 'Usulan Tindakan Finansial');
        $current = $proposalData['current'] ?? ($proposalData['current_state'] ?? '-');
        $proposed = $proposalData['proposed'] ?? ($proposalData['proposed_state'] ?? '-');
        $impact = $proposalData['estimated_impact'] ?? '-';
        $evidence = (array) ($proposalData['evidence'] ?? []);
        $risk = $proposalData['risk'] ?? ($proposalData['risk_level'] ?? 'Perubahan memerlukan verifikasi dampak operasional');
        $proposalId = $proposalData['proposal_id'] ?? ($proposalData['id'] ?? null);

        $evidenceText = '';
        foreach ($evidence as $ev) {
            $evidenceText .= "- {$ev}\n";
        }
        if (empty($evidenceText)) {
            $evidenceText = "- Data transaksi dan performa operasional relevan\n";
        }

        $idLine = $proposalId ? "\nProposal ID: {$proposalId}\n" : "\n";

        return <<<EOT
ACTION PROPOSAL
----------------
{$title}

Current:
{$current}

Proposed:
{$proposed}

Estimated impact:
{$impact}

Evidence:
{$evidenceText}
Risk:
{$risk}
{$idLine}PERSETUJUAN RESMI PEMILIK:
AI TIDAK mengeksekusi mutasi data finansial secara otomatis.
Dibutuhkan peninjauan Owner dan verifikasi PIN Manager sebelum aksi dapat dijalankan.
[REVIEW ACTION] [APPROVE VIA PIN]
EOT;
    }

    /**
     * Membangun proposal aksi kanonikal dari rekomendasi investigasi (Phase 5.16-C).
     *
     * @param int $userId Authenticated owner ID
     * @param int|null $outletId Validated outlet context
     * @param array $recommendation Rekomendasi terstruktur
     * @return array Canonical proposal data
     */
    public function buildProposalFromRecommendation(
        int $userId,
        ?int $outletId,
        array $recommendation
    ): array {
        $actionType = $recommendation['action_type'] ?? 'GENERAL_ACTION';
        $proposalId = 'prop-' . Str::random(12);

        $payload = $recommendation['payload'] ?? [];
        $current = $payload['current_value'] ?? ($recommendation['current'] ?? null);
        $proposed = $payload['proposed_value'] ?? ($recommendation['proposed'] ?? null);
        $changePct = abs((float) ($payload['change_pct'] ?? 0.0));

        // Tentukan tingkat risiko dan kebutuhan approval
        $riskLevel = self::RISK_LOW;
        $requiresApproval = false;

        if ($actionType === 'BUDGET_CAP' || $changePct >= 20.0 || in_array($actionType, ['PRICE_CHANGE', 'TRANSACTION_CANCEL'])) {
            $riskLevel = self::RISK_HIGH;
            $requiresApproval = true;
        } elseif ($changePct >= 10.0 || $actionType === 'PRICE_ADJUSTMENT_BULK') {
            $riskLevel = self::RISK_MEDIUM;
            $requiresApproval = true;
        }

        $proposal = [
            'id'                => $proposalId,
            'proposal_id'       => $proposalId,
            'user_id'           => $userId,
            'outlet_id'         => $outletId,
            'action_type'       => $actionType,
            'title'             => $recommendation['title'] ?? 'Usulan Tindakan Finansial',
            'description'       => $recommendation['description'] ?? '',
            'current_state'     => $current,
            'proposed_state'    => $proposed,
            'estimated_impact'  => $recommendation['estimated_impact'] ?? '-',
            'evidence'          => (array) ($recommendation['evidence'] ?? []),
            'confidence'        => (float) ($recommendation['confidence'] ?? 0.8),
            'status'            => 'PROPOSED',
            'risk_level'        => $riskLevel,
            'requires_approval' => $requiresApproval,
            'payload'           => $payload,
            'disclaimer'        => 'Human Review Required: AI tidak mengeksekusi mutasi data secara otonom.',
            'created_at'        => Carbon::now()->toIso8601String(),
        ];

        $proposal['formatted_output'] = $this->formatCanonicalProposal($proposal);

        // Simpan ke transient cache layer (TTL 15 menit)
        Cache::put(self::CACHE_PREFIX . $proposalId, $proposal, Carbon::now()->addMinutes(self::CACHE_TTL_MINUTES));

        return $proposal;
    }

    /**
     * Memverifikasi otorisasi tenant atas proposal aksi (Pencegahan Cross-Tenant Leakage).
     *
     * @param array $proposal Data proposal
     * @param int $authenticatedUserId User ID yang sedang login
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function verifyTenantAuthorization(array $proposal, int $authenticatedUserId): void
    {
        $proposalUserId = (int) ($proposal['user_id'] ?? 0);
        if ($proposalUserId !== $authenticatedUserId) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Akses proposal ditolak: Tenant mismatch.');
        }
    }

    /**
     * Mencatat jejak audit resmi saat proposal aksi disetujui dan dieksekusi (Audit Trail SSOT).
     *
     * @param array $proposal Data proposal yang disetujui
     * @param int $userId Owner / Manager penanggung jawab
     * @param string|null $pin PIN verifikasi
     * @return bool
     */
    public function recordAuditLog(array $proposal, int $userId, ?string $pin = null): bool
    {
        try {
            \App\Models\AuditLog::create([
                'user_id'       => $userId,
                'action'        => 'COPILOT_ACTION_EXECUTED',
                'target_type'   => $proposal['action_type'] ?? 'PROPOSAL',
                'target_id'     => (string) ($proposal['id'] ?? ($proposal['proposal_id'] ?? 'unknown')),
                'before_values' => ['state' => $proposal['current_state'] ?? null],
                'after_values'  => ['state' => $proposal['proposed_state'] ?? null],
                'metadata'      => [
                    'title'            => $proposal['title'] ?? null,
                    'estimated_impact' => $proposal['estimated_impact'] ?? null,
                    'verified_pin'     => !empty($pin),
                    'risk_level'       => $proposal['risk_level'] ?? 'LOW',
                ],
                'ip_address'    => request()->ip() ?? '127.0.0.1',
                'user_agent'    => request()->userAgent() ?? 'Kaching-Copilot-Core',
            ]);

            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Gagal mencatat audit log Copilot action: ' . $e->getMessage());
            return false;
        }
    }
}
