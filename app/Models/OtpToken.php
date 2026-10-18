<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $otp_code
 * @property string $purpose
 * @property string|null $target
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property int $attempt_count
 * @property int $max_attempts
 */
#[Fillable([
    'user_id',
    'otp_code',
    'purpose',
    'target',
    'expires_at',
    'used_at',
    'attempt_count',
])]
#[Hidden(['otp_code'])]
class OtpToken extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
