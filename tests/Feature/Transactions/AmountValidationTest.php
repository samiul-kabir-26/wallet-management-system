<?php

use App\Models\Cap;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Modules\Transactions\Exceptions\InvalidAmountException;
use Modules\Transactions\Services\TransactionService;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SystemSettingsSeeder::class);
});

function createActorForAmountTest(string $roleName = 'USER', float $balance = 50000.00): User
{
    $user = User::factory()->create([
        'pin' => Hash::make('12345'),
    ]);
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    Wallet::factory()->for($user)->balance($balance)->create();
    Cap::factory()->for($user)->state(['daily_cap' => 100000.00, 'monthly_cap' => 500000.00])->usage(0.00, 0.00)->create();

    return $user;
}

test('amount validation rejects invalid formats, non-numeric, zero, negative, and out-of-bound values with 422', function (mixed $invalidAmount) {
    $sender = createActorForAmountTest('USER', 50000.00);
    $recipient = createActorForAmountTest('USER', 1000.00);

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => $invalidAmount,
        'pin' => '12345',
        'idempotency_key' => 'inv-amount-'.md5((string) $invalidAmount),
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);

    // Database is untouched
    expect(Transaction::count())->toBe(0);
    $sender->userWallet->refresh();
    $recipient->userWallet->refresh();
    expect((float) $sender->userWallet->balance)->toBe(50000.00)
        ->and((float) $recipient->userWallet->balance)->toBe(1000.00);
})->with([
    'three decimal places (0.001)' => 0.001,
    'three decimal places (10.999)' => 10.999,
    'zero' => 0,
    'negative (-5)' => -5,
    'negative float (-10.50)' => -10.50,
    'above maximum limit (30000)' => 30000.00,
    'non-numeric string ("abc")' => 'abc',
    'three decimal string ("10.123")' => '10.123',
]);

test('amount validation accepts integers, 1 decimal float, and 2 decimal strings', function (mixed $validAmount, float $expectedAmount) {
    $sender = createActorForAmountTest('USER', 50000.00);
    $recipient = createActorForAmountTest('USER', 1000.00);

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => $validAmount,
        'pin' => '12345',
        'idempotency_key' => 'valid-amount-'.md5((string) $validAmount),
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.transaction.amount', fn ($val) => (float) $val === $expectedAmount);

    expect(Transaction::count())->toBe(1);
})->with([
    'integer (10)' => [10, 10.00],
    '1 decimal float (10.5)' => [10.5, 10.50],
    '2 decimal string ("10.50")' => ['10.50', 10.50],
    'exact max limit string ("25000.00")' => ['25000.00', 25000.00],
]);

test('transaction service throws InvalidAmountException when amount is zero or negative', function (mixed $zeroOrNegative) {
    $sender = createActorForAmountTest('USER', 50000.00);
    $recipient = createActorForAmountTest('USER', 1000.00);

    $service = app(TransactionService::class);

    expect(fn () => $service->transfer($sender, $recipient->phone_number, $zeroOrNegative, 'tx-srv-inv-key', '12345'))
        ->toThrow(InvalidAmountException::class);

    expect(fn () => $service->topUp($sender, $zeroOrNegative, 'topup-srv-inv-key'))
        ->toThrow(InvalidAmountException::class);
})->with([
    'zero int' => 0,
    'zero string' => '0.00',
    'negative float' => -5.00,
    'negative string' => '-10.50',
]);
