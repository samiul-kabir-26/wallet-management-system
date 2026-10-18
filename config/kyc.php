<?php

return [
    /*
    |--------------------------------------------------------------------------
    | KYC Tiers
    |--------------------------------------------------------------------------
    |
    | A customer's tier sets the daily and monthly limits for outgoing
    | cash-outs and transfers (written to their caps row, which CapService
    | enforces). New self- and staff-registered accounts start at
    | "initial_tier"; accounts that existed before tiers were introduced keep
    | their old 10,000 / 50,000 limits as STANDARD.
    |
    | requires_verification: the tier can only be given to an account whose
    | identity document staff have approved.
    |
    */
    'initial_tier' => env('KYC_INITIAL_TIER', 'BASIC'),

    'tiers' => [
        'BASIC' => [
            'label' => 'Basic',
            'description' => 'Phone verified',
            'daily_cap' => (float) env('KYC_BASIC_DAILY_CAP', 5000),
            'monthly_cap' => (float) env('KYC_BASIC_MONTHLY_CAP', 20000),
            'requires_verification' => false,
        ],
        'STANDARD' => [
            'label' => 'Standard',
            'description' => 'NID verified by staff',
            'daily_cap' => (float) env('KYC_STANDARD_DAILY_CAP', 10000),
            'monthly_cap' => (float) env('KYC_STANDARD_MONTHLY_CAP', 50000),
            'requires_verification' => true,
        ],
        'PREMIUM' => [
            'label' => 'Premium',
            'description' => 'Verified, higher limits granted by staff',
            'daily_cap' => (float) env('KYC_PREMIUM_DAILY_CAP', 25000),
            'monthly_cap' => (float) env('KYC_PREMIUM_MONTHLY_CAP', 100000),
            'requires_verification' => true,
        ],
    ],

    /*
    | Upper bound for custom per-user limits set by staff.
    */
    'max_custom_daily_cap' => (float) env('KYC_MAX_CUSTOM_DAILY_CAP', 100000),
    'max_custom_monthly_cap' => (float) env('KYC_MAX_CUSTOM_MONTHLY_CAP', 500000),
];
