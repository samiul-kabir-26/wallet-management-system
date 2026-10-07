<?php

use App\Models\AgentInfo;
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

function createActorForCashOut(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

function createAgentForCashOut(float $balance = 2000.00, float $commissionRate = 0.0100, string $status = 'APPROVED'): User
{
    $agent = createActorForCashOut('AGENT');

    AgentInfo::create([
        'user_id' => $agent->id,
        'commission_rate' => $commissionRate,
        'status' => $status,
    ]);

    Wallet::factory()->for($agent)->balance($balance)->create();

    return $agent;
}

test('user can successfully cash-out via approved agent, writing CASH_OUT and linked COMMISSION_PAYOUT rows', function () {
    $user = createActorForCashOut('USER');
    $userWallet = Wallet::factory()->for($user)->balance(1500.00)->create();
    $userCap = Cap::factory()->for($user)->usage(0.00, 0.00)->create();

    $agent = createAgentForCashOut(balance: 2000.00, commissionRate: 0.0100);

    Sanctum::actingAs($user, ['user']);

    // Cash out 1000. System fee (5%) = 50. Agent commission (1%) = 10.
    // User pays: 1000 + 50 = 1050. Remaining balance: 1500 - 1050 = 450.
    // Agent receives: 1000 + 10 = 1010. New balance: 2000 + 1010 = 3010.
    $response = $this->postJson('/api/v1/transactions/cash-out', [
        'agent_id' => $agent->id,
        'amount' => 1000.00,
        'description' => 'Cash-out withdrawal at counter',
        'idempotency_key' => 'cash-out-key-001',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.transaction.type', 'CASH_OUT')
        ->assertJsonPath('data.transaction.amount', fn ($val) => (float) $val === 1000.0)
        ->assertJsonPath('data.transaction.system_fee_amount', fn ($val) => (float) $val === 50.0)
        ->assertJsonPath('data.transaction.agent_commission_amount', fn ($val) => (float) $val === 10.0)
        ->assertJsonPath('data.transaction.user_id', $user->id)
        ->assertJsonPath('data.transaction.sender_id', $user->id)
        ->assertJsonPath('data.transaction.agent_id', $agent->id)
        ->assertJsonPath('data.transaction.recipient_id', null)
        ->assertJsonPath('data.transaction.sender_wallet_balance_after', fn ($val) => (float) $val === 450.0)
        ->assertJsonPath('data.transaction.agent_wallet_balance_after', fn ($val) => (float) $val === 3010.0);

    $userWallet->refresh();
    $agent->wallet->refresh();
    expect((float) $userWallet->balance)->toBe(450.00)
        ->and((float) $agent->wallet->balance)->toBe(3010.00);

    // Exactly two transaction rows exist in the entire database
    expect(Transaction::count())->toBe(2);

    $cashOutRow = Transaction::where('type', 'CASH_OUT')->firstOrFail();
    $commissionRow = Transaction::where('type', 'COMMISSION_PAYOUT')->firstOrFail();

    // Verify linkage via meta.related_transaction_id
    expect($commissionRow->meta['related_transaction_id'])->toBe($cashOutRow->id);

    // Verify consistent agent_wallet_balance_after values across both rows
    expect((float) $cashOutRow->agent_wallet_balance_after)->toBe(3010.00)
        ->and((float) $commissionRow->recipient_wallet_balance_after)->toBe(3010.00)
        ->and((float) $commissionRow->agent_wallet_balance_after)->toBe(3010.00);

    // Verify COMMISSION_PAYOUT fields
    expect((float) $commissionRow->amount)->toBe(10.00)
        ->and($commissionRow->recipient_id)->toBe($agent->id)
        ->and($commissionRow->agent_id)->toBe($agent->id)
        ->and($commissionRow->initiated_by)->toBe($user->id)
        ->and($commissionRow->user_id)->toBeNull()
        ->and($commissionRow->sender_id)->toBeNull();

    // Verify cap was incremented by principal amount
    $userCap->refresh();
    expect((float) $userCap->daily_used)->toBe(1000.00)
        ->and((float) $userCap->monthly_used)->toBe(1000.00);

    // Verify agent total_commission counter was updated
    $agent->agentInfo->refresh();
    expect((float) $agent->agentInfo->total_commission)->toBe(10.00);
});

test('submitting same idempotency_key on cash-out returns existing transaction and does not mutate balances or caps twice', function () {
    $user = createActorForCashOut('USER');
    $userWallet = Wallet::factory()->for($user)->balance(1500.00)->create();
    $userCap = Cap::factory()->for($user)->usage(0.00, 0.00)->create();

    $agent = createAgentForCashOut(2000.00);

    Sanctum::actingAs($user, ['user']);

    $payload = [
        'agent_id' => $agent->id,
        'amount' => 500.00,
        'idempotency_key' => 'cash-out-idem-key',
    ];

    $response1 = $this->postJson('/api/v1/transactions/cash-out', $payload);
    $response2 = $this->postJson('/api/v1/transactions/cash-out', $payload);

    $response1->assertStatus(201);
    $response2->assertSuccessful()
        ->assertJsonPath('data.transaction.id', $response1->json('data.transaction.id'));

    // Fee: 25. Total debit: 525. Commission: 5. Total credit: 505.
    $userWallet->refresh();
    $agent->wallet->refresh();
    expect((float) $userWallet->balance)->toBe(975.00)
        ->and((float) $agent->wallet->balance)->toBe(2505.00);

    // Exactly 2 rows (CASH_OUT and COMMISSION_PAYOUT), not 4
    expect(Transaction::count())->toBe(2);

    $userCap->refresh();
    expect((float) $userCap->daily_used)->toBe(500.00);
});

test('cash-out fails when daily cap is exceeded and creates zero transaction rows and zero cap increment', function () {
    $user = createActorForCashOut('USER');
    $userWallet = Wallet::factory()->for($user)->balance(2000.00)->create();
    // Daily cap is 10000. Current used: 9500. Available: 500.
    $userCap = Cap::factory()->for($user)->usage(9500.00, 10000.00)->create();

    $agent = createAgentForCashOut(2000.00);

    Sanctum::actingAs($user, ['user']);

    // Attempting 600 exceeds 500 remaining daily cap
    $response = $this->postJson('/api/v1/transactions/cash-out', [
        'agent_id' => $agent->id,
        'amount' => 600.00,
        'idempotency_key' => 'exceed-daily-cap',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $userWallet->refresh();
    $agent->wallet->refresh();
    $userCap->refresh();

    // Balances and caps untouched
    expect((float) $userWallet->balance)->toBe(2000.00)
        ->and((float) $agent->wallet->balance)->toBe(2000.00)
        ->and((float) $userCap->daily_used)->toBe(9500.00)
        ->and(Transaction::count())->toBe(0);
});

test('cash-out fails when monthly cap is exceeded and creates zero transaction rows and zero cap increment', function () {
    $user = createActorForCashOut('USER');
    $userWallet = Wallet::factory()->for($user)->balance(2000.00)->create();
    // Monthly cap is 50000. Current used: 49500. Available: 500.
    $userCap = Cap::factory()->for($user)->usage(1000.00, 49500.00)->create();

    $agent = createAgentForCashOut(2000.00);

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/cash-out', [
        'agent_id' => $agent->id,
        'amount' => 600.00,
        'idempotency_key' => 'exceed-monthly-cap',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $userWallet->refresh();
    $userCap->refresh();

    expect((float) $userWallet->balance)->toBe(2000.00)
        ->and((float) $userCap->monthly_used)->toBe(49500.00)
        ->and(Transaction::count())->toBe(0);
});

test('cash-out fails when user balance cannot cover amount plus system fee', function () {
    $user = createActorForCashOut('USER');
    // Balance is 1000. To cash out 1000, fee is 50, user needs 1050.
    $userWallet = Wallet::factory()->for($user)->balance(1000.00)->create();
    $userCap = Cap::factory()->for($user)->usage(0.00, 0.00)->create();

    $agent = createAgentForCashOut(2000.00);

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/cash-out', [
        'agent_id' => $agent->id,
        'amount' => 1000.00,
        'idempotency_key' => 'insufficient-balance-cash-out',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $userWallet->refresh();
    $userCap->refresh();

    expect((float) $userWallet->balance)->toBe(1000.00)
        ->and((float) $userCap->daily_used)->toBe(0.00)
        ->and(Transaction::count())->toBe(0);
});

test('cash-out fails if user wallet is blocked', function () {
    $user = createActorForCashOut('USER');
    $userWallet = Wallet::factory()->for($user)->balance(2000.00)->blocked()->create();
    $userCap = Cap::factory()->for($user)->usage(0.00, 0.00)->create();

    $agent = createAgentForCashOut(2000.00);

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/cash-out', [
        'agent_id' => $agent->id,
        'amount' => 500.00,
        'idempotency_key' => 'user-blocked-cash-out',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    $userWallet->refresh();
    $userCap->refresh();

    expect((float) $userWallet->balance)->toBe(2000.00)
        ->and((float) $userCap->daily_used)->toBe(0.00)
        ->and(Transaction::count())->toBe(0);
});

test('cash-out fails if agent wallet is blocked', function () {
    $user = createActorForCashOut('USER');
    $userWallet = Wallet::factory()->for($user)->balance(2000.00)->create();
    $userCap = Cap::factory()->for($user)->usage(0.00, 0.00)->create();

    $agent = createAgentForCashOut(2000.00);
    $agent->wallet->update(['is_blocked' => true]);

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/cash-out', [
        'agent_id' => $agent->id,
        'amount' => 500.00,
        'idempotency_key' => 'agent-blocked-cash-out',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    $userWallet->refresh();
    $userCap->refresh();

    expect((float) $userWallet->balance)->toBe(2000.00)
        ->and((float) $userCap->daily_used)->toBe(0.00)
        ->and(Transaction::count())->toBe(0);
});

test('cash-out fails if agent is not approved', function () {
    $user = createActorForCashOut('USER');
    $userWallet = Wallet::factory()->for($user)->balance(2000.00)->create();

    $agent = createAgentForCashOut(2000.00, 0.0100, 'PENDING');

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/cash-out', [
        'agent_id' => $agent->id,
        'amount' => 500.00,
        'idempotency_key' => 'pending-agent-cash-out',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('success', false);

    expect(Transaction::count())->toBe(0);
});

test('agent or admin cannot perform cash-out', function () {
    $agent = createAgentForCashOut(2000.00);
    $targetAgent = createAgentForCashOut(2000.00);

    Sanctum::actingAs($agent, ['agent']);

    $this->postJson('/api/v1/transactions/cash-out', [
        'agent_id' => $targetAgent->id,
        'amount' => 100.00,
        'idempotency_key' => 'agent-cash-out',
    ])->assertStatus(403);

    $admin = createActorForCashOut('ADMIN');
    Wallet::factory()->for($admin)->balance(1000.00)->create();

    Sanctum::actingAs($admin, ['admin']);

    $this->postJson('/api/v1/transactions/cash-out', [
        'agent_id' => $targetAgent->id,
        'amount' => 100.00,
        'idempotency_key' => 'admin-cash-out',
    ])->assertStatus(403);
});

test('sequential cash-outs through the same agent accumulate total_commission additively', function () {
    $user1 = createActorForCashOut('USER');
    Wallet::factory()->for($user1)->balance(2000.00)->create();
    Cap::factory()->for($user1)->usage(0.00, 0.00)->create();

    $user2 = createActorForCashOut('USER');
    Wallet::factory()->for($user2)->balance(2000.00)->create();
    Cap::factory()->for($user2)->usage(0.00, 0.00)->create();

    $agent = createAgentForCashOut(balance: 2000.00, commissionRate: 0.0100);

    // First cash out: 1000.00 -> commission = 10.00
    Sanctum::actingAs($user1, ['user']);
    $this->postJson('/api/v1/transactions/cash-out', [
        'agent_id' => $agent->id,
        'amount' => 1000.00,
        'idempotency_key' => 'seq-cash-out-1',
    ])->assertStatus(201);

    $agent->agentInfo->refresh();
    expect((float) $agent->agentInfo->total_commission)->toBe(10.00);

    // Second cash out: 1500.00 -> commission = 15.00, total should be 25.00
    Sanctum::actingAs($user2, ['user']);
    $this->postJson('/api/v1/transactions/cash-out', [
        'agent_id' => $agent->id,
        'amount' => 1500.00,
        'idempotency_key' => 'seq-cash-out-2',
    ])->assertStatus(201);

    $agent->agentInfo->refresh();
    expect((float) $agent->agentInfo->total_commission)->toBe(25.00);
});
