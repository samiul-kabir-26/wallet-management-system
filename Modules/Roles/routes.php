<?php

use Illuminate\Support\Facades\Route;
use Modules\Roles\Http\Controllers\RoleController;

Route::middleware(['auth:sanctum', 'ability:admin', 'password.changed'])->group(function (): void {
    Route::get('/', [RoleController::class, 'index']);
    Route::post('/', [RoleController::class, 'store']);
    Route::get('{role}', [RoleController::class, 'show']);
    Route::patch('{role}', [RoleController::class, 'update']);
    Route::delete('{role}', [RoleController::class, 'destroy']);
    Route::put('{role}/permissions', [RoleController::class, 'syncPermissions']);
});
