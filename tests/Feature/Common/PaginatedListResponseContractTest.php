<?php

use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seedRolesAndPermissions();
});

function createActor(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

test('users all-users endpoint matches paginated response contract and items is not empty', function (): void {
    $admin = createActor('ADMIN');
    $agent = createActor('AGENT');
    $customer = createActor('USER');

    Sanctum::actingAs($admin, ['admin']);

    // 1. General list
    $response = $this->getJson('/api/v1/users/all-users');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'items',
                'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to'],
            ],
        ]);

    $items = $response->json('data.items');
    expect($items)->not->toBeEmpty()
        ->and($response->json('data.pagination.total'))->toBeGreaterThanOrEqual(3);

    // 2. Server-side role filtering (role=AGENT)
    $agentResponse = $this->getJson('/api/v1/users/all-users?role=AGENT');

    $agentResponse->assertOk()
        ->assertJsonStructure([
            'data' => [
                'items',
                'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to'],
            ],
        ]);

    $agentItems = $agentResponse->json('data.items');
    expect($agentItems)->not->toBeEmpty();
    foreach ($agentItems as $item) {
        expect($item['roles'])->toContain('AGENT');
    }
});

test('wallets admin all endpoint matches paginated response contract and items is not empty', function (): void {
    $admin = createActor('ADMIN');
    $customer = createActor('USER');
    Wallet::factory()->for($customer)->balance(500.00)->create();

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->getJson('/api/v1/wallets/admin/all');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'items',
                'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to'],
            ],
        ]);

    expect($response->json('data.items'))->not->toBeEmpty()
        ->and($response->json('data.pagination.total'))->toBeGreaterThanOrEqual(1);
});

test('transactions history endpoint matches paginated response contract and items is not empty', function (): void {
    $user = createActor('USER');
    $wallet = Wallet::factory()->for($user)->balance(1000.00)->create();

    Transaction::create([
        'user_id' => $user->id,
        'sender_id' => $user->id,
        'recipient_wallet_id' => $wallet->id,
        'initiated_by' => $user->id,
        'type' => 'TOP_UP',
        'amount' => '100.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'tx-contract-history-1',
    ]);

    Sanctum::actingAs($user, ['user']);

    $response = $this->getJson('/api/v1/transactions/history');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'items',
                'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to'],
            ],
        ]);

    expect($response->json('data.items'))->not->toBeEmpty()
        ->and($response->json('data.pagination.total'))->toBe(1);
});

test('transactions admin all endpoint matches paginated response contract and items is not empty', function (): void {
    $admin = createActor('ADMIN');
    $user = createActor('USER');
    $wallet = Wallet::factory()->for($user)->balance(1000.00)->create();

    Transaction::create([
        'user_id' => $user->id,
        'sender_id' => $user->id,
        'recipient_wallet_id' => $wallet->id,
        'initiated_by' => $user->id,
        'type' => 'TOP_UP',
        'amount' => '250.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'tx-contract-admin-1',
    ]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->getJson('/api/v1/transactions/admin/all');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'items',
                'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to'],
            ],
        ]);

    expect($response->json('data.items'))->not->toBeEmpty()
        ->and($response->json('data.pagination.total'))->toBe(1);
});

test('roles endpoint matches paginated response contract and items is not empty', function (): void {
    $admin = createActor('ADMIN');

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->getJson('/api/v1/roles');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'items',
                'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to'],
            ],
        ]);

    expect($response->json('data.items'))->not->toBeEmpty()
        ->and($response->json('data.pagination.total'))->toBeGreaterThanOrEqual(1);
});
