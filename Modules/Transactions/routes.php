<?php

use Illuminate\Support\Facades\Route;
use Modules\Transactions\Http\Controllers\TransactionController;

// ADMIN endpoints: admin audit & float issue
Route::middleware(['auth:sanctum', 'ability:admin', 'password.changed'])->group(function (): void {
    Route::get('/admin/all', [TransactionController::class, 'adminAll']);
    Route::post('/admin/float-issue', [TransactionController::class, 'adminIssueFloat']);
});

// Common authenticated query endpoints: history
Route::middleware(['auth:sanctum', 'ability:admin,agent,user', 'password.changed'])->group(function (): void {
    Route::get('/history', [TransactionController::class, 'history']);
});

// USER endpoints: caps, top-up, cash-out, transfer
Route::middleware(['auth:sanctum', 'ability:user', 'password.changed'])->group(function (): void {
    Route::get('/caps', [TransactionController::class, 'myCaps']);
    Route::post('/top-up', [TransactionController::class, 'topUp']);
    Route::post('/cash-out', [TransactionController::class, 'cashOut']);
    Route::post('/transfer', [TransactionController::class, 'transfer']);
});

// AGENT endpoints: agent/cash-in, agent/withdrawal
Route::middleware(['auth:sanctum', 'ability:agent', 'password.changed'])->group(function (): void {
    Route::post('/agent/cash-in', [TransactionController::class, 'agentCashIn']);
    Route::post('/agent/withdrawal', [TransactionController::class, 'agentWithdrawal']);
});

// Transaction details (parameterized route at end, guarded by policy)
Route::middleware(['auth:sanctum', 'ability:admin,agent,user', 'password.changed'])->group(function (): void {
    Route::get('/{transaction}', [TransactionController::class, 'show'])->whereNumber('transaction');
});
