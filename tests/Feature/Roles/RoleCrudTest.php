<?php

use App\Models\AuditLog;
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

function createSuperAdminUser(): User
{
    $user = User::factory()->create();
    $role = Role::where('name', 'SUPER_ADMIN')->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

function createStaffUserWithPermissions(array $permissions): User
{
    $role = Role::create([
        'name' => 'CUSTOM_STAFF_'.uniqid(),
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);

    $permissionIds = Permission::whereIn('name', $permissions)->pluck('id')->all();
    $role->permissions()->attach($permissionIds);

    $user = User::factory()->create();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

test('super admin can create a custom role with valid permissions', function (): void {
    $superAdmin = createSuperAdminUser();
    Sanctum::actingAs($superAdmin, ['admin']);

    $payload = [
        'name' => 'AUDITOR',
        'description' => 'Financial Auditor Role',
        'permissions' => ['transactions.view_any', 'wallets.view_any'],
    ];

    $response = $this->postJson('/api/v1/roles', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.role.name', 'AUDITOR')
        ->assertJsonPath('data.role.panel', 'admin')
        ->assertJsonPath('data.role.is_system', false);

    $createdRole = Role::where('name', 'AUDITOR')->firstOrFail();
    expect($createdRole->permissions()->pluck('name')->all())->toEqualCanonicalizing([
        'transactions.view_any',
        'wallets.view_any',
    ]);

    $audit = AuditLog::where('action', 'role.created')
        ->where('auditable_id', $createdRole->id)
        ->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($superAdmin->id);
});

test('creating role fails with 422 when permissions contain unknown permission', function (): void {
    $superAdmin = createSuperAdminUser();
    Sanctum::actingAs($superAdmin, ['admin']);

    $response = $this->postJson('/api/v1/roles', [
        'name' => 'INVALID_PERM_ROLE',
        'permissions' => ['invalid.permission_name'],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['permissions.0']);
});

test('creating role fails with 422 on duplicate role name', function (): void {
    $superAdmin = createSuperAdminUser();
    Sanctum::actingAs($superAdmin, ['admin']);

    $this->postJson('/api/v1/roles', [
        'name' => 'DUPLICATE_ROLE',
    ])->assertStatus(201);

    $secondResponse = $this->postJson('/api/v1/roles', [
        'name' => 'DUPLICATE_ROLE',
    ]);

    $secondResponse->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

test('created role is always forced to panel=admin even if request supplies panel=user', function (): void {
    $superAdmin = createSuperAdminUser();
    Sanctum::actingAs($superAdmin, ['admin']);

    $response = $this->postJson('/api/v1/roles', [
        'name' => 'PANEL_TEST_ROLE',
        'panel' => 'user',
    ]);

    $response->assertStatus(201);
    $role = Role::where('name', 'PANEL_TEST_ROLE')->firstOrFail();
    $panelVal = $role->panel instanceof RolePanel ? $role->panel->value : $role->panel;
    expect($panelVal)->toBe('admin');
});

test('renaming or deleting a system role is rejected with 403', function (): void {
    $superAdmin = createSuperAdminUser();
    Sanctum::actingAs($superAdmin, ['admin']);

    $adminRole = Role::where('name', 'ADMIN')->firstOrFail();

    $this->patchJson("/api/v1/roles/{$adminRole->id}", [
        'name' => 'RENAMED_ADMIN',
    ])->assertStatus(403);

    $this->deleteJson("/api/v1/roles/{$adminRole->id}")
        ->assertStatus(403);
});

test('deleting a role in use returns 409, and unused custom role can be deleted', function (): void {
    $superAdmin = createSuperAdminUser();
    Sanctum::actingAs($superAdmin, ['admin']);

    $role = Role::create([
        'name' => 'TEMP_ROLE',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);

    $assignedUser = User::factory()->create();
    $assignedUser->roles()->attach($role->id, ['assigned_at' => now()]);

    // Role has user assignments -> 409 Conflict
    $this->deleteJson("/api/v1/roles/{$role->id}")
        ->assertStatus(409);

    // Unassign the user
    $assignedUser->roles()->detach($role->id);

    // Now deletion succeeds
    $this->deleteJson("/api/v1/roles/{$role->id}")
        ->assertStatus(200);

    expect(Role::where('name', 'TEMP_ROLE')->exists())->toBeFalse();
});

test('endpoints require roles.view and roles.manage permissions', function (): void {
    $plainUser = User::factory()->create();
    Sanctum::actingAs($plainUser, ['admin']);

    // Without roles.view -> 403 on GET
    $this->getJson('/api/v1/roles')->assertStatus(403);
    $this->getJson('/api/v1/permissions')->assertStatus(403);

    // Without roles.manage -> 403 on POST
    $this->postJson('/api/v1/roles', ['name' => 'UNAUTH_ROLE'])->assertStatus(403);

    // Staff with only roles.view
    $viewer = createStaffUserWithPermissions(['roles.view']);
    Sanctum::actingAs($viewer, ['admin']);

    $this->getJson('/api/v1/roles')->assertStatus(200);
    $this->getJson('/api/v1/permissions')->assertStatus(200);
    $this->postJson('/api/v1/roles', ['name' => 'UNAUTH_ROLE'])->assertStatus(403);
});

test('syncing permissions persists exact set and records detailed audit diff', function (): void {
    $superAdmin = createSuperAdminUser();
    Sanctum::actingAs($superAdmin, ['admin']);

    $role = Role::create([
        'name' => 'SYNCABLE_ROLE',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);
    $initialPerm = Permission::where('name', 'wallets.view_any')->firstOrFail();
    $role->permissions()->attach($initialPerm->id);

    $response = $this->putJson("/api/v1/roles/{$role->id}/permissions", [
        'permissions' => ['wallets.block', 'wallets.unblock'],
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    expect($role->refresh()->permissions()->pluck('name')->all())->toEqualCanonicalizing([
        'wallets.block',
        'wallets.unblock',
    ]);

    $audit = AuditLog::where('action', 'role.permissions_synced')
        ->where('auditable_id', $role->id)
        ->latest('id')
        ->firstOrFail();

    expect($audit->metadata['added'])->toEqualCanonicalizing(['wallets.block', 'wallets.unblock'])
        ->and($audit->metadata['removed'])->toEqualCanonicalizing(['wallets.view_any'])
        ->and($audit->metadata['before'])->toEqualCanonicalizing(['wallets.view_any'])
        ->and($audit->metadata['after'])->toEqualCanonicalizing(['wallets.block', 'wallets.unblock']);
});
