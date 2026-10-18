<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maker-checker
    |--------------------------------------------------------------------------
    |
    | When enabled, float issuance, fee/commission/bonus setting changes,
    | transaction reversals and agent commission-rate changes are stored as
    | approval requests and only applied when a different staff member who
    | holds the same permission approves them.
    |
    | Production always requires approval. Turning it off is for the test
    | suite and single-admin demos, where nobody else could approve.
    |
    */
    'enabled' => (bool) env('MAKER_CHECKER_ENABLED', true),
];
