<?php

namespace App\Services\Financial;

use App\Helpers\TimezoneContract;
use App\Models\Outlet;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * FinancialScenarioService
 *
 * Deterministic scenario simulator engine for Kaching Copilot.
 * Calculates what-if scenarios (price changes, cost changes, volume/revenue changes,
 * expense changes, and combined scenarios) against historical baseline metrics.
 *
 * STRICT INVARIANTS:
 * 1. LLM IS NOT A CALCULATOR — all arithmetic is performed deterministically here.
 * 2. 100% READ-ONLY — zero database mutations (INSERT/UPDATE/DELETE).
 * 3. SSOT REUSE — FinancialCalculationService is the single source of truth for baselines.
 * 4. FAIL CLOSED — untrusted inputs, unauthorized outlets, or out-of-bounds parameters abort immediately.
 */
class FinancialScenarioService
{
    public const DEFAULT_BASELINE_PERIOD = '30_DAYS';

    public const ALLOWED_SCENARIO_TYPES = [
        'PRICE_CHANGE',
        'COST_CHANGE',
        'REVENUE_CHANGE',
        'EXPENSE_CHANGE',
        'COMBINED_SCENARIO',
        'COMBINED', // Alias for COMBINED_SCENARIO
        'FINANCING_CAPACITY',
    ];

    public const FORBIDDEN_INPUT_KEYS = [
        'user_id',
        'outlet_id',
        'tenant_id',
        'sql',
        'query',
        'raw',
        'formula',
        'threshold',
        'where',
        'select',
    ];

    protected FinancialCalculationService $calcService;
    protected CashFlowForecastService $forecastService;
    protected FinancingCapacitySimulatorService $financingSimulator;

    public function __construct(
        ?FinancialCalculationService $calcService = null,
        ?CashFlowForecastService $forecastService = null,
        ?FinancingCapacitySimulatorService $financingSimulator = null
    ) {
        $this->calcService = $calcService ?? app(FinancialCalculationService::class);
        $this->forecastService = $forecastService ?? app(CashFlowForecastService::class);
        $this->financingSimulator = $financingSimulator ?? app(FinancingCapacitySimulatorService::class);
    }

    /**
     * Run deterministic financial scenario simulation.
     *
     * @param int $userId Authenticated tenant ID (server-derived SSOT)
     * @param int|null $outletId Outlet ID (validated against ownership if non-null)
     * @param array $scenarioInput Untrusted scenario parameters from LLM/client
     * @param string|null $customStartDate Optional test injection for baseline start date
     * @param string|null $customEndDate Optional test injection for baseline end date
     * @return array Structured simulation result
     */
    public function simulate(
        int $userId,
        ?int $outletId,
        array $scenarioInput,
        ?string $customStartDate = null,
        ?string $customEndDate = null
    ): array {
        // 1. Tenant & Authentication Gate
        if ($userId <= 0) {
            return [
                'status' => 'ERROR',
                'code' => 'UNAUTHENTICATED',
                'message' => 'Konteks autentikasi tenant tidak valid.',
            ];
        }

        // 2. Outlet Ownership Validation (Fail-closed)
        if ($outletId !== null) {
            $outletExists = Outlet::where('user_id', $userId)->where('id', $outletId)->exists();
            if (!$outletExists) {
                return [
                    'status' => 'ERROR',
                    'code' => 'UNAUTHORIZED_OUTLET',
                    'message' => 'Outlet tidak ditemukan atau bukan milik akun ini.',
                ];
            }
        }

        // 3. Security & Forbidden Keys Inspection
        foreach (self::FORBIDDEN_INPUT_KEYS as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $scenarioInput)) {
                return [
                    'status' => 'ERROR',
                    'code' => 'FORBIDDEN_PARAMETER',
                    'message' => "Parameter '{$forbiddenKey}' dilarang dalam input scenario.",
                ];
            }
        }

        // 4. Scenario Type Validation
        $scenarioType = $scenarioInput['scenario_type'] ?? null;
        if (!is_string($scenarioType) || !in_array($scenarioType, self::ALLOWED_SCENARIO_TYPES, true)) {
            return [
                'status' => 'ERROR',
                'code' => 'INVALID_SCENARIO_TYPE',
                'message' => 'Tipe scenario tidak dikenali. Tipe yang didukung: PRICE_CHANGE, COST_CHANGE, REVENUE_CHANGE, EXPENSE_CHANGE, COMBINED_SCENARIO.',
            ];
        }

        if ($scenarioType === 'COMBINED') {
            $scenarioType = 'COMBINED_SCENARIO';
        }

        // 5. Input Schema & Parameter Bounds Validation
        $validationResult = $this->validateScenarioParameters($scenarioType, $scenarioInput);
        if ($validationResult !== null) {
            return $validationResult;
        }

        if ($scenarioType === 'FINANCING_CAPACITY') {
            return $this->simulateFinancingCapacity($userId, $outletId, $scenarioInput);
        }

        // 6. Resolve Baseline Period & Timezone
        $tz = $outletId !== null ? TimezoneContract::resolveOutletTimezone($outletId) : 'Asia/Jakarta';
        $periodType = $scenarioInput['baseline_period'] ?? self::DEFAULT_BASELINE_PERIOD;

        if ($customStartDate !== null && $customEndDate !== null) {
            $startDate = $customStartDate;
            $endDate = $customEndDate;
        } else {
            $now = Carbon::now($tz);
            $endDate = $now->format('Y-m-d');
            if ($periodType === '7_DAYS' || $periodType === '7_days') {
                $startDate = $now->copy()->subDays(6)->format('Y-m-d');
                $periodType = '7_DAYS';
            } else {
                $startDate = $now->copy()->subDays(29)->format('Y-m-d');
                $periodType = '30_DAYS';
            }
        }

        // 7. Load Historical Baseline via Phase A SSOT Engine
        try {
            $snapshot = $this->calcService->computeSnapshot($userId, $outletId, $startDate, $endDate, $tz);
        } catch (\Throwable $e) {
            Log::error('FinancialScenarioService: Failed to compute baseline snapshot', [
                'user_id' => $userId,
                'outlet_id' => $outletId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'ERROR',
                'code' => 'BASELINE_CALCULATION_FAILED',
                'message' => 'Gagal memuat metrik baseline finansial.',
            ];
        }

        $baseRevenue = (float) ($snapshot['revenue']['value'] ?? 0.0);
        $baseCogs = (float) ($snapshot['cogs']['value'] ?? 0.0);
        $baseGrossProfit = (float) ($snapshot['gross_profit']['value'] ?? 0.0);
        $baseExpenses = (float) ($snapshot['expenses']['value'] ?? 0.0);
        $baseNetProfit = (float) ($snapshot['net_profit']['value'] ?? 0.0);
        $baseGrossMargin = $baseRevenue > 0 ? round(($baseGrossProfit / $baseRevenue) * 100.0, 2) : null;
        $baseNetMargin = $baseRevenue > 0 ? round(($baseNetProfit / $baseRevenue) * 100.0, 2) : null;

        $revenueRecordCount = (int) ($snapshot['revenue']['records_count'] ?? 0);
        $expenseRecordCount = (int) ($snapshot['expenses']['records_count'] ?? 0);
        $hasUntrackedCogs = (bool) ($snapshot['cogs']['has_untracked_cogs'] ?? false);
        $untrackedItemsPct = (float) ($snapshot['cogs']['untracked_items_percentage'] ?? 0.0);

        // 8. Baseline Data Sufficiency Check
        if ($baseRevenue <= 0.0 && $baseExpenses <= 0.0 && $revenueRecordCount === 0 && $expenseRecordCount === 0) {
            return [
                'status' => 'ERROR',
                'code' => 'INSUFFICIENT_DATA',
                'message' => 'Data transaksi dan pengeluaran pada periode baseline tidak mencukupi untuk simulasi (data kosong).',
                'limitations' => [
                    'Tidak ditemukan data transaksi maupun pengeluaran pada periode baseline.',
                ],
            ];
        }

        // 9. Execute Deterministic Scenario Transformation
        return $this->executeSimulation(
            $scenarioType,
            $scenarioInput,
            $baseRevenue,
            $baseCogs,
            $baseGrossProfit,
            $baseExpenses,
            $baseNetProfit,
            $baseGrossMargin,
            $baseNetMargin,
            $startDate,
            $endDate,
            $tz,
            $periodType,
            $hasUntrackedCogs,
            $untrackedItemsPct
        );
    }

    /**
     * Validate scenario parameters strictly against bounds and types.
     */
    protected function validateScenarioParameters(string $scenarioType, array $input): ?array
    {
        switch ($scenarioType) {
            case 'PRICE_CHANGE':
                if (!isset($input['price_change_percent'])) {
                    return [
                        'status' => 'ERROR',
                        'code' => 'MISSING_REQUIRED_PARAMETER',
                        'message' => "Parameter 'price_change_percent' wajib diisi untuk skenario PRICE_CHANGE.",
                    ];
                }
                $val = $input['price_change_percent'];
                if (!is_numeric($val) || is_nan($val) || is_infinite($val)) {
                    return [
                        'status' => 'ERROR',
                        'code' => 'INVALID_PARAMETER_VALUE',
                        'message' => "Parameter 'price_change_percent' harus berupa angka numerik.",
                    ];
                }
                $num = (float) $val;
                if ($num < -50.0 || $num > 100.0) {
                    return [
                        'status' => 'ERROR',
                        'code' => 'PARAMETER_OUT_OF_BOUNDS',
                        'message' => "Parameter 'price_change_percent' harus berada di antara -50% dan +100%.",
                    ];
                }
                break;

            case 'COST_CHANGE':
                if (!isset($input['cost_change_percent'])) {
                    return [
                        'status' => 'ERROR',
                        'code' => 'MISSING_REQUIRED_PARAMETER',
                        'message' => "Parameter 'cost_change_percent' wajib diisi untuk skenario COST_CHANGE.",
                    ];
                }
                $val = $input['cost_change_percent'];
                if (!is_numeric($val) || is_nan($val) || is_infinite($val)) {
                    return [
                        'status' => 'ERROR',
                        'code' => 'INVALID_PARAMETER_VALUE',
                        'message' => "Parameter 'cost_change_percent' harus berupa angka numerik.",
                    ];
                }
                $num = (float) $val;
                if ($num < -50.0 || $num > 200.0) {
                    return [
                        'status' => 'ERROR',
                        'code' => 'PARAMETER_OUT_OF_BOUNDS',
                        'message' => "Parameter 'cost_change_percent' harus berada di antara -50% dan +200%.",
                    ];
                }
                break;

            case 'REVENUE_CHANGE':
                if (!isset($input['revenue_change_percent'])) {
                    return [
                        'status' => 'ERROR',
                        'code' => 'MISSING_REQUIRED_PARAMETER',
                        'message' => "Parameter 'revenue_change_percent' wajib diisi untuk skenario REVENUE_CHANGE.",
                    ];
                }
                $val = $input['revenue_change_percent'];
                if (!is_numeric($val) || is_nan($val) || is_infinite($val)) {
                    return [
                        'status' => 'ERROR',
                        'code' => 'INVALID_PARAMETER_VALUE',
                        'message' => "Parameter 'revenue_change_percent' harus berupa angka numerik.",
                    ];
                }
                $num = (float) $val;
                if ($num < -90.0 || $num > 300.0) {
                    return [
                        'status' => 'ERROR',
                        'code' => 'PARAMETER_OUT_OF_BOUNDS',
                        'message' => "Parameter 'revenue_change_percent' harus berada di antara -90% dan +300%.",
                    ];
                }
                break;

            case 'EXPENSE_CHANGE':
                $hasAmount = isset($input['expense_change_amount']);
                $hasPercent = isset($input['expense_change_percent']);

                if (!$hasAmount && !$hasPercent) {
                    return [
                        'status' => 'ERROR',
                        'code' => 'MISSING_REQUIRED_PARAMETER',
                        'message' => "Skenario EXPENSE_CHANGE membutuhkan setidaknya 'expense_change_amount' atau 'expense_change_percent'.",
                    ];
                }

                if ($hasAmount) {
                    $amt = $input['expense_change_amount'];
                    if (!is_numeric($amt) || is_nan($amt) || is_infinite($amt)) {
                        return [
                            'status' => 'ERROR',
                            'code' => 'INVALID_PARAMETER_VALUE',
                            'message' => "Parameter 'expense_change_amount' harus berupa angka numerik.",
                        ];
                    }
                    $numAmt = (float) $amt;
                    if ($numAmt < -100000000.0 || $numAmt > 100000000.0) {
                        return [
                            'status' => 'ERROR',
                            'code' => 'PARAMETER_OUT_OF_BOUNDS',
                            'message' => "Parameter 'expense_change_amount' melewati batas wajar (Rp -100 jt s/d +100 jt).",
                        ];
                    }
                }

                if ($hasPercent) {
                    $pct = $input['expense_change_percent'];
                    if (!is_numeric($pct) || is_nan($pct) || is_infinite($pct)) {
                        return [
                            'status' => 'ERROR',
                            'code' => 'INVALID_PARAMETER_VALUE',
                            'message' => "Parameter 'expense_change_percent' harus berupa angka numerik.",
                        ];
                    }
                    $numPct = (float) $pct;
                    if ($numPct < -80.0 || $numPct > 300.0) {
                        return [
                            'status' => 'ERROR',
                            'code' => 'PARAMETER_OUT_OF_BOUNDS',
                            'message' => "Parameter 'expense_change_percent' harus berada di antara -80% dan +300%.",
                        ];
                    }
                }
                break;

            case 'COMBINED_SCENARIO':
                $presentCount = 0;
                $allowedCombined = [
                    'price_change_percent',
                    'revenue_change_percent',
                    'cost_change_percent',
                    'expense_change_amount',
                    'expense_change_percent',
                ];

                foreach ($allowedCombined as $param) {
                    if (isset($input[$param])) {
                        $presentCount++;
                        $val = $input[$param];
                        if (!is_numeric($val) || is_nan($val) || is_infinite($val)) {
                            return [
                                'status' => 'ERROR',
                                'code' => 'INVALID_PARAMETER_VALUE',
                                'message' => "Parameter '{$param}' harus berupa angka numerik.",
                            ];
                        }
                    }
                }

                if ($presentCount === 0) {
                    return [
                        'status' => 'ERROR',
                        'code' => 'MISSING_REQUIRED_PARAMETER',
                        'message' => "Skenario COMBINED_SCENARIO membutuhkan setidaknya satu parameter simulasi.",
                    ];
                }

                // Check bounds for individual combined inputs
                if (isset($input['price_change_percent'])) {
                    $p = (float) $input['price_change_percent'];
                    if ($p < -50.0 || $p > 100.0) {
                        return [
                            'status' => 'ERROR',
                            'code' => 'PARAMETER_OUT_OF_BOUNDS',
                            'message' => "Parameter 'price_change_percent' harus berada di antara -50% dan +100%.",
                        ];
                    }
                }

                if (isset($input['revenue_change_percent'])) {
                    $r = (float) $input['revenue_change_percent'];
                    if ($r < -90.0 || $r > 300.0) {
                        return [
                            'status' => 'ERROR',
                            'code' => 'PARAMETER_OUT_OF_BOUNDS',
                            'message' => "Parameter 'revenue_change_percent' harus berada di antara -90% dan +300%.",
                        ];
                    }
                }

                if (isset($input['cost_change_percent'])) {
                    $c = (float) $input['cost_change_percent'];
                    if ($c < -50.0 || $c > 200.0) {
                        return [
                            'status' => 'ERROR',
                            'code' => 'PARAMETER_OUT_OF_BOUNDS',
                            'message' => "Parameter 'cost_change_percent' harus berada di antara -50% dan +200%.",
                        ];
                    }
                }

                if (isset($input['expense_change_percent'])) {
                    $ep = (float) $input['expense_change_percent'];
                    if ($ep < -80.0 || $ep > 300.0) {
                        return [
                            'status' => 'ERROR',
                            'code' => 'PARAMETER_OUT_OF_BOUNDS',
                            'message' => "Parameter 'expense_change_percent' harus berada di antara -80% dan +300%.",
                        ];
                    }
                }

                if (isset($input['expense_change_amount'])) {
                    $ea = (float) $input['expense_change_amount'];
                    if ($ea < -100000000.0 || $ea > 100000000.0) {
                        return [
                            'status' => 'ERROR',
                            'code' => 'PARAMETER_OUT_OF_BOUNDS',
                            'message' => "Parameter 'expense_change_amount' melewati batas wajar (Rp -100 jt s/d +100 jt).",
                        ];
                    }
                }
                break;

            case 'FINANCING_CAPACITY':
                if (!isset($input['amount']) || !is_numeric($input['amount']) || (float) $input['amount'] <= 0) {
                    return [
                        'status'  => 'ERROR',
                        'code'    => 'INVALID_AMOUNT',
                        'message' => "Parameter 'amount' wajib diisi dan harus berupa angka positif.",
                    ];
                }
                break;
        }

        return null;
    }

    /**
     * Compute deterministic scenario numbers and build structured response.
     */
    protected function executeSimulation(
        string $scenarioType,
        array $input,
        float $baseRevenue,
        float $baseCogs,
        float $baseGrossProfit,
        float $baseExpenses,
        float $baseNetProfit,
        ?float $baseGrossMargin,
        ?float $baseNetMargin,
        string $startDate,
        string $endDate,
        string $tz,
        string $periodType,
        bool $hasUntrackedCogs,
        float $untrackedItemsPct
    ): array {
        $assumptions = [];
        $limitations = [];

        // Global baseline limitations
        $limitations[] = "Simulasi berbasis data historis {$periodType} ({$startDate} s/d {$endDate}).";
        $limitations[] = 'Hasil simulasi bersifat matematis deterministik dan bukan kepastian kinerja masa depan.';

        if ($hasUntrackedCogs) {
            $limitations[] = "Terdapat {$untrackedItemsPct}% item transaksi tanpa data HPP (cost price). Akurasi proyeksi COGS dan margin terbatas.";
        }

        if ($baseRevenue <= 0.0) {
            $limitations[] = 'Baseline revenue bernilai 0: persentase margin dan pertumbuhan omzet tidak terdefinisi.';
        }

        // Initialize simulation variables
        $simRevenue = $baseRevenue;
        $simCogs = $baseCogs;
        $simExpenses = $baseExpenses;

        switch ($scenarioType) {
            case 'PRICE_CHANGE':
                $pct = (float) $input['price_change_percent'];
                $priceFactor = 1.0 + ($pct / 100.0);

                $simRevenue = $baseRevenue * $priceFactor;
                $simCogs = $baseCogs; // Zero elasticity: volume & unit cost constant
                $simExpenses = $baseExpenses;

                $assumptions[] = 'Zero elasticity: Kuantitas/volume transaksi dianggap tetap untuk simulasi.';
                $assumptions[] = 'Biaya bahan baku (HPP unit) dan biaya operasional tetap konstan.';
                $limitations[] = 'Simulasi mengabaikan potensi penurunan volume pembelian akibat kenaikan harga.';
                break;

            case 'COST_CHANGE':
                $pct = (float) $input['cost_change_percent'];
                $costFactor = 1.0 + ($pct / 100.0);

                $simRevenue = $baseRevenue;
                $simCogs = $baseCogs * $costFactor;
                $simExpenses = $baseExpenses;

                $assumptions[] = 'Harga jual dan volume transaksi diasumsikan tidak mengalami perubahan.';
                $assumptions[] = 'Fluktuasi HPP diserap langsung oleh margin merchant tanpa penyesuaian harga menu.';
                break;

            case 'REVENUE_CHANGE':
                $pct = (float) $input['revenue_change_percent'];
                $revFactor = 1.0 + ($pct / 100.0);

                $simRevenue = $baseRevenue * $revFactor;
                $simCogs = $baseCogs * $revFactor; // Variable COGS model
                $simExpenses = $baseExpenses;     // Fixed operating expenses

                $assumptions[] = 'Perubahan omzet diasumsikan berasal dari perubahan volume penjualan, sehingga COGS variabel bergerak proporsional.';
                $assumptions[] = 'Biaya operasional (sewa, utilitas, gaji) diasumsikan tetap konstan.';
                break;

            case 'EXPENSE_CHANGE':
                $nominalChange = 0.0;
                if (isset($input['expense_change_amount'])) {
                    $nominalChange = (float) $input['expense_change_amount'];
                } elseif (isset($input['expense_change_percent'])) {
                    $nominalChange = $baseExpenses * ((float) $input['expense_change_percent'] / 100.0);
                }

                $simRevenue = $baseRevenue;
                $simCogs = $baseCogs;
                $simExpenses = $baseExpenses + $nominalChange;

                if ($simExpenses < 0.0) {
                    return [
                        'status' => 'ERROR',
                        'code' => 'INVALID_EXPENSE_RESULT',
                        'message' => 'Simulasi menghasilkan beban operasional negatif (< 0), input tidak valid.',
                    ];
                }

                $assumptions[] = 'Pendapatan dan HPP bahan baku tidak terpengaruh oleh penyesuaian beban operasional.';
                break;

            case 'COMBINED_SCENARIO':
                $priceFactor = 1.0 + (((float) ($input['price_change_percent'] ?? 0.0)) / 100.0);
                $volumeFactor = 1.0 + (((float) ($input['revenue_change_percent'] ?? 0.0)) / 100.0);
                $costFactor = 1.0 + (((float) ($input['cost_change_percent'] ?? 0.0)) / 100.0);

                $simRevenue = $baseRevenue * $priceFactor * $volumeFactor;
                $simCogs = $baseCogs * $volumeFactor * $costFactor;

                $nominalExpenseChange = 0.0;
                if (isset($input['expense_change_amount'])) {
                    $nominalExpenseChange = (float) $input['expense_change_amount'];
                } elseif (isset($input['expense_change_percent'])) {
                    $nominalExpenseChange = $baseExpenses * (((float) $input['expense_change_percent']) / 100.0);
                }

                $simExpenses = $baseExpenses + $nominalExpenseChange;
                if ($simExpenses < 0.0) {
                    return [
                        'status' => 'ERROR',
                        'code' => 'INVALID_EXPENSE_RESULT',
                        'message' => 'Simulasi menghasilkan beban operasional negatif (< 0), input tidak valid.',
                    ];
                }

                $assumptions[] = 'Kombinasi multi-variabel: Efek harga dan volume dipisahkan secara independen untuk mencegah double-counting.';
                $assumptions[] = 'Perubahan omzet (revenue_change_percent) memengaruhi volume dan COGS secara proporsional.';
                $assumptions[] = 'Perubahan harga (price_change_percent) memengaruhi omzet tanpa mengubah kuantitas unit.';
                $assumptions[] = 'Perubahan biaya bahan baku (cost_change_percent) memengaruhi HPP unit.';
                break;
        }

        // Compute simulated profit and margins
        $simGrossProfit = $simRevenue - $simCogs;
        $simNetProfit = $simGrossProfit - $simExpenses;

        $simGrossMargin = $simRevenue > 0.0 ? round(($simGrossProfit / $simRevenue) * 100.0, 2) : null;
        $simNetMargin = $simRevenue > 0.0 ? round(($simNetProfit / $simRevenue) * 100.0, 2) : null;

        // Compute deltas
        $deltaRevenue = $simRevenue - $baseRevenue;
        $deltaRevenuePct = $baseRevenue > 0.0 ? round(($deltaRevenue / $baseRevenue) * 100.0, 2) : null;

        $deltaCogs = $simCogs - $baseCogs;
        $deltaCogsPct = $baseCogs > 0.0 ? round(($deltaCogs / $baseCogs) * 100.0, 2) : null;

        $deltaGrossProfit = $simGrossProfit - $baseGrossProfit;
        $deltaGrossProfitPct = ($baseGrossProfit != 0.0)
            ? round(($deltaGrossProfit / abs($baseGrossProfit)) * 100.0, 2)
            : null;

        $deltaExpenses = $simExpenses - $baseExpenses;
        $deltaExpensesPct = ($baseExpenses > 0.0)
            ? round(($deltaExpenses / $baseExpenses) * 100.0, 2)
            : null;

        $deltaNetProfit = $simNetProfit - $baseNetProfit;
        $deltaNetProfitPct = ($baseNetProfit != 0.0)
            ? round(($deltaNetProfit / abs($baseNetProfit)) * 100.0, 2)
            : null;

        $deltaGrossMarginPp = ($simGrossMargin !== null && $baseGrossMargin !== null)
            ? round($simGrossMargin - $baseGrossMargin, 2)
            : null;

        $deltaNetMarginPp = ($simNetMargin !== null && $baseNetMargin !== null)
            ? round($simNetMargin - $baseNetMargin, 2)
            : null;

        return [
            'status' => 'SUCCESS',
            'scenario' => [
                'type' => $scenarioType,
                'parameters' => $input,
            ],
            'baseline' => [
                'revenue' => round($baseRevenue, 2),
                'cogs' => round($baseCogs, 2),
                'gross_profit' => round($baseGrossProfit, 2),
                'expenses' => round($baseExpenses, 2),
                'net_profit' => round($baseNetProfit, 2),
                'gross_margin' => $baseGrossMargin,
                'net_margin' => $baseNetMargin,
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'timezone' => $tz,
                    'type' => $periodType,
                ],
                'data_quality' => [
                    'has_untracked_cogs' => $hasUntrackedCogs,
                    'untracked_items_percentage' => $untrackedItemsPct,
                    'status' => $hasUntrackedCogs ? 'WARNING' : 'HEALTHY',
                ],
            ],
            'result' => [
                'revenue' => round($simRevenue, 2),
                'cogs' => round($simCogs, 2),
                'gross_profit' => round($simGrossProfit, 2),
                'expenses' => round($simExpenses, 2),
                'net_profit' => round($simNetProfit, 2),
                'gross_margin' => $simGrossMargin,
                'net_margin' => $simNetMargin,
            ],
            'delta' => [
                'revenue' => round($deltaRevenue, 2),
                'revenue_percent' => $deltaRevenuePct,
                'cogs' => round($deltaCogs, 2),
                'cogs_percent' => $deltaCogsPct,
                'gross_profit' => round($deltaGrossProfit, 2),
                'gross_profit_percent' => $deltaGrossProfitPct,
                'expenses' => round($deltaExpenses, 2),
                'expenses_percent' => $deltaExpensesPct,
                'net_profit' => round($deltaNetProfit, 2),
                'net_profit_percent' => $deltaNetProfitPct,
                'gross_margin_pp' => $deltaGrossMarginPp,
                'net_margin_pp' => $deltaNetMarginPp,
            ],
            'assumptions' => $assumptions,
            'limitations' => $limitations,
            'data_quality' => $hasUntrackedCogs ? 'UNTRACKED_COGS_WARNING' : 'HEALTHY',
        ];
    }

    /**
     * Deterministic Purchase Simulation (FinTechathon P0.1).
     *
     * Calculates projected cash after a planned purchase:
     * projected_cash_after_purchase = current_cash + projected_inflow - purchase_amount
     * buffer_surplus = projected_cash_after_purchase - minimum_operating_buffer
     *
     * Invariants:
     * - 100% Deterministic — no LLM arithmetic.
     * - 100% Read-only — zero DB mutation.
     * - No credit decision / loan evaluation.
     *
     * @param int $userId Authenticated tenant ID (SSOT)
     * @param int|null $outletId Validated outlet ID
     * @param array $input Untrusted input ['amount' => float, 'date' => string, 'minimum_buffer' => float]
     * @return array Structured simulation result
     */
    public function simulatePurchase(int $userId, ?int $outletId, array $input): array
    {
        // 1. Tenant Authentication Gate
        if ($userId <= 0) {
            return [
                'status' => 'ERROR',
                'code' => 'UNAUTHENTICATED',
                'message' => 'Konteks autentikasi tenant tidak valid.',
            ];
        }

        // 2. Outlet Ownership Gate
        if ($outletId !== null) {
            $outletExists = Outlet::where('user_id', $userId)->where('id', $outletId)->exists();
            if (!$outletExists) {
                return [
                    'status' => 'ERROR',
                    'code' => 'UNAUTHORIZED_OUTLET',
                    'message' => 'Outlet tidak ditemukan atau bukan milik akun ini.',
                ];
            }
        }

        // 3. Amount Validation (amount > 0)
        if (!isset($input['amount']) || !is_numeric($input['amount']) || (float) $input['amount'] <= 0) {
            return [
                'status' => 'ERROR',
                'code' => 'INVALID_AMOUNT',
                'message' => 'Nilai pembelian (amount) harus berupa angka lebih besar dari 0.',
            ];
        }
        $purchaseAmount = (float) $input['amount'];

        // 4. Date Validation (format YYYY-MM-DD)
        $dateStr = $input['date'] ?? null;
        if (!is_string($dateStr) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return [
                'status' => 'ERROR',
                'code' => 'INVALID_DATE',
                'message' => 'Format tanggal tidak valid. Gunakan format YYYY-MM-DD.',
            ];
        }

        try {
            $parsedDate = Carbon::createFromFormat('Y-m-d', $dateStr, 'Asia/Jakarta')->startOfDay();
            if ($parsedDate->format('Y-m-d') !== $dateStr) {
                return [
                    'status' => 'ERROR',
                    'code' => 'INVALID_DATE',
                    'message' => 'Tanggal tidak valid pada kalender.',
                ];
            }
        } catch (\Throwable $e) {
            return [
                'status' => 'ERROR',
                'code' => 'INVALID_DATE',
                'message' => 'Format tanggal tidak valid: ' . $e->getMessage(),
            ];
        }

        // 5. Minimum Operating Buffer Validation
        $minimumBuffer = 1500000.0; // Default safe buffer Rp 1.500.000
        if (isset($input['minimum_buffer'])) {
            if (!is_numeric($input['minimum_buffer']) || (float) $input['minimum_buffer'] < 0) {
                return [
                    'status' => 'ERROR',
                    'code' => 'INVALID_BUFFER',
                    'message' => 'Minimum safety buffer harus berupa angka non-negatif.',
                ];
            }
            $minimumBuffer = (float) $input['minimum_buffer'];
        }

        $limitations = [];

        // 6. Current Operating Cash (via FinancialCalculationService SSOT)
        $cashData = $this->calcService->getCashPosition($userId, $outletId);
        $currentCash = 0.0;
        if ($cashData && isset($cashData['current_cash_position']) && $cashData['current_cash_position'] !== null) {
            $currentCash = (float) $cashData['current_cash_position'];
        } else {
            $limitations[] = 'Data posisi kas laci fisik aktif tidak ditemukan. Saldo kas saat ini diasumsikan 0.';
        }

        // 7. Projected Inflow on Purchase Date (via CashFlowForecastService SSOT)
        $projectedInflow = 0.0;
        $projectionAvailable = false;

        $today = Carbon::today('Asia/Jakarta');
        if ($parsedDate->lt($today)) {
            $limitations[] = 'Tanggal pembelian berada di masa lalu. Proyeksi arus kas masa depan tidak berlaku.';
        } else {
            try {
                $forecastResult = $this->forecastService->forecast(
                    $userId,
                    $outletId,
                    $today->toDateString(),
                    ['horizon_days' => 14]
                );

                if (isset($forecastResult['status']) && $forecastResult['status'] === 'SUCCESS' && !empty($forecastResult['daily_forecast'])) {
                    foreach ($forecastResult['daily_forecast'] as $dayItem) {
                        if (($dayItem['date'] ?? '') === $dateStr) {
                            $projectedInflow = (float) ($dayItem['projected_inflow'] ?? 0.0);
                            $projectionAvailable = true;
                            break;
                        }
                    }
                    if (!$projectionAvailable) {
                        $limitations[] = "Tanggal pembelian ($dateStr) berada di luar horizon proyeksi kas (14 hari). Pemasukan diproyeksikan 0.";
                    }
                } else {
                    $reason = $forecastResult['message'] ?? 'Riwayat transaksi belum mencukupi untuk peramalan musiman.';
                    $limitations[] = "Proyeksi arus kas tidak tersedia: $reason. Pemasukan diproyeksikan 0.";
                }
            } catch (\Throwable $e) {
                Log::warning('simulatePurchase forecast exception', ['error' => $e->getMessage()]);
                $limitations[] = 'Gagal memuat proyeksi arus kas. Pemasukan diproyeksikan 0.';
            }
        }

        // 8. Deterministic Arithmetic
        $projectedCashAfterPurchase = $currentCash + $projectedInflow - $purchaseAmount;
        $bufferSurplus = $projectedCashAfterPurchase - $minimumBuffer;

        // 9. Status Classification
        if ($bufferSurplus > 0) {
            $status = 'WITHIN_BUFFER';
        } elseif ($bufferSurplus == 0.0) {
            $status = 'EXACT_BUFFER';
        } else {
            $status = $projectedCashAfterPurchase < 0 ? 'DEFICIT' : 'BELOW_BUFFER';
        }

        return [
            'status' => $status,
            'purchase_amount' => $purchaseAmount,
            'current_cash' => $currentCash,
            'projected_inflow' => $projectedInflow,
            'projected_cash_after_purchase' => $projectedCashAfterPurchase,
            'minimum_operating_buffer' => $minimumBuffer,
            'buffer_surplus' => $bufferSurplus,
            'projection_available' => $projectionAvailable,
            'purchase_date' => $dateStr,
            'limitations' => $limitations,
        ];
    }

    /**
     * Deterministic Financing Capacity Simulation (Phase 5.16-B).
     */
    public function simulateFinancingCapacity(int $userId, ?int $outletId, array $input): array
    {
        return $this->financingSimulator->simulate($userId, $outletId, $input);
    }
}
