<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    public function definition(): array
    {
        $domain = fake()->randomElement(['users', 'wallets', 'agents', 'transactions', 'settings']);
        $action = fake()->unique()->word();

        return [
            'name' => "{$domain}.{$action}",
            'group' => ucfirst($domain),
            'description' => fake()->sentence(),
        ];
    }
}
