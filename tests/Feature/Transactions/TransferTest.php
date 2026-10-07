<?php

use App\Models\Cap;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SystemSettingsSeeder::class);
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

test('user can successfully transfer funds to another user', function () {
    $sender = createUserForTransfer(1500.00);
    $recipient = createUserForTransfer(200.00);

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_id' => $recipient->id,
        'amount' => 500.00,
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
        ->assertJsonPath('data.transaction.recipient_wallet_balance_after', fn ($val) => (float) $val === 700.0)
        ->assertJsonPath('data.transaction.agent_wallet_balance_after', null);

    // Refresh wallet balances
    $sender->wallet->refresh();
    $recipient->wallet->refresh();
    expect((float) $sender->wallet->balance)->toBe(1000.00)
        ->and((float) $recipient->wallet->balance)->toBe(700.00);

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

test('transfer accepts recipientId in camelCase', function () {
    $sender = createUserForTransfer(1000.00);
    $recipient = createUserForTransfer(300.00);

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipientId' => $recipient->id,
        'amount' => 250.00,
        'idempotency_key' => 'camel-recipient-key',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.transaction.recipient_id', $recipient->id);

    $sender->wallet->refresh();
    $recipient->wallet->refresh();
    expect((float) $sender->wallet->balance)->toBe(750.00)
        ->and((float) $recipient->wallet->balance)->toBe(550.00);
});

test('submitting same idempotency_key returns existing transaction without duplicate mutations', function () {
    $sender = createUserForTransfer(1000.00);
    $recipient = createUserForTransfer(500.00);

    Sanctum::actingAs($sender, ['user']);

    $payload = [
        'recipient_id' => $recipient->id,
        'amount' => 400.00,
        'idempotency_key' => 'transfer-idem-001',
    ];

    $response1 = $this->postJson('/api/v1/transactions/transfer', $payload);
    $response2 = $this->postJson('/api/v1/transactions/transfer', $payload);

    $response1->assertStatus(201);
    $response2->assertSuccessful()
        ->assertJsonPath('data.transaction.id', $response1->json('data.transaction.id'));

    $sender->wallet->refresh();
    $recipient->wallet->refresh();
    expect((float) $sender->wallet->balance)->toBe(600.00)
        ->and((float) $recipient->wallet->balance)->toBe(900.00);

    expect(Transaction::count())->toBe(1);

    $senderCap = Cap::where('user_id', $sender->id)->first();
    expect((float) $senderCap->daily_used)->toBe(400.00);
});

test('transfer fails when sender has insufficient balance', function () {
    $sender = createUserForTransfer(300.00);
    $recipient = createUserForTransfer(100.00);

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_id' => $recipient->id,
        'amount' => 500.00,
        'idempotency_key' => 'transfer-insufficient',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $sender->wallet->refresh();
    $recipient->wallet->refresh();
    expect((float) $sender->wallet->balance)->toBe(300.00)
        ->and((float) $recipient->wallet->balance)->toBe(100.00);

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
        'recipient_id' => $recipient->id,
        'amount' => 600.00,
        'idempotency_key' => 'transfer-exceed-daily',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $sender->wallet->refresh();
    $recipient->wallet->refresh();
    expect((float) $sender->wallet->balance)->toBe(2000.00)
        ->and((float) $recipient->wallet->balance)->toBe(500.00);

    expect(Transaction::count())->toBe(0);
});

test('transfer fails when monthly cap is exceeded', function () {
    $sender = createActorForTransfer('USER');
    Wallet::factory()->for($sender)->balance(2000.00)->create();
    Cap::factory()->for($sender)->usage(1000.00, 49500.00)->create();

    $recipient = createUserForTransfer(500.00);

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_id' => $recipient->id,
        'amount' => 600.00,
        'idempotency_key' => 'transfer-exceed-monthly',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $sender->wallet->refresh();
    $recipient->wallet->refresh();
    expect((float) $sender->wallet->balance)->toBe(2000.00)
        ->and((float) $recipient->wallet->balance)->toBe(500.00);

    expect(Transaction::count())->toBe(0);
});

test('self-transfer is rejected with validation error', function () {
    $sender = createUserForTransfer(1000.00);

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_id' => $sender->id,
        'amount' => 100.00,
        'idempotency_key' => 'transfer-self',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['recipient_id']);

    expect(Transaction::count())->toBe(0);
});

test('transfer fails when sender wallet is blocked', function () {
    $sender = createActorForTransfer('USER');
    Wallet::factory()->for($sender)->balance(1000.00)->blocked()->create();
    Cap::factory()->for($sender)->usage(0.00, 0.00)->create();

    $recipient = createUserForTransfer(500.00);

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_id' => $recipient->id,
        'amount' => 100.00,
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
        'recipient_id' => $recipient->id,
        'amount' => 100.00,
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
        'recipient_id' => $agent->id,
        'amount' => 100.00,
        'idempotency_key' => 'recipient-not-user',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(Transaction::count())->toBe(0);
});

test('agent or admin cannot initiate a transfer', function () {
    $sender = createUserForTransfer(1000.00);

    $agent = createActorForTransfer('AGENT');
    Wallet::factory()->for($agent)->balance(2000.00)->create();

    Sanctum::actingAs($agent, ['agent']);

    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_id' => $sender->id,
        'amount' => 100.00,
        'idempotency_key' => 'agent-transfer',
    ])->assertStatus(403);

    $admin = createActorForTransfer('ADMIN');
    Wallet::factory()->for($admin)->balance(1000.00)->create();

    Sanctum::actingAs($admin, ['admin']);

    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_id' => $sender->id,
        'amount' => 100.00,
        'idempotency_key' => 'admin-transfer',
    ])->assertStatus(403);
});
