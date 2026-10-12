<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration handles data migration for the legacy MODERATOR role.
 *
 * NOTE on MODERATOR transition:
 * This is a one-way data migration. If the legacy MODERATOR role has active user assignments,
 * it is preserved as a custom admin role with baseline customer-care permissions
 * ('transactions.view_any', 'pin_reset.initiate') and recorded in audit_logs.
 * If unassigned, it is deleted.
 * The down() method does not restore deleted MODERATOR records.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $modRole = DB::table('roles')->where('name', 'MODERATOR')->first();
        if (! $modRole) {
            return;
        }

        $hasAssignments = DB::table('user_roles')->where('role_id', $modRole->id)->exists();
        if (! $hasAssignments) {
            DB::table('roles')->where('id', $modRole->id)->delete();

            return;
        }

        DB::table('roles')->where('id', $modRole->id)->update([
            'panel' => 'admin',
            'is_system' => false,
        ]);

        $now = now();
        $requiredPerms = [
            'transactions.view_any' => [
                'group' => 'Transactions',
                'description' => 'View all system transactions and sensitive fields',
            ],
            'pin_reset.initiate' => [
                'group' => 'Auth',
                'description' => 'Initiate staff-assisted customer PIN reset',
            ],
        ];

        foreach ($requiredPerms as $permName => $meta) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permName,
                'group' => $meta['group'],
                'description' => $meta['description'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permIds = DB::table('permissions')->whereIn('name', array_keys($requiredPerms))->pluck('id');
        foreach ($permIds as $permId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $modRole->id,
                'permission_id' => $permId,
                'created_at' => $now,
            ]);
        }

        DB::table('audit_logs')->insert([
            'actor_id' => null,
            'action' => 'migration.moderator_role_migrated',
            'auditable_type' => 'App\\Models\\Role',
            'auditable_id' => $modRole->id,
            'metadata' => json_encode([
                'message' => 'Preserved legacy MODERATOR as custom admin role with baseline customer-care permissions',
                'permissions' => array_keys($requiredPerms),
            ]),
            'created_at' => $now,
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * One-way data migration: intentionally does not restore deleted roles or revoke assigned permissions.
     */
    public function down(): void
    {
        // Intentionally empty: one-way data transition
    }
};
