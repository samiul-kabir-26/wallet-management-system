<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Public marketing & auth entry routes
Route::inertia('/', 'Welcome')->name('home');
Route::inertia('/login', 'Auth/Login')->name('login');
Route::inertia('/register', 'Auth/Register')->name('register');

// Customer panel routes
Route::prefix('user')->name('user.')->group(function (): void {
    Route::inertia('/dashboard', 'User/Dashboard')->name('dashboard');
    Route::inertia('/wallet', 'User/Wallet')->name('wallet');
    Route::inertia('/transfer', 'User/Transfer')->name('transfer');
    Route::inertia('/history', 'User/History')->name('history');
});

// Agent panel routes
Route::prefix('agent')->name('agent.')->group(function (): void {
    Route::inertia('/dashboard', 'Agent/Dashboard')->name('dashboard');
    Route::inertia('/transactions', 'Agent/Transactions')->name('transactions');
    Route::inertia('/withdrawal', 'Agent/Withdrawal')->name('withdrawal');
});

// Administrative panel routes
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::inertia('/dashboard', 'Admin/Dashboard')->name('dashboard');
    Route::inertia('/users', 'Admin/Users')->name('users');
    Route::inertia('/agents', 'Admin/Agents')->name('agents');
    Route::inertia('/wallets', 'Admin/Wallets')->name('wallets');
    Route::inertia('/transactions', 'Admin/Transactions')->name('transactions');
    Route::inertia('/settings', 'Admin/Settings')->name('settings');
});
