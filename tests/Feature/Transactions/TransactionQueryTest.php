<?php

use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SystemSettingsSeeder::class);
});

function createActorForQuery(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

test('user can only view their own transactions in history', function () {
    $user1 = createActorForQuery('USER');
    $user2 = createActorForQuery('USER');
    $user3 = createActorForQuery('USER');

    // Tx 1: User 1 transfers to User 2
    Transaction::create([
        'user_id' => $user1->id,
        'sender_id' => $user1->id,
        'recipient_id' => $user2->id,
        'initiated_by' => $user1->id,
        'type' => 'TRANSFER',
        'amount' => '100.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'tx-1',
    ]);

    // Tx 2: User 3 tops up (User 1 is NOT involved)
    Transaction::create([
        'user_id' => $user3->id,
        'sender_id' => null,
        'recipient_id' => null,
        'initiated_by' => $user3->id,
        'type' => 'TOP_UP',
        'amount' => '500.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'tx-2',
    ]);

    // Tx 3: User 3 transfers to User 1 (User 1 is recipient)
    Transaction::create([
        'user_id' => $user3->id,
        'sender_id' => $user3->id,
        'recipient_id' => $user1->id,
        'initiated_by' => $user3->id,
        'type' => 'TRANSFER',
        'amount' => '200.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'tx-3',
    ]);

    Sanctum::actingAs($user1, ['user']);

    $response = $this->getJson('/api/v1/transactions/history');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data.transactions')
        ->assertJsonPath('data.pagination.total', 2);

    $transactionIds = collect($response->json('data.transactions'))->pluck('id')->all();
    $tx1 = Transaction::where('idempotency_key', 'tx-1')->first();
    $tx2 = Transaction::where('idempotency_key', 'tx-2')->first();
    $tx3 = Transaction::where('idempotency_key', 'tx-3')->first();

    expect($transactionIds)->toContain($tx1->id)
        ->and($transactionIds)->toContain($tx3->id)
        ->and($transactionIds)->not->toContain($tx2->id);
});

test('history can be filtered by transaction type and status', function () {
    $user = createActorForQuery('USER');

    Transaction::create([
        'user_id' => $user->id,
        'sender_id' => $user->id,
        'initiated_by' => $user->id,
        'type' => 'TOP_UP',
        'amount' => '100.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'filter-tx-1',
    ]);

    Transaction::create([
        'user_id' => $user->id,
        'sender_id' => $user->id,
        'initiated_by' => $user->id,
        'type' => 'CASH_OUT',
        'amount' => '200.00',
        'system_fee_amount' => '10.00',
        'system_fee_rate' => '0.0500',
        'agent_commission_amount' => '2.00',
        'agent_commission_rate' => '0.0100',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'filter-tx-2',
    ]);

    Sanctum::actingAs($user, ['user']);

    $response = $this->getJson('/api/v1/transactions/history?type=TOP_UP');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.transactions')
        ->assertJsonPath('data.transactions.0.type', 'TOP_UP');
});

test('history supports pagination parameters', function () {
    $user = createActorForQuery('USER');

    for ($i = 1; $i <= 5; $i++) {
        Transaction::create([
            'user_id' => $user->id,
            'sender_id' => $user->id,
            'initiated_by' => $user->id,
            'type' => 'TOP_UP',
            'amount' => '50.00',
            'system_fee_amount' => '0.00',
            'system_fee_rate' => '0.0000',
            'agent_commission_amount' => '0.00',
            'agent_commission_rate' => '0.0000',
            'currency' => 'BDT',
            'status' => 'COMPLETED',
            'idempotency_key' => "page-tx-{$i}",
        ]);
    }

    Sanctum::actingAs($user, ['user']);

    $response = $this->getJson('/api/v1/transactions/history?per_page=2&page=1');

    $response->assertStatus(200)
        ->assertJsonPath('data.pagination.total', 5)
        ->assertJsonPath('data.pagination.per_page', 2)
        ->assertJsonPath('data.pagination.current_page', 1)
        ->assertJsonPath('data.pagination.last_page', 3)
        ->assertJsonCount(2, 'data.transactions');
});

test('transaction participant can view transaction details', function () {
    $user1 = createActorForQuery('USER');
    $user2 = createActorForQuery('USER');

    $tx = Transaction::create([
        'user_id' => $user1->id,
        'sender_id' => $user1->id,
        'recipient_id' => $user2->id,
        'initiated_by' => $user1->id,
        'type' => 'TRANSFER',
        'amount' => '300.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'show-tx-1',
    ]);

    // Sender can view
    Sanctum::actingAs($user1, ['user']);
    $this->getJson("/api/v1/transactions/{$tx->id}")
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.transaction.id', $tx->id)
        ->assertJsonPath('data.transaction.amount', fn ($val) => (float) $val === 300.0);

    // Recipient can view
    Sanctum::actingAs($user2, ['user']);
    $this->getJson("/api/v1/transactions/{$tx->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.transaction.id', $tx->id);
});

test('non-participant user is forbidden from viewing transaction details', function () {
    $user1 = createActorForQuery('USER');
    $user2 = createActorForQuery('USER');
    $outsider = createActorForQuery('USER');

    $tx = Transaction::create([
        'user_id' => $user1->id,
        'sender_id' => $user1->id,
        'recipient_id' => $user2->id,
        'initiated_by' => $user1->id,
        'type' => 'TRANSFER',
        'amount' => '300.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'forbidden-show-tx',
    ]);

    Sanctum::actingAs($outsider, ['user']);
    $this->getJson("/api/v1/transactions/{$tx->id}")
        ->assertStatus(403);
});

test('admin can view any transaction details', function () {
    $user1 = createActorForQuery('USER');
    $admin = createActorForQuery('ADMIN');

    $tx = Transaction::create([
        'user_id' => $user1->id,
        'sender_id' => $user1->id,
        'initiated_by' => $user1->id,
        'type' => 'TOP_UP',
        'amount' => '500.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'admin-view-tx',
    ]);

    Sanctum::actingAs($admin, ['admin']);
    $this->getJson("/api/v1/transactions/{$tx->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.transaction.id', $tx->id);
});

test('admin can view all transactions via admin audit endpoint', function () {
    $user1 = createActorForQuery('USER');
    $user2 = createActorForQuery('USER');
    $admin = createActorForQuery('ADMIN');

    Transaction::create([
        'user_id' => $user1->id,
        'sender_id' => $user1->id,
        'initiated_by' => $user1->id,
        'type' => 'TOP_UP',
        'amount' => '100.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'audit-tx-1',
    ]);

    Transaction::create([
        'user_id' => $user2->id,
        'sender_id' => $user2->id,
        'initiated_by' => $user2->id,
        'type' => 'TRANSFER',
        'amount' => '200.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'audit-tx-2',
    ]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->getJson('/api/v1/transactions/admin/all');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.pagination.total', 2)
        ->assertJsonCount(2, 'data.transactions');
});

test('non-admin is forbidden from admin audit endpoint', function () {
    $user = createActorForQuery('USER');
    $agent = createActorForQuery('AGENT');

    Sanctum::actingAs($user, ['user']);
    $this->getJson('/api/v1/transactions/admin/all')
        ->assertStatus(403);

    Sanctum::actingAs($agent, ['agent']);
    $this->getJson('/api/v1/transactions/admin/all')
        ->assertStatus(403);
});

test('token without admin, agent, or user ability is forbidden from history and details', function () {
    $user = createActorForQuery('USER');
    $tx = Transaction::create([
        'user_id' => $user->id,
        'sender_id' => $user->id,
        'initiated_by' => $user->id,
        'type' => 'TOP_UP',
        'amount' => '100.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'ability-test-tx',
    ]);

    // Token has restricted ability only (e.g. password-change)
    Sanctum::actingAs($user, ['password-change']);

    $this->getJson('/api/v1/transactions/history')
        ->assertStatus(403);

    $this->getJson("/api/v1/transactions/{$tx->id}")
        ->assertStatus(403);
});

test('admin without admin token ability cannot bypass participant check on transaction details', function () {
    $user = createActorForQuery('USER');
    $admin = createActorForQuery('ADMIN');

    $tx = Transaction::create([
        'user_id' => $user->id,
        'sender_id' => $user->id,
        'initiated_by' => $user->id,
        'type' => 'TOP_UP',
        'amount' => '100.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'admin-token-ability-tx',
    ]);

    // Admin authenticated with only 'user' ability, not 'admin'
    Sanctum::actingAs($admin, ['user']);

    $this->getJson("/api/v1/transactions/{$tx->id}")
        ->assertStatus(403);
});

test('admin without admin token ability only sees participated transactions in history', function () {
    $user = createActorForQuery('USER');
    $admin = createActorForQuery('ADMIN');

    Transaction::create([
        'user_id' => $user->id,
        'sender_id' => $user->id,
        'initiated_by' => $user->id,
        'type' => 'TOP_UP',
        'amount' => '100.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'admin-history-unrelated',
    ]);

    Transaction::create([
        'user_id' => $admin->id,
        'sender_id' => $admin->id,
        'initiated_by' => $admin->id,
        'type' => 'TOP_UP',
        'amount' => '200.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'admin-history-own',
    ]);

    // Admin authenticated with only 'user' ability
    Sanctum::actingAs($admin, ['user']);

    $response = $this->getJson('/api/v1/transactions/history');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.transactions')
        ->assertJsonPath('data.transactions.0.user_id', $admin->id);
});
