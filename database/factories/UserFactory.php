<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone_number' => fake()->unique()->numerify('01#########'),
            'password' => static::$password ??= Hash::make('password'),
            'pin' => Hash::make('12345'),
            'is_active' => true,
            'is_verified' => true,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => false,
        ]);
    }
}