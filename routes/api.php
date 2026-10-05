<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\AuthController;

Route::prefix('v1/auth')->group(base_path('Modules/Authentication/routes.php'));

// Users endpoints (temporary placement until Modules/Users is created, per CLAUDE.md §10)
Route::middleware(['auth:sanctum', 'ability:admin'])->group(function (): void {
    Route::patch('v1/users/{user}/grant-admin-access', [AuthController::class, 'grantAdminAccess']);
});
