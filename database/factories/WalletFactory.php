<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Wallet>
     */
    protected $model = Wallet::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'currency' => 'BDT',
            'is_blocked' => false,
            'blocked_by' => null,
            'blocked_at' => null,
        ];
    }

    /**
     * Set the balance via direct property assignment, preserving non-fillable balance protection.
     */
    public function balance(float|string $balance): static
    {
        return $this->afterCreating(function (Wallet $wallet) use ($balance) {
            $wallet->balance = $balance;
            $wallet->save();
        });
    }

    /**
     * Mark the wallet as blocked.
     */
    public function blocked(?int $blockedBy = null): static
    {
        return $this->state(fn () => [
            'is_blocked' => true,
            'blocked_by' => $blockedBy,
            'blocked_at' => now(),
        ]);
    }

    /**
     * Intercept balance passed directly into create() attributes,
     * assigning it via direct property assignment rather than mass assignment.
     *
     * @param  array<string, mixed>|callable  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        $balance = null;
        if (is_array($attributes) && array_key_exists('balance', $attributes)) {
            $balance = $attributes['balance'];
            unset($attributes['balance']);
        }

        $result = parent::create($attributes, $parent);

        if ($balance !== null) {
            if ($result instanceof Collection) {
                $result->each(function (Wallet $wallet) use ($balance) {
                    $wallet->balance = $balance;
                    $wallet->save();
                });
            } elseif ($result instanceof Wallet) {
                $result->balance = $balance;
                $result->save();
            }
        }

        return $result;
    }
}
