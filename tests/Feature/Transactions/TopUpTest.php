<?php

use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Services\PinVerifier;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function createActorWithRoleForTopUp(string $roleName, string $pin = '12345'): User
{
    $user = User::factory()->create([
        'pin' => Hash::make($pin),
    ]);
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);
    RateLimiter::clear(PinVerifier::getThrottleKey($user->id));

    return $user;
}

test('user can successfully top-up their wallet with correct pin', function () {
    $user = createActorWithRoleForTopUp('USER', '12345');
    $wallet = Wallet::factory()->for($user)->balance(100.00)->create();

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 500.00,
        'pin' => '12345',
        'description' => 'Self top-up via card',
        'idempotency_key' => 'topup-key-001',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.transaction.type', 'TOP_UP')
        ->assertJsonPath('data.transaction.amount', fn ($val) => (float) $val === 500.0)
        ->assertJsonPath('data.transaction.system_fee_amount', fn ($val) => (float) $val === 0.0)
        ->assertJsonPath('data.transaction.agent_commission_amount', fn ($val) => (float) $val === 0.0)
        ->assertJsonPath('data.transaction.user_id', $user->id)
        ->assertJsonPath('data.transaction.recipient_id', $user->id)
        ->assertJsonPath('data.transaction.sender_id', null)
        ->assertJsonPath('data.transaction.agent_id', null)
        ->assertJsonPath('data.transaction.initiated_by', $user->id)
        ->assertJsonPath('data.transaction.status', 'COMPLETED')
        ->assertJsonPath('data.transaction.recipient_wallet_balance_after', fn ($val) => (float) $val === 600.0)
        ->assertJsonMissingPath('data.transaction.sender_wallet_balance_after');

    $wallet->refresh();
    expect((float) $wallet->balance)->toBe(600.00);

    expect(Transaction::where('idempotency_key', 'topup-key-001')->count())->toBe(1);
});

test('submitting same idempotency_key on top-up returns same transaction and credits only once', function () {
    $user = createActorWithRoleForTopUp('USER', '12345');
    $wallet = Wallet::factory()->for($user)->balance(200.00)->create();

    Sanctum::actingAs($user, ['user']);

    $payload = [
        'amount' => 300.00,
        'pin' => '12345',
        'idempotency_key' => 'topup-idem-test',
    ];

    $response1 = $this->postJson('/api/v1/transactions/top-up', $payload);
    $response2 = $this->postJson('/api/v1/transactions/top-up', $payload);

    $response1->assertStatus(201);
    $response2->assertSuccessful()
        ->assertJsonPath('data.transaction.id', $response1->json('data.transaction.id'));

    $wallet->refresh();
    // 200 + 300 = 500, not credited twice (which would be 800)
    expect((float) $wallet->balance)->toBe(500.00);
    expect(Transaction::where('idempotency_key', 'topup-idem-test')->count())->toBe(1);
});

test('top-up fails on a blocked wallet and leaves zero transaction rows', function () {
    $user = createActorWithRoleForTopUp('USER', '12345');
    $wallet = Wallet::factory()->for($user)->balance(100.00)->blocked()->create();

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 200.00,
        'pin' => '12345',
        'idempotency_key' => 'topup-blocked-key',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    $wallet->refresh();
    expect((float) $wallet->balance)->toBe(100.00);
    expect(Transaction::count())->toBe(0);
});

test('agent or admin cannot perform top-up', function () {
    $agent = createActorWithRoleForTopUp('AGENT', '12345');
    Wallet::factory()->for($agent)->balance(100.00)->create();

    Sanctum::actingAs($agent, ['agent']);

    $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'agent-topup',
    ])->assertStatus(403);

    $admin = createActorWithRoleForTopUp('ADMIN', '12345');
    Wallet::factory()->for($admin)->balance(100.00)->create();

    Sanctum::actingAs($admin, ['admin']);

    $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'admin-topup',
    ])->assertStatus(403);
});

test('top-up validates required parameters including pin', function () {
    $user = createActorWithRoleForTopUp('USER');
    Wallet::factory()->for($user)->balance(100.00)->create();

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/top-up', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['amount', 'idempotency_key', 'pin']);
});

test('top-up rejects invalid pin format', function () {
    $user = createActorWithRoleForTopUp('USER');
    Wallet::factory()->for($user)->balance(100.00)->create();

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 100.00,
        'pin' => '123', // < 5 digits
        'idempotency_key' => 'pin-short',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['pin']);
});

test('top-up rejects incorrect pin with 422', function () {
    $user = createActorWithRoleForTopUp('USER', '12345');
    $wallet = Wallet::factory()->for($user)->balance(100.00)->create();

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 100.00,
        'pin' => '99999',
        'idempotency_key' => 'wrong-pin-topup',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Wrong PIN. 4 attempt(s) left.');

    $wallet->refresh();
    expect((float) $wallet->balance)->toBe(100.00);
    expect(Transaction::count())->toBe(0);
});

test('top-up locks account after 5 consecutive failed pin attempts and returns 429', function () {
    $user = createActorWithRoleForTopUp('USER', '12345');
    Wallet::factory()->for($user)->balance(100.00)->create();

    Sanctum::actingAs($user, ['user']);

    for ($i = 1; $i <= 4; $i++) {
        $this->postJson('/api/v1/transactions/top-up', [
            'amount' => 100.00,
            'pin' => '99999',
            'idempotency_key' => "lockout-test-{$i}",
        ])->assertStatus(422);
    }

    // 5th failed attempt triggers lockout and returns 429
    $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 100.00,
        'pin' => '99999',
        'idempotency_key' => 'lockout-test-5',
    ])->assertStatus(429);

    // 6th attempt with correct PIN is still locked out with 429
    $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 100.00,
        'pin' => '12345',
        'idempotency_key' => 'lockout-test-6',
    ])->assertStatus(429);
});

test('top-up rejects zero or negative amounts', function () {
    $user = createActorWithRoleForTopUp('USER', '12345');
    Wallet::factory()->for($user)->balance(100.00)->create();

    Sanctum::actingAs($user, ['user']);

    $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 0,
        'pin' => '12345',
        'idempotency_key' => 'key-0',
    ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

    $this->postJson('/api/v1/transactions/top-up', [
        'amount' => -50,
        'pin' => '12345',
        'idempotency_key' => 'key-neg',
    ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
});
