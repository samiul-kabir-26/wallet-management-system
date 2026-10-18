<?php

use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\Controllers\SslCommerzCallbackController;
use Modules\Payments\Http\Controllers\TopUpController;

// USER endpoints: start a top-up and re-check one with the gateway
Route::middleware(['auth:sanctum', 'ability:user', 'password.changed'])->group(function (): void {
    Route::post('/transactions/top-up', [TopUpController::class, 'store']);
    Route::post('/transactions/top-up/{transaction}/refresh', [TopUpController::class, 'refresh'])
        ->whereNumber('transaction')
        ->middleware('throttle:10,1');
});

// SSLCommerz IPN (server to server). Unauthenticated: the payload is only a hint and is validated with SSLCommerz.
Route::post('/payments/sslcommerz/ipn', [SslCommerzCallbackController::class, 'ipn'])
    ->middleware('throttle:60,1')
    ->name('payments.sslcommerz.ipn');
