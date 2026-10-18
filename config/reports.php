<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    |
    | "max_days": the longest range the dashboard metrics accept.
    | "export_max_rows": a ledger export larger than this is refused (narrow
    | the filters instead), which keeps one request from dumping the whole
    | ledger and bounds the work a single export can do.
    |
    */
    'max_days' => (int) env('REPORTS_MAX_DAYS', 90),
    'export_max_rows' => (int) env('REPORTS_EXPORT_MAX_ROWS', 50000),
];
