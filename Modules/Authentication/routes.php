<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\AuthController;
use Modules\Authentication\Http\Controllers\SecurityController;

// Public routes
Route::post('/register/request-otp', [AuthController::class, 'requestRegisterOtp'])->middleware('throttle:phone-otp');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/agent/login', [AuthController::class, 'loginAgent'])->middleware('throttle:login');
Route::post('/user/login', [AuthController::class, 'loginUser'])->middleware('throttle:login');
Route::post('/admin/login', [AuthController::class, 'adminLoginRequestOtp'])->middleware('throttle:login');
Route::post('/admin/verify-otp', [AuthController::class, 'adminVerifyOtp'])->middleware('throttle:login');
Route::post('/verify-device', [AuthController::class, 'verifyDevice'])->middleware('throttle:login');
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

// PIN change for a signed-in agent or customer who knows their current PIN
Route::post('/change-pin', [AuthController::class, 'changePin'])
    ->middleware(['auth:sanctum', 'ability:agent,user', 'throttle:10,1']);

// Protected routes (Requires valid Sanctum token with 'admin' ability and changed password)
Route::middleware(['auth:sanctum', 'ability:admin', 'password.changed'])->group(function (): void {
    Route::post('/set-pin/request-otp', [AuthController::class, 'requestSetPinOtp']);
    Route::post('/set-pin', [AuthController::class, 'setPin']);
    Route::post('/pin-reset/initiate', [AuthController::class, 'initiatePinReset']);

    // Authenticator app (TOTP) for staff sign-in
    Route::get('/totp', [SecurityController::class, 'totpStatus']);
    Route::post('/totp/setup', [SecurityController::class, 'totpSetup']);
    Route::post('/totp/confirm', [SecurityController::class, 'totpConfirm'])->middleware('throttle:10,1');
    Route::post('/totp/disable', [SecurityController::class, 'totpDisable'])->middleware('throttle:10,1');
});

// The signed-in account's sessions and trusted devices (any panel)
Route::middleware(['auth:sanctum', 'ability:admin,agent,user', 'password.changed'])->group(function (): void {
    Route::get('/sessions', [SecurityController::class, 'sessions']);
    Route::delete('/sessions/{session}', [SecurityController::class, 'revokeSession'])->whereNumber('session');
    Route::get('/devices', [SecurityController::class, 'devices']);
    Route::delete('/devices/{device}', [SecurityController::class, 'forgetDevice'])->whereNumber('device');
});

// Session routes
Route::middleware(['auth:sanctum'])->group(function (): void {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);
});
