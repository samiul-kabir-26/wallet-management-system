<?php

use Illuminate\Support\Facades\Route;
use Modules\Transactions\Http\Controllers\FloatRequestController;
use Modules\Transactions\Http\Controllers\PaymentRequestController;
use Modules\Transactions\Http\Controllers\TransactionController;

// ADMIN endpoints: admin audit & float issue
Route::middleware(['auth:sanctum', 'ability:admin', 'password.changed'])->group(function (): void {
    Route::get('/admin/all', [TransactionController::class, 'adminAll']);
    Route::post('/admin/float-issue', [TransactionController::class, 'adminIssueFloat']);
    Route::patch('/admin/withdrawals/{transaction}/approve', [TransactionController::class, 'approveWithdrawal'])->whereNumber('transaction');
    Route::patch('/admin/withdrawals/{transaction}/reject', [TransactionController::class, 'rejectWithdrawal'])->whereNumber('transaction');
    Route::post('/admin/{transaction}/reverse', [TransactionController::class, 'reverse'])->whereNumber('transaction')->middleware('throttle:30,1');

    // Agent float requests (staff side; agents.issue_float is checked in the controller)
    Route::get('/admin/float-requests', [FloatRequestController::class, 'adminIndex']);
    Route::get('/admin/float-requests/{floatRequest}/slip', [FloatRequestController::class, 'adminSlip'])->whereNumber('floatRequest');
    Route::post('/admin/float-requests/{floatRequest}/approve', [FloatRequestController::class, 'approve'])->whereNumber('floatRequest')->middleware('throttle:30,1');
    Route::post('/admin/float-requests/{floatRequest}/reject', [FloatRequestController::class, 'reject'])->whereNumber('floatRequest')->middleware('throttle:30,1');
});

// Common authenticated query endpoints: history
Route::middleware(['auth:sanctum', 'ability:admin,agent,user', 'password.changed'])->group(function (): void {
    Route::get('/history', [TransactionController::class, 'history']);
});

// USER endpoints: caps, cash-out, transfer (top-up lives in the Payments module)
Route::middleware(['auth:sanctum', 'ability:user', 'password.changed'])->group(function (): void {
    Route::get('/caps', [TransactionController::class, 'myCaps']);
    Route::get('/quote', [TransactionController::class, 'quote']);
    Route::post('/cash-out', [TransactionController::class, 'cashOut']);
    Route::post('/transfer', [TransactionController::class, 'transfer']);

    // Request money from another customer
    Route::get('/requests', [PaymentRequestController::class, 'index']);
    Route::post('/requests', [PaymentRequestController::class, 'store'])->middleware('throttle:10,1');
    Route::post('/requests/{paymentRequest}/pay', [PaymentRequestController::class, 'pay'])->whereNumber('paymentRequest');
    Route::post('/requests/{paymentRequest}/decline', [PaymentRequestController::class, 'decline'])->whereNumber('paymentRequest');
    Route::post('/requests/{paymentRequest}/cancel', [PaymentRequestController::class, 'cancel'])->whereNumber('paymentRequest');
});

// AGENT endpoints: agent/cash-in, agent/withdrawal
Route::middleware(['auth:sanctum', 'ability:agent', 'password.changed'])->group(function (): void {
    Route::post('/agent/cash-in', [TransactionController::class, 'agentCashIn']);
    Route::post('/agent/withdrawal', [TransactionController::class, 'agentWithdrawal']);

    // Float requests against a bank deposit
    Route::get('/agent/float-requests', [FloatRequestController::class, 'agentIndex']);
    Route::post('/agent/float-requests', [FloatRequestController::class, 'store'])->middleware('throttle:10,1');
    Route::post('/agent/float-requests/{floatRequest}/cancel', [FloatRequestController::class, 'cancel'])->whereNumber('floatRequest');
    Route::get('/agent/float-requests/{floatRequest}/slip', [FloatRequestController::class, 'agentSlip'])->whereNumber('floatRequest');
});

// Transaction details (parameterized route at end, guarded by policy)
Route::middleware(['auth:sanctum', 'ability:admin,agent,user', 'password.changed'])->group(function (): void {
    Route::get('/{transaction}', [TransactionController::class, 'show'])->whereNumber('transaction');
});
