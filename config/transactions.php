<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Business Timezone
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored in UTC, but daily and monthly caps roll over at
    | midnight in this timezone.
    |
    */
    'business_timezone' => env('TRANSACTION_BUSINESS_TIMEZONE', 'Asia/Dhaka'),

    /*
    |--------------------------------------------------------------------------
    | Transaction Limits (BDT)
    |--------------------------------------------------------------------------
    |
    | Minimum and maximum amounts per transaction for each transaction type.
    | Amounts are validated exact to 2 decimal places.
    |
    */
    'limits' => [
        'top_up' => [
            'min' => env('TRANSACTION_TOP_UP_MIN', 10.00),
            'max' => env('TRANSACTION_TOP_UP_MAX', 50000.00),
        ],
        'transfer' => [
            'min' => env('TRANSACTION_TRANSFER_MIN', 10.00),
            'max' => env('TRANSACTION_TRANSFER_MAX', 25000.00),
        ],
        'cash_in' => [
            'min' => env('TRANSACTION_CASH_IN_MIN', 10.00),
            'max' => env('TRANSACTION_CASH_IN_MAX', 30000.00),
        ],
        'cash_out' => [
            'min' => env('TRANSACTION_CASH_OUT_MIN', 10.00),
            'max' => env('TRANSACTION_CASH_OUT_MAX', 25000.00),
        ],
        'agent_withdrawal' => [
            'min' => env('TRANSACTION_AGENT_WITHDRAWAL_MIN', 10.00),
            'max' => env('TRANSACTION_AGENT_WITHDRAWAL_MAX', 50000.00),
        ],
        'float_issue' => [
            'min' => env('TRANSACTION_FLOAT_ISSUE_MIN', 100.00),
            'max' => env('TRANSACTION_FLOAT_ISSUE_MAX', 500000.00),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Signup Bonus (BDT)
    |--------------------------------------------------------------------------
    |
    | Credited to every wallet opened at registration. Staff can toggle it and
    | change the amount from System Settings; these values apply until they do.
    | The source label is shown to customers as the sender of the bonus.
    |
    */
    'signup_bonus' => [
        'default_enabled' => (bool) env('SIGNUP_BONUS_ENABLED', true),
        'default_amount' => env('SIGNUP_BONUS_AMOUNT', 50.00),
        'max_amount' => env('SIGNUP_BONUS_MAX', 1000.00),
        'source_label' => env('SIGNUP_BONUS_SOURCE_LABEL', 'bKash'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ledger Integrity Check
    |--------------------------------------------------------------------------
    |
    | `php artisan wallets:verify-ledger` recomputes every balance from the
    | transactions table. Wallets opened before the signup bonus became a
    | ledger row got this amount from a column default with no transaction
    | behind it; a difference of exactly this amount on a wallet with no
    | SIGNUP_BONUS row is reported as a known legacy gap, not a failure.
    |
    */
    'ledger_check' => [
        'legacy_opening_balance' => env('LEDGER_LEGACY_OPENING_BALANCE', 50.00),
    ],
];
