<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Roles\Enums\Permission as PermissionEnum;

/**
 * Migration removes any role_permissions attachments for super-admin-only permissions.
 *
 * Super-admin-only permissions (such as users.grant_admin_access) cannot be granted
 * to roles. Any existing attachments in role_permissions are cleaned up here.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $superAdminOnlyPermNames = collect(PermissionEnum::cases())
            ->filter(fn (PermissionEnum $p): bool => $p->isSuperAdminOnly())
            ->map(fn (PermissionEnum $p): string => $p->value)
            ->all();

        $permIds = DB::table('permissions')->whereIn('name', $superAdminOnlyPermNames)->pluck('id');
        if ($permIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permIds)->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // One-way data cleanup: super-admin-only permissions should never be attached to roles.
    }
};
