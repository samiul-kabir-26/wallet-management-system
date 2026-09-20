<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            'SUPER_ADMIN',
            'ADMIN',
            'MODERATOR',
            'AGENT',
            'USER',
        ];

        Role::insert(
            array_map(
                fn ($role) => [
                    'name' => $role,
                    'description' => str_replace('_', ' ', $role),
                ],
                $roles
            )
        );
    }
}
