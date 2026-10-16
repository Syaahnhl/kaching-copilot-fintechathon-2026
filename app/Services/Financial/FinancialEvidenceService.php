<?php

namespace App\Services\Financial;

class FinancialEvidenceService
{
    /**
     * Wrap a single financial warning into a canonical machine-readable evidence envelope.
     *
     * @param array $warningPayload Raw formatted warning from FinancialWarningService::formatContract()
     * @return array Canonical evidence contract
     */
    public function wrapWarning(array $warningPayload): array
    {
        $code = $warningPayload['code'] ?? 'UNKNOWN_WARNING';
        $status = $warningPayload['status'] ?? 'UNKNOWN';
        $severity = $warningPayload['severity'] ?? 'UNKNOWN';
        $actual = $warningPayload['actual'] ?? null;
        $baseline = $warningPayload['baseline'] ?? null;
        $threshold = $warningPayload['threshold'] ?? null;
        $unit = $warningPayload['unit'] ?? 'IDR';
        $period = $warningPayload['period'] ?? null;
        $sourceEvidence = $warningPayload['evidence'] ?? [];
        $dataQuality = $warningPayload['data_quality'] ?? 'DQ_UNDEFINED';
        $confidence = $warningPayload['confidence'] ?? 'NOT_APPLICABLE_DETERMINISTIC';
        $limitations = $warningPayload['limitations'] ?? [];

        return [
            'evidence_version' => '1.0',
            'engine'           => 'FinancialWarningService',
            'calculation_type' => 'EMPIRICAL_HEURISTIC',
            'subject'          => $code,
            'period'           => $period,
            'baseline'         => [
                'value'   => $baseline,
                'unit'    => $unit,
                'details' => is_array($sourceEvidence) ? ($sourceEvidence['baseline_details'] ?? null) : null,
            ],
            'observed_or_projected' => [
                'type'     => 'OBSERVED_ANOMALY',
                'value'    => $actual,
                'unit'     => $unit,
                'metric'   => $warningPayload['metric'] ?? null,
                'severity' => $severity,
                'status'   => $status,
                'details'  => $sourceEvidence,
            ],
            'delta_or_threshold' => [
                'threshold_applied'      => $threshold,
                'percentage_change'      => is_array($sourceEvidence) ? ($sourceEvidence['percentage_change'] ?? null) : null,
                'nominal_drop'           => is_array($sourceEvidence) ? ($sourceEvidence['nominal_drop'] ?? null) : null,
                'nominal_increase'       => is_array($sourceEvidence) ? ($sourceEvidence['nominal_increase'] ?? null) : null,
                'ratio'                  => is_array($sourceEvidence) ? ($sourceEvidence['ratio'] ?? null) : null,
                'drop_percentage_points' => is_array($sourceEvidence) ? ($sourceEvidence['drop_percentage_points'] ?? null) : null,
                'unit'                   => $unit,
            ],
            'data_quality' => [
                'state'       => $dataQuality,
                'is_reliable' => $dataQuality === 'DQ_SUFFICIENT',
            ],
            'confidence_or_evaluation' => [
                'level' => $confidence,
            ],
            'assumptions' => [],
            'limitations' => is_array($limitations) ? $limitations : [],
        ];
    }

    /**
     * Wrap all active warnings and insufficient data entries into canonical evidence envelopes.
     *
     * @param array $warningsContainer Array of warnings or structured container
     * @param array $insufficientData Optional array of insufficient data items
     * @return array Canonical evidence collection
     */
    public function wrapAllWarnings(array $warningsContainer, array $insufficientData = []): array
    {
        $activeList = $warningsContainer;
        if (isset($warningsContainer['warnings']) && is_array($warningsContainer['warnings'])) {
            $activeList = $warningsContainer['warnings'];
            if (empty($insufficientData) && isset($warningsContainer['insufficient_data']) && is_array($warningsContainer['insufficient_data'])) {
                $insufficientData = $warningsContainer['insufficient_data'];
            }
        }

        $wrappedActive = [];
        foreach ($activeList as $w) {
            if (is_array($w)) {
                $wrappedActive[] = $this->wrapWarning($w);
            }
        }

        $wrappedInsufficient = [];
        foreach ($insufficientData as $item) {
            if (is_array($item)) {
                $wrappedInsufficient[] = [
                    'evidence_version' => '1.0',
                    'engine'           => 'FinancialWarningService',
                    'calculation_type' => 'EMPIRICAL_HEURISTIC',
                    'subject'          => $item['code'] ?? 'FINANCIAL_WARNING',
                    'period'           => null,
                    'baseline'         => null,
                    'observed_or_projected' => null,
                    'delta_or_threshold'    => null,
                    'data_quality'          => [
                        'state'       => $item['data_quality'] ?? 'INSUFFICIENT_DATA',
                        'is_reliable' => false,
                    ],
                    'confidence_or_evaluation' => [
                        'level' => 'INSUFFICIENT',
                    ],
                    'assumptions'           => [],
                    'limitations'           => is_array($item['limitations'] ?? null) ? $item['limitations'] : [],
                ];
            }
        }

        return [
            'active_warnings'   => $wrappedActive,
            'insufficient_data' => $wrappedInsufficient,
        ];
    }

    /**
     * Wrap a scenario simulation payload into a canonical machine-readable evidence envelope.
     *
     * @param array $scenarioPayload Raw output from FinancialScenarioService::simulate()
     * @return array Canonical evidence contract
     */
    public function wrapScenario(array $scenarioPayload): array
    {
        $status = $scenarioPayload['status'] ?? 'UNKNOWN';

        if ($status !== 'SUCCESS') {
            return [
                'evidence_version' => '1.0',
                'engine'           => 'FinancialScenarioService',
                'calculation_type' => 'DETERMINISTIC_SIMULATION',
                'subject'          => $scenarioPayload['scenario']['type'] ?? ($scenarioPayload['code'] ?? 'SIMULATION_ERROR'),
                'period'           => null,
                'baseline'         => null,
                'observed_or_projected' => null,
                'delta_or_threshold'    => null,
                'data_quality'          => [
                    'state'       => $scenarioPayload['code'] ?? 'ERROR',
                    'is_reliable' => false,
                ],
                'confidence_or_evaluation' => [
                    'level' => 'NOT_APPLICABLE_DETERMINISTIC',
                ],
                'assumptions'           => [],
                'limitations'           => isset($scenarioPayload['message']) ? [(string) $scenarioPayload['message']] : [],
            ];
        }

        $baseline = $scenarioPayload['baseline'] ?? [];
        $result = $scenarioPayload['result'] ?? [];
        $delta = $scenarioPayload['delta'] ?? [];
        $dataQuality = $scenarioPayload['data_quality'] ?? ($baseline['data_quality']['status'] ?? 'HEALTHY');

        return [
            'evidence_version' => '1.0',
            'engine'           => 'FinancialScenarioService',
            'calculation_type' => 'DETERMINISTIC_SIMULATION',
            'subject'          => $scenarioPayload['scenario']['type'] ?? 'FINANCIAL_SCENARIO',
            'period'           => $baseline['period'] ?? null,
            'baseline'         => [
                'revenue'      => $baseline['revenue'] ?? null,
                'cogs'         => $baseline['cogs'] ?? null,
                'gross_profit' => $baseline['gross_profit'] ?? null,
                'expenses'     => $baseline['expenses'] ?? null,
                'net_profit'   => $baseline['net_profit'] ?? null,
                'gross_margin' => $baseline['gross_margin'] ?? null,
                'net_margin'   => $baseline['net_margin'] ?? null,
                'unit'         => 'IDR',
            ],
            'observed_or_projected' => [
                'type'         => 'SIMULATED_OUTCOME',
                'revenue'      => $result['revenue'] ?? null,
                'cogs'         => $result['cogs'] ?? null,
                'gross_profit' => $result['gross_profit'] ?? null,
                'expenses'     => $result['expenses'] ?? null,
                'net_profit'   => $result['net_profit'] ?? null,
                'gross_margin' => $result['gross_margin'] ?? null,
                'net_margin'   => $result['net_margin'] ?? null,
                'unit'         => 'IDR',
            ],
            'delta_or_threshold' => [
                'parameters_applied'   => $scenarioPayload['scenario']['parameters'] ?? [],
                'revenue_delta'        => $delta['revenue'] ?? null,
                'revenue_percent'      => $delta['revenue_percent'] ?? null,
                'cogs_delta'           => $delta['cogs'] ?? null,
                'cogs_percent'         => $delta['cogs_percent'] ?? null,
                'gross_profit_delta'   => $delta['gross_profit'] ?? null,
                'gross_profit_percent' => $delta['gross_profit_percent'] ?? null,
                'expenses_delta'       => $delta['expenses'] ?? null,
                'expenses_percent'     => $delta['expenses_percent'] ?? null,
                'net_profit_delta'     => $delta['net_profit'] ?? null,
                'net_profit_percent'   => $delta['net_profit_percent'] ?? null,
                'gross_margin_pp'      => $delta['gross_margin_pp'] ?? null,
                'net_margin_pp'        => $delta['net_margin_pp'] ?? null,
            ],
            'data_quality' => [
                'state'                      => $dataQuality,
                'is_reliable'                => $dataQuality === 'HEALTHY',
                'has_untracked_cogs'         => $baseline['data_quality']['has_untracked_cogs'] ?? false,
                'untracked_items_percentage' => $baseline['data_quality']['untracked_items_percentage'] ?? 0.0,
            ],
            'confidence_or_evaluation' => [
                'level' => 'NOT_APPLICABLE_DETERMINISTIC',
            ],
            'assumptions' => is_array($scenarioPayload['assumptions'] ?? null) ? $scenarioPayload['assumptions'] : [],
            'limitations' => is_array($scenarioPayload['limitations'] ?? null) ? $scenarioPayload['limitations'] : [],
        ];
    }

    /**
     * Wrap a cash flow forecast payload into a canonical machine-readable evidence envelope.
     *
     * @param array $forecastPayload Raw output from CashFlowForecastService::forecast()
     * @return array Canonical evidence contract
     */
    public function wrapForecast(array $forecastPayload): array
    {
        $status = $forecastPayload['status'] ?? 'UNKNOWN';
        $isSuccess = $status === 'SUCCESS';

        $period = $forecastPayload['forecast_period'] ?? null;
        $dataQualityDetails = $forecastPayload['data_quality_details'] ?? null;
        $closedDays = $forecastPayload['regularly_closed_weekdays'] ?? [];

        return [
            'evidence_version' => '1.0',
            'engine'           => 'CashFlowForecastService',
            'calculation_type' => 'HISTORICAL_PROXY_FORECAST',
            'subject'          => 'OPERATING_CASH_FLOW_PROXY_FORECAST_7D',
            'period'           => $period,
            'baseline'         => [
                'training_window'           => '28_calendar_days',
                'data_quality_details'      => $dataQualityDetails,
                'regularly_closed_weekdays' => $closedDays,
            ],
            'observed_or_projected' => [
                'type'                              => 'PROJECTED_OPERATING_PROXY',
                'projected_inflow'                  => $forecastPayload['projected_inflow'] ?? null,
                'projected_outflow'                 => $forecastPayload['projected_outflow'] ?? null,
                'projected_net_operating_cash_flow' => $forecastPayload['projected_net_operating_cash_flow'] ?? null,
                'daily_forecast'                    => $forecastPayload['daily_forecast'] ?? [],
                'unit'                              => 'IDR',
                'product_boundary_notice'           => 'Operating Cash-Flow Proxy Forecast != bank balance != total business liquidity != ending cash balance != formal accounting forecast',
            ],
            'delta_or_threshold' => [
                'model_selected' => $forecastPayload['model'] ?? 'NONE',
                'horizon_days'   => $period['horizon_days'] ?? ($forecastPayload['horizon_days'] ?? 7),
            ],
            'data_quality' => [
                'state'       => $forecastPayload['data_quality'] ?? $status,
                'is_reliable' => $isSuccess,
                'details'     => $dataQualityDetails,
            ],
            'confidence_or_evaluation' => [
                'level'            => $isSuccess ? 'EVALUATED_BACKTEST' : 'INSUFFICIENT',
                'accuracy_metrics' => $forecastPayload['backtest'] ?? null,
            ],
            'assumptions' => is_array($forecastPayload['assumptions'] ?? null) ? $forecastPayload['assumptions'] : [],
            'limitations' => is_array($forecastPayload['limitations'] ?? null) ? $forecastPayload['limitations'] : [],
        ];
    }

    /**
     * Wrap today sales metrics into a canonical machine-readable evidence envelope.
     *
     * @param float $amount Aggregated sales amount
     * @param string $date Date in YYYY-MM-DD
     * @param string $timezone Timezone string
     * @return array Canonical evidence contract
     */
    public function wrapSales(float $amount, string $date, string $timezone = 'Asia/Jakarta'): array
    {
        return [
            'evidence_version' => '1.0',
            'engine'           => 'FinancialCalculationService',
            'calculation_type' => 'DETERMINISTIC_AGGREGATION',
            'subject'          => 'TODAY_SALES',
            'period'           => [
                'start'    => $date,
                'end'      => $date,
                'timezone' => $timezone,
                'type'     => 'calendar_day',
            ],
            'baseline'         => null,
            'observed_or_projected' => [
                'type'   => 'OBSERVED_SALES_AGGREGATE',
                'value'  => $amount,
                'unit'   => 'IDR',
                'status' => 'completed',
            ],
            'delta_or_threshold' => null,
            'data_quality' => [
                'state'       => 'SUFFICIENT',
                'is_reliable' => true,
            ],
            'confidence_or_evaluation' => [
                'level' => 'NOT_APPLICABLE_DETERMINISTIC',
            ],
            'assumptions' => [
                'Only completed transactions are included.',
                'Scoped strictly to authenticated user and active date in ' . $timezone . '.',
            ],
            'limitations' => [
                'Excludes pending, cancelled, or refunded transactions.',
                'Does not account for cash drawer discrepancy or unpaid open bills.',
            ],
        ];
    }

    /**
     * Wrap an external business radar payload into a canonical machine-readable evidence envelope.
     *
     * @param array $radarPayload Raw output from ExternalBusinessRadarService::inspect()
     * @return array Canonical evidence contract
     */
    public function wrapRadar(array $radarPayload): array
    {
        $status = $radarPayload['status'] ?? 'UNKNOWN';
        $category = $radarPayload['signal_category'] ?? 'ALL';
        $retrievedAt = $radarPayload['retrieved_at'] ?? null;
        $signals = is_array($radarPayload['signals'] ?? null) ? $radarPayload['signals'] : [];
        $merchantSummary = $radarPayload['merchant_context_summary'] ?? [];
        $dataQuality = $radarPayload['data_quality'] ?? ($status === 'SUCCESS' ? 'VERIFIED_PUBLIC_SOURCE' : $status);
        $overallFreshness = $radarPayload['overall_freshness'] ?? 'FRESH';

        $sources = [];
        $externalSignals = [];
        foreach ($signals as $sig) {
            if (is_array($sig)) {
                $sources[] = [
                    'source_name'           => $sig['source_name'] ?? 'Unknown',
                    'source_url'            => $sig['source_url'] ?? null,
                    'source_classification' => $sig['source_classification'] ?? 'NEWS_AGGREGATOR',
                    'published_at'          => $sig['published_at'] ?? null,
                    'retrieved_at'          => $sig['retrieved_at'] ?? $retrievedAt,
                    'freshness_status'      => $sig['freshness_status'] ?? 'UNKNOWN',
                    'content_hash'          => $sig['content_hash'] ?? null,
                    'content_security_flag' => $sig['content_security_flag'] ?? 'SAFE',
                ];

                $externalSignals[] = [
                    'signal_id'            => $sig['signal_id'] ?? null,
                    'external_signal_type' => $sig['external_signal_type'] ?? 'COMMODITY_PRICE',
                    'headline'             => $sig['headline'] ?? '',
                    'summary'              => $sig['summary'] ?? '',
                    'matches'              => $sig['matches'] ?? [],
                    'potential_relevance'  => $sig['potential_relevance'] ?? [],
                ];
            }
        }

        return [
            'evidence_version' => '1.0',
            'engine'           => 'ExternalBusinessRadar',
            'calculation_type' => 'HEURISTIC_RELEVANCE_MATCHING',
            'subject'          => 'EXTERNAL_MARKET_AND_ENVIRONMENTAL_SIGNALS',
            'period'           => [
                'retrieved_at'    => $retrievedAt,
                'signal_category' => $category,
            ],
            'baseline'         => [
                'merchant_context' => [
                    'user_id'             => $merchantSummary['user_id'] ?? null,
                    'outlet_id'           => $merchantSummary['outlet_id'] ?? null,
                    'matched_items_count' => $merchantSummary['matched_items_count'] ?? 0,
                    'matched_menus_count' => $merchantSummary['matched_menus_count'] ?? 0,
                ],
            ],
            'observed_or_projected' => [
                'type'                    => 'EXTERNAL_SIGNALS_OBSERVATION',
                'status'                  => $status,
                'signals_count'           => count($signals),
                'external_signals'        => $externalSignals,
                'source_attributions'     => $sources,
                'financial_impact_notice' => 'External radar outputs qualitative risk and operational indicators. Radar does NOT compute financial formulas. For quantitative margin or profit simulations, call tool get_financial_scenario.',
            ],
            'delta_or_threshold' => [
                'matching_tier_weights' => [
                    'TIER_1_ITEM_DIRECT_MATCH'      => 1.0,
                    'TIER_2_MENU_KEYWORD_MATCH'     => 0.7,
                    'TIER_3_EXPENSE_CATEGORY_MATCH' => 0.5,
                    'TIER_4_GEOGRAPHIC_MATCH'       => 0.4,
                ],
                'ranking_semantic' => 'Heuristic relevance weight (not probabilistic confidence)',
            ],
            'data_quality' => [
                'state'       => $dataQuality,
                'is_reliable' => in_array($dataQuality, ['VERIFIED_PUBLIC_SOURCE', 'SUFFICIENT']),
                'freshness'   => $overallFreshness,
            ],
            'confidence_or_evaluation' => [
                'level'       => 'QUALITATIVE_EXTERNAL_SIGNAL',
                'explanation' => 'Signals represent third-party public reporting. Not evaluated as certainty or merchant invoice drift.',
            ],
            'assumptions' => [
                'External price movements and wholesale market trends do not immediately alter merchant supplier contract prices.',
                'Weather events and public alerts reflect regional conditions and may vary at hyper-local outlet vicinity.',
            ],
            'limitations' => [
                'Relies on server-side fixed public RSS/API endpoints without scraping private sources.',
                'Unverified against specific merchant supplier invoice receipts or negotiated supplier price sheets.',
                'Does not provide autonomous trading, purchasing, or automated POS menu price modifications.',
            ],
        ];
    }

    /**
     * Membungkus hasil evaluasi Financing Readiness ke dalam canonical evidence envelope.
     * FinTechathon 2026 - Financial Evidence & Explainability.
     *
     * @param  array  $readinessPayload
     * @return array
     */
    public function wrapFinancingReadiness(array $readinessPayload): array
    {
        $status = $readinessPayload['status'] ?? 'ERROR';
        $period = $readinessPayload['evaluation_period'] ?? [];
        $pillars = $readinessPayload['pillars'] ?? [];
        $evidenceSummary = $readinessPayload['evidence_summary'] ?? [];
        $disclaimer = $readinessPayload['disclaimer'] ?? 'Financing Readiness Index mengukur kelengkapan data operasional dan kedisiplinan pembukuan UMKM, bukan persetujuan kredit (credit score) dan tidak menjamin penerimaan pinjaman oleh lembaga keuangan.';
        $limitations = $readinessPayload['limitations'] ?? [];

        return [
            'type'        => 'financing_readiness',
            'status'      => $status,
            'score'       => $readinessPayload['score'] ?? 0,
            'band'        => $readinessPayload['band'] ?? 'NEEDS_IMPROVEMENT',
            'period'      => [
                'start_date' => $period['start_date'] ?? null,
                'end_date'   => $period['end_date'] ?? null,
                'days'       => $period['days'] ?? 30,
            ],
            'evidence_coverage' => $evidenceSummary,
            'pillars'           => $pillars,
            'limitations'       => $limitations,
            'disclaimer'        => $disclaimer,
            'generated_at'      => now()->toIso8601String(),
        ];
    }
}
