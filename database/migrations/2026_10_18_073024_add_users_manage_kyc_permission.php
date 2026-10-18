<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $permission = 'users.manage_kyc';

    /**
     * Run the migrations.
     *
     * Adds the catalog entry and grants it to an already-seeded ADMIN role, because RolePermissionSeeder
     * only seeds ADMIN defaults when the role has no permissions yet.
     */
    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => $this->permission],
            ['group' => 'Users', 'description' => 'Review identity documents and change KYC tiers and limits', 'created_at' => $now, 'updated_at' => $now],
        );

        $adminRoleId = DB::table('roles')->where('name', 'ADMIN')->value('id');
        if ($adminRoleId === null || ! DB::table('role_permissions')->where('role_id', $adminRoleId)->exists()) {
            return;
        }

        DB::table('role_permissions')->insertOrIgnore([
            'role_id' => $adminRoleId,
            'permission_id' => DB::table('permissions')->where('name', $this->permission)->value('id'),
            'granted_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', $this->permission)->value('id');

        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
