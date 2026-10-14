<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Catalog entries introduced after the initial permission seed, with their UI group and label.
     *
     * @var array<string, array{group: string, description: string}>
     */
    private array $permissions = [
        'users.verify_phone' => ['group' => 'Users', 'description' => 'Mark a user phone number as verified after an identity check'],
        'users.view_activity' => ['group' => 'Users', 'description' => 'View user activity: login records and audit trail'],
        'agents.approve_withdrawal' => ['group' => 'Agents', 'description' => 'Approve or reject pending agent bank withdrawals'],
        'settings.manage_session_timeouts' => ['group' => 'Settings', 'description' => 'Change the idle auto-logout timeout for each panel'],
    ];

    /**
     * Run the migrations.
     *
     * Adds the new catalog entries and grants them to an already-seeded ADMIN role, because
     * RolePermissionSeeder only seeds ADMIN defaults when the role has no permissions yet.
     */
    public function up(): void
    {
        $now = now();

        foreach ($this->permissions as $name => $attributes) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [...$attributes, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $adminRoleId = DB::table('roles')->where('name', 'ADMIN')->value('id');
        if ($adminRoleId === null || ! DB::table('role_permissions')->where('role_id', $adminRoleId)->exists()) {
            return;
        }

        $permissionIds = DB::table('permissions')->whereIn('name', array_keys($this->permissions))->pluck('id');
        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $adminRoleId,
                'permission_id' => $permissionId,
                'granted_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('name', array_keys($this->permissions))->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
