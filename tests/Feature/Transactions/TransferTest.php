<?php

use App\Models\Cap;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Services\PinVerifier;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SystemSettingsSeeder::class);
    RateLimiter::clear(PinVerifier::getThrottleKey(1));
});

function createActorForTransfer(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

function createUserForTransfer(float $balance = 1000.00): User
{
    $user = createActorForTransfer('USER');
    Wallet::factory()->for($user)->balance($balance)->create();
    Cap::factory()->for($user)->usage(0.00, 0.00)->create();

    return $user;
}

test('user can successfully transfer funds to another user with valid PIN', function () {
    $sender = createUserForTransfer(1500.00);
    $recipient = createUserForTransfer(200.00);

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 500.00,
        'pin' => '12345',
        'description' => 'Rent split payment',
        'idempotency_key' => 'transfer-key-001',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Transfer completed successfully.')
        ->assertJsonPath('data.transaction.type', 'TRANSFER')
        ->assertJsonPath('data.transaction.amount', fn ($val) => (float) $val === 500.0)
        ->assertJsonPath('data.transaction.system_fee_amount', fn ($val) => (float) $val === 0.0)
        ->assertJsonPath('data.transaction.agent_commission_amount', fn ($val) => (float) $val === 0.0)
        ->assertJsonPath('data.transaction.user_id', $sender->id)
        ->assertJsonPath('data.transaction.sender_id', $sender->id)
        ->assertJsonPath('data.transaction.recipient_id', $recipient->id)
        ->assertJsonPath('data.transaction.agent_id', null)
        ->assertJsonPath('data.transaction.initiated_by', $sender->id)
        ->assertJsonPath('data.transaction.sender_wallet_balance_after', fn ($val) => (float) $val === 1000.0)
        ->assertJsonMissingPath('data.transaction.recipient_wallet_balance_after')
        ->assertJsonMissingPath('data.transaction.agent_wallet_balance_after');

    // Refresh wallet balances
    $sender->userWallet->refresh();
    $recipient->userWallet->refresh();
    expect((float) $sender->userWallet->balance)->toBe(1000.00)
        ->and((float) $recipient->userWallet->balance)->toBe(700.00);

    // Exactly one transaction row
    expect(Transaction::count())->toBe(1);

    $tx = Transaction::first();
    expect($tx->type)->toBe('TRANSFER')
        ->and((float) $tx->amount)->toBe(500.00)
        ->and((float) $tx->system_fee_amount)->toBe(0.00)
        ->and((float) $tx->agent_commission_amount)->toBe(0.00)
        ->and($tx->sender_id)->toBe($sender->id)
        ->and($tx->recipient_id)->toBe($recipient->id)
        ->and($tx->agent_id)->toBeNull()
        ->and((float) $tx->sender_wallet_balance_after)->toBe(1000.00)
        ->and((float) $tx->recipient_wallet_balance_after)->toBe(700.00)
        ->and($tx->agent_wallet_balance_after)->toBeNull();

    // Verify sender cap was incremented
    $senderCap = Cap::where('user_id', $sender->id)->first();
    expect((float) $senderCap->daily_used)->toBe(500.00)
        ->and((float) $senderCap->monthly_used)->toBe(500.00);

    // Verify recipient cap was NOT incremented
    $recipientCap = Cap::where('user_id', $recipient->id)->first();
    expect((float) $recipientCap->daily_used)->toBe(0.00)
        ->and((float) $recipientCap->monthly_used)->toBe(0.00);
});

test('transfer accepts recipientPhone in camelCase', function () {
    $sender = createUserForTransfer(1000.00);
    $recipient = createUserForTransfer(300.00);

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipientPhone' => $recipient->phone_number,
        'amount' => 250.00,
        'pin' => '12345',
        'idempotency_key' => 'camel-recipient-key',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.transaction.recipient_id', $recipient->id);

    $sender->userWallet->refresh();
    $recipient->userWallet->refresh();
    expect((float) $sender->userWallet->balance)->toBe(750.00)
        ->and((float) $recipient->userWallet->balance)->toBe(550.00);
});

test('submitting same idempotency_key with identical parameters returns existing transaction', function () {
    $sender = createUserForTransfer(1000.00);
    $recipient = createUserForTransfer(500.00);

    Sanctum::actingAs($sender, ['user']);

    $payload = [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 400.00,
        'pin' => '12345',
        'idempotency_key' => 'transfer-idem-001',
    ];

    $response1 = $this->postJson('/api/v1/transactions/transfer', $payload);
    $response2 = $this->postJson('/api/v1/transactions/transfer', $payload);

    $response1->assertStatus(201);
    $response2->assertSuccessful()
        ->assertJsonPath('data.transaction.id', $response1->json('data.transaction.id'));

    $sender->userWallet->refresh();
    $recipient->userWallet->refresh();
    expect((float) $sender->userWallet->balance)->toBe(600.00)
        ->and((float) $recipient->userWallet->balance)->toBe(900.00);

    expect(Transaction::count())->toBe(1);

    $senderCap = Cap::where('user_id', $sender->id)->first();
    expect((float) $senderCap->daily_used)->toBe(400.00);
});

test('reusing idempotency_key with different parameters is rejected with 409 conflict', function () {
    $sender = createUserForTransfer(1000.00);
    $recipient1 = createUserForTransfer(500.00);
    $recipient2 = createUserForTransfer(500.00);

    Sanctum::actingAs($sender, ['user']);

    // Initial transfer
    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient1->phone_number,
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'idem-conflict-key',
    ])->assertStatus(201);

    // Same key, different amount -> 409
    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient1->phone_number,
        'amount' => 200.00,
        'pin' => '12345',
        'idempotency_key' => 'idem-conflict-key',
    ])->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Idempotency key reused with different parameters.');

    // Same key, different recipient -> 409
    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient2->phone_number,
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'idem-conflict-key',
    ])->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Idempotency key reused with different parameters.');
});

test('transfer fails with wrong PIN and reports attempts left', function () {
    $sender = createUserForTransfer(1000.00);
    $recipient = createUserForTransfer(500.00);

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 100.00,
        'pin' => '99999',
        'idempotency_key' => 'wrong-pin-001',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Wrong PIN. 4 attempt(s) left.')
        ->assertJsonPath('errors.pin.0', 'Wrong PIN. 4 attempt(s) left.')
        ->assertJsonPath('meta.attempts_remaining', 4);

    expect(Transaction::count())->toBe(0);
});

test('exceeding PIN attempts locks out user with 429', function () {
    $sender = createUserForTransfer(1000.00);
    $recipient = createUserForTransfer(500.00);

    Sanctum::actingAs($sender, ['user']);

    // Attempt 1 to 4 -> 422
    for ($i = 1; $i <= 4; $i++) {
        $this->postJson('/api/v1/transactions/transfer', [
            'recipient_phone' => $recipient->phone_number,
            'amount' => 10.00,
            'pin' => '99999',
            'idempotency_key' => "lockout-test-{$i}",
        ])->assertStatus(422);
    }

    // 5th attempt -> 429 locked
    $fifth = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 10.00,
        'pin' => '99999',
        'idempotency_key' => 'lockout-test-5',
    ]);

    $fifth->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors.pin.0', fn ($msg) => str_contains($msg, 'locked'))
        ->assertJsonPath('meta.locked', true)
        ->assertJsonPath('meta.retry_after', fn ($val) => $val > 0)
        ->assertHeader('Retry-After');

    // Subsequent attempt even with correct PIN is blocked
    $subsequent = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 10.00,
        'pin' => '12345',
        'idempotency_key' => 'lockout-test-correct-after-locked',
    ]);

    $subsequent->assertStatus(429)
        ->assertHeader('Retry-After');
});

test('transfer fails when sender has insufficient balance', function () {
    $sender = createUserForTransfer(300.00);
    $recipient = createUserForTransfer(100.00);

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 500.00,
        'pin' => '12345',
        'idempotency_key' => 'transfer-insufficient',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $sender->userWallet->refresh();
    $recipient->userWallet->refresh();
    expect((float) $sender->userWallet->balance)->toBe(300.00)
        ->and((float) $recipient->userWallet->balance)->toBe(100.00);

    expect(Transaction::count())->toBe(0);

    $senderCap = Cap::where('user_id', $sender->id)->first();
    expect((float) $senderCap->daily_used)->toBe(0.00);
});

test('transfer fails when daily cap is exceeded', function () {
    $sender = createActorForTransfer('USER');
    Wallet::factory()->for($sender)->balance(2000.00)->create();
    Cap::factory()->for($sender)->usage(9500.00, 10000.00)->create();

    $recipient = createUserForTransfer(500.00);

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 600.00,
        'pin' => '12345',
        'idempotency_key' => 'transfer-exceed-daily',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $sender->userWallet->refresh();
    $recipient->userWallet->refresh();
    expect((float) $sender->userWallet->balance)->toBe(2000.00)
        ->and((float) $recipient->userWallet->balance)->toBe(500.00);

    expect(Transaction::count())->toBe(0);
});

test('transfer fails when monthly cap is exceeded', function () {
    $sender = createActorForTransfer('USER');
    Wallet::factory()->for($sender)->balance(2000.00)->create();
    Cap::factory()->for($sender)->usage(1000.00, 49500.00)->create();

    $recipient = createUserForTransfer(500.00);

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 600.00,
        'pin' => '12345',
        'idempotency_key' => 'transfer-exceed-monthly',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $sender->userWallet->refresh();
    $recipient->userWallet->refresh();
    expect((float) $sender->userWallet->balance)->toBe(2000.00)
        ->and((float) $recipient->userWallet->balance)->toBe(500.00);

    expect(Transaction::count())->toBe(0);
});

test('self-transfer is rejected', function () {
    $sender = createUserForTransfer(1000.00);

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $sender->phone_number,
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'transfer-self',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'You cannot transfer money to yourself.');

    expect(Transaction::count())->toBe(0);
});

test('transfer fails when sender wallet is blocked', function () {
    $sender = createActorForTransfer('USER');
    Wallet::factory()->for($sender)->balance(1000.00)->blocked()->create();
    Cap::factory()->for($sender)->usage(0.00, 0.00)->create();

    $recipient = createUserForTransfer(500.00);

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'sender-blocked',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    expect(Transaction::count())->toBe(0);
});

test('transfer fails when recipient wallet is blocked', function () {
    $sender = createUserForTransfer(1000.00);

    $recipient = createActorForTransfer('USER');
    Wallet::factory()->for($recipient)->balance(500.00)->blocked()->create();
    Cap::factory()->for($recipient)->usage(0.00, 0.00)->create();

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'recipient-blocked',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    expect(Transaction::count())->toBe(0);
});

test('transfer fails when recipient is not a user (e.g. agent)', function () {
    $sender = createUserForTransfer(1000.00);

    $agent = createActorForTransfer('AGENT');
    Wallet::factory()->for($agent)->balance(500.00)->create();

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $agent->phone_number,
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'recipient-not-user',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(Transaction::count())->toBe(0);
});

test('transfer validates recipient_phone and pin rules', function () {
    $sender = createUserForTransfer(1000.00);

    Sanctum::actingAs($sender, ['user']);

    // Missing phone
    $this->postJson('/api/v1/transactions/transfer', [
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'phone-val-missing',
    ])->assertStatus(422)->assertJsonValidationErrors(['recipient_phone']);

    // Missing pin
    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => '01711000000',
        'amount' => 100.00,
        'idempotency_key' => 'pin-val-missing',
    ])->assertStatus(422)->assertJsonValidationErrors(['pin']);

    // Invalid PIN length (< 5 digits)
    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => '01711000000',
        'amount' => 100.00,
        'pin' => '123',
        'idempotency_key' => 'pin-val-short',
    ])->assertStatus(422)->assertJsonValidationErrors(['pin']);

    // Non-numeric PIN
    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => '01711000000',
        'amount' => 100.00,
        'pin' => 'abcde',
        'idempotency_key' => 'pin-val-nonnumeric',
    ])->assertStatus(422)->assertJsonValidationErrors(['pin']);
});

test('agent or admin cannot initiate a transfer', function () {
    $sender = createUserForTransfer(1000.00);

    $agent = createActorForTransfer('AGENT');
    Wallet::factory()->for($agent)->balance(2000.00)->create();

    Sanctum::actingAs($agent, ['agent']);

    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $sender->phone_number,
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'agent-transfer',
    ])->assertStatus(403);

    $admin = createActorForTransfer('ADMIN');
    Wallet::factory()->for($admin)->balance(1000.00)->create();

    Sanctum::actingAs($admin, ['admin']);

    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $sender->phone_number,
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'admin-transfer',
    ])->assertStatus(403);
});

test('transferring to a deleted user number is rejected', function () {
    $sender = createUserForTransfer(1000.00);

    $deletedRecipient = createActorForTransfer('USER');
    Wallet::factory()->for($deletedRecipient)->balance(500.00)->create();
    Cap::factory()->for($deletedRecipient)->usage(0.00, 0.00)->create();
    $deletedRecipient->is_deleted = true;
    $deletedRecipient->save();

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $deletedRecipient->phone_number,
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'transfer-to-deleted',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['recipient_phone']);

    expect(Transaction::count())->toBe(0);
});

test('can_receive and the transfer endpoint give the same answer for the same recipient', function () {
    $sender = createUserForTransfer(1500.00);
    Sanctum::actingAs($sender, ['user']);

    // Case 1: Eligible recipient -> can_receive is true, transfer succeeds (201)
    $eligible = createUserForTransfer(200.00);
    $lookup1 = $this->getJson("/api/v1/users/lookup?phone_number={$eligible->phone_number}")->assertOk();
    expect($lookup1->json('data.can_receive'))->toBeTrue();

    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $eligible->phone_number,
        'amount' => 50.00,
        'pin' => '12345',
        'idempotency_key' => 'consistency-eligible',
    ])->assertStatus(201);

    // Case 2: Blocked wallet -> can_receive is false, transfer fails (403)
    $blocked = createActorForTransfer('USER');
    Wallet::factory()->for($blocked)->balance(200.00)->blocked()->create();
    $lookup2 = $this->getJson("/api/v1/users/lookup?phone_number={$blocked->phone_number}")->assertOk();
    expect($lookup2->json('data.can_receive'))->toBeFalse();

    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $blocked->phone_number,
        'amount' => 50.00,
        'pin' => '12345',
        'idempotency_key' => 'consistency-blocked',
    ])->assertStatus(403);

    // Case 3: Agent recipient -> can_receive is false, transfer fails (422)
    $agent = createActorForTransfer('AGENT');
    Wallet::factory()->agent()->for($agent)->balance(500.00)->create();
    $lookup3 = $this->getJson("/api/v1/users/lookup?phone_number={$agent->phone_number}")->assertOk();
    expect($lookup3->json('data.can_receive'))->toBeFalse();

    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $agent->phone_number,
        'amount' => 50.00,
        'pin' => '12345',
        'idempotency_key' => 'consistency-agent',
    ])->assertStatus(422);

    // Case 4: Self transfer -> can_receive is false, transfer fails (422)
    $lookup4 = $this->getJson("/api/v1/users/lookup?phone_number={$sender->phone_number}")->assertOk();
    expect($lookup4->json('data.can_receive'))->toBeFalse();

    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $sender->phone_number,
        'amount' => 50.00,
        'pin' => '12345',
        'idempotency_key' => 'consistency-self',
    ])->assertStatus(422);
});
