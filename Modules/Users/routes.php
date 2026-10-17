<?php

use Illuminate\Support\Facades\Route;
use Modules\Users\Http\Controllers\AccountController;
use Modules\Users\Http\Controllers\UserController;

// The signed-in account managing itself (any panel). Registered before /{user} so "me" is not bound as an ID.
Route::middleware(['auth:sanctum', 'ability:admin,agent,user', 'password.changed'])->prefix('me')->group(function (): void {
    Route::get('/', [AccountController::class, 'show']);
    Route::patch('/', [AccountController::class, 'update']);
    Route::post('/avatar', [AccountController::class, 'updateAvatar'])->middleware('throttle:10,1');
    Route::delete('/avatar', [AccountController::class, 'removeAvatar']);
    Route::post('/phone', [AccountController::class, 'requestPhoneAddition'])->middleware('throttle:phone-otp');
    Route::post('/phone/confirm', [AccountController::class, 'confirmPhoneAddition'])->middleware('throttle:10,1');
    Route::post('/phone-verification', [AccountController::class, 'requestPhoneVerification'])->middleware('throttle:phone-otp');
    Route::post('/phone-verification/confirm', [AccountController::class, 'confirmPhoneVerification'])->middleware('throttle:10,1');
});

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
