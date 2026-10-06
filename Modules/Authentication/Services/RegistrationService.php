<?php

namespace Modules\Authentication\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegistrationService
{
    /**
     * Register a new user or agent with associated wallet, cap, and role.
     *
     * @param  array{name: string, phone_number: string, pin: string, role: string}  $data
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            // 1. Create the user
            $user = User::create([
                'name' => $data['name'],
                'phone_number' => $data['phone_number'],
                'pin' => Hash::make($data['pin']),
            ]);

            // 2. Create the initial wallet (schema defaults: balance 50.00, currency BDT)
            $user->wallet()->create();

            // 3. Create transaction cap limits (schema defaults: daily 10,000.00, monthly 50,000.00)
            $user->cap()->create();

            // 4. If AGENT, create agent_info record (schema default: status PENDING)
            if ($data['role'] === 'AGENT') {
                $user->agentInfo()->create();
            }

            // 5. Assign the requested role
            $role = Role::where('name', $data['role'])->firstOrFail();
            $user->roles()->attach($role->id, [
                'assigned_at' => now(),
            ]);

            // 6. Eager load roles so the resulting model is fully hydrated for resources
            return $user->refresh()->load('roles');
        });
    }
}
