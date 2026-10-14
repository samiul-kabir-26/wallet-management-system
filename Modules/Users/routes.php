<?php

use Illuminate\Support\Facades\Route;
use Modules\Users\Http\Controllers\UserController;

// Administrative staff routes
Route::middleware(['auth:sanctum', 'ability:admin', 'password.changed'])->group(function (): void {
    Route::post('/register', [UserController::class, 'register']);
    Route::get('/all-users', [UserController::class, 'index']);
    Route::post('/{user}/assign-role', [UserController::class, 'assignRole']);
    Route::patch('/{user}/approve-agent', [UserController::class, 'approveAgent']);
    Route::patch('/{user}/suspend-agent', [UserController::class, 'suspendAgent']);
    Route::patch('/{user}/verify-phone', [UserController::class, 'verifyPhone']);
    Route::get('/{user}/login-activity', [UserController::class, 'loginActivity']);
    Route::get('/{user}/audit-trail', [UserController::class, 'auditTrail']);
});

// Profile detail and update routes (Admin, Agent, User - UserPolicy enforces self or admin)
Route::middleware(['auth:sanctum', 'ability:admin,agent,user', 'password.changed'])->group(function (): void {
    Route::get('/lookup', [UserController::class, 'lookup'])->middleware('throttle:30,1');
    Route::get('/{user}', [UserController::class, 'show']);
    Route::patch('/{user}', [UserController::class, 'update']);
});
