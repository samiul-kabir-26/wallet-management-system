<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\AuthController;
use Modules\Roles\Http\Controllers\PermissionController;

Route::prefix('v1/auth')->group(base_path('Modules/Authentication/routes.php'));
Route::prefix('v1/users')->group(base_path('Modules/Users/routes.php'));
Route::prefix('v1/wallets')->group(base_path('Modules/Wallets/routes.php'));
Route::prefix('v1/transactions')->group(base_path('Modules/Transactions/routes.php'));
Route::prefix('v1/system-settings')->group(base_path('Modules/SystemSettings/routes.php'));
Route::prefix('v1/roles')->group(base_path('Modules/Roles/routes.php'));
Route::prefix('v1/approvals')->group(base_path('Modules/Approvals/routes.php'));
Route::prefix('v1/reports')->group(base_path('Modules/Reports/routes.php'));
Route::prefix('v1')->group(base_path('Modules/Payments/routes.php'));

// Users & Permissions endpoints with admin protection
Route::middleware(['auth:sanctum', 'ability:admin', 'password.changed'])->group(function (): void {
    Route::patch('v1/users/{user}/grant-admin-access', [AuthController::class, 'grantAdminAccess']);
    Route::get('v1/permissions', [PermissionController::class, 'index']);
});
