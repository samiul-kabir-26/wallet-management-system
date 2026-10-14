<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Sanctum\Sanctum;
use Modules\Roles\Enums\RolePanel;
use Modules\Users\Exceptions\UserRequiresPasswordForStaffRoleException;
use Modules\Users\Services\UserService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->userService = app(UserService::class);
});

test('assignRole rejects non-existent roles and staff roles when target has no password', function () {
    $userWithoutPassword = User::factory()->withoutPassword()->create();
    $admin = User::factory()->create();

    expect(fn () => $this->userService->assignRole($userWithoutPassword, 'NON_EXISTENT_ROLE', $admin))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->userService->assignRole($userWithoutPassword, 'ADMIN', $admin))
        ->toThrow(UserRequiresPasswordForStaffRoleException::class);
});

test('assignRole assigns AGENT role to existing USER, initializes AGENT wallet at 0.00 balance, and writes AuditLog', function () {
    $user = User::factory()->create();
    $userRole = Role::where('name', 'USER')->firstOrFail();
    $user->roles()->attach($userRole->id, ['assigned_at' => now()]);
    $userWallet = Wallet::factory()->for($user)->balance(50.00)->create();

    $actor = User::factory()->create();

    $updatedUser = $this->userService->assignRole($user, 'AGENT', $actor);

    expect($updatedUser->hasRole('AGENT'))->toBeTrue()
        ->and($updatedUser->hasRole('USER'))->toBeTrue()
        ->and($updatedUser->agentInfo)->not->toBeNull()
        ->and($updatedUser->agentInfo->status)->toBe('PENDING');

    // User's USER wallet remains at 50.00
    $userWallet->refresh();
    expect((float) $userWallet->balance)->toBe(50.00);

    // AGENT wallet created with 0.00 starting balance (not minting 50.00)
    $agentWallet = $updatedUser->agentWallet;
    expect($agentWallet)->not->toBeNull()
        ->and($agentWallet->kind)->toBe(Wallet::KIND_AGENT)
        ->and((float) $agentWallet->balance)->toBe(0.00);

    // Audit log recorded
    $log = AuditLog::where('action', 'ROLE_ASSIGNED')
        ->where('auditable_id', $user->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_id)->toBe($actor->id)
        ->and($log->metadata['role'])->toBe('AGENT')
        ->and($log->metadata['assigned_by'])->toBe($actor->id);
});

test('assignRole assigns USER role to existing AGENT, initializes USER wallet at 0.00 balance, and writes AuditLog', function () {
    $agent = User::factory()->create();
    $agentRole = Role::where('name', 'AGENT')->firstOrFail();
    $agent->roles()->attach($agentRole->id, ['assigned_at' => now()]);
    $agentWallet = Wallet::factory()->agent()->for($agent)->balance(50.00)->create();

    $actor = User::factory()->create();

    $updatedUser = $this->userService->assignRole($agent, 'USER', $actor);

    expect($updatedUser->hasRole('USER'))->toBeTrue()
        ->and($updatedUser->hasRole('AGENT'))->toBeTrue()
        ->and($updatedUser->cap)->not->toBeNull();

    // USER wallet created with 0.00 starting balance
    $userWallet = $updatedUser->userWallet;
    expect($userWallet)->not->toBeNull()
        ->and($userWallet->kind)->toBe(Wallet::KIND_USER)
        ->and((float) $userWallet->balance)->toBe(0.00);

    // Audit log recorded
    $log = AuditLog::where('action', 'ROLE_ASSIGNED')
        ->where('auditable_id', $agent->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_id)->toBe($actor->id)
        ->and($log->metadata['role'])->toBe('USER');
});

test('admin can assign AGENT role to existing customer via endpoint', function () {
    $admin = User::factory()->create();
    $adminRole = Role::where('name', 'ADMIN')->firstOrFail();
    $admin->roles()->attach($adminRole->id, ['assigned_at' => now()]);

    $user = User::factory()->create();
    $userRole = Role::where('name', 'USER')->firstOrFail();
    $user->roles()->attach($userRole->id, ['assigned_at' => now()]);
    $userWallet = Wallet::factory()->for($user)->balance(50.00)->create();

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson("/api/v1/users/{$user->id}/assign-role", [
        'role' => 'AGENT',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Role assigned successfully')
        ->assertJsonPath('data.user.id', $user->id);

    $user->refresh();
    expect($user->hasRole('AGENT'))->toBeTrue()
        ->and($user->hasRole('USER'))->toBeTrue()
        ->and($user->agentInfo)->not->toBeNull()
        ->and($user->agentInfo->status)->toBe('PENDING');

    // Existing USER wallet unchanged
    $userWallet->refresh();
    expect((float) $userWallet->balance)->toBe(50.00);

    // AGENT wallet initialized at 0.00
    $agentWallet = $user->agentWallet;
    expect($agentWallet)->not->toBeNull()
        ->and($agentWallet->kind)->toBe(Wallet::KIND_AGENT)
        ->and((float) $agentWallet->balance)->toBe(0.00);

    // AuditLog recorded
    $log = AuditLog::where('action', 'ROLE_ASSIGNED')
        ->where('auditable_id', $user->id)
        ->first();
    expect($log)->not->toBeNull()
        ->and($log->actor_id)->toBe($admin->id)
        ->and($log->metadata['role'])->toBe('AGENT')
        ->and($log->metadata['assigned_by'])->toBe($admin->id);
});

test('admin can assign USER role to existing agent via endpoint', function () {
    $admin = User::factory()->create();
    $adminRole = Role::where('name', 'ADMIN')->firstOrFail();
    $admin->roles()->attach($adminRole->id, ['assigned_at' => now()]);

    $agent = User::factory()->create();
    $agentRole = Role::where('name', 'AGENT')->firstOrFail();
    $agent->roles()->attach($agentRole->id, ['assigned_at' => now()]);
    $agentWallet = Wallet::factory()->agent()->for($agent)->balance(100.00)->create();

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson("/api/v1/users/{$agent->id}/assign-role", [
        'role' => 'USER',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Role assigned successfully')
        ->assertJsonPath('data.user.id', $agent->id);

    $agent->refresh();
    expect($agent->hasRole('USER'))->toBeTrue()
        ->and($agent->hasRole('AGENT'))->toBeTrue()
        ->and($agent->cap)->not->toBeNull();

    // Existing AGENT wallet unchanged
    $agentWallet->refresh();
    expect((float) $agentWallet->balance)->toBe(100.00);

    // USER wallet initialized at 0.00
    $userWallet = $agent->userWallet;
    expect($userWallet)->not->toBeNull()
        ->and($userWallet->kind)->toBe(Wallet::KIND_USER)
        ->and((float) $userWallet->balance)->toBe(0.00);

    // AuditLog recorded
    $log = AuditLog::where('action', 'ROLE_ASSIGNED')
        ->where('auditable_id', $agent->id)
        ->first();
    expect($log)->not->toBeNull()
        ->and($log->actor_id)->toBe($admin->id)
        ->and($log->metadata['role'])->toBe('USER');
});

test('assignRole endpoint rejects invalid roles and staff assignment without password with 422', function () {
    $admin = User::factory()->create();
    $adminRole = Role::where('name', 'ADMIN')->firstOrFail();
    $admin->roles()->attach($adminRole->id, ['assigned_at' => now()]);

    $userWithoutPassword = User::factory()->withoutPassword()->create();

    Sanctum::actingAs($admin, ['admin']);

    // Non-existent roles fail validation
    $this->postJson("/api/v1/users/{$userWithoutPassword->id}/assign-role", ['role' => 'BOGUS'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['role']);

    $this->postJson("/api/v1/users/{$userWithoutPassword->id}/assign-role", ['role' => 'MODERATOR'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['role']);

    // Admin role to user without password fails with 422
    $this->postJson("/api/v1/users/{$userWithoutPassword->id}/assign-role", ['role' => 'ADMIN'])
        ->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'Cannot assign a staff role to a user without a password. Grant admin access via invite instead.',
        ]);
});

test('super admin can assign custom admin-panel role to existing staff member with password', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach(Role::where('name', 'SUPER_ADMIN')->first()->id, ['assigned_at' => now()]);

    $customRole = Role::create([
        'name' => 'AUDITOR',
        'panel' => RolePanel::Admin,
        'description' => 'Auditor staff role',
        'is_system' => false,
    ]);

    $existingStaff = User::factory()->create([
        'email' => 'staff_auditor@example.com',
        'password' => Hash::make('StaffPassword123!'),
    ]);
    $existingStaff->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    Sanctum::actingAs($superAdmin, ['admin']);

    $response = $this->postJson("/api/v1/users/{$existingStaff->id}/assign-role", [
        'role' => 'AUDITOR',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Role assigned successfully');

    $existingStaff->refresh();
    expect($existingStaff->hasRole('AUDITOR'))->toBeTrue()
        ->and($existingStaff->hasRole('ADMIN'))->toBeTrue()
        ->and($existingStaff->email)->toBe('staff_auditor@example.com')
        ->and(Hash::check('StaffPassword123!', $existingStaff->password))->toBeTrue();

    // Verify audit log
    $log = AuditLog::where('action', 'ROLE_ASSIGNED')
        ->where('auditable_id', $existingStaff->id)
        ->where('metadata->role', 'AUDITOR')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_id)->toBe($superAdmin->id);
});

test('non-super admin cannot escalate by assigning a staff role exceeding their permissions', function () {
    $managerRole = Role::create([
        'name' => 'STAFF_LEAD',
        'panel' => RolePanel::Admin,
        'description' => 'Staff lead',
        'is_system' => false,
    ]);
    $assignPerm = Permission::where('name', 'users.assign_role')->firstOrFail();
    $managerRole->permissions()->attach([$assignPerm->id]);

    $manager = User::factory()->create();
    $manager->roles()->attach($managerRole->id, ['assigned_at' => now()]);

    $existingStaff = User::factory()->create();
    $existingStaff->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    Sanctum::actingAs($manager, ['admin']);

    // Attempting to assign SUPER_ADMIN or ADMIN (which has more permissions) must fail with 403
    $this->postJson("/api/v1/users/{$existingStaff->id}/assign-role", [
        'role' => 'ADMIN',
    ])->assertStatus(403);
});

test('non-admin callers are forbidden from assigning roles with 403', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    // User with 'user' ability
    Sanctum::actingAs($user, ['user']);
    $this->postJson("/api/v1/users/{$target->id}/assign-role", ['role' => 'AGENT'])
        ->assertStatus(403);

    // Agent with 'agent' ability
    $agent = User::factory()->create();
    Sanctum::actingAs($agent, ['agent']);
    $this->postJson("/api/v1/users/{$target->id}/assign-role", ['role' => 'USER'])
        ->assertStatus(403);

    // Staff user with 'admin' ability but lacking users.assign_role permission
    $staff = User::factory()->create();
    $supportRole = Role::create([
        'name' => 'SUPPORT_STAFF',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);
    $staff->roles()->attach($supportRole->id, ['assigned_at' => now()]);
    Sanctum::actingAs($staff, ['admin']);
    $this->postJson("/api/v1/users/{$target->id}/assign-role", ['role' => 'AGENT'])
        ->assertStatus(403);
});

test('unauthenticated caller receives 401 on assignRole endpoint', function () {
    $target = User::factory()->create();
    $this->postJson("/api/v1/users/{$target->id}/assign-role", ['role' => 'AGENT'])
        ->assertStatus(401);
});

test('assigning an already-held role returns 200 with no duplicate wallet and no second audit row', function () {
    $admin = User::factory()->create();
    $adminRole = Role::where('name', 'ADMIN')->firstOrFail();
    $admin->roles()->attach($adminRole->id, ['assigned_at' => now()]);

    $user = User::factory()->create();
    $userRole = Role::where('name', 'USER')->firstOrFail();
    $user->roles()->attach($userRole->id, ['assigned_at' => now()]);
    $agentRole = Role::where('name', 'AGENT')->firstOrFail();
    $user->roles()->attach($agentRole->id, ['assigned_at' => now()]);
    $user->agentInfo()->create(['status' => 'PENDING']);
    $agentWallet = Wallet::factory()->agent()->for($user)->balance(0.00)->create();

    Sanctum::actingAs($admin, ['admin']);

    $auditCountBefore = AuditLog::count();

    $response = $this->postJson("/api/v1/users/{$user->id}/assign-role", [
        'role' => 'AGENT',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    // No duplicate wallet created
    expect($user->wallets()->where('kind', Wallet::KIND_AGENT)->count())->toBe(1);

    // No second audit row recorded
    expect(AuditLog::count())->toBe($auditCountBefore);
});

test('admin cannot assign a role to themselves', function () {
    $admin = User::factory()->create();
    $adminRole = Role::where('name', 'ADMIN')->firstOrFail();
    $admin->roles()->attach($adminRole->id, ['assigned_at' => now()]);

    Sanctum::actingAs($admin, ['admin']);

    $this->postJson("/api/v1/users/{$admin->id}/assign-role", ['role' => 'AGENT'])
        ->assertStatus(403);
});

test('assignRole rejects inactive target user with 403', function () {
    $admin = User::factory()->create();
    $adminRole = Role::where('name', 'ADMIN')->firstOrFail();
    $admin->roles()->attach($adminRole->id, ['assigned_at' => now()]);

    $inactiveUser = User::factory()->create(['is_active' => 'INACTIVE']);
    $userRole = Role::where('name', 'USER')->firstOrFail();
    $inactiveUser->roles()->attach($userRole->id, ['assigned_at' => now()]);

    Sanctum::actingAs($admin, ['admin']);

    $this->postJson("/api/v1/users/{$inactiveUser->id}/assign-role", ['role' => 'AGENT'])
        ->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Target user account is inactive.');

    // Verify no wallet created for inactive target
    expect($inactiveUser->wallets()->where('kind', Wallet::KIND_AGENT)->exists())->toBeFalse();
});
