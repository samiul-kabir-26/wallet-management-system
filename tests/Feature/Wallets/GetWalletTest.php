<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function createActorWithRoleForWallet(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

test('user can view their own wallet via /me', function () {
    $user = createActorWithRoleForWallet('USER');
    $wallet = Wallet::factory()->for($user)->balance(250.75)->create([
        'currency' => 'BDT',
    ]);

    Sanctum::actingAs($user, ['user']);

    $response = $this->getJson('/api/v1/wallets/me');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.wallet.id', $wallet->id)
        ->assertJsonPath('data.wallet.user_id', $user->id)
        ->assertJsonPath('data.wallet.balance', 250.75)
        ->assertJsonPath('data.wallet.currency', 'BDT')
        ->assertJsonPath('data.wallet.is_blocked', false);
});

test('agent can view their own wallet via /me', function () {
    $agent = createActorWithRoleForWallet('AGENT');
    $wallet = Wallet::factory()->for($agent)->balance(1500.00)->create([
        'currency' => 'BDT',
    ]);

    Sanctum::actingAs($agent, ['agent']);

    $response = $this->getJson('/api/v1/wallets/me');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.wallet.id', $wallet->id)
        ->assertJsonPath('data.wallet.balance', 1500);
});

test('admin can view all wallets via /admin/all with pagination and filters', function () {
    $admin = createActorWithRoleForWallet('ADMIN');

    $user1 = createActorWithRoleForWallet('USER');
    $wallet1 = Wallet::factory()->for($user1)->balance(100.00)->create(['is_blocked' => false]);

    $user2 = createActorWithRoleForWallet('USER');
    $wallet2 = Wallet::factory()->for($user2)->balance(200.00)->blocked()->create();

    Sanctum::actingAs($admin, ['admin']);

    // 1. Unfiltered list
    $response = $this->getJson('/api/v1/wallets/admin/all');
    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'wallets',
                'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
            ],
        ]);

    // 2. Filter by is_blocked=true
    $blockedResponse = $this->getJson('/api/v1/wallets/admin/all?is_blocked=true');
    $blockedResponse->assertOk()
        ->assertJsonPath('data.wallets.0.id', $wallet2->id);

    // 3. Filter by user_id
    $userFilteredResponse = $this->getJson("/api/v1/wallets/admin/all?user_id={$user1->id}");
    $userFilteredResponse->assertOk()
        ->assertJsonPath('data.wallets.0.id', $wallet1->id);
});

test('regular user cannot access admin list all wallets endpoint', function () {
    $user = createActorWithRoleForWallet('USER');
    Sanctum::actingAs($user, ['user']);

    $response = $this->getJson('/api/v1/wallets/admin/all');

    $response->assertStatus(403);
});

test('unauthenticated request to wallet endpoints returns 401', function () {
    $this->getJson('/api/v1/wallets/me')->assertStatus(401);
    $this->getJson('/api/v1/wallets/admin/all')->assertStatus(401);
});
