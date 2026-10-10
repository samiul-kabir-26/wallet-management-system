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

test('distinct idempotency keys from the same user succeed independently and mutate balance consecutively', function () {
    $user = User::factory()->create();
    $role = Role::where('name', 'USER')->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);
    $wallet = Wallet::factory()->for($user)->balance(200.00)->create();

    Sanctum::actingAs($user, ['user']);

    $key1 = 'key-distinct-1-'.uniqid();
    $key2 = 'key-distinct-2-'.uniqid();

    $response1 = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 100.00,
        'description' => 'First top-up',
        'idempotency_key' => $key1,
    ]);

    $response2 = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 150.00,
        'description' => 'Second top-up',
        'idempotency_key' => $key2,
    ]);

    $response1->assertStatus(201);
    $response2->assertStatus(201);

    expect($response1->json('data.transaction.id'))->not->toBe($response2->json('data.transaction.id'))
        ->and(Transaction::whereIn('idempotency_key', [$key1, $key2])->count())->toBe(2);

    $wallet->refresh();
    // 200 + 100 + 150 = 450.00
    expect((float) $wallet->balance)->toBe(450.00);
});

test('distinct idempotency keys for agent withdrawal execute independently', function () {
    $agent = User::factory()->create();
    $role = Role::where('name', 'AGENT')->firstOrFail();
    $agent->roles()->attach($role->id, ['assigned_at' => now()]);
    AgentInfo::create([
        'user_id' => $agent->id,
        'commission_rate' => 0.0100,
        'status' => 'APPROVED',
    ]);
    $wallet = Wallet::factory()->for($agent)->balance(1000.00)->create();

    Sanctum::actingAs($agent, ['agent']);

    $key1 = 'withdraw-key-1-'.uniqid();
    $key2 = 'withdraw-key-2-'.uniqid();

    $response1 = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 200.00,
        'description' => 'Withdraw part 1',
        'idempotency_key' => $key1,
    ]);

    $response2 = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 300.00,
        'description' => 'Withdraw part 2',
        'idempotency_key' => $key2,
    ]);

    $response1->assertStatus(201);
    $response2->assertStatus(201);

    expect($response1->json('data.transaction.id'))->not->toBe($response2->json('data.transaction.id'))
        ->and(Transaction::whereIn('idempotency_key', [$key1, $key2])->count())->toBe(2);

    $wallet->refresh();
    // 1000 - 200 - 300 = 500.00
    expect((float) $wallet->balance)->toBe(500.00);
});

test('the same idempotency key used by two different users creates two separate transactions', function () {
    $role = Role::where('name', 'USER')->firstOrFail();

    $alice = User::factory()->create();
    $alice->roles()->attach($role->id, ['assigned_at' => now()]);
    $aliceWallet = Wallet::factory()->for($alice)->balance(100.00)->create();

    $bob = User::factory()->create();
    $bob->roles()->attach($role->id, ['assigned_at' => now()]);
    $bobWallet = Wallet::factory()->for($bob)->balance(100.00)->create();

    $sharedKey = 'shared-key-'.uniqid();

    Sanctum::actingAs($alice, ['user']);
    $aliceResponse = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 40.00,
        'idempotency_key' => $sharedKey,
    ]);

    Sanctum::actingAs($bob, ['user']);
    $bobResponse = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 70.00,
        'idempotency_key' => $sharedKey,
    ]);

    $aliceResponse->assertStatus(201);
    $bobResponse->assertStatus(201)
        ->assertJsonPath('data.transaction.user_id', $bob->id)
        ->assertJsonPath('data.transaction.initiated_by', $bob->id)
        ->assertJsonPath('data.transaction.amount', 70);

    expect($bobResponse->json('data.transaction.id'))->not->toBe($aliceResponse->json('data.transaction.id'))
        ->and(Transaction::where('idempotency_key', $sharedKey)->count())->toBe(2)
        ->and((float) $aliceWallet->refresh()->balance)->toBe(140.00)
        ->and((float) $bobWallet->refresh()->balance)->toBe(170.00);
});

test('replaying an idempotency key from the same user returns the original transaction without moving money again', function () {
    $user = User::factory()->create();
    $role = Role::where('name', 'USER')->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);
    $wallet = Wallet::factory()->for($user)->balance(100.00)->create();

    Sanctum::actingAs($user, ['user']);

    $payload = [
        'amount' => 25.00,
        'idempotency_key' => 'replay-key-'.uniqid(),
    ];

    $first = $this->postJson('/api/v1/transactions/top-up', $payload);
    $second = $this->postJson('/api/v1/transactions/top-up', $payload);

    $first->assertStatus(201);
    $second->assertSuccessful();

    expect($second->json('data.transaction.id'))->toBe($first->json('data.transaction.id'))
        ->and(Transaction::where('idempotency_key', $payload['idempotency_key'])->count())->toBe(1)
        ->and((float) $wallet->refresh()->balance)->toBe(125.00);
});
