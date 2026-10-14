<?php

use App\Models\AgentInfo;
use App\Models\Cap;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Services\PinVerifier;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SystemSettingsSeeder::class);
});

function createActorForCashIn(string $roleName, string $pin = '12345'): User
{
    $user = User::factory()->create([
        'pin' => Hash::make($pin),
    ]);
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

function createAgentForCashIn(float $balance = 2000.00, string $status = 'APPROVED', string $pin = '12345'): User
{
    $agent = createActorForCashIn('AGENT', $pin);

    AgentInfo::create([
        'user_id' => $agent->id,
        'commission_rate' => 0.0100,
        'status' => $status,
    ]);

    Wallet::factory()->agent()->for($agent)->balance($balance)->create();
    RateLimiter::clear(PinVerifier::getThrottleKey($agent->id));

    return $agent;
}

function createCustomerForCashIn(float $balance = 100.00): User
{
    $customer = createActorForCashIn('USER');
    Wallet::factory()->for($customer)->balance($balance)->create();
    Cap::factory()->for($customer)->usage(0.00, 0.00)->create();

    return $customer;
}

test('agent can successfully perform cash-in to a customer with 1:1 balance movement', function () {
    $agent = createAgentForCashIn(2000.00);
    $customer = createCustomerForCashIn(100.00);

    Sanctum::actingAs($agent, ['agent']);

    $totalBefore = (float) Wallet::sum('balance');

    $response = $this->postJson('/api/v1/transactions/agent/cash-in', [
        'customer_phone' => $customer->phone_number,
        'amount' => 500.00,
        'pin' => '12345',
        'description' => 'Cash-in deposit at agent store',
        'idempotency_key' => 'agent-cashin-001',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Cash-in completed successfully.')
        ->assertJsonPath('data.transaction.type', 'CASH_IN')
        ->assertJsonPath('data.transaction.amount', fn ($val) => (float) $val === 500.0)
        ->assertJsonPath('data.transaction.system_fee_amount', fn ($val) => (float) $val === 0.0)
        ->assertJsonPath('data.transaction.agent_commission_amount', fn ($val) => (float) $val === 0.0)
        ->assertJsonPath('data.transaction.user_id', $customer->id)
        ->assertJsonPath('data.transaction.recipient_id', $customer->id)
        ->assertJsonPath('data.transaction.sender_id', null)
        ->assertJsonPath('data.transaction.agent_id', $agent->id)
        ->assertJsonPath('data.transaction.initiated_by', $agent->id)
        ->assertJsonPath('data.transaction.status', 'COMPLETED')
        ->assertJsonPath('data.transaction.agent_wallet_balance_after', fn ($val) => (float) $val === 1500.0)
        ->assertJsonMissingPath('data.transaction.recipient_wallet_balance_after');

    $customer->userWallet->refresh();
    $agent->agentWallet->refresh();

    // 1:1 movement: agent float decreases by 500, customer balance increases by 500
    expect((float) $customer->userWallet->balance)->toBe(600.00)
        ->and((float) $agent->agentWallet->balance)->toBe(1500.00);

    // Total money across all wallets is preserved
    $totalAfter = (float) Wallet::sum('balance');
    expect($totalAfter)->toBe($totalBefore);

    expect(Transaction::where('idempotency_key', 'agent-cashin-001')->count())->toBe(1);
});

test('user token cannot call agent cash-in endpoint (403) and old route returns 404', function () {
    $customer = createCustomerForCashIn(500.00);
    $agent = createAgentForCashIn(2000.00);

    Sanctum::actingAs($customer, ['user']);

    // 1. User token calling /agent/cash-in -> 403 Forbidden
    $this->postJson('/api/v1/transactions/agent/cash-in', [
        'customer_phone' => $customer->phone_number,
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'user-calling-agent-route',
    ])->assertStatus(403);

    // 2. Old /transactions/cash-in route returns 404 Not Found
    $this->postJson('/api/v1/transactions/cash-in', [
        'customer_phone' => $customer->phone_number,
        'amount' => 100.00,
        'idempotency_key' => 'calling-deleted-route',
    ])->assertStatus(404);
});

test('unapproved or suspended agent is rejected from cash-in', function () {
    $pendingAgent = createAgentForCashIn(2000.00, 'PENDING');
    $customer = createCustomerForCashIn(100.00);

    Sanctum::actingAs($pendingAgent, ['agent']);

    $response = $this->postJson('/api/v1/transactions/agent/cash-in', [
        'customer_phone' => $customer->phone_number,
        'amount' => 200.00,
        'pin' => '12345',
        'idempotency_key' => 'pending-agent-cashin',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('success', false);

    expect(Transaction::count())->toBe(0);

    // Suspended agent
    $suspendedAgent = createAgentForCashIn(2000.00, 'SUSPENDED');
    Sanctum::actingAs($suspendedAgent, ['agent']);

    $this->postJson('/api/v1/transactions/agent/cash-in', [
        'customer_phone' => $customer->phone_number,
        'amount' => 200.00,
        'pin' => '12345',
        'idempotency_key' => 'suspended-agent-cashin',
    ])->assertStatus(409);

    expect(Transaction::count())->toBe(0);
});

test('wrong agent PIN on cash-in returns 422 and counts toward lockout', function () {
    $agent = createAgentForCashIn(2000.00, 'APPROVED', '12345');
    $customer = createCustomerForCashIn(100.00);

    Sanctum::actingAs($agent, ['agent']);

    $response = $this->postJson('/api/v1/transactions/agent/cash-in', [
        'customer_phone' => $customer->phone_number,
        'amount' => 100.00,
        'pin' => '99999',
        'idempotency_key' => 'wrong-pin-cashin-1',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('meta.attempts_remaining', 4);

    expect(RateLimiter::attempts(PinVerifier::getThrottleKey($agent->id)))->toBe(1);
    expect(Transaction::count())->toBe(0);
});

test('insufficient float balance is rejected and balances remain untouched', function () {
    $agent = createAgentForCashIn(200.00);
    $customer = createCustomerForCashIn(100.00);

    Sanctum::actingAs($agent, ['agent']);

    $response = $this->postJson('/api/v1/transactions/agent/cash-in', [
        'customer_phone' => $customer->phone_number,
        'amount' => 500.00,
        'pin' => '12345',
        'idempotency_key' => 'insufficient-float-cashin',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $customer->userWallet->refresh();
    $agent->agentWallet->refresh();

    expect((float) $customer->userWallet->balance)->toBe(100.00)
        ->and((float) $agent->agentWallet->balance)->toBe(200.00);

    expect(Transaction::count())->toBe(0);
});

test('blocked agent wallet or blocked customer wallet is rejected', function () {
    $agent = createAgentForCashIn(2000.00);
    $customer = createCustomerForCashIn(100.00);

    // 1. Blocked agent wallet
    $agent->agentWallet->update(['is_blocked' => true]);
    Sanctum::actingAs($agent, ['agent']);

    $this->postJson('/api/v1/transactions/agent/cash-in', [
        'customer_phone' => $customer->phone_number,
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'agent-blocked-cashin',
    ])->assertStatus(403);

    // Unblock agent, block customer
    $agent->agentWallet->update(['is_blocked' => false]);
    $customer->userWallet->update(['is_blocked' => true]);

    $this->postJson('/api/v1/transactions/agent/cash-in', [
        'customer_phone' => $customer->phone_number,
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'customer-blocked-cashin',
    ])->assertStatus(403);

    expect(Transaction::count())->toBe(0);
});

test('cash-in to self, to agent-only user, or to deleted customer is rejected', function () {
    $agent = createAgentForCashIn(2000.00);
    Sanctum::actingAs($agent, ['agent']);

    // 1. Self cash-in
    $this->postJson('/api/v1/transactions/agent/cash-in', [
        'customer_phone' => $agent->phone_number,
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'self-cashin',
    ])->assertStatus(422)
        ->assertJsonPath('message', 'You cannot cash-in to your own account.');

    // 2. Recipient is agent-only (not a USER)
    $otherAgent = createAgentForCashIn(1000.00);
    $this->postJson('/api/v1/transactions/agent/cash-in', [
        'customer_phone' => $otherAgent->phone_number,
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'agent-only-target-cashin',
    ])->assertStatus(422);

    // 3. Recipient is deleted
    $deletedCustomer = createCustomerForCashIn(100.00);
    $deletedCustomer->is_deleted = true;
    $deletedCustomer->save();

    $this->postJson('/api/v1/transactions/agent/cash-in', [
        'customer_phone' => $deletedCustomer->phone_number,
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'deleted-customer-cashin',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['customer_phone']);

    expect(Transaction::count())->toBe(0);
});

test('replaying same idempotency key returns existing transaction; different parameters return 409 conflict', function () {
    $agent = createAgentForCashIn(2000.00);
    $customer1 = createCustomerForCashIn(100.00);
    $customer2 = createCustomerForCashIn(200.00);

    Sanctum::actingAs($agent, ['agent']);

    $payload = [
        'customer_phone' => $customer1->phone_number,
        'amount' => 300.00,
        'pin' => '12345',
        'idempotency_key' => 'cash-in-idem-test',
    ];

    // First request -> 201 Created
    $res1 = $this->postJson('/api/v1/transactions/agent/cash-in', $payload);
    $res1->assertStatus(201);
    $txId = $res1->json('data.transaction.id');

    // Second request with identical payload -> Returns same transaction
    $res2 = $this->postJson('/api/v1/transactions/agent/cash-in', $payload);
    $res2->assertSuccessful()
        ->assertJsonPath('data.transaction.id', $txId);

    // Third request with different amount -> 409 Conflict
    $res3 = $this->postJson('/api/v1/transactions/agent/cash-in', [
        'customer_phone' => $customer1->phone_number,
        'amount' => 500.00,
        'pin' => '12345',
        'idempotency_key' => 'cash-in-idem-test',
    ]);
    $res3->assertStatus(409)
        ->assertJsonPath('message', 'Idempotency key reused with different parameters.');

    // Fourth request with different customer -> 409 Conflict
    $res4 = $this->postJson('/api/v1/transactions/agent/cash-in', [
        'customer_phone' => $customer2->phone_number,
        'amount' => 300.00,
        'pin' => '12345',
        'idempotency_key' => 'cash-in-idem-test',
    ]);
    $res4->assertStatus(409)
        ->assertJsonPath('message', 'Idempotency key reused with different parameters.');

    expect(Transaction::count())->toBe(1);
});
