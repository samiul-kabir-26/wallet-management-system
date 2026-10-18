<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Top-up Gateway
    |--------------------------------------------------------------------------
    |
    | "sslcommerz" sends the customer to the SSLCommerz checkout and credits the
    | wallet only after the payment is validated with SSLCommerz.
    |
    | "simulated" credits the wallet immediately with no payment behind it. It
    | exists for the test suite and offline demos, and is ignored in production.
    |
    */
    'top_up_gateway' => env('TOP_UP_GATEWAY', 'sslcommerz'),

    /*
    |--------------------------------------------------------------------------
    | SSLCommerz
    |--------------------------------------------------------------------------
    |
    | The defaults are SSLCommerz's public sandbox store. Register your own
    | sandbox store at https://developer.sslcommerz.com/registration/ and set
    | the credentials in .env. Live mode needs a merchant account.
    |
    */
    'sslcommerz' => [
        'store_id' => env('SSLCOMMERZ_STORE_ID', 'testbox'),
        'store_password' => env('SSLCOMMERZ_STORE_PASSWORD', 'qwerty'),
        'sandbox' => (bool) env('SSLCOMMERZ_SANDBOX', true),
        'sandbox_url' => 'https://sandbox.sslcommerz.com',
        'live_url' => 'https://securepay.sslcommerz.com',
        'timeout_seconds' => (int) env('SSLCOMMERZ_TIMEOUT', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Top-up Reconciliation
    |--------------------------------------------------------------------------
    |
    | IPNs get lost and customers close the tab, so a scheduled job asks the
    | gateway about top-ups still PENDING after "check_after_minutes". Ones the
    | gateway still has no payment for after "expire_after_hours" are marked
    | FAILED. A payment confirmed later is still credited.
    |
    */
    'reconciliation' => [
        'check_after_minutes' => (int) env('TOP_UP_RECONCILE_AFTER_MINUTES', 10),
        'expire_after_hours' => (int) env('TOP_UP_EXPIRE_AFTER_HOURS', 24),
    ],
];
