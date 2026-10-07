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
