<?php

use App\Models\AgentInfo;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function createActorForCashIn(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

function createAgentForCashIn(float $balance = 1000.00, string $status = 'APPROVED'): User
{
    $agent = createActorForCashIn('AGENT');

    AgentInfo::create([
        'user_id' => $agent->id,
        'commission_rate' => 0.0100,
        'status' => $status,
    ]);

    Wallet::factory()->for($agent)->balance($balance)->create();

    return $agent;
}

test('user can successfully perform cash-in via an approved agent with 1:1 balance movement', function () {
    $user = createActorForCashIn('USER');
    $userWallet = Wallet::factory()->for($user)->balance(100.00)->create();

    $agent = createAgentForCashIn(1000.00);

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/cash-in', [
        'agent_id' => $agent->id,
        'amount' => 500.00,
        'description' => 'Cash-in deposit at agent point',
        'idempotency_key' => 'cash-in-key-001',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.transaction.type', 'CASH_IN')
        ->assertJsonPath('data.transaction.amount', fn ($val) => (float) $val === 500.0)
        ->assertJsonPath('data.transaction.system_fee_amount', fn ($val) => (float) $val === 0.0)
        ->assertJsonPath('data.transaction.agent_commission_amount', fn ($val) => (float) $val === 0.0)
        ->assertJsonPath('data.transaction.user_id', $user->id)
        ->assertJsonPath('data.transaction.sender_id', null)
        ->assertJsonPath('data.transaction.recipient_id', $user->id)
        ->assertJsonPath('data.transaction.agent_id', $agent->id)
        ->assertJsonPath('data.transaction.initiated_by', $user->id)
        ->assertJsonPath('data.transaction.status', 'COMPLETED')
        ->assertJsonPath('data.transaction.recipient_wallet_balance_after', fn ($val) => (float) $val === 600.0)
        ->assertJsonPath('data.transaction.agent_wallet_balance_after', fn ($val) => (float) $val === 500.0)
        ->assertJsonPath('data.transaction.sender_wallet_balance_after', null);

    $userWallet->refresh();
    $agent->wallet->refresh();

    // 1:1 transfer: agent debited by 500, user credited by 500
    expect((float) $userWallet->balance)->toBe(600.00)
        ->and((float) $agent->wallet->balance)->toBe(500.00);

    expect(Transaction::where('idempotency_key', 'cash-in-key-001')->count())->toBe(1);
});

test('submitting same idempotency_key on cash-in returns existing transaction without double balance movement', function () {
    $user = createActorForCashIn('USER');
    $userWallet = Wallet::factory()->for($user)->balance(200.00)->create();

    $agent = createAgentForCashIn(1000.00);

    Sanctum::actingAs($user, ['user']);

    $payload = [
        'agent_id' => $agent->id,
        'amount' => 300.00,
        'idempotency_key' => 'cash-in-idem-test',
    ];

    $response1 = $this->postJson('/api/v1/transactions/cash-in', $payload);
    $response2 = $this->postJson('/api/v1/transactions/cash-in', $payload);

    $response1->assertStatus(201);
    $response2->assertSuccessful()
        ->assertJsonPath('data.transaction.id', $response1->json('data.transaction.id'));

    $userWallet->refresh();
    $agent->wallet->refresh();

    expect((float) $userWallet->balance)->toBe(500.00)
        ->and((float) $agent->wallet->balance)->toBe(700.00);

    expect(Transaction::where('idempotency_key', 'cash-in-idem-test')->count())->toBe(1);
});

test('cash-in fails if agent has insufficient balance and creates zero transaction records', function () {
    $user = createActorForCashIn('USER');
    $userWallet = Wallet::factory()->for($user)->balance(100.00)->create();

    $agent = createAgentForCashIn(200.00);

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/cash-in', [
        'agent_id' => $agent->id,
        'amount' => 500.00,
        'idempotency_key' => 'cash-in-insufficient',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $userWallet->refresh();
    $agent->wallet->refresh();

    expect((float) $userWallet->balance)->toBe(100.00)
        ->and((float) $agent->wallet->balance)->toBe(200.00);

    expect(Transaction::count())->toBe(0);
});

test('cash-in fails if user wallet is blocked and creates zero transaction records', function () {
    $user = createActorForCashIn('USER');
    $userWallet = Wallet::factory()->for($user)->balance(100.00)->blocked()->create();

    $agent = createAgentForCashIn(1000.00);

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/cash-in', [
        'agent_id' => $agent->id,
        'amount' => 200.00,
        'idempotency_key' => 'cash-in-user-blocked',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    $userWallet->refresh();
    $agent->wallet->refresh();

    expect((float) $userWallet->balance)->toBe(100.00)
        ->and((float) $agent->wallet->balance)->toBe(1000.00);

    expect(Transaction::count())->toBe(0);
});

test('cash-in fails if agent wallet is blocked and creates zero transaction records', function () {
    $user = createActorForCashIn('USER');
    $userWallet = Wallet::factory()->for($user)->balance(100.00)->create();

    $agent = createAgentForCashIn(1000.00);
    $agent->wallet->update(['is_blocked' => true]);

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/cash-in', [
        'agent_id' => $agent->id,
        'amount' => 200.00,
        'idempotency_key' => 'cash-in-agent-blocked',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    $userWallet->refresh();
    $agent->wallet->refresh();

    expect((float) $userWallet->balance)->toBe(100.00)
        ->and((float) $agent->wallet->balance)->toBe(1000.00);

    expect(Transaction::count())->toBe(0);
});

test('cash-in fails if agent is not approved', function () {
    $user = createActorForCashIn('USER');
    $userWallet = Wallet::factory()->for($user)->balance(100.00)->create();

    $agent = createAgentForCashIn(1000.00, 'PENDING');

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/cash-in', [
        'agent_id' => $agent->id,
        'amount' => 200.00,
        'idempotency_key' => 'cash-in-pending-agent',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('success', false);

    $userWallet->refresh();
    $agent->wallet->refresh();

    expect((float) $userWallet->balance)->toBe(100.00)
        ->and((float) $agent->wallet->balance)->toBe(1000.00);

    expect(Transaction::count())->toBe(0);
});

test('cash-in fails if target user is not an agent', function () {
    $user = createActorForCashIn('USER');
    Wallet::factory()->for($user)->balance(100.00)->create();

    $otherUser = createActorForCashIn('USER');
    Wallet::factory()->for($otherUser)->balance(1000.00)->create();

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/cash-in', [
        'agent_id' => $otherUser->id,
        'amount' => 100.00,
        'idempotency_key' => 'cash-in-not-an-agent',
    ]);

    $response->assertStatus(404)
        ->assertJsonPath('success', false);

    expect(Transaction::count())->toBe(0);
});

test('user cannot cash in from themselves', function () {
    $user = createActorForCashIn('USER');
    Wallet::factory()->for($user)->balance(100.00)->create();

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/cash-in', [
        'agent_id' => $user->id,
        'amount' => 100.00,
        'idempotency_key' => 'cash-in-self',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['agent_id']);
});

test('agent or admin cannot perform cash-in', function () {
    $agent = createAgentForCashIn(1000.00);
    $targetAgent = createAgentForCashIn(2000.00);

    Sanctum::actingAs($agent, ['agent']);

    $this->postJson('/api/v1/transactions/cash-in', [
        'agent_id' => $targetAgent->id,
        'amount' => 100.00,
        'idempotency_key' => 'agent-cash-in',
    ])->assertStatus(403);

    $admin = createActorForCashIn('ADMIN');
    Wallet::factory()->for($admin)->balance(500.00)->create();

    Sanctum::actingAs($admin, ['admin']);

    $this->postJson('/api/v1/transactions/cash-in', [
        'agent_id' => $targetAgent->id,
        'amount' => 100.00,
        'idempotency_key' => 'admin-cash-in',
    ])->assertStatus(403);
});
