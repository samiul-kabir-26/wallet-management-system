<?php

use Illuminate\Support\Facades\Route;
use Modules\Approvals\Http\Controllers\ApprovalController;

// Maker-checker: staff review each other's sensitive actions
Route::middleware(['auth:sanctum', 'ability:admin', 'password.changed'])->group(function (): void {
    Route::get('/', [ApprovalController::class, 'index']);
    Route::get('/{approval}', [ApprovalController::class, 'show'])->whereNumber('approval');
    Route::post('/{approval}/approve', [ApprovalController::class, 'approve'])->whereNumber('approval')->middleware('throttle:30,1');
    Route::post('/{approval}/reject', [ApprovalController::class, 'reject'])->whereNumber('approval')->middleware('throttle:30,1');
    Route::post('/{approval}/cancel', [ApprovalController::class, 'cancel'])->whereNumber('approval');
});
