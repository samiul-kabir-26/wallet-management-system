<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $role = Role::where('name', 'SUPER_ADMIN')->firstOrFail();

        $user = User::create([
            'name'=>'SUPER ADMIN',
            'email'=> config('admin.email'),
            'password'=>Hash::make(config('admin.password')),
            'user_type' => 'ADMIN_TRACK',
            'is_verified' => true,
            'is_active' => 'ACTIVE',
            
        ]);

        UserRole::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'assigned_at' => now(),
        ]);
    }
}
