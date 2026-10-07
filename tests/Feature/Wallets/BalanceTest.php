<?php

use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Modules\Wallets\Exceptions\InsufficientBalanceException;
use Modules\Wallets\Exceptions\WalletBlockedException;
use Modules\Wallets\Services\WalletService;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->walletService = app(WalletService::class);
});

test('credit and debit operations maintain exact decimal precision', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->balance(100.55)->create();

    // 1. Credit 0.45
    $this->walletService->updateBalance($user->id, 0.45, 'CREDIT');
    $wallet->refresh();
    expect((string) $wallet->balance)->toBe('101.00');

    // 2. Debit 50.75
    $this->walletService->updateBalance($user->id, 50.75, 'DEBIT');
    $wallet->refresh();
    expect((string) $wallet->balance)->toBe('50.25');
});

test('cannot debit more than available wallet balance', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->balance(50.00)->create();

    expect(fn () => $this->walletService->updateBalance($user->id, 50.01, 'DEBIT'))
        ->toThrow(InsufficientBalanceException::class);

    $wallet->refresh();
    expect((string) $wallet->balance)->toBe('50.00');
});

test('cannot debit or credit a blocked wallet', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->balance(200.00)->blocked()->create();

    expect(fn () => $this->walletService->updateBalance($user->id, 50.00, 'DEBIT'))
        ->toThrow(WalletBlockedException::class);

    expect(fn () => $this->walletService->updateBalance($user->id, 50.00, 'CREDIT'))
        ->toThrow(WalletBlockedException::class);

    $wallet->refresh();
    expect((string) $wallet->balance)->toBe('200.00');
});

test('sequential debits and credits maintain balance integrity', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->balance(1000.00)->create();

    $this->walletService->updateBalance($user->id, 250.00, 'DEBIT');
    $this->walletService->updateBalance($user->id, 150.00, 'DEBIT');
    $this->walletService->updateBalance($user->id, 400.00, 'CREDIT');
    $this->walletService->updateBalance($user->id, 300.00, 'DEBIT');

    $wallet->refresh();
    // 1000 - 250 - 150 + 400 - 300 = 700.00
    expect((string) $wallet->balance)->toBe('700.00');
});
