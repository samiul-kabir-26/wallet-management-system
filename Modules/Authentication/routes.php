<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\AuthController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/agent/login', [AuthController::class, 'loginAgent']);
Route::post('/user/login', [AuthController::class, 'loginUser']);
