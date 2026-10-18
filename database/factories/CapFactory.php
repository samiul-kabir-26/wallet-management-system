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
            // Anchor counters to the current business day so usage() states are not treated as stale
            'last_reset_date' => now(config('transactions.business_timezone'))->toDateString(),
        ];
    }

    /**
     * Set the usage directly via property assignment, preserving non-fillable protection.
     */
    public function usage(float|string $dailyUsed, float|string $monthlyUsed): static
    {
        return $this->afterCreating(function (Cap $cap) use ($dailyUsed, $monthlyUsed) {
            // Pick up the column defaults the insert left to the database
            $cap->refresh();

            $cap->daily_used = number_format((float) $dailyUsed, 2, '.', '');
            $cap->monthly_used = number_format((float) $monthlyUsed, 2, '.', '');
            $cap->save();
        });
    }

    /**
     * Set custom cap limits directly via property assignment.
     */
    public function limits(float|string $dailyCap, float|string $monthlyCap): static
    {
        return $this->afterCreating(function (Cap $cap) use ($dailyCap, $monthlyCap) {
            // Pick up the column defaults the insert left to the database
            $cap->refresh();

            $cap->daily_cap = number_format((float) $dailyCap, 2, '.', '');
            $cap->monthly_cap = number_format((float) $monthlyCap, 2, '.', '');
            $cap->save();
        });
    }

    /**
     * Intercept non-fillable attributes passed directly into create(),
     * assigning them via direct property assignment rather than mass assignment.
     *
     * @param  (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        $intercepted = [];
        $passThrough = $attributes;
        // A callable state is passed through untouched; only literal attribute arrays are intercepted
        if (is_array($attributes) && ! is_callable($attributes)) {
            $passThrough = [];
            foreach ($attributes as $key => $value) {
                if (in_array($key, ['daily_cap', 'monthly_cap', 'daily_used', 'monthly_used'], true)) {
                    $intercepted[$key] = $value;
                } else {
                    $passThrough[(string) $key] = $value;
                }
            }
        }

        $result = parent::create($passThrough, $parent);

        $apply = function (Cap $cap) use ($intercepted) {
            // Pick up the column defaults the insert left to the database
            $cap->refresh();

            if (! empty($intercepted)) {
                foreach ($intercepted as $field => $val) {
                    $cap->{$field} = number_format((float) $val, 2, '.', '');
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
