<?php

namespace App\Models;

use Database\Factories\CapFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property numeric-string $daily_cap
 * @property numeric-string $monthly_cap
 * @property numeric-string $daily_used
 * @property numeric-string $monthly_used
 * @property bool $is_custom
 * @property Carbon|null $last_reset_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
])]
class Cap extends Model
{
    /** @use HasFactory<CapFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'daily_cap' => 'decimal:2',
            'monthly_cap' => 'decimal:2',
            'daily_used' => 'decimal:2',
            'monthly_used' => 'decimal:2',
            'last_reset_date' => 'date',
            'is_custom' => 'boolean',
        ];
    }

    /**
     * New cap rows take their limits from the owner's KYC tier unless limits were set explicitly.
     */
    protected static function booted(): void
    {
        static::creating(function (Cap $cap): void {
            // Limits left to the database are not set on the model yet
            if ($cap->getAttribute('daily_cap') !== null && $cap->getAttribute('monthly_cap') !== null) {
                return;
            }

            $tier = config('kyc.tiers.'.(User::whereKey($cap->user_id)->value('kyc_tier') ?? 'STANDARD'));
            if (! is_array($tier)) {
                return;
            }

            $cap->setAttribute('daily_cap', $cap->getAttribute('daily_cap') ?? number_format((float) $tier['daily_cap'], 2, '.', ''));
            $cap->setAttribute('monthly_cap', $cap->getAttribute('monthly_cap') ?? number_format((float) $tier['monthly_cap'], 2, '.', ''));
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
