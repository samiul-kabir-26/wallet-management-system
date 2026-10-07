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

function createAgentWithWallet(float $balance = 1000.00, string $status = 'APPROVED'): User
{
    $agent = User::factory()->create();
    $role = Role::where('name', 'AGENT')->firstOrFail();
    $agent->roles()->attach($role->id, ['assigned_at' => now()]);

    AgentInfo::create([
        'user_id' => $agent->id,
        'commission_rate' => 0.0100,
        'status' => $status,
    ]);

    Wallet::factory()->for($agent)->balance($balance)->create();

    return $agent;
}

test('approved agent can successfully withdraw funds from their wallet', function () {
    $agent = createAgentWithWallet(1000.00);

    Sanctum::actingAs($agent, ['agent']);

    $response = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 400.00,
        'description' => 'Withdraw commission earnings',
        'idempotency_key' => 'withdrawal-key-001',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.transaction.type', 'AGENT_WITHDRAWAL')
        ->assertJsonPath('data.transaction.amount', fn ($val) => (float) $val === 400.0)
        ->assertJsonPath('data.transaction.system_fee_amount', fn ($val) => (float) $val === 0.0)
        ->assertJsonPath('data.transaction.agent_commission_amount', fn ($val) => (float) $val === 0.0)
        ->assertJsonPath('data.transaction.user_id', $agent->id)
        ->assertJsonPath('data.transaction.sender_id', $agent->id)
        ->assertJsonPath('data.transaction.agent_id', $agent->id)
        ->assertJsonPath('data.transaction.recipient_id', null)
        ->assertJsonPath('data.transaction.initiated_by', $agent->id)
        ->assertJsonPath('data.transaction.status', 'COMPLETED')
        ->assertJsonPath('data.transaction.sender_wallet_balance_after', fn ($val) => (float) $val === 600.0)
        ->assertJsonPath('data.transaction.recipient_wallet_balance_after', null);

    $agent->wallet->refresh();
    expect((float) $agent->wallet->balance)->toBe(600.00);

    expect(Transaction::where('idempotency_key', 'withdrawal-key-001')->count())->toBe(1);
});

test('submitting same idempotency_key on agent withdrawal returns same transaction and debits only once', function () {
    $agent = createAgentWithWallet(1000.00);

    Sanctum::actingAs($agent, ['agent']);

    $payload = [
        'amount' => 300.00,
        'idempotency_key' => 'withdraw-idem-test',
    ];

    $response1 = $this->postJson('/api/v1/transactions/agent/withdrawal', $payload);
    $response2 = $this->postJson('/api/v1/transactions/agent/withdrawal', $payload);

    $response1->assertStatus(201);
    $response2->assertSuccessful()
        ->assertJsonPath('data.transaction.id', $response1->json('data.transaction.id'));

    $agent->wallet->refresh();
    // 1000 - 300 = 700, not debited twice (which would be 400)
    expect((float) $agent->wallet->balance)->toBe(700.00);
    expect(Transaction::where('idempotency_key', 'withdraw-idem-test')->count())->toBe(1);
});

test('agent withdrawal fails if requested amount exceeds wallet balance', function () {
    $agent = createAgentWithWallet(200.00);

    Sanctum::actingAs($agent, ['agent']);

    $response = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 500.00,
        'idempotency_key' => 'exceed-balance-key',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $agent->wallet->refresh();
    expect((float) $agent->wallet->balance)->toBe(200.00);
    expect(Transaction::count())->toBe(0);
});

test('agent withdrawal fails on a blocked agent wallet', function () {
    $agent = createAgentWithWallet(500.00);
    $agent->wallet->update(['is_blocked' => true]);

    Sanctum::actingAs($agent, ['agent']);

    $response = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 100.00,
        'idempotency_key' => 'blocked-agent-key',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    $agent->wallet->refresh();
    expect((float) $agent->wallet->balance)->toBe(500.00);
    expect(Transaction::count())->toBe(0);
});

test('agent withdrawal fails if agent is not approved', function () {
    $agent = createAgentWithWallet(500.00, 'PENDING');

    Sanctum::actingAs($agent, ['agent']);

    $response = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 100.00,
        'idempotency_key' => 'pending-agent-key',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('success', false);

    $agent->wallet->refresh();
    expect((float) $agent->wallet->balance)->toBe(500.00);
    expect(Transaction::count())->toBe(0);
});

test('regular user or admin cannot initiate agent withdrawal', function () {
    $user = User::factory()->create();
    $userRole = Role::where('name', 'USER')->firstOrFail();
    $user->roles()->attach($userRole->id, ['assigned_at' => now()]);
    Wallet::factory()->for($user)->balance(500.00)->create();

    Sanctum::actingAs($user, ['user']);

    $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 100.00,
        'idempotency_key' => 'user-withdrawal-key',
    ])->assertStatus(403);
});
