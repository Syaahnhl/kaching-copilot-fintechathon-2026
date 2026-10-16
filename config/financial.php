<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Kaching Financial Warning Engine Configuration (Phase B.2)
    |--------------------------------------------------------------------------
    |
    | Deterministic thresholds and quality gates for the financial warning
    | rules. AI Copilot reads the output contract and never overrides thresholds.
    |
    */

    'cache_ttl_minutes' => env('FINANCIAL_WARNING_CACHE_TTL', 30),

    'revenue_decline' => [
        'watch_min_pct' => 20.0,
        'watch_max_pct' => 25.0,
        'warning_pct' => 25.0,
        'critical_pct' => 50.0,
        'min_baseline_idr' => 500000.0,
        'window_days' => 7,
    ],

    'expense_spike' => [
        'warning_multiplier' => 1.50,
        'critical_multiplier' => 3.00,
        'warning_min_expense_idr' => 500000.0,
        'critical_min_expense_idr' => 2000000.0,
        'min_baseline_30d_idr' => 200000.0,
        'current_window_days' => 7,
        'baseline_window_days' => 30,
    ],

    'margin_compression' => [
        'warning_drop_pp' => 5.0,
        'critical_drop_pp' => 15.0,
        'max_untracked_cogs_pct' => 15.0,
        'window_days' => 7,
    ],

    'void_refund' => [
        'warning_pct' => 5.0,
        'critical_pct' => 15.0,
        'min_transactions' => 20,
        'window_days' => 7,
    ],

    'drawer_discrepancy' => [
        'warning_nominal_idr' => 50000.0,
        'warning_pct' => 2.0,
        'critical_nominal_idr' => 500000.0,
        'critical_pct' => 10.0,
        'min_cash_sales_idr' => 100000.0,
    ],

    'cash_depletion' => [
        'status' => 'SUSPENDED',
        'reason' => 'POS cash drawer is not equivalent to business liquidity.',
    ],
];
