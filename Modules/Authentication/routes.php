<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\AuthController;

// Public routes
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/agent/login', [AuthController::class, 'loginAgent'])->middleware('throttle:login');
Route::post('/user/login', [AuthController::class, 'loginUser'])->middleware('throttle:login');
Route::post('/admin/login', [AuthController::class, 'adminLoginRequestOtp'])->middleware('throttle:login');
Route::post('/admin/verify-otp', [AuthController::class, 'adminVerifyOtp'])->middleware('throttle:login');
Route::post('/accept-invite/{user}', [AuthController::class, 'acceptInvite'])
    ->name('auth.accept-invite')
    ->middleware('signed');
Route::post('/reset-pin/{user}', [AuthController::class, 'resetPin'])
    ->name('auth.reset-pin')
    ->middleware('signed');

Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:login');

// Password change (Accessible with either 'password-change' or 'admin' ability)
Route::post('/change-password', [AuthController::class, 'changePassword'])
    ->name('auth.change-password')
    ->middleware(['auth:sanctum', 'ability:password-change,admin']);

// Protected routes (Requires valid Sanctum token with 'admin' ability and changed password)
Route::middleware(['auth:sanctum', 'ability:admin', 'password.changed'])->group(function (): void {
    Route::post('/set-pin/request-otp', [AuthController::class, 'requestSetPinOtp']);
    Route::post('/set-pin', [AuthController::class, 'setPin']);
    Route::post('/pin-reset/initiate', [AuthController::class, 'initiatePinReset']);
});

// Session routes
Route::middleware(['auth:sanctum'])->group(function (): void {
    Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
