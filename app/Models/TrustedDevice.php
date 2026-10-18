<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A device a customer or agent proved with an SMS code. Only a hash of the client's device ID is kept.
 *
 * @property int $id
 * @property int $user_id
 * @property string $device_hash
 * @property string|null $label
 * @property string|null $ip_address
 * @property Carbon|null $last_used_at
 */
#[Fillable(['user_id', 'device_hash', 'label', 'ip_address', 'last_used_at'])]
class TrustedDevice extends Model
{
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Hash a client device ID so the raw value is never stored.
     */
    public static function hash(string $deviceId): string
    {
        return hash_hmac('sha256', $deviceId, (string) config('app.key'));
    }
}
