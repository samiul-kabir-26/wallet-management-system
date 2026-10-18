<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use Modules\Roles\Enums\RolePanel;

beforeEach(function (): void {
    $this->seed([
        PermissionSeeder::class,
        RoleSeeder::class,
        RolePermissionSeeder::class,
    ]);
});

function createAdminActor(): User
{
    $admin = User::factory()->create();
    $adminRole = Role::where('name', 'ADMIN')->firstOrFail();
    $admin->roles()->attach($adminRole->id, ['assigned_at' => now()]);

    return $admin;
}

function grantRolesManageToAdmin(User $admin): void
{
    $rolesManagePerm = Permission::where('name', 'roles.manage')->firstOrFail();
    $adminRole = Role::where('name', 'ADMIN')->firstOrFail();
    $adminRole->permissions()->syncWithoutDetaching([$rolesManagePerm->id]);
    $admin->flushPermissionsMemo();
}

test('an admin with roles.manage cannot create a role with permissions they lack', function (): void {
    $admin = createAdminActor();
    grantRolesManageToAdmin($admin);
    Sanctum::actingAs($admin, ['admin']);

    // ADMIN lacks users.grant_admin_access by default
    expect($admin->can('users.grant_admin_access'))->toBeFalse();

    $response = $this->postJson('/api/v1/roles', [
        'name' => 'ESCALATED_ROLE',
        'permissions' => ['wallets.block', 'users.grant_admin_access'],
    ]);

    $response->assertStatus(403);
    expect(Role::where('name', 'ESCALATED_ROLE')->exists())->toBeFalse();
});

test('an admin cannot remove a permission they lack from another role', function (): void {
    $admin = createAdminActor();
    grantRolesManageToAdmin($admin);
    Sanctum::actingAs($admin, ['admin']);

    // Create a target role holding a permission the admin lacks
    $superRole = Role::create([
        'name' => 'SUPER_DELEGATE',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);
    $grantAdminPerm = Permission::where('name', 'users.grant_admin_access')->firstOrFail();
    $blockPerm = Permission::where('name', 'wallets.block')->firstOrFail();
    $superRole->permissions()->attach([$grantAdminPerm->id, $blockPerm->id]);

    // Admin tries to remove users.grant_admin_access (which admin lacks)
    $response = $this->putJson("/api/v1/roles/{$superRole->id}/permissions", [
        'permissions' => ['wallets.block'],
    ]);

    $response->assertStatus(403);
    expect($superRole->refresh()->permissions()->where('name', 'users.grant_admin_access')->exists())->toBeTrue();
});

test('an admin cannot edit or sync the permissions of a role they hold', function (): void {
    $admin = createAdminActor();
    grantRolesManageToAdmin($admin);
    Sanctum::actingAs($admin, ['admin']);

    $adminRole = Role::where('name', 'ADMIN')->firstOrFail();

    // Admin holds the ADMIN role; cannot modify it
    $response = $this->putJson("/api/v1/roles/{$adminRole->id}/permissions", [
        'permissions' => ['wallets.block'],
    ]);

    $response->assertStatus(403);
});

test('admin cannot modify SUPER_ADMIN or ADMIN permissions, but SUPER_ADMIN can modify ADMIN', function (): void {
    $admin = createAdminActor();
    grantRolesManageToAdmin($admin);
    Sanctum::actingAs($admin, ['admin']);

    $superRole = Role::where('name', 'SUPER_ADMIN')->firstOrFail();
    $adminRole = Role::where('name', 'ADMIN')->firstOrFail();

    // Admin cannot modify SUPER_ADMIN permissions -> 403
    $this->putJson("/api/v1/roles/{$superRole->id}/permissions", [
        'permissions' => ['wallets.block'],
    ])->assertStatus(403);

    // Admin cannot modify ADMIN permissions -> 403
    $this->putJson("/api/v1/roles/{$adminRole->id}/permissions", [
        'permissions' => ['wallets.block'],
    ])->assertStatus(403);

    // Super Admin CAN modify ADMIN permissions -> 200
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach($superRole->id, ['assigned_at' => now()]);
    Sanctum::actingAs($superAdmin, ['admin']);

    $this->putJson("/api/v1/roles/{$adminRole->id}/permissions", [
        'permissions' => ['wallets.block', 'wallets.unblock'],
    ])->assertStatus(200);

    expect($adminRole->refresh()->permissions()->pluck('name')->all())->toEqualCanonicalizing([
        'wallets.block',
        'wallets.unblock',
    ]);
});

test('an admin cannot assign a role whose permissions exceed their own', function (): void {
    $admin = createAdminActor();
    Sanctum::actingAs($admin, ['admin']);

    // Create a powerful role holding users.grant_admin_access
    $privilegedRole = Role::create([
        'name' => 'INVITER',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);
    $grantAdminPerm = Permission::where('name', 'users.grant_admin_access')->firstOrFail();
    $privilegedRole->permissions()->attach($grantAdminPerm->id);

    $target = User::factory()->create();

    // Admin attempts to register user with the privileged role
    $response = $this->postJson('/api/v1/users/register', [
        'name' => 'Target User',
        'email' => 'target@example.com',
        'password' => 'SecurePass123!',
        'role' => 'INVITER',
    ]);

    $response->assertStatus(403);
});

test('an admin cannot assign the SUPER_ADMIN role', function (): void {
    $admin = createAdminActor();
    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/users/register', [
        'name' => 'Fake Super Admin',
        'email' => 'fake.sa@example.com',
        'password' => 'SecurePass123!',
        'role' => 'SUPER_ADMIN',
    ]);

    // SUPER_ADMIN is never registrable through this endpoint, so validation rejects it before the escalation check
    $response->assertUnprocessable()->assertJsonValidationErrors('role');
    expect(User::where('email', 'fake.sa@example.com')->exists())->toBeFalse();
});

test('staff actions are rejected with 403 when using a non-admin token', function (): void {
    $admin = createAdminActor();
    grantRolesManageToAdmin($admin);

    // Non-admin token ability (e.g. user or agent)
    Sanctum::actingAs($admin, ['user']);

    $this->getJson('/api/v1/roles')->assertStatus(403);
    $this->getJson('/api/v1/permissions')->assertStatus(403);
    $this->postJson('/api/v1/roles', ['name' => 'NEW_ROLE'])->assertStatus(403);
});

test('cannot sync permissions on USER or AGENT customer roles', function (): void {
    $superRole = Role::where('name', 'SUPER_ADMIN')->firstOrFail();
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach($superRole->id, ['assigned_at' => now()]);
    Sanctum::actingAs($superAdmin, ['admin']);

    $userRole = Role::where('name', 'USER')->firstOrFail();
    $agentRole = Role::where('name', 'AGENT')->firstOrFail();

    $this->putJson("/api/v1/roles/{$userRole->id}/permissions", [
        'permissions' => ['wallets.view_any'],
    ])->assertStatus(403);

    $this->putJson("/api/v1/roles/{$agentRole->id}/permissions", [
        'permissions' => ['wallets.view_any'],
    ])->assertStatus(403);
});

test('Gate::before does not bypass policy methods for SUPER_ADMIN', function (): void {
    $superRole = Role::where('name', 'SUPER_ADMIN')->firstOrFail();
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach($superRole->id, ['assigned_at' => now()]);
    Sanctum::actingAs($superAdmin, ['admin']);

    // Policy method UserPolicy::delete always returns false
    expect(Gate::allows('delete', $superAdmin))->toBeFalse();

    // Policy method UserPolicy::approveAgent disallows self-approval
    expect(Gate::allows('approveAgent', $superAdmin))->toBeFalse();

    // Catalog permissions ARE bypassed for SUPER_ADMIN with admin token
    expect(Gate::allows('wallets.view_any'))->toBeTrue();
    expect(Gate::allows('roles.manage'))->toBeTrue();
});

test('RoleController index caps per_page at 100 and validates panel query', function (): void {
    $superRole = Role::where('name', 'SUPER_ADMIN')->firstOrFail();
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach($superRole->id, ['assigned_at' => now()]);
    Sanctum::actingAs($superAdmin, ['admin']);

    // per_page > 100 fails validation
    $this->getJson('/api/v1/roles?per_page=1000')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['per_page']);

    // invalid panel fails validation
    $this->getJson('/api/v1/roles?panel=invalid_panel')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['panel']);

    // valid panel succeeds
    $this->getJson('/api/v1/roles?panel=admin&per_page=100')
        ->assertOk();
});

test('SUPER_ADMIN cannot sync users.grant_admin_access onto a custom role', function (): void {
    $superRole = Role::where('name', 'SUPER_ADMIN')->firstOrFail();
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach($superRole->id, ['assigned_at' => now()]);
    Sanctum::actingAs($superAdmin, ['admin']);

    $customRole = Role::create([
        'name' => 'HR_LEAD',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);

    $response = $this->putJson("/api/v1/roles/{$customRole->id}/permissions", [
        'permissions' => ['users.grant_admin_access'],
    ]);

    $response->assertStatus(403);
    expect($customRole->refresh()->permissions()->where('name', 'users.grant_admin_access')->exists())->toBeFalse();
});

test('SUPER_ADMIN cannot create a role with users.grant_admin_access', function (): void {
    $superRole = Role::where('name', 'SUPER_ADMIN')->firstOrFail();
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach($superRole->id, ['assigned_at' => now()]);
    Sanctum::actingAs($superAdmin, ['admin']);

    $response = $this->postJson('/api/v1/roles', [
        'name' => 'HR_STAFF',
        'permissions' => ['users.grant_admin_access'],
    ]);

    $response->assertStatus(403);
    expect(Role::where('name', 'HR_STAFF')->exists())->toBeFalse();
});

test('inserting a role_permissions row directly for users.grant_admin_access assigned to a non-SUPER_ADMIN is denied by Gate and cannot call grant-admin-access', function (): void {
    $admin = createAdminActor();
    $grantPerm = Permission::where('name', 'users.grant_admin_access')->firstOrFail();
    $adminRole = Role::where('name', 'ADMIN')->firstOrFail();

    // Directly insert row into role_permissions
    DB::table('role_permissions')->insert([
        'role_id' => $adminRole->id,
        'permission_id' => $grantPerm->id,
        'created_at' => now(),
    ]);
    $admin->flushPermissionsMemo();

    Sanctum::actingAs($admin, ['admin']);

    // Gate::define unconditionally denies super-admin-only permissions to all non-super-admins
    expect($admin->can('users.grant_admin_access'))->toBeFalse();

    $target = User::factory()->withoutPassword()->create(['is_verified' => true]);

    $response = $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'newadmin@example.com',
        'role' => 'ADMIN',
    ]);

    $response->assertStatus(403);
});

test('non-super-admin cannot create a role with super-admin-only permission users.grant_admin_access', function (): void {
    $admin = createAdminActor();
    grantRolesManageToAdmin($admin);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/roles', [
        'name' => 'INVITER_ROLE',
        'permissions' => ['users.grant_admin_access'],
    ]);

    $response->assertStatus(403);
    expect(Role::where('name', 'INVITER_ROLE')->exists())->toBeFalse();
});

test('non-super-admin cannot sync super-admin-only permission users.grant_admin_access to any role', function (): void {
    $admin = createAdminActor();
    grantRolesManageToAdmin($admin);

    $customRole = Role::create([
        'name' => 'SUPPORT_LEAD',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->putJson("/api/v1/roles/{$customRole->id}/permissions", [
        'permissions' => ['users.grant_admin_access'],
    ]);

    $response->assertStatus(403);
    expect($customRole->refresh()->permissions()->where('name', 'users.grant_admin_access')->exists())->toBeFalse();
});

test('non-super-admin cannot assign a role containing users.grant_admin_access to a user', function (): void {
    $admin = createAdminActor();
    $assignRolePerm = Permission::where('name', 'users.assign_role')->firstOrFail();
    $adminRole = Role::where('name', 'ADMIN')->firstOrFail();
    $adminRole->permissions()->syncWithoutDetaching([$assignRolePerm->id]);
    $admin->flushPermissionsMemo();

    $grantPerm = Permission::where('name', 'users.grant_admin_access')->firstOrFail();

    // Directly insert onto a custom role
    $inviterRole = Role::create([
        'name' => 'STAFF_INVITER',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);
    DB::table('role_permissions')->insert([
        'role_id' => $inviterRole->id,
        'permission_id' => $grantPerm->id,
        'created_at' => now(),
    ]);

    $targetUser = User::factory()->create(['password' => Hash::make('password')]);
    $targetUser->roles()->attach($adminRole->id, ['assigned_at' => now()]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson("/api/v1/users/{$targetUser->id}/assign-role", [
        'role' => 'STAFF_INVITER',
    ]);

    $response->assertStatus(403);
    expect($targetUser->fresh()->hasRole('STAFF_INVITER'))->toBeFalse();
});

test('non-super-admin User::permissions drops super-admin-only permissions even if legacy DB row exists', function (): void {
    $grantPerm = Permission::where('name', 'users.grant_admin_access')->firstOrFail();

    $customRole = Role::create([
        'name' => 'LEGACY_ESCALATED',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);

    DB::table('role_permissions')->insert([
        'role_id' => $customRole->id,
        'permission_id' => $grantPerm->id,
        'created_at' => now(),
    ]);

    $user = User::factory()->create(['password' => Hash::make('password')]);
    $user->roles()->attach($customRole->id, ['assigned_at' => now()]);

    // Test with lazy-loading query
    expect($user->permissions())->not->toContain('users.grant_admin_access');

    // Test with eager-loaded relations
    $eagerUser = User::with('roles.permissions')->find($user->id);
    expect($eagerUser->permissions())->not->toContain('users.grant_admin_access');
});

test('non-super-admin does not receive super-admin-only permissions on /me even if legacy DB row exists', function (): void {
    $grantPerm = Permission::where('name', 'users.grant_admin_access')->firstOrFail();

    $customRole = Role::create([
        'name' => 'LEGACY_ADMIN_STAFF',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);

    DB::table('role_permissions')->insert([
        'role_id' => $customRole->id,
        'permission_id' => $grantPerm->id,
        'created_at' => now(),
    ]);

    $user = User::factory()->create(['password' => Hash::make('password')]);
    $user->roles()->attach($customRole->id, ['assigned_at' => now()]);

    Sanctum::actingAs($user, ['admin']);

    $response = $this->getJson('/api/v1/auth/me');

    $response->assertStatus(200);
    $perms = $response->json('data.user.permissions');
    expect($perms)->not->toContain('users.grant_admin_access');
});

test('permissions catalog returns is_super_admin_only: true for users.grant_admin_access and false for all other permissions', function (): void {
    $admin = createAdminActor();
    Sanctum::actingAs($admin, ['admin']);

    $response = $this->getJson('/api/v1/permissions');

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    // Verify response structure contains permissions and grouped, and does NOT leak unused catalog key
    $data = $response->json('data');
    expect($data)->toHaveKeys(['permissions', 'grouped'])
        ->not->toHaveKey('catalog');

    $allPerms = collect($data['permissions']);
    expect($allPerms)->toHaveCount(23);

    foreach ($allPerms as $perm) {
        if ($perm['name'] === 'users.grant_admin_access') {
            expect($perm['is_super_admin_only'])->toBeTrue();
        } else {
            expect($perm['is_super_admin_only'])->toBeFalse();
        }
    }
});
