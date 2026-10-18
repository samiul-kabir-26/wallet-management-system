<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Modules\Roles\Enums\Permission as PermissionEnum;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminRole = Role::where('name', 'ADMIN')->first();
        if (! $adminRole) {
            return;
        }

        // Only seed default permissions if ADMIN currently has no permissions assigned.
        // Prevents overwriting manual customizations made by SUPER_ADMIN.
        if ($adminRole->permissions()->count() > 0) {
            return;
        }

        // All catalog permissions except users.grant_admin_access, roles.manage, and the two that
        // move money on staff say-so: agents.issue_float (creates e-money) and transactions.reverse.
        // SUPER_ADMIN grants those to a dedicated role.
        $adminPermissions = collect(PermissionEnum::cases())
            ->filter(fn (PermissionEnum $p): bool => ! in_array($p, [
                PermissionEnum::UsersGrantAdminAccess,
                PermissionEnum::RolesManage,
                PermissionEnum::AgentsIssueFloat,
                PermissionEnum::TransactionsReverse,
            ], true))
            ->map(fn (PermissionEnum $p): string => $p->value)
            ->all();

        $permissionIds = Permission::whereIn('name', $adminPermissions)->pluck('id')->all();

        $adminRole->permissions()->syncWithPivotValues($permissionIds, [
            'granted_by' => null,
        ]);
    }
}
