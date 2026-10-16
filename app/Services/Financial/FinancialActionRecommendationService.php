<?php

namespace App\Services\Financial;

use App\Models\Outlet;
use App\Models\User;
use App\Services\Policy\ActionPolicyService;
use Illuminate\Support\Facades\Log;

/**
 * Phase Next: FinancialActionRecommendationService
 *
 * Deterministic Recommendation Adapter connecting Financial Intelligence
 * with ActionPolicyService and Backoffice UI.
 *
 * HARD SAFETY:
 * - 100% Deterministic — Zero LLM involvement in calculation, mapping, or output values.
 * - Zero Database Mutation — Zero INSERT, UPDATE, DELETE on any table.
 * - Proposal-Safe — Whitelist strictly checked via ActionPolicyService; state is transient Cache-only.
 * - Tenant Isolated — All inputs strictly scoped by user_id and validated outlet_id (fail-closed).
 * - Honest Output — Never invents numbers or predicts unverified profits/savings.
 */
class FinancialActionRecommendationService
{
    // Canonical Action Recommendation Types
    public const ACTION_COST_CONTROL_REVIEW         = 'COST_CONTROL_REVIEW';
    public const ACTION_CASH_CONTROL_REVIEW         = 'CASH_CONTROL_REVIEW';
    public const ACTION_MENU_MARGIN_REVIEW          = 'MENU_MARGIN_REVIEW';
    public const ACTION_SALES_PERFORMANCE_REVIEW    = 'SALES_PERFORMANCE_REVIEW';
    public const ACTION_CASH_RECONCILIATION_REVIEW  = 'CASH_RECONCILIATION_REVIEW';
    public const ACTION_FINANCIAL_RECORD_COMPLETION = 'FINANCIAL_RECORD_COMPLETION';

    // Canonical Priority Levels
    public const PRIORITY_CRITICAL = 'CRITICAL';
    public const PRIORITY_HIGH     = 'HIGH';
    public const PRIORITY_MEDIUM   = 'MEDIUM';
    public const PRIORITY_LOW      = 'LOW';
    public const PRIORITY_INFO     = 'INFO';

    // Supported Action Policy Mutation Whitelist (Bridges to ActionPolicyService)
    public const POLICY_ACTION_EXPENSE_CREATE      = 'expense.create';
    public const POLICY_ACTION_MENU_PRICE_CHANGE   = 'menu.price_change';
    public const POLICY_ACTION_STOCK_ADJUST        = 'stock.adjust';
    public const POLICY_ACTION_TRANSACTION_CANCEL  = 'transaction.cancel';

    public function __construct(
        private readonly FinancialWarningService $warningService,
        private readonly FinancingReadinessService $readinessService,
        private readonly ?CashFlowForecastService $forecastService = null,
        private readonly ?FinancialScenarioService $scenarioService = null
    ) {
    }

    /**
     * Membangun daftar rekomendasi tindakan deterministik terstruktur.
     *
     * @param int $userId Authenticated merchant owner ID
     * @param int|null $outletId Validated outlet context
     * @param array $options Opsi tambahan (pre-evaluated warnings, financing_readiness, purchase_simulation, etc.)
     * @return array Daftar rekomendasi terstruktur
     */
    public function generateRecommendations(int $userId, ?int $outletId = null, array $options = []): array
    {
        // 1. Validasi Kepemilikan Outlet jika outletId diberikan (Tenant Isolation fail-closed)
        if ($outletId !== null) {
            $outletExists = Outlet::where('user_id', $userId)
                ->where('id', $outletId)
                ->exists();

            if (!$outletExists) {
                Log::warning('FinancialActionRecommendationService: Unauthorized or nonexistent outlet', [
                    'user_id'   => $userId,
                    'outlet_id' => $outletId,
                ]);
                return [];
            }
        }

        // 2. Ambil atau evaluasi warning deterministik
        $warnings = $options['warnings'] ?? null;
        if ($warnings === null) {
            try {
                $warnings = $this->warningService->evaluateAllWarnings($userId, $outletId);
            } catch (\Throwable $e) {
                Log::error('FinancialActionRecommendationService: Failed to evaluate warnings', [
                    'error'     => $e->getMessage(),
                    'user_id'   => $userId,
                    'outlet_id' => $outletId,
                ]);
                $warnings = [];
            }
        }

        // 3. Ambil atau evaluasi kesiapan pembiayaan
        $financingReadiness = $options['financing_readiness'] ?? null;
        if ($financingReadiness === null) {
            try {
                $financingReadiness = $this->readinessService->calculate($userId, $outletId);
            } catch (\Throwable $e) {
                Log::error('FinancialActionRecommendationService: Failed to evaluate financing readiness', [
                    'error'     => $e->getMessage(),
                    'user_id'   => $userId,
                    'outlet_id' => $outletId,
                ]);
                $financingReadiness = [];
            }
        }

        $recommendations = [];

        // -------------------------------------------------------------
        // RULE A: EXPENSE SPIKE -> COST_CONTROL_REVIEW
        // -------------------------------------------------------------
        if (!empty($warnings[FinancialWarningService::CODE_EXPENSE_SPIKE])) {
            $recExpense = $this->evaluateExpenseSpike(
                $warnings[FinancialWarningService::CODE_EXPENSE_SPIKE],
                $userId,
                $outletId
            );
            if ($recExpense !== null) {
                $recommendations[] = $recExpense;
            }
        }

        // -------------------------------------------------------------
        // RULE B: CASH / PURCHASE PRESSURE -> CASH_CONTROL_REVIEW
        // -------------------------------------------------------------
        $recCash = $this->evaluateCashPressure($userId, $outletId, $options);
        if ($recCash !== null) {
            $recommendations[] = $recCash;
        }

        // -------------------------------------------------------------
        // RULE C: MARGIN COMPRESSION -> MENU_MARGIN_REVIEW
        // -------------------------------------------------------------
        if (!empty($warnings[FinancialWarningService::CODE_MARGIN_COMPRESSION])) {
            $recMargin = $this->evaluateMarginCompression(
                $warnings[FinancialWarningService::CODE_MARGIN_COMPRESSION],
                $userId,
                $outletId
            );
            if ($recMargin !== null) {
                $recommendations[] = $recMargin;
            }
        }

        // -------------------------------------------------------------
        // RULE D: REVENUE DECLINE -> SALES_PERFORMANCE_REVIEW
        // -------------------------------------------------------------
        if (!empty($warnings[FinancialWarningService::CODE_REVENUE_DECLINE])) {
            $recRevenue = $this->evaluateRevenueDecline(
                $warnings[FinancialWarningService::CODE_REVENUE_DECLINE],
                $userId,
                $outletId
            );
            if ($recRevenue !== null) {
                $recommendations[] = $recRevenue;
            }
        }

        // -------------------------------------------------------------
        // RULE E: DRAWER DISCREPANCY -> CASH_RECONCILIATION_REVIEW
        // -------------------------------------------------------------
        if (!empty($warnings[FinancialWarningService::CODE_DRAWER_DISCREPANCY])) {
            $recDrawer = $this->evaluateDrawerDiscrepancy(
                $warnings[FinancialWarningService::CODE_DRAWER_DISCREPANCY],
                $userId,
                $outletId
            );
            if ($recDrawer !== null) {
                $recommendations[] = $recDrawer;
            }
        }

        // -------------------------------------------------------------
        // RULE F: FINANCING READINESS -> FINANCIAL_RECORD_COMPLETION
        // -------------------------------------------------------------
        $recReadinessList = $this->evaluateFinancingReadiness($financingReadiness, $userId, $outletId);
        foreach ($recReadinessList as $recItem) {
            $recommendations[] = $recItem;
        }

        return $recommendations;
    }

    /**
     * Memeriksa apakah status warning aktif (bukan INSUFFICIENT_DATA, NO_WARNING, atau SUSPENDED).
     */
    private function isWarningActive(array $warning): bool
    {
        $status = $warning['status'] ?? '';
        if ($status === FinancialWarningService::STATUS_INSUFFICIENT_DATA ||
            $status === FinancialWarningService::STATUS_NO_WARNING ||
            $status === FinancialWarningService::STATUS_SUSPENDED ||
            $status === 'INSUFFICIENT_DATA' ||
            $status === 'NO_WARNING' ||
            $status === 'SUSPENDED') {
            return false;
        }

        if ($status === FinancialWarningService::STATUS_WARNING ||
            $status === 'WARNING' ||
            $status === 'ALARM' ||
            $status === 'WATCH') {
            return true;
        }

        $severity = $warning['severity'] ?? '';
        return in_array($severity, [
            FinancialWarningService::SEVERITY_WATCH,
            FinancialWarningService::SEVERITY_WARNING,
            FinancialWarningService::SEVERITY_CRITICAL,
            'warning',
            'danger',
        ], true);
    }

    /**
     * Menyelesaikan tingkat prioritas dari warning.
     */
    private function resolvePriority(array $warning): string
    {
        $severity = strtolower((string) ($warning['severity'] ?? ''));
        $status = strtoupper((string) ($warning['status'] ?? ''));

        if ($severity === 'critical' || $severity === 'danger' || $status === 'ALARM' || $status === 'CRITICAL') {
            return self::PRIORITY_HIGH;
        }
        if ($severity === 'warning' || $status === 'WARNING') {
            return self::PRIORITY_HIGH;
        }
        return self::PRIORITY_MEDIUM;
    }

    /**
     * RULE A: Evaluasi EXPENSE_SPIKE -> COST_CONTROL_REVIEW
     */
    private function evaluateExpenseSpike(array $warning, int $userId, ?int $outletId): ?array
    {
        if (!$this->isWarningActive($warning)) {
            return null; // Abaikan jika INSUFFICIENT_DATA atau NO_WARNING
        }

        $priority = $this->resolvePriority($warning);

        $ratio = $warning['metric']['ratio'] ?? ($warning['evidence']['spike_ratio'] ?? null);
        $ratioText = $ratio !== null ? " ({$ratio}x baseline)" : "";

        return [
            'recommendation_id' => "rec_expense_spike_{$userId}_" . ($outletId ?? 'all'),
            'action_type'       => self::ACTION_COST_CONTROL_REVIEW,
            'priority'          => $priority,
            'what'              => 'Review kategori dan rincian pengeluaran terbesar pada periode lonjakan beban operasional.',
            'why'               => "Lonjakan beban operasional terdeteksi oleh deterministic warning engine{$ratioText}.",
            'evidence'          => [
                'rule'             => FinancialWarningService::CODE_EXPENSE_SPIKE,
                'status'           => $warning['status'] ?? 'WARNING',
                'current_expense'  => $warning['metric']['current_period_expense'] ?? null,
                'baseline_mean'    => $warning['metric']['baseline_mean'] ?? null,
                'spike_ratio'      => $ratio,
                'observation_days' => $warning['evidence']['observation_days'] ?? 7,
                'top_categories'   => $warning['evidence']['top_categories'] ?? [],
            ],
            'expected_impact'   => [
                'description'  => 'Potential cost-control opportunity; impact requires scenario simulation.',
                'quantifiable' => false,
            ],
            'limitations'       => $warning['limitations'] ?? [
                'Data evaluasi berasal dari transaksi beban kasir dan backoffice yang tercatat.',
            ],
            'source'            => [
                'service' => 'FinancialWarningService',
                'rule'    => FinancialWarningService::CODE_EXPENSE_SPIKE,
                'table'   => 'expenses',
            ],
            'proposal_capable'  => false,
            'suggested_action'  => 'Review / Skenario Penghematan',
        ];
    }

    /**
     * RULE B: Evaluasi Cash Pressure / Purchase Simulation -> CASH_CONTROL_REVIEW
     */
    private function evaluateCashPressure(int $userId, ?int $outletId, array $options): ?array
    {
        // 1. Ambil dari pre-computed purchase simulation
        $sim = $options['purchase_simulation'] ?? null;

        // 2. Jika purchase_amount diteruskan eksplisit dan ada scenarioService
        if (!$sim && !empty($options['purchase_amount']) && $this->scenarioService) {
            $purchaseAmount = (float) $options['purchase_amount'];
            if ($purchaseAmount > 0) {
                try {
                    $input = [
                        'amount' => $purchaseAmount,
                        'date'   => $options['purchase_date'] ?? \Carbon\Carbon::now('Asia/Jakarta')->format('Y-m-d'),
                    ];
                    if (isset($options['minimum_buffer'])) {
                        $input['minimum_buffer'] = (float) $options['minimum_buffer'];
                    }
                    $sim = $this->scenarioService->simulatePurchase($userId, $outletId, $input);
                } catch (\Throwable $e) {
                    $sim = null;
                }
            }
        }

        // 3. Cek apakah ada cash_forecast dengan deficit
        if (!$sim && !empty($options['cash_forecast'])) {
            $forecast = $options['cash_forecast'];
            $forecastStatus = $forecast['status'] ?? '';
            if ($forecastStatus === 'DEFICIT' || $forecastStatus === 'BELOW_BUFFER') {
                $sim = [
                    'status'         => $forecastStatus,
                    'current_cash'   => $forecast['summary']['opening_cash'] ?? null,
                    'projected_cash' => $forecast['summary']['projected_ending_cash'] ?? null,
                    'buffer_floor'   => $forecast['summary']['buffer_floor'] ?? null,
                    'buffer_surplus' => $forecast['summary']['buffer_surplus'] ?? null,
                    'limitations'    => $forecast['limitations'] ?? [],
                ];
            }
        }

        if (!$sim) {
            return null;
        }

        $status = $sim['status'] ?? '';
        if ($status !== 'BELOW_BUFFER' && $status !== 'DEFICIT') {
            return null; // Abaikan jika WITHIN_BUFFER atau INSUFFICIENT_DATA
        }

        $priority = ($status === 'DEFICIT')
            ? self::PRIORITY_CRITICAL
            : self::PRIORITY_HIGH;

        $statusLabel = ($status === 'DEFICIT') ? 'Defisit Kas' : 'Di Bawah Safety Buffer';

        return [
            'recommendation_id' => "rec_cash_pressure_{$userId}_" . ($outletId ?? 'all'),
            'action_type'       => self::ACTION_CASH_CONTROL_REVIEW,
            'priority'          => $priority,
            'what'              => 'Review atau tunda pengeluaran discretionary sebelum melakukan pembelian atau pengeluaran modal tambahan.',
            'why'               => "Projected cash berada pada status {$statusLabel} berdasarkan evaluasi arus kas operasional.",
            'evidence'          => [
                'status'         => $status,
                'purchase_amount'=> $sim['purchase_amount'] ?? null,
                'current_cash'   => $sim['current_cash'] ?? null,
                'projected_cash' => $sim['projected_cash_after_purchase'] ?? ($sim['projected_cash'] ?? null),
                'buffer_floor'   => $sim['buffer_floor'] ?? null,
                'buffer_surplus' => $sim['buffer_surplus'] ?? null,
            ],
            'expected_impact'   => [
                'description'  => 'Protects operating cash buffer against liquidity deficit.',
                'quantifiable' => false,
            ],
            'limitations'       => $sim['limitations'] ?? [
                'Simulasi berbasis saldo kas laci POS dan proyeksi inflow historis.',
            ],
            'source'            => [
                'service' => 'FinancialScenarioService',
                'table'   => 'transactions, expenses',
            ],
            'proposal_capable'  => false,
            'suggested_action'  => 'Review Arus Kas & Skenario',
        ];
    }

    /**
     * RULE C: Evaluasi MARGIN_COMPRESSION -> MENU_MARGIN_REVIEW
     */
    private function evaluateMarginCompression(array $warning, int $userId, ?int $outletId): ?array
    {
        if (!$this->isWarningActive($warning)) {
            return null; // Abaikan jika INSUFFICIENT_DATA atau NO_WARNING
        }

        $priority = $this->resolvePriority($warning);

        $drop = $warning['metric']['drop_percentage_points'] ?? null;
        $dropText = $drop !== null ? " ({$drop} percentage points)" : "";

        return [
            'recommendation_id' => "rec_margin_compression_{$userId}_" . ($outletId ?? 'all'),
            'action_type'       => self::ACTION_MENU_MARGIN_REVIEW,
            'priority'          => $priority,
            'what'              => 'Review HPP dan struktur harga jual menu yang mengalami tekanan margin.',
            'why'               => "Gross margin terkompresi{$dropText} berdasarkan deterministic warning engine.",
            'evidence'          => [
                'rule'                   => FinancialWarningService::CODE_MARGIN_COMPRESSION,
                'status'                 => $warning['status'] ?? 'WARNING',
                'current_margin_pct'     => $warning['metric']['current_margin_pct'] ?? null,
                'baseline_margin_pct'    => $warning['metric']['baseline_margin_pct'] ?? null,
                'drop_percentage_points' => $drop,
            ],
            'expected_impact'   => [
                'description'  => 'Identifies margin recovery potential; run scenario simulation for exact impact.',
                'quantifiable' => false,
            ],
            'limitations'       => $warning['limitations'] ?? [
                'Tingkat akurasi bergantung pada kelengkapan input HPP menu pada master catalog.',
            ],
            'source'            => [
                'service' => 'FinancialWarningService',
                'rule'    => FinancialWarningService::CODE_MARGIN_COMPRESSION,
                'table'   => 'transactions, menus, transaction_items',
            ],
            'proposal_capable'  => false,
            'suggested_action'  => 'Review HPP & Skenario Harga',
        ];
    }

    /**
     * RULE D: Evaluasi REVENUE_DECLINE -> SALES_PERFORMANCE_REVIEW
     */
    private function evaluateRevenueDecline(array $warning, int $userId, ?int $outletId): ?array
    {
        if (!$this->isWarningActive($warning)) {
            return null; // Abaikan jika INSUFFICIENT_DATA atau NO_WARNING
        }

        $priority = $this->resolvePriority($warning);

        $drop = $warning['metric']['drop_pct'] ?? null;
        $dropText = $drop !== null ? " (-{$drop}% vs baseline)" : "";

        return [
            'recommendation_id' => "rec_revenue_decline_{$userId}_" . ($outletId ?? 'all'),
            'action_type'       => self::ACTION_SALES_PERFORMANCE_REVIEW,
            'priority'          => $priority,
            'what'              => 'Review menu, kategori produk, dan periode operasional yang berkontribusi pada penurunan omzet.',
            'why'               => "Revenue decline terdeteksi oleh warning engine{$dropText}.",
            'evidence'          => [
                'rule'            => FinancialWarningService::CODE_REVENUE_DECLINE,
                'status'          => $warning['status'] ?? 'WARNING',
                'current_revenue' => $warning['metric']['current_period_revenue'] ?? null,
                'baseline_mean'   => $warning['metric']['baseline_mean'] ?? null,
                'drop_pct'        => $drop,
            ],
            'expected_impact'   => [
                'description'  => 'Identifies sales recovery opportunities; forecast improvement requires operational adjustments.',
                'quantifiable' => false,
            ],
            'limitations'       => $warning['limitations'] ?? [
                'Fluktuasi omzet dapat dipengaruhi oleh faktor eksternal seperti musim, cuaca, atau hari libur.',
            ],
            'source'            => [
                'service' => 'FinancialWarningService',
                'rule'    => FinancialWarningService::CODE_REVENUE_DECLINE,
                'table'   => 'transactions',
            ],
            'proposal_capable'  => false,
            'suggested_action'  => 'Review Penjualan & Promosi',
        ];
    }

    /**
     * RULE E: Evaluasi DRAWER_DISCREPANCY -> CASH_RECONCILIATION_REVIEW
     */
    private function evaluateDrawerDiscrepancy(array $warning, int $userId, ?int $outletId): ?array
    {
        if (!$this->isWarningActive($warning)) {
            return null; // Abaikan jika INSUFFICIENT_DATA atau NO_WARNING
        }

        $amount = $warning['metric']['discrepancy_amount'] ?? null;
        $amountFormatted = $amount !== null ? 'Rp ' . number_format(abs((float) $amount), 0, ',', '.') : 'signifikan';

        return [
            'recommendation_id' => "rec_drawer_discrepancy_{$userId}_" . ($outletId ?? 'all'),
            'action_type'       => self::ACTION_CASH_RECONCILIATION_REVIEW,
            'priority'          => self::PRIORITY_HIGH,
            'what'              => 'Review rekonsiliasi kas fisik dan shift kasir terkait untuk investigasi selisih laci kas.',
            'why'               => "Drawer discrepancy terdeteksi antara kas fisik aktual dengan expected cash sistem ({$amountFormatted}).",
            'evidence'          => [
                'rule'               => FinancialWarningService::CODE_DRAWER_DISCREPANCY,
                'status'             => $warning['status'] ?? 'WARNING',
                'discrepancy_amount' => $amount,
                'shift_id'           => $warning['evidence']['shift_id'] ?? null,
                'cashier_name'       => $warning['evidence']['cashier_name'] ?? null,
            ],
            'expected_impact'   => [
                'description'  => 'Prevents cash leakage and improves audit trail accuracy.',
                'quantifiable' => false,
            ],
            'limitations'       => $warning['limitations'] ?? [
                'Hanya mencakup shift yang telah diselesaikan (closed shifts).',
            ],
            'source'            => [
                'service' => 'FinancialWarningService',
                'rule'    => FinancialWarningService::CODE_DRAWER_DISCREPANCY,
                'table'   => 'shifts',
            ],
            'proposal_capable'  => false,
            'suggested_action'  => 'Audit Log Kasir & Shift',
        ];
    }

    /**
     * RULE F: Evaluasi Financing Readiness Incomplete Evidence -> FINANCIAL_RECORD_COMPLETION
     */
    private function evaluateFinancingReadiness(array $financingReadiness, int $userId, ?int $outletId): array
    {
        $recommendations = [];
        $evidenceSummary = $financingReadiness['evidence_summary'] ?? [];

        foreach ($evidenceSummary as $key => $item) {
            $status = $item['status'] ?? '';
            if ($status !== 'INSUFFICIENT' && $status !== 'PARTIAL') {
                continue;
            }

            $pillarKey = $item['key'] ?? (is_string($key) ? $key : 'pillar_' . $key);
            $name = $item['name'] ?? ($item['label'] ?? ucwords(str_replace('_', ' ', (string) $pillarKey)));
            $priority = ($status === 'INSUFFICIENT') ? self::PRIORITY_MEDIUM : self::PRIORITY_LOW;

            $recommendations[] = [
                'recommendation_id' => "rec_fr_{$pillarKey}_{$userId}_" . ($outletId ?? 'all'),
                'action_type'       => self::ACTION_FINANCIAL_RECORD_COMPLETION,
                'priority'          => $priority,
                'what'              => "Lengkapi bukti pembukuan dan catatan operasional untuk pilar: {$name}.",
                'why'               => "Financing readiness mendeteksi status {$status} pada pilar pembukuan {$name}.",
                'evidence'          => [
                    'pillar'       => $pillarKey,
                    'status'       => $status,
                    'record_count' => $item['record_count'] ?? 0,
                    'summary'      => $item['summary'] ?? '',
                ],
                'expected_impact'   => [
                    'description'  => 'Improves completeness of financial evidence.',
                    'quantifiable' => false,
                ],
                'limitations'       => [
                    'Bukan jaminan persetujuan kredit perbankan.',
                    'Hanya mengukur kelengkapan pencatatan operasional.',
                ],
                'source'            => [
                    'service' => 'FinancingReadinessService',
                    'pillar'  => $key,
                    'source'  => $item['source'] ?? 'Tabel POS',
                ],
                'proposal_capable'  => false,
                'suggested_action'  => 'Lengkapi Data Operasional',
            ];
        }

        return $recommendations;
    }

    /**
     * PROPOSAL BRIDGE: Membuat transient Action Proposal via ActionPolicyService
     *
     * HANYA dipanggil jika terdapat otorisasi pengguna dan aksi bisnis yang valid.
     * ZERO DATABASE MUTATION: proposal disimpan sementara pada Cache (TTL 15 menit).
     */
    public function createProposalFromRecommendation(
        User $user,
        int $outletId,
        string $action,
        array $parameters,
        string $rationale = ''
    ): array {
        // Validasi outlet ownership (fail-closed)
        $outletExists = Outlet::where('user_id', $user->id)->where('id', $outletId)->exists();
        if (!$outletExists) {
            return [
                'proposal_id' => null,
                'decision'    => ActionPolicyService::DECISION_DENY,
                'action'      => $action,
                'risk_level'  => ActionPolicyService::RISK_HIGH,
                'reason'      => "Outlet ID {$outletId} tidak valid atau bukan milik merchant ini.",
                'expires_at'  => null,
                'parameters'  => [],
            ];
        }

        /** @var ActionPolicyService $policyService */
        $policyService = app(ActionPolicyService::class);

        return $policyService->evaluateProposal(
            $user->id,
            $outletId,
            $action,
            $parameters
        );
    }
}
