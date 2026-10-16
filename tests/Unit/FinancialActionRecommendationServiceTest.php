<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Expense;
use App\Models\Menu;
use App\Models\Transaction;
use App\Services\Financial\FinancialActionRecommendationService;
use App\Services\Financial\FinancialWarningService;
use App\Services\Financial\FinancingReadinessService;
use App\Services\Financial\FinancialScenarioService;
use App\Services\Policy\ActionPolicyService;

class FinancialActionRecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Outlet $outlet;
    private FinancialActionRecommendationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'pro',
        ]);

        $this->outlet = Outlet::create([
            'user_id' => $this->user->id,
            'name'    => 'Cabang Utama',
            'address' => 'Jl. Sudirman No. 1',
            'phone'   => '081234567890',
        ]);

        $this->service = app(FinancialActionRecommendationService::class);
    }

    /**
     * TEST 1: EXPENSE_SPIKE -> recommendation
     */
    public function test_1_expense_spike_generates_cost_control_recommendation(): void
    {
        $mockWarnings = [
            FinancialWarningService::CODE_EXPENSE_SPIKE => [
                'status'   => 'WARNING',
                'severity' => 'danger',
                'metric'   => [
                    'current_period_expense' => 5000000.0,
                    'baseline_mean'          => 2000000.0,
                    'ratio'                  => 2.5,
                ],
                'evidence' => [
                    'spike_ratio'      => 2.5,
                    'observation_days' => 7,
                    'top_categories'   => [['category' => 'Bahan Baku', 'total' => 3500000.0]],
                ],
                'limitations' => ['Evaluasi beban operasional tercatat.'],
            ],
        ];

        $recs = $this->service->generateRecommendations($this->user->id, $this->outlet->id, [
            'warnings' => $mockWarnings,
        ]);

        $this->assertNotEmpty($recs);
        $costRec = collect($recs)->firstWhere('action_type', FinancialActionRecommendationService::ACTION_COST_CONTROL_REVIEW);
        $this->assertNotNull($costRec);
        $this->assertSame(FinancialActionRecommendationService::PRIORITY_HIGH, $costRec['priority']);
        $this->assertFalse($costRec['proposal_capable']);
        $this->assertEquals(2.5, $costRec['evidence']['spike_ratio']);
    }

    /**
     * TEST 2: REVENUE_DECLINE -> recommendation
     */
    public function test_2_revenue_decline_generates_sales_performance_recommendation(): void
    {
        $mockWarnings = [
            FinancialWarningService::CODE_REVENUE_DECLINE => [
                'status'   => 'WARNING',
                'severity' => 'warning',
                'metric'   => [
                    'current_period_revenue' => 8000000.0,
                    'baseline_mean'          => 12000000.0,
                    'drop_pct'               => 33.3,
                ],
                'evidence' => ['drop_percentage' => 33.3],
                'limitations' => ['Tren dipengaruhi hari libur.'],
            ],
        ];

        $recs = $this->service->generateRecommendations($this->user->id, $this->outlet->id, [
            'warnings' => $mockWarnings,
        ]);

        $revRec = collect($recs)->firstWhere('action_type', FinancialActionRecommendationService::ACTION_SALES_PERFORMANCE_REVIEW);
        $this->assertNotNull($revRec);
        $this->assertSame(FinancialActionRecommendationService::PRIORITY_HIGH, $revRec['priority']);
        $this->assertFalse($revRec['proposal_capable']);
        $this->assertEquals(33.3, $revRec['evidence']['drop_pct']);
    }

    /**
     * TEST 3: MARGIN_COMPRESSION -> recommendation
     */
    public function test_3_margin_compression_generates_menu_margin_recommendation(): void
    {
        $mockWarnings = [
            FinancialWarningService::CODE_MARGIN_COMPRESSION => [
                'status'   => 'WARNING',
                'severity' => 'danger',
                'metric'   => [
                    'current_margin_pct'     => 42.0,
                    'baseline_margin_pct'    => 55.0,
                    'drop_percentage_points' => 13.0,
                ],
                'evidence' => [],
                'limitations' => ['HPP menu harus akurat.'],
            ],
        ];

        $recs = $this->service->generateRecommendations($this->user->id, $this->outlet->id, [
            'warnings' => $mockWarnings,
        ]);

        $marginRec = collect($recs)->firstWhere('action_type', FinancialActionRecommendationService::ACTION_MENU_MARGIN_REVIEW);
        $this->assertNotNull($marginRec);
        $this->assertSame(FinancialActionRecommendationService::PRIORITY_HIGH, $marginRec['priority']);
        $this->assertFalse($marginRec['proposal_capable']);
        $this->assertEquals(13.0, $marginRec['evidence']['drop_percentage_points']);
    }

    /**
     * TEST 4: DRAWER_DISCREPANCY -> recommendation
     */
    public function test_4_drawer_discrepancy_generates_cash_reconciliation_recommendation(): void
    {
        $mockWarnings = [
            FinancialWarningService::CODE_DRAWER_DISCREPANCY => [
                'status'   => 'WARNING',
                'severity' => 'warning',
                'metric'   => [
                    'discrepancy_amount' => -150000.0,
                ],
                'evidence' => [
                    'shift_id'     => 101,
                    'cashier_name' => 'Budi',
                ],
                'limitations' => ['Hanya shift closed.'],
            ],
        ];

        $recs = $this->service->generateRecommendations($this->user->id, $this->outlet->id, [
            'warnings' => $mockWarnings,
        ]);

        $drawerRec = collect($recs)->firstWhere('action_type', FinancialActionRecommendationService::ACTION_CASH_RECONCILIATION_REVIEW);
        $this->assertNotNull($drawerRec);
        $this->assertSame(FinancialActionRecommendationService::PRIORITY_HIGH, $drawerRec['priority']);
        $this->assertEquals(-150000.0, $drawerRec['evidence']['discrepancy_amount']);
        $this->assertEquals(101, $drawerRec['evidence']['shift_id']);
    }

    /**
     * TEST 5: CASH DEFICIT -> recommendation
     */
    public function test_5_cash_deficit_generates_cash_control_recommendation(): void
    {
        $mockSimulation = [
            'status'                        => 'DEFICIT',
            'purchase_amount'               => 15000000.0,
            'current_cash'                  => 5000000.0,
            'projected_cash_after_purchase' => -10000000.0,
            'buffer_floor'                  => 2000000.0,
            'buffer_surplus'                => -12000000.0,
            'limitations'                   => ['Asumsi kas laci.'],
        ];

        $recs = $this->service->generateRecommendations($this->user->id, $this->outlet->id, [
            'purchase_simulation' => $mockSimulation,
        ]);

        $cashRec = collect($recs)->firstWhere('action_type', FinancialActionRecommendationService::ACTION_CASH_CONTROL_REVIEW);
        $this->assertNotNull($cashRec);
        $this->assertSame(FinancialActionRecommendationService::PRIORITY_CRITICAL, $cashRec['priority']);
        $this->assertEquals('DEFICIT', $cashRec['evidence']['status']);
        $this->assertEquals(-10000000.0, $cashRec['evidence']['projected_cash']);
    }

    /**
     * TEST 6: Financing evidence incomplete -> recommendation
     */
    public function test_6_incomplete_financing_evidence_generates_completion_recommendations(): void
    {
        $mockReadiness = [
            'status'           => 'ASSESSED',
            'score'            => 45,
            'band'             => 'DEVELOPING',
            'evidence_summary' => [
                'cogs_completeness' => [
                    'name'         => 'Kelengkapan HPP Menu',
                    'status'       => 'INSUFFICIENT',
                    'record_count' => 0,
                    'summary'      => 'HPP belum terisi.',
                    'source'       => 'Master Menu',
                ],
                'expense_tracking' => [
                    'name'         => 'Pencatatan Beban Operasional',
                    'status'       => 'PARTIAL',
                    'record_count' => 4,
                    'summary'      => 'Beban tercatat baru 4 transaksi.',
                    'source'       => 'Tabel Beban',
                ],
                'sales_history' => [
                    'name'         => 'Riwayat Penjualan',
                    'status'       => 'AVAILABLE',
                    'record_count' => 500,
                    'summary'      => 'Data penjualan memadai.',
                    'source'       => 'Tabel Transaksi',
                ],
            ],
        ];

        $recs = $this->service->generateRecommendations($this->user->id, $this->outlet->id, [
            'financing_readiness' => $mockReadiness,
        ]);

        $frRecs = collect($recs)->where('action_type', FinancialActionRecommendationService::ACTION_FINANCIAL_RECORD_COMPLETION);
        $this->assertCount(2, $frRecs);

        $cogsRec = $frRecs->firstWhere('evidence.pillar', 'cogs_completeness');
        $this->assertNotNull($cogsRec);
        $this->assertSame(FinancialActionRecommendationService::PRIORITY_MEDIUM, $cogsRec['priority']);

        $expenseRec = $frRecs->firstWhere('evidence.pillar', 'expense_tracking');
        $this->assertNotNull($expenseRec);
        $this->assertSame(FinancialActionRecommendationService::PRIORITY_LOW, $expenseRec['priority']);

        $this->assertNull($frRecs->firstWhere('evidence.pillar', 'sales_history'));
    }

    /**
     * TEST 7: INSUFFICIENT_DATA -> no false recommendation
     */
    public function test_7_insufficient_data_does_not_generate_false_recommendations(): void
    {
        $mockWarnings = [
            FinancialWarningService::CODE_EXPENSE_SPIKE => [
                'status'   => 'INSUFFICIENT_DATA',
                'severity' => 'info',
                'metric'   => ['current_period_expense' => 0],
                'evidence' => [],
            ],
            FinancialWarningService::CODE_REVENUE_DECLINE => [
                'status'   => 'NO_WARNING',
                'severity' => 'none',
                'metric'   => [],
                'evidence' => [],
            ],
            FinancialWarningService::CODE_MARGIN_COMPRESSION => [
                'status'   => 'SUSPENDED',
                'severity' => 'none',
                'metric'   => [],
                'evidence' => [],
            ],
        ];

        $recs = $this->service->generateRecommendations($this->user->id, $this->outlet->id, [
            'warnings'            => $mockWarnings,
            'financing_readiness' => ['evidence_summary' => []],
        ]);

        $this->assertEmpty($recs);
    }

    /**
     * TEST 8: recommendation contains WHAT
     */
    public function test_8_recommendation_contains_what(): void
    {
        $mockWarnings = [
            FinancialWarningService::CODE_EXPENSE_SPIKE => [
                'status'   => 'WARNING',
                'severity' => 'danger',
                'metric'   => ['current_period_expense' => 7000000.0, 'baseline_mean' => 3000000.0, 'ratio' => 2.33],
                'evidence' => ['spike_ratio' => 2.33],
                'limitations' => ['Beban kasir saja.'],
            ],
        ];

        $recs = $this->service->generateRecommendations($this->user->id, $this->outlet->id, ['warnings' => $mockWarnings]);
        $this->assertNotEmpty($recs);
        $this->assertArrayHasKey('what', $recs[0]);
        $this->assertNotEmpty($recs[0]['what']);
    }

    /**
     * TEST 9: recommendation contains WHY
     */
    public function test_9_recommendation_contains_why(): void
    {
        $mockWarnings = [
            FinancialWarningService::CODE_EXPENSE_SPIKE => [
                'status'   => 'WARNING',
                'severity' => 'danger',
                'metric'   => ['current_period_expense' => 7000000.0, 'baseline_mean' => 3000000.0, 'ratio' => 2.33],
                'evidence' => ['spike_ratio' => 2.33],
            ],
        ];

        $recs = $this->service->generateRecommendations($this->user->id, $this->outlet->id, ['warnings' => $mockWarnings]);
        $this->assertNotEmpty($recs);
        $this->assertArrayHasKey('why', $recs[0]);
        $this->assertStringContainsString('Lonjakan beban', $recs[0]['why']);
    }

    /**
     * TEST 10: recommendation contains EVIDENCE
     */
    public function test_10_recommendation_contains_evidence(): void
    {
        $mockWarnings = [
            FinancialWarningService::CODE_EXPENSE_SPIKE => [
                'status'   => 'WARNING',
                'severity' => 'danger',
                'metric'   => ['current_period_expense' => 7000000.0, 'baseline_mean' => 3000000.0, 'ratio' => 2.33],
                'evidence' => ['spike_ratio' => 2.33],
            ],
        ];

        $recs = $this->service->generateRecommendations($this->user->id, $this->outlet->id, ['warnings' => $mockWarnings]);
        $this->assertNotEmpty($recs);
        $this->assertArrayHasKey('evidence', $recs[0]);
        $this->assertIsArray($recs[0]['evidence']);
        $this->assertEquals(2.33, $recs[0]['evidence']['spike_ratio']);
    }

    /**
     * TEST 11: recommendation contains EXPECTED_IMPACT
     */
    public function test_11_recommendation_contains_expected_impact(): void
    {
        $mockWarnings = [
            FinancialWarningService::CODE_EXPENSE_SPIKE => [
                'status'   => 'WARNING',
                'severity' => 'danger',
                'metric'   => ['current_period_expense' => 7000000.0, 'baseline_mean' => 3000000.0, 'ratio' => 2.33],
                'evidence' => ['spike_ratio' => 2.33],
            ],
        ];

        $recs = $this->service->generateRecommendations($this->user->id, $this->outlet->id, ['warnings' => $mockWarnings]);
        $this->assertNotEmpty($recs);
        $this->assertArrayHasKey('expected_impact', $recs[0]);
        $this->assertIsArray($recs[0]['expected_impact']);
        $this->assertArrayHasKey('description', $recs[0]['expected_impact']);
        $this->assertFalse($recs[0]['expected_impact']['quantifiable']);
    }

    /**
     * TEST 12: recommendation contains LIMITATIONS
     */
    public function test_12_recommendation_contains_limitations(): void
    {
        $mockWarnings = [
            FinancialWarningService::CODE_EXPENSE_SPIKE => [
                'status'      => 'WARNING',
                'severity'    => 'danger',
                'metric'      => ['ratio' => 2.0],
                'evidence'    => [],
                'limitations' => ['Beban kasir saja.'],
            ],
        ];

        $recs = $this->service->generateRecommendations($this->user->id, $this->outlet->id, ['warnings' => $mockWarnings]);
        $this->assertNotEmpty($recs);
        $this->assertArrayHasKey('limitations', $recs[0]);
        $this->assertIsArray($recs[0]['limitations']);
        $this->assertNotEmpty($recs[0]['limitations']);
    }

    /**
     * TEST 13: recommendation contains SOURCE
     */
    public function test_13_recommendation_contains_source(): void
    {
        $mockWarnings = [
            FinancialWarningService::CODE_EXPENSE_SPIKE => [
                'status'   => 'WARNING',
                'severity' => 'danger',
                'metric'   => ['ratio' => 2.0],
                'evidence' => [],
            ],
        ];

        $recs = $this->service->generateRecommendations($this->user->id, $this->outlet->id, ['warnings' => $mockWarnings]);
        $this->assertNotEmpty($recs);
        $this->assertArrayHasKey('source', $recs[0]);
        $this->assertEquals('FinancialWarningService', $recs[0]['source']['service']);
        $this->assertEquals('expenses', $recs[0]['source']['table']);
    }

    /**
     * TEST 14: Deterministic output (Multiple runs with same inputs return identical output)
     */
    public function test_14_recommendation_output_is_strictly_deterministic(): void
    {
        $mockWarnings = [
            FinancialWarningService::CODE_REVENUE_DECLINE => [
                'status'   => 'WARNING',
                'severity' => 'warning',
                'metric'   => ['current_period_revenue' => 1000000, 'baseline_mean' => 2000000, 'drop_pct' => 50.0],
                'evidence' => ['drop_percentage' => 50.0],
            ],
        ];

        $run1 = $this->service->generateRecommendations($this->user->id, $this->outlet->id, ['warnings' => $mockWarnings]);
        $run2 = $this->service->generateRecommendations($this->user->id, $this->outlet->id, ['warnings' => $mockWarnings]);

        $this->assertEquals($run1, $run2);
    }

    /**
     * TEST 15: No database mutation (DB record count remains unchanged before & after)
     */
    public function test_15_recommendation_generation_causes_zero_database_mutations(): void
    {
        $userCountBefore = User::count();
        $outletCountBefore = Outlet::count();
        $expenseCountBefore = Expense::count();
        $menuCountBefore = Menu::count();
        $trxCountBefore = Transaction::count();

        $mockWarnings = [
            FinancialWarningService::CODE_EXPENSE_SPIKE => [
                'status'   => 'WARNING',
                'severity' => 'danger',
                'metric'   => ['current_period_expense' => 1000000, 'baseline_mean' => 500000, 'ratio' => 2.0],
                'evidence' => ['spike_ratio' => 2.0],
            ],
        ];

        $this->service->generateRecommendations($this->user->id, $this->outlet->id, ['warnings' => $mockWarnings]);

        $this->assertSame($userCountBefore, User::count());
        $this->assertSame($outletCountBefore, Outlet::count());
        $this->assertSame($expenseCountBefore, Expense::count());
        $this->assertSame($menuCountBefore, Menu::count());
        $this->assertSame($trxCountBefore, Transaction::count());
    }

    /**
     * TEST 16: Tenant isolation (Scoped to user and outlet context)
     */
    public function test_16_tenant_isolation_is_strictly_enforced(): void
    {
        $otherUser = User::factory()->create();
        $otherOutlet = Outlet::create([
            'user_id' => $otherUser->id,
            'name'    => 'Cabang Lain',
            'address' => 'Jl. Lain',
            'phone'   => '08999999999',
        ]);

        $mockWarnings = [
            FinancialWarningService::CODE_EXPENSE_SPIKE => [
                'status'   => 'WARNING',
                'severity' => 'warning',
                'metric'   => ['ratio' => 1.8],
                'evidence' => ['spike_ratio' => 1.8],
            ],
        ];

        $user1Recs = $this->service->generateRecommendations($this->user->id, $this->outlet->id, ['warnings' => $mockWarnings]);
        $user2Recs = $this->service->generateRecommendations($otherUser->id, $otherOutlet->id, ['warnings' => $mockWarnings]);

        $this->assertNotEmpty($user1Recs);
        $this->assertNotEmpty($user2Recs);
        $this->assertNotSame($user1Recs[0]['recommendation_id'], $user2Recs[0]['recommendation_id']);
        $this->assertStringContainsString((string) $this->user->id, $user1Recs[0]['recommendation_id']);
        $this->assertStringContainsString((string) $otherUser->id, $user2Recs[0]['recommendation_id']);
    }

    /**
     * TEST 17: No arbitrary user_id (Mismatch outlet ownership returns empty array)
     */
    public function test_17_unauthorized_or_cross_tenant_outlet_returns_empty_recommendations(): void
    {
        $attacker = User::factory()->create();

        $mockWarnings = [
            FinancialWarningService::CODE_EXPENSE_SPIKE => [
                'status'   => 'WARNING',
                'severity' => 'danger',
                'metric'   => ['ratio' => 3.0],
                'evidence' => ['spike_ratio' => 3.0],
            ],
        ];

        // Attacker attempts to query $this->outlet->id which belongs to $this->user
        $result = $this->service->generateRecommendations($attacker->id, $this->outlet->id, ['warnings' => $mockWarnings]);

        $this->assertEmpty($result);
    }

    /**
     * TEST 18: No fabricated financial values
     */
    public function test_18_no_fabricated_financial_values(): void
    {
        $mockWarnings = [
            FinancialWarningService::CODE_EXPENSE_SPIKE => [
                'status'   => 'WARNING',
                'severity' => 'warning',
                'metric'   => [
                    'current_period_expense' => 9876543.0,
                    'baseline_mean'          => 4567890.0,
                    'ratio'                  => 2.16,
                ],
                'evidence' => ['spike_ratio' => 2.16],
            ],
        ];

        $recs = $this->service->generateRecommendations($this->user->id, $this->outlet->id, ['warnings' => $mockWarnings]);

        $item = $recs[0];
        $this->assertSame(9876543.0, $item['evidence']['current_expense']);
        $this->assertSame(4567890.0, $item['evidence']['baseline_mean']);
        $this->assertSame(2.16, $item['evidence']['spike_ratio']);
        $this->assertFalse($item['expected_impact']['quantifiable']);
        $this->assertStringContainsString('impact requires scenario simulation', $item['expected_impact']['description']);
    }

    /**
     * TEST 19: Valid policy action -> transient proposal
     */
    public function test_19_valid_policy_action_creates_transient_cache_proposal(): void
    {
        $result = $this->service->createProposalFromRecommendation(
            $this->user,
            $this->outlet->id,
            FinancialActionRecommendationService::POLICY_ACTION_EXPENSE_CREATE,
            [
                'name'   => 'Beli Bahan Baku Tambahan',
                'amount' => 750000,
            ],
            'Mitigasi kekurangan stok'
        );

        $this->assertNotNull($result['proposal_id']);
        $this->assertSame(ActionPolicyService::DECISION_REQUIRES_APPROVAL, $result['decision']);
        $this->assertSame(FinancialActionRecommendationService::POLICY_ACTION_EXPENSE_CREATE, $result['action']);

        // Verify stored in Cache transiently
        $cached = Cache::get(ActionPolicyService::CACHE_PREFIX . $result['proposal_id']);
        $this->assertNotNull($cached);
        $this->assertSame($this->user->id, $cached['user_id']);
        $this->assertSame($this->outlet->id, $cached['outlet_id']);
    }

    /**
     * TEST 20: Invalid action -> rejected
     */
    public function test_20_invalid_policy_action_is_rejected(): void
    {
        $result = $this->service->createProposalFromRecommendation(
            $this->user,
            $this->outlet->id,
            'arbitrary.dangerous_mutation',
            ['foo' => 'bar'],
            'Test'
        );

        $this->assertNull($result['proposal_id']);
        $this->assertSame(ActionPolicyService::DECISION_UNSUPPORTED, $result['decision']);
    }

    /**
     * TEST 21: Proposal does not mutate DB
     */
    public function test_21_proposal_creation_causes_zero_db_mutations(): void
    {
        $expenseCountBefore = Expense::count();
        $userCountBefore = User::count();

        $this->service->createProposalFromRecommendation(
            $this->user,
            $this->outlet->id,
            FinancialActionRecommendationService::POLICY_ACTION_EXPENSE_CREATE,
            [
                'name'   => 'Beban Listrik',
                'amount' => 500000,
            ],
            'Test proposal zero mutation'
        );

        $this->assertSame($expenseCountBefore, Expense::count());
        $this->assertSame($userCountBefore, User::count());
    }

    /**
     * TEST 22: Proposal respects tenant isolation
     */
    public function test_22_proposal_creation_respects_tenant_isolation(): void
    {
        $attacker = User::factory()->create();

        // Attacker attempts to propose action for $this->outlet which belongs to $this->user
        $result = $this->service->createProposalFromRecommendation(
            $attacker,
            $this->outlet->id,
            FinancialActionRecommendationService::POLICY_ACTION_EXPENSE_CREATE,
            [
                'name'   => 'Hack Expense',
                'amount' => 100000,
            ]
        );

        $this->assertNull($result['proposal_id']);
        $this->assertSame(ActionPolicyService::DECISION_DENY, $result['decision']);
        $this->assertStringContainsString('bukan milik', $result['reason']);
    }
}
