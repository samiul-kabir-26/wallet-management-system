<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Public marketing & auth entry routes
Route::inertia('/', 'Welcome')->name('home');
Route::inertia('/login', 'Auth/Login')->name('login');
Route::inertia('/register', 'Auth/Register')->name('register');

// Emailed signed links open these pages, which post the signature back to the matching API route
Route::get('/accept-invite/{user}', fn (int $user) => Inertia::render('Auth/AcceptInvite', ['userId' => $user]))
    ->whereNumber('user')
    ->name('accept-invite');
Route::get('/reset-pin/{user}', fn (int $user) => Inertia::render('Auth/ResetPin', ['userId' => $user]))
    ->whereNumber('user')
    ->name('reset-pin');

// Customer panel routes
Route::prefix('user')->name('user.')->group(function (): void {
    Route::inertia('/dashboard', 'User/Dashboard')->name('dashboard');
    Route::inertia('/wallet', 'User/Wallet')->name('wallet');
    Route::inertia('/transfer', 'User/Transfer')->name('transfer');
    Route::inertia('/history', 'User/History')->name('history');
    Route::inertia('/account', 'User/Account')->name('account');
});

// Agent panel routes
Route::prefix('agent')->name('agent.')->group(function (): void {
    Route::inertia('/dashboard', 'Agent/Dashboard')->name('dashboard');
    Route::inertia('/cash-in', 'Agent/CashIn')->name('cash-in');
    Route::inertia('/transactions', 'Agent/Transactions')->name('transactions');
    Route::inertia('/withdrawal', 'Agent/Withdrawal')->name('withdrawal');
    Route::inertia('/account', 'Agent/Account')->name('account');
});

// Administrative panel routes
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::inertia('/dashboard', 'Admin/Dashboard')->name('dashboard');
    Route::inertia('/users', 'Admin/Users')->name('users');
    Route::get('/users/{user}', fn (int $user) => Inertia::render('Admin/UserProfile', ['userId' => $user]))
        ->whereNumber('user')
        ->name('users.show');
    Route::inertia('/withdrawals', 'Admin/Withdrawals')->name('withdrawals');
    Route::inertia('/roles', 'Admin/Roles')->name('roles');
    Route::inertia('/agents', 'Admin/Agents')->name('agents');
    Route::inertia('/wallets', 'Admin/Wallets')->name('wallets');
    Route::inertia('/transactions', 'Admin/Transactions')->name('transactions');
    Route::inertia('/settings', 'Admin/Settings')->name('settings');
    Route::inertia('/account', 'Admin/Account')->name('account');
});

// TEMPORARY: local-only OTP and email link inspection pages. Remove this line and routes/dev.php before committing.
if (app()->isLocal()) {
    require __DIR__.'/dev.php';
}
