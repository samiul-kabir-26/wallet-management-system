<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Roles\Enums\RolePanel;

beforeEach(function (): void {
    $this->seed([
        PermissionSeeder::class,
        RoleSeeder::class,
    ]);
});

test('migration 100004 deletes legacy role_permissions rows for super-admin-only permissions', function (): void {
    $grantPerm = Permission::where('name', 'users.grant_admin_access')->firstOrFail();

    $customRole = Role::create([
        'name' => 'LEGACY_CUSTOM_ROLE',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);

    // Force an existing legacy row into role_permissions
    DB::table('role_permissions')->insert([
        'role_id' => $customRole->id,
        'permission_id' => $grantPerm->id,
        'created_at' => now(),
    ]);

    expect(DB::table('role_permissions')->where('permission_id', $grantPerm->id)->exists())->toBeTrue();

    // Execute migration up()
    $migration = require database_path('migrations/2026_10_12_100004_cleanup_super_admin_only_role_permissions.php');
    $migration->up();

    expect(DB::table('role_permissions')->where('permission_id', $grantPerm->id)->exists())->toBeFalse();
});
