<?php

namespace Database\Factories;

use App\Models\Cap;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Cap>
 */
class CapFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Cap>
     */
    protected $model = Cap::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
        ];
    }

    /**
     * Set the usage directly via property assignment, preserving non-fillable protection.
     */
    public function usage(float|string $dailyUsed, float|string $monthlyUsed): static
    {
        return $this->afterCreating(function (Cap $cap) use ($dailyUsed, $monthlyUsed) {
            if ($cap->daily_cap === null) {
                $cap->refresh();
            }

            $cap->daily_used = $dailyUsed;
            $cap->monthly_used = $monthlyUsed;
            $cap->save();
        });
    }

    /**
     * Set custom cap limits directly via property assignment.
     */
    public function limits(float|string $dailyCap, float|string $monthlyCap): static
    {
        return $this->afterCreating(function (Cap $cap) use ($dailyCap, $monthlyCap) {
            if ($cap->daily_cap === null) {
                $cap->refresh();
            }

            $cap->daily_cap = $dailyCap;
            $cap->monthly_cap = $monthlyCap;
            $cap->save();
        });
    }

    /**
     * Intercept non-fillable attributes passed directly into create(),
     * assigning them via direct property assignment rather than mass assignment.
     *
     * @param  array<string, mixed>|callable  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        $intercepted = [];
        if (is_array($attributes)) {
            foreach (['daily_cap', 'monthly_cap', 'daily_used', 'monthly_used'] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $intercepted[$field] = $attributes[$field];
                    unset($attributes[$field]);
                }
            }
        }

        $result = parent::create($attributes, $parent);

        $apply = function (Cap $cap) use ($intercepted) {
            if ($cap->daily_cap === null) {
                $cap->refresh();
            }

            if (! empty($intercepted)) {
                foreach ($intercepted as $field => $val) {
                    $cap->{$field} = $val;
                }
                $cap->save();
            }
        };

        if ($result instanceof Collection) {
            $result->each($apply);
        } elseif ($result instanceof Cap) {
            $apply($result);
        }

        return $result;
    }
}
