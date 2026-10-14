<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Modules\Roles\Enums\Permission as PermissionEnum;
use Modules\Roles\Enums\RolePanel;

beforeEach(function (): void {
    $this->seed([
        PermissionSeeder::class,
        RoleSeeder::class,
        RolePermissionSeeder::class,
    ]);
});

test('every permission enum case exists in the database', function (): void {
    $expectedNames = collect(PermissionEnum::cases())->map(fn (PermissionEnum $p): string => $p->value)->all();
    $actualNames = Permission::pluck('name')->all();

    expect($actualNames)->toHaveCount(count(PermissionEnum::cases()));
    foreach ($expectedNames as $name) {
        expect($actualNames)->toContain($name);
    }
});

test('permission seeder is idempotent and does not duplicate rows', function (): void {
    $countBefore = Permission::count();

    // Run seeder second time
    $this->seed(PermissionSeeder::class);

    expect(Permission::count())->toBe($countBefore);

    // Run seeder third time
    $this->seed(PermissionSeeder::class);

    expect(Permission::count())->toBe($countBefore);
});

test('role seeder backfills and sets panel and is_system correctly for system roles', function (): void {
    $superAdmin = Role::where('name', 'SUPER_ADMIN')->firstOrFail();
    expect($superAdmin->panel)->toBe(RolePanel::Admin)
        ->and($superAdmin->is_system)->toBeTrue();

    $admin = Role::where('name', 'ADMIN')->firstOrFail();
    expect($admin->panel)->toBe(RolePanel::Admin)
        ->and($admin->is_system)->toBeTrue();

    $agent = Role::where('name', 'AGENT')->firstOrFail();
    expect($agent->panel)->toBe(RolePanel::Agent)
        ->and($agent->is_system)->toBeTrue();

    $user = Role::where('name', 'USER')->firstOrFail();
    expect($user->panel)->toBe(RolePanel::User)
        ->and($user->is_system)->toBeTrue();

    // Running RoleSeeder again is idempotent
    $this->seed(RoleSeeder::class);
    expect(Role::whereIn('name', ['SUPER_ADMIN', 'ADMIN', 'AGENT', 'USER'])->count())->toBe(4);
});

test('super admin has zero rows in role_permissions table', function (): void {
    $superAdmin = Role::where('name', 'SUPER_ADMIN')->firstOrFail();

    expect($superAdmin->permissions()->count())->toBe(0);
});

test('admin role has default permission matrix excluding grant_admin_access and roles_manage', function (): void {
    $admin = Role::where('name', 'ADMIN')->firstOrFail();
    $adminPermissions = $admin->permissions()->pluck('name')->all();

    expect($adminPermissions)->not->toContain(PermissionEnum::UsersGrantAdminAccess->value)
        ->and($adminPermissions)->not->toContain(PermissionEnum::RolesManage->value)
        ->and($adminPermissions)->toContain(PermissionEnum::UsersViewAny->value)
        ->and($adminPermissions)->toContain(PermissionEnum::WalletsViewAny->value)
        ->and($adminPermissions)->toContain(PermissionEnum::TransactionsViewAny->value);
});

test('permissions sync artisan command completes successfully', function (): void {
    $this->artisan('permissions:sync')
        ->assertSuccessful();

    expect(Permission::count())->toBe(count(PermissionEnum::cases()));
});
