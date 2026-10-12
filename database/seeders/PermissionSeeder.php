<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Modules\Roles\Enums\Permission as PermissionEnum;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $records = array_map(
            fn (PermissionEnum $permission) => [
                'name' => $permission->value,
                'group' => $permission->group(),
                'description' => $permission->label(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            PermissionEnum::cases()
        );

        Permission::upsert(
            $records,
            ['name'],
            ['group', 'description', 'updated_at']
        );
    }
}
