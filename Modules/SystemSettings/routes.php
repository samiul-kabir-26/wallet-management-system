<?php

use Illuminate\Support\Facades\Route;
use Modules\SystemSettings\Http\Controllers\SystemSettingController;

// View settings (any authenticated role)
Route::middleware(['auth:sanctum', 'ability:admin,agent,user', 'password.changed'])->group(function (): void {
    Route::get('/', [SystemSettingController::class, 'index']);
    Route::get('/session-timeouts', [SystemSettingController::class, 'sessionTimeouts']);
});

// Update settings (admin only)
Route::middleware(['auth:sanctum', 'ability:admin', 'password.changed'])->group(function (): void {
    Route::patch('/', [SystemSettingController::class, 'update']);
    Route::patch('/session-timeouts', [SystemSettingController::class, 'updateSessionTimeouts']);
});
