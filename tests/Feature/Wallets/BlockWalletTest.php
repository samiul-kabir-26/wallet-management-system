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

function createActorForBlockWallet(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

test('admin can block a wallet and records blocked_by, blocked_at, and AuditLog', function () {
    $admin = createActorForBlockWallet('ADMIN');
    $user = createActorForBlockWallet('USER');
    $wallet = Wallet::factory()->for($user)->balance(100.00)->create(['is_blocked' => false]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/wallets/{$wallet->id}/block", [
        'reason' => 'Suspicious deposit pattern',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Wallet blocked successfully')
        ->assertJsonPath('data.wallet.id', $wallet->id)
        ->assertJsonPath('data.wallet.is_blocked', true)
        ->assertJsonPath('data.wallet.blocked_by', $admin->id);

    $wallet->refresh();
    expect($wallet->is_blocked)->toBeTrue()
        ->and($wallet->blocked_by)->toBe($admin->id)
        ->and($wallet->blocked_at)->not->toBeNull();

    $auditLog = AuditLog::where('action', 'WALLET_BLOCKED')
        ->where('auditable_id', $wallet->id)
        ->firstOrFail();

    expect($auditLog->actor_id)->toBe($admin->id)
        ->and($auditLog->metadata['reason'])->toBe('Suspicious deposit pattern');
});

test('blocking a wallet without a reason fails with 422', function () {
    $admin = createActorForBlockWallet('ADMIN');
    $user = createActorForBlockWallet('USER');
    $wallet = Wallet::factory()->for($user)->balance(100.00)->create(['is_blocked' => false]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/wallets/{$wallet->id}/block", []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

test('blocking an already blocked wallet is idempotent', function () {
    $admin = createActorForBlockWallet('ADMIN');
    $user = createActorForBlockWallet('USER');
    $wallet = Wallet::factory()->for($user)->balance(100.00)->blocked($admin->id)->create();

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/wallets/{$wallet->id}/block", [
        'reason' => 'Re-blocking test',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.wallet.is_blocked', true);
});

test('re-blocking an already blocked wallet by a different admin preserves original block attributes and records both audit entries', function () {
    $adminA = createActorForBlockWallet('ADMIN');
    $adminB = createActorForBlockWallet('ADMIN');
    $user = createActorForBlockWallet('USER');
    $wallet = Wallet::factory()->for($user)->balance(100.00)->create();

    // 1. Initial block by Admin A
    Sanctum::actingAs($adminA, ['admin']);
    $firstResponse = $this->patchJson("/api/v1/wallets/{$wallet->id}/block", [
        'reason' => 'First reason: suspicious transactions',
    ]);
    $firstResponse->assertOk()
        ->assertJsonPath('data.wallet.is_blocked', true)
        ->assertJsonPath('data.wallet.blocked_by', $adminA->id);

    $wallet->refresh();
    $originalBlockedAt = $wallet->blocked_at;
    expect($wallet->blocked_by)->toBe($adminA->id);

    // 2. Second block by Admin B with a different reason
    Sanctum::actingAs($adminB, ['admin']);
    $secondResponse = $this->patchJson("/api/v1/wallets/{$wallet->id}/block", [
        'reason' => 'Second reason: law enforcement request',
    ]);
    $secondResponse->assertOk()
        ->assertJsonPath('data.wallet.is_blocked', true)
        ->assertJsonPath('data.wallet.blocked_by', $adminA->id);

    $wallet->refresh();
    expect($wallet->blocked_by)->toBe($adminA->id)
        ->and($wallet->blocked_at->toIso8601String())->toBe($originalBlockedAt->toIso8601String());

    // 3. Both audit records exist with their respective admin and stated reason
    $auditLogs = AuditLog::where('action', 'WALLET_BLOCKED')
        ->where('auditable_id', $wallet->id)
        ->orderBy('id', 'asc')
        ->get();

    expect($auditLogs)->toHaveCount(2)
        ->and($auditLogs[0]->actor_id)->toBe($adminA->id)
        ->and($auditLogs[0]->metadata['reason'])->toBe('First reason: suspicious transactions')
        ->and($auditLogs[1]->actor_id)->toBe($adminB->id)
        ->and($auditLogs[1]->metadata['reason'])->toBe('Second reason: law enforcement request');
});

test('regular user cannot block a wallet', function () {
    $user = createActorForBlockWallet('USER');
    $wallet = Wallet::factory()->for($user)->balance(100.00)->create(['is_blocked' => false]);

    Sanctum::actingAs($user, ['user']);

    $response = $this->patchJson("/api/v1/wallets/{$wallet->id}/block", [
        'reason' => 'Unauthorized block',
    ]);

    $response->assertStatus(403);
});

test('unauthenticated caller cannot access block wallet endpoint', function () {
    $user = createActorForBlockWallet('USER');
    $wallet = Wallet::factory()->for($user)->balance(100.00)->create();

    $response = $this->patchJson("/api/v1/wallets/{$wallet->id}/block", [
        'reason' => 'Anonymous block',
    ]);

    $response->assertStatus(401);
});
