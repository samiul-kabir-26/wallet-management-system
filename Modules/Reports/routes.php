<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\ReportController;

// Admin reports: dashboard metrics (transactions.view_any) and ledger export (transactions.export)
Route::middleware(['auth:sanctum', 'ability:admin', 'password.changed'])->group(function (): void {
    Route::get('/overview', [ReportController::class, 'overview']);
    Route::get('/ledger.csv', [ReportController::class, 'exportLedger'])->middleware('throttle:6,1');
});
