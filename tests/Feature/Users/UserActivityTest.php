<?php

use App\Models\AuditLog;
use App\Models\LoginActivity;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function createActivityTestUser(string $roleName, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->roles()->attach(Role::where('name', $roleName)->firstOrFail()->id, ['assigned_at' => now()]);

    return $user;
}

test('admin marks a customer phone as verified and the change is audited once', function () {
    $admin = createActivityTestUser('ADMIN');
    $customer = createActivityTestUser('USER', ['is_verified' => false]);

    Sanctum::actingAs($admin, ['admin']);
    $this->patchJson("/api/v1/users/{$customer->id}/verify-phone")
        ->assertOk()
        ->assertJsonPath('data.user.is_verified', true);

    // Idempotent: a second call neither fails nor writes another audit entry
    $this->patchJson("/api/v1/users/{$customer->id}/verify-phone")->assertOk();

    expect((bool) $customer->refresh()->is_verified)->toBeTrue()
        ->and(AuditLog::where('action', 'PHONE_VERIFIED')->where('auditable_id', $customer->id)->count())->toBe(1);
});

test('a verified phone unblocks granting admin access', function () {
    $superAdmin = createActivityTestUser('SUPER_ADMIN');
    $customer = User::factory()->withoutPassword()->unverified()->create();
    $customer->roles()->attach(Role::where('name', 'USER')->firstOrFail()->id, ['assigned_at' => now()]);

    Sanctum::actingAs($superAdmin, ['admin']);
    $payload = ['email' => 'new.staff@wallet.test', 'role' => 'ADMIN'];

    $this->patchJson("/api/v1/users/{$customer->id}/grant-admin-access", $payload)->assertStatus(422);

    $this->patchJson("/api/v1/users/{$customer->id}/verify-phone")->assertOk();
    $this->patchJson("/api/v1/users/{$customer->id}/grant-admin-access", $payload)->assertOk();
});

test('verifying a phone needs the permission, an admin token and another account', function () {
    $admin = createActivityTestUser('ADMIN', ['is_verified' => false]);
    $customer = createActivityTestUser('USER', ['is_verified' => false]);
    $auditor = createActivityTestUser('USER');
    $auditor->roles()->attach(Role::create(['name' => 'AUDITOR', 'panel' => 'admin', 'is_system' => false])->id, ['assigned_at' => now()]);

    Sanctum::actingAs($admin, ['admin']);
    $this->patchJson("/api/v1/users/{$admin->id}/verify-phone")->assertForbidden();

    Sanctum::actingAs($auditor, ['admin']);
    $this->patchJson("/api/v1/users/{$customer->id}/verify-phone")->assertForbidden();

    Sanctum::actingAs($admin, ['user']);
    $this->patchJson("/api/v1/users/{$customer->id}/verify-phone")->assertForbidden();

    expect((bool) $customer->refresh()->is_verified)->toBeFalse();
});

test('an account without a phone number cannot be phone-verified', function () {
    $admin = createActivityTestUser('ADMIN');
    $staff = createActivityTestUser('ADMIN', ['phone_number' => null, 'is_verified' => false]);

    Sanctum::actingAs($admin, ['admin']);
    $this->patchJson("/api/v1/users/{$staff->id}/verify-phone")->assertStatus(422);
});

test('PIN logins record successful and failed attempts with panel, IP and device', function () {
    $customer = createActivityTestUser('USER');
    RateLimiter::clear('pin-attempts:'.$customer->id);

    $this->withHeader('User-Agent', 'WalletTest/1.0')->postJson('/api/v1/auth/user/login', [
        'phone_number' => $customer->phone_number,
        'pin' => '99999',
    ])->assertUnauthorized();

    $this->withHeader('User-Agent', 'WalletTest/1.0')->postJson('/api/v1/auth/user/login', [
        'phone_number' => $customer->phone_number,
        'pin' => '12345',
    ])->assertOk();

    // Wrong panel for this account: recorded as a failure with the reason shown to the caller
    $this->postJson('/api/v1/auth/agent/login', [
        'phone_number' => $customer->phone_number,
        'pin' => '12345',
    ])->assertForbidden();

    $records = LoginActivity::where('user_id', $customer->id)->orderBy('id')->get();

    expect($records)->toHaveCount(3)
        ->and($records[0]->status)->toBe('FAILED')
        ->and($records[0]->panel)->toBe('user')
        ->and($records[0]->user_agent)->toBe('WalletTest/1.0')
        ->and($records[0]->ip_address)->not->toBeNull()
        ->and($records[1]->status)->toBe('SUCCESS')
        ->and($records[2]->panel)->toBe('agent')
        ->and($records[2]->failure_reason)->toContain('customer account');
});

test('a login attempt for an unknown number is recorded without a user', function () {
    $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => '01999999999',
        'pin' => '12345',
    ])->assertUnauthorized();

    $record = LoginActivity::sole();
    expect($record->user_id)->toBeNull()
        ->and($record->identifier)->toBe('01999999999')
        ->and($record->status)->toBe('FAILED');
});

test('staff with users.view_activity read a user login records and audit trail', function () {
    $admin = createActivityTestUser('ADMIN');
    $customer = createActivityTestUser('USER');
    $wallet = Wallet::factory()->for($customer)->create();

    LoginActivity::create(['user_id' => $customer->id, 'panel' => 'user', 'identifier' => $customer->phone_number, 'status' => 'SUCCESS']);
    AuditLog::create(['actor_id' => $admin->id, 'action' => 'WALLET_BLOCKED', 'auditable_type' => $wallet->getMorphClass(), 'auditable_id' => $wallet->id]);
    AuditLog::create(['actor_id' => $admin->id, 'action' => 'PHONE_VERIFIED', 'auditable_type' => $customer->getMorphClass(), 'auditable_id' => $customer->id]);
    AuditLog::create(['actor_id' => $customer->id, 'action' => 'PIN_RESET_COMPLETED', 'auditable_type' => $customer->getMorphClass(), 'auditable_id' => $customer->id]);
    AuditLog::create(['actor_id' => $admin->id, 'action' => 'UNRELATED', 'auditable_type' => $admin->getMorphClass(), 'auditable_id' => $admin->id]);

    Sanctum::actingAs($admin, ['admin']);

    $this->getJson("/api/v1/users/{$customer->id}/login-activity")
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.items.0.status', 'SUCCESS');

    $actions = collect($this->getJson("/api/v1/users/{$customer->id}/audit-trail")->assertOk()->json('data.items'))->pluck('action');
    expect($actions->all())->toEqualCanonicalizing(['WALLET_BLOCKED', 'PHONE_VERIFIED', 'PIN_RESET_COMPLETED']);
});

test('activity endpoints are denied without users.view_activity or an admin token', function () {
    $customer = createActivityTestUser('USER');
    $auditor = createActivityTestUser('USER');
    $auditor->roles()->attach(Role::create(['name' => 'AUDITOR', 'panel' => 'admin', 'is_system' => false])->id, ['assigned_at' => now()]);

    Sanctum::actingAs($auditor, ['admin']);
    $this->getJson("/api/v1/users/{$customer->id}/login-activity")->assertForbidden();
    $this->getJson("/api/v1/users/{$customer->id}/audit-trail")->assertForbidden();

    Sanctum::actingAs($customer, ['user']);
    $this->getJson("/api/v1/users/{$customer->id}/login-activity")->assertForbidden();
});
