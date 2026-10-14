<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('moderator data migration deletes unassigned MODERATOR role when migrating from pre-100002 schema', function () {
    $migration100002 = require database_path('migrations/2026_10_12_100002_add_panel_and_system_to_roles_table.php');
    $migration100003 = require database_path('migrations/2026_10_12_100003_migrate_legacy_moderator_role.php');

    // 1. Roll back 100002 to reproduce legacy schema state prior to panel column introduction
    $migration100002->down();

    // 2. Insert legacy unassigned MODERATOR role without panel column
    $modRoleId = DB::table('roles')->insertGetId([
        'name' => 'MODERATOR',
        'description' => 'Legacy moderator',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 3. Migrate forward through 100002 (adds panel, defaults legacy roles) and 100003 (data migration)
    try {
        $migration100002->up();
        $migration100003->up();

        expect(DB::table('roles')->where('id', $modRoleId)->exists())->toBeFalse();
    } finally {
        // Ensure schema is fully up if any intermediate assertion fails
        if (! Schema::hasColumn('roles', 'panel')) {
            $migration100002->up();
        }
    }
});

test('moderator data migration converts assigned MODERATOR into custom admin role with customer-care permissions when migrating from pre-100002 schema', function () {
    $moderatorUser = User::factory()->create();

    $migration100002 = require database_path('migrations/2026_10_12_100002_add_panel_and_system_to_roles_table.php');
    $migration100003 = require database_path('migrations/2026_10_12_100003_migrate_legacy_moderator_role.php');

    // 1. Roll back 100002 to reproduce legacy schema state prior to panel column introduction
    $migration100002->down();

    // 2. Insert legacy MODERATOR role without panel column
    $modRoleId = DB::table('roles')->insertGetId([
        'name' => 'MODERATOR',
        'description' => 'Legacy moderator',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 3. Assign legacy MODERATOR to a user
    DB::table('user_roles')->insert([
        'user_id' => $moderatorUser->id,
        'role_id' => $modRoleId,
        'assigned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        // 4. Migrate forward through 100002 and 100003
        $migration100002->up();
        $migration100003->up();

        // 5. Assert role has panel = admin and is_system = false
        $role = Role::find($modRoleId);
        expect($role)->not->toBeNull()
            ->and($role->panel->value)->toBe('admin')
            ->and($role->is_system)->toBeFalse();

        // 6. Assert role has pin_reset.initiate and transactions.view_any permissions
        $assignedPermissions = $role->permissions()->pluck('name')->all();
        expect($assignedPermissions)->toContain('transactions.view_any')
            ->and($assignedPermissions)->toContain('pin_reset.initiate');

        // 7. Assert audit log row was recorded
        $auditLog = AuditLog::where('action', 'migration.moderator_role_migrated')
            ->where('auditable_id', $modRoleId)
            ->first();

        expect($auditLog)->not->toBeNull()
            ->and($auditLog->actor_id)->toBeNull();

        // 8. Assert the user can call POST /api/v1/auth/pin-reset/initiate
        $customer = User::factory()->create();

        Sanctum::actingAs($moderatorUser, ['admin']);

        $response = $this->postJson('/api/v1/auth/pin-reset/initiate', [
            'phone_number' => $customer->phone_number,
            'email' => 'care-verified@example.com',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'PIN reset link generated and sent.',
            ]);
    } finally {
        if (! Schema::hasColumn('roles', 'panel')) {
            $migration100002->up();
        }
    }
});
