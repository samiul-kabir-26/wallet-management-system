<?php

use Illuminate\Support\Facades\Route;
use Modules\Users\Http\Controllers\UserController;

// Administrative staff routes
Route::middleware(['auth:sanctum', 'ability:admin', 'password.changed'])->group(function (): void {
    Route::post('/register', [UserController::class, 'register']);
    Route::get('/all-users', [UserController::class, 'index']);
    Route::patch('/{user}/approve-agent', [UserController::class, 'approveAgent']);
    Route::patch('/{user}/suspend-agent', [UserController::class, 'suspendAgent']);
});

// Profile detail and update routes (Admin, Agent, User - UserPolicy enforces self or admin)
Route::middleware(['auth:sanctum', 'ability:admin,agent,user', 'password.changed'])->group(function (): void {
    Route::get('/{user}', [UserController::class, 'show']);
    Route::patch('/{user}', [UserController::class, 'update']);
});
