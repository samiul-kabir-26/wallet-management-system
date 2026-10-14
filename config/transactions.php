<?php

return [
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
];
