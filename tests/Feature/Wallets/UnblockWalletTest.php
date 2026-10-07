<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function createActorForUnblockWallet(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

test('admin can unblock a blocked wallet and creates AuditLog record', function () {
    $admin = createActorForUnblockWallet('ADMIN');
    $user = createActorForUnblockWallet('USER');
    $wallet = Wallet::factory()->for($user)->balance(100.00)->blocked($admin->id)->create();

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/wallets/{$wallet->id}/unblock");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Wallet unblocked successfully')
        ->assertJsonPath('data.wallet.id', $wallet->id)
        ->assertJsonPath('data.wallet.is_blocked', false);

    $wallet->refresh();
    expect($wallet->is_blocked)->toBeFalse();

    $auditLog = AuditLog::where('action', 'WALLET_UNBLOCKED')
        ->where('auditable_id', $wallet->id)
        ->firstOrFail();

    expect($auditLog->actor_id)->toBe($admin->id);
});

test('unblocking an already active wallet is idempotent', function () {
    $admin = createActorForUnblockWallet('ADMIN');
    $user = createActorForUnblockWallet('USER');
    $wallet = Wallet::factory()->for($user)->balance(100.00)->create([
        'is_blocked' => false,
    ]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/wallets/{$wallet->id}/unblock");

    $response->assertOk()
        ->assertJsonPath('data.wallet.is_blocked', false);
});

test('regular user cannot unblock a wallet', function () {
    $user = createActorForUnblockWallet('USER');
    $wallet = Wallet::factory()->for($user)->balance(100.00)->blocked()->create();

    Sanctum::actingAs($user, ['user']);

    $response = $this->patchJson("/api/v1/wallets/{$wallet->id}/unblock");

    $response->assertStatus(403);
});

test('unauthenticated caller cannot access unblock wallet endpoint', function () {
    $user = createActorForUnblockWallet('USER');
    $wallet = Wallet::factory()->for($user)->balance(100.00)->blocked()->create();

    $response = $this->patchJson("/api/v1/wallets/{$wallet->id}/unblock");

    $response->assertStatus(401);
});
