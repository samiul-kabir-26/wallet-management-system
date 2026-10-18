<?php

namespace App\Models;

use Database\Factories\CapFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            if ($cap->daily_cap !== null && $cap->monthly_cap !== null) {
                return;
            }

            $tier = config('kyc.tiers.'.(User::whereKey($cap->user_id)->value('kyc_tier') ?? 'STANDARD'));
            if (! is_array($tier)) {
                return;
            }

            $cap->daily_cap ??= number_format((float) $tier['daily_cap'], 2, '.', '');
            $cap->monthly_cap ??= number_format((float) $tier['monthly_cap'], 2, '.', '');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
