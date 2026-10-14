<?php

use App\Models\AgentInfo;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Services\PinVerifier;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    RateLimiter::clear(PinVerifier::getThrottleKey(1));
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

    Wallet::factory()->agent()->for($agent)->balance($balance)->create();

    return $agent;
}

test('approved agent can successfully withdraw funds from their wallet', function () {
    $agent = createAgentWithWallet(1000.00);

    Sanctum::actingAs($agent, ['agent']);

    $response = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 400.00,
        'pin' => '12345',
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
        ->assertJsonMissingPath('data.transaction.recipient_wallet_balance_after');

    $agent->agentWallet->refresh();
    expect((float) $agent->agentWallet->balance)->toBe(600.00);

    expect(Transaction::where('idempotency_key', 'withdrawal-key-001')->count())->toBe(1);
});

test('submitting same idempotency_key on agent withdrawal returns same transaction and debits only once', function () {
    $agent = createAgentWithWallet(1000.00);

    Sanctum::actingAs($agent, ['agent']);

    $payload = [
        'amount' => 300.00,
        'pin' => '12345',
        'idempotency_key' => 'withdraw-idem-test',
    ];

    $response1 = $this->postJson('/api/v1/transactions/agent/withdrawal', $payload);
    $response2 = $this->postJson('/api/v1/transactions/agent/withdrawal', $payload);

    $response1->assertStatus(201);
    $response2->assertSuccessful()
        ->assertJsonPath('data.transaction.id', $response1->json('data.transaction.id'));

    $agent->agentWallet->refresh();
    // 1000 - 300 = 700, not debited twice (which would be 400)
    expect((float) $agent->agentWallet->balance)->toBe(700.00);
    expect(Transaction::where('idempotency_key', 'withdraw-idem-test')->count())->toBe(1);
});

test('reusing idempotency_key with different amount on agent withdrawal is rejected with 409 conflict', function () {
    $agent = createAgentWithWallet(1000.00);

    Sanctum::actingAs($agent, ['agent']);

    $response1 = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 300.00,
        'pin' => '12345',
        'idempotency_key' => 'withdraw-conflict-key',
    ]);
    $response1->assertStatus(201);

    $response2 = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 400.00,
        'pin' => '12345',
        'idempotency_key' => 'withdraw-conflict-key',
    ]);
    $response2->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Idempotency key reused with different parameters.');

    $agent->agentWallet->refresh();
    expect((float) $agent->agentWallet->balance)->toBe(700.00);
    expect(Transaction::where('idempotency_key', 'withdraw-conflict-key')->count())->toBe(1);
});

test('agent withdrawal fails if requested amount exceeds wallet balance', function () {
    $agent = createAgentWithWallet(200.00);

    Sanctum::actingAs($agent, ['agent']);

    $response = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 500.00,
        'pin' => '12345',
        'idempotency_key' => 'exceed-balance-key',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $agent->agentWallet->refresh();
    expect((float) $agent->agentWallet->balance)->toBe(200.00);
    expect(Transaction::count())->toBe(0);
});

test('agent withdrawal fails on a blocked agent wallet', function () {
    $agent = createAgentWithWallet(500.00);
    $agent->agentWallet->update(['is_blocked' => true]);

    Sanctum::actingAs($agent, ['agent']);

    $response = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'blocked-agent-key',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    $agent->agentWallet->refresh();
    expect((float) $agent->agentWallet->balance)->toBe(500.00);
    expect(Transaction::count())->toBe(0);
});

test('agent withdrawal fails if agent is not approved', function () {
    $agent = createAgentWithWallet(500.00, 'PENDING');

    Sanctum::actingAs($agent, ['agent']);

    $response = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'pending-agent-key',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('success', false);

    $agent->agentWallet->refresh();
    expect((float) $agent->agentWallet->balance)->toBe(500.00);
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
        'pin' => '12345',
        'idempotency_key' => 'user-withdrawal-key',
    ])->assertStatus(403);
});

test('agent withdrawal fails with wrong PIN and reports attempts left', function () {
    $agent = createAgentWithWallet(1000.00);

    Sanctum::actingAs($agent, ['agent']);

    $response = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 200.00,
        'pin' => '99999',
        'idempotency_key' => 'wrong-pin-withdraw-001',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Wrong PIN. 4 attempt(s) left.')
        ->assertJsonPath('errors.pin.0', 'Wrong PIN. 4 attempt(s) left.')
        ->assertJsonPath('meta.attempts_remaining', 4);

    expect(Transaction::count())->toBe(0);
});

test('exceeding PIN attempts on agent withdrawal locks out agent with 429', function () {
    $agent = createAgentWithWallet(1000.00);

    Sanctum::actingAs($agent, ['agent']);

    for ($i = 1; $i <= 4; $i++) {
        $this->postJson('/api/v1/transactions/agent/withdrawal', [
            'amount' => 10.00,
            'pin' => '99999',
            'idempotency_key' => "lockout-withdraw-{$i}",
        ])->assertStatus(422);
    }

    $fifth = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 10.00,
        'pin' => '99999',
        'idempotency_key' => 'lockout-withdraw-5',
    ]);

    $fifth->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors.pin.0', fn ($msg) => str_contains($msg, 'locked'))
        ->assertJsonPath('meta.locked', true)
        ->assertJsonPath('meta.retry_after', fn ($val) => $val > 0)
        ->assertHeader('Retry-After');

    // Subsequent attempt even with correct PIN is blocked
    $subsequent = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 10.00,
        'pin' => '12345',
        'idempotency_key' => 'lockout-withdraw-correct-after',
    ]);

    $subsequent->assertStatus(429)
        ->assertHeader('Retry-After');
});

test('agent withdrawal validates pin rules', function () {
    $agent = createAgentWithWallet(1000.00);

    Sanctum::actingAs($agent, ['agent']);

    // Missing pin
    $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 100.00,
        'idempotency_key' => 'withdraw-pin-missing',
    ])->assertStatus(422)->assertJsonValidationErrors(['pin']);

    // Short pin (< 5)
    $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 100.00,
        'pin' => '123',
        'idempotency_key' => 'withdraw-pin-short',
    ])->assertStatus(422)->assertJsonValidationErrors(['pin']);

    // Non-numeric pin
    $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 100.00,
        'pin' => 'abcde',
        'idempotency_key' => 'withdraw-pin-nonnumeric',
    ])->assertStatus(422)->assertJsonValidationErrors(['pin']);
});
