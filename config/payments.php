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
];
