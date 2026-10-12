<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Roles\Enums\RolePanel;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        $name = strtoupper(fake()->unique()->lexify('ROLE_????'));

        return [
            'name' => $name,
            'description' => fake()->sentence(),
            'panel' => RolePanel::User,
            'is_system' => false,
            'created_by' => null,
        ];
    }

    public function admin(): static
    {
        return $this->adminPanel();
    }

    public function adminPanel(): static
    {
        return $this->state(fn () => [
            'panel' => RolePanel::Admin,
        ]);
    }

    public function agent(): static
    {
        return $this->agentPanel();
    }

    public function agentPanel(): static
    {
        return $this->state(fn () => [
            'panel' => RolePanel::Agent,
        ]);
    }

    public function user(): static
    {
        return $this->userPanel();
    }

    public function userPanel(): static
    {
        return $this->state(fn () => [
            'panel' => RolePanel::User,
        ]);
    }

    public function system(): static
    {
        return $this->state(fn () => [
            'is_system' => true,
        ]);
    }
}
