<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Modules\Roles\Enums\RolePanel;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $roles = [
            [
                'name' => 'SUPER_ADMIN',
                'description' => 'Super Administrator',
                'panel' => RolePanel::Admin->value,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'ADMIN',
                'description' => 'Administrator',
                'panel' => RolePanel::Admin->value,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'AGENT',
                'description' => 'Agent',
                'panel' => RolePanel::Agent->value,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'USER',
                'description' => 'User',
                'panel' => RolePanel::User->value,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        Role::upsert(
            $roles,
            ['name'],
            ['description', 'panel', 'is_system', 'updated_at']
        );
    }
}
