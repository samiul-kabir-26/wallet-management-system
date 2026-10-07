<?php

use Illuminate\Support\Facades\Route;
use Modules\Wallets\Http\Controllers\WalletController;

// User self-service wallet routes (Accessible by all tracks: user, agent, admin)
Route::middleware(['auth:sanctum', 'ability:admin,agent,user', 'password.changed'])->group(function (): void {
    Route::get('/me', [WalletController::class, 'me']);
});

// Administrative wallet management routes (Admin/Super Admin only)
Route::middleware(['auth:sanctum', 'ability:admin', 'password.changed'])->group(function (): void {
    Route::get('/admin/all', [WalletController::class, 'adminIndex']);
    Route::patch('/{wallet}/block', [WalletController::class, 'block']);
    Route::patch('/{wallet}/unblock', [WalletController::class, 'unblock']);
});
