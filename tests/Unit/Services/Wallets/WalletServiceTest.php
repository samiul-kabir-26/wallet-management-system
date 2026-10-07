<?php

use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Wallets\Exceptions\InsufficientBalanceException;
use Modules\Wallets\Exceptions\WalletBlockedException;
use Modules\Wallets\Exceptions\WalletNotFoundException;
use Modules\Wallets\Services\WalletService;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->service = app(WalletService::class);
});

test('credit balance increases wallet balance atomically', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->balance(50.00)->create();

    $updatedWallet = $this->service->updateBalance($user->id, 100.50, 'CREDIT');

    expect((float) $updatedWallet->balance)->toBe(150.50);

    $wallet->refresh();
    expect((float) $wallet->balance)->toBe(150.50);
});

test('debit balance decreases wallet balance atomically', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->balance(150.50)->create();

    $updatedWallet = $this->service->updateBalance($user->id, 50.25, 'DEBIT');

    expect((float) $updatedWallet->balance)->toBe(100.25);

    $wallet->refresh();
    expect((float) $wallet->balance)->toBe(100.25);
});

test('debit exceeding available balance throws InsufficientBalanceException', function () {
    $user = User::factory()->create();
    Wallet::factory()->for($user)->balance(50.00)->create();

    expect(fn () => $this->service->updateBalance($user->id, 50.01, 'DEBIT'))
        ->toThrow(InsufficientBalanceException::class);
});

test('updating balance on a blocked wallet throws WalletBlockedException', function () {
    $user = User::factory()->create();
    Wallet::factory()->for($user)->balance(100.00)->blocked()->create();

    expect(fn () => $this->service->updateBalance($user->id, 20.00, 'DEBIT'))
        ->toThrow(WalletBlockedException::class);

    expect(fn () => $this->service->updateBalance($user->id, 20.00, 'CREDIT'))
        ->toThrow(WalletBlockedException::class);
});

test('updating balance on nonexistent user wallet throws WalletNotFoundException', function () {
    expect(fn () => $this->service->updateBalance(99999, 10.00, 'CREDIT'))
        ->toThrow(WalletNotFoundException::class);
});

test('lockWalletsForUsers locks and returns wallets sorted deterministically by user_id', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $wallet1 = Wallet::factory()->for($user1)->balance(100.00)->create();
    $wallet2 = Wallet::factory()->for($user2)->balance(200.00)->create();

    // Ensure order is tested regardless of input order
    $higherId = max($user1->id, $user2->id);
    $lowerId = min($user1->id, $user2->id);

    $locked = $this->service->lockWalletsForUsers([$higherId, $lowerId]);

    expect($locked)->toHaveCount(2)
        ->and($locked->first()->user_id)->toBe($lowerId)
        ->and($locked->last()->user_id)->toBe($higherId);
});
