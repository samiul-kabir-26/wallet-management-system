<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\AuthController;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/agent/login', [AuthController::class, 'loginAgent']);
Route::post('/user/login', [AuthController::class, 'loginUser']);
Route::post('/admin/login', [AuthController::class, 'adminLoginRequestOtp']);
Route::post('/admin/verify-otp', [AuthController::class, 'adminVerifyOtp']);
Route::post('/accept-invite/{user}', [AuthController::class, 'acceptInvite'])
    ->name('auth.accept-invite')
    ->middleware('signed');
Route::post('/reset-pin/{user}', [AuthController::class, 'resetPin'])
    ->name('auth.reset-pin')
    ->middleware('signed');

// Password change (Accessible with either 'password-change' or 'admin' ability)
Route::post('/change-password', [AuthController::class, 'changePassword'])
    ->middleware(['auth:sanctum', 'ability:password-change,admin']);

// Protected routes (Requires valid Sanctum token with 'admin' ability)
Route::middleware(['auth:sanctum', 'ability:admin'])->group(function (): void {
    Route::post('/set-pin/request-otp', [AuthController::class, 'requestSetPinOtp']);
    Route::post('/set-pin', [AuthController::class, 'setPin']);
    Route::post('/pin-reset/initiate', [AuthController::class, 'initiatePinReset']);
});

Route::middleware(['auth:sanctum'])->group(function (): void {
    Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
