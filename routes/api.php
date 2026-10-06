<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\AuthController;

Route::prefix('v1/auth')->group(base_path('Modules/Authentication/routes.php'));
Route::prefix('v1/users')->group(base_path('Modules/Users/routes.php'));
Route::prefix('v1/wallets')->group(base_path('Modules/Wallets/routes.php'));

// Users endpoints (temporary placement until Modules/Users routing is fully wired, per CLAUDE.md §10)
Route::middleware(['auth:sanctum', 'ability:admin', 'password.changed'])->group(function (): void {
    Route::patch('v1/users/{user}/grant-admin-access', [AuthController::class, 'grantAdminAccess']);
});
