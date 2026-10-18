<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer asking another customer for money.
 *
 * @property int $id
 * @property int $requester_id
 * @property int $payer_id
 * @property string $amount
 * @property string|null $note
 * @property string $status
 * @property int|null $transaction_id
 * @property Carbon $expires_at
 * @property Carbon|null $responded_at
 */
#[Fillable([
    'requester_id',
    'payer_id',
    'amount',
    'note',
    'status',
    'transaction_id',
    'expires_at',
    'responded_at',
])]
class PaymentRequest extends Model
{
    public const string PENDING = 'PENDING';

    public const string PAID = 'PAID';

    public const string DECLINED = 'DECLINED';

    public const string CANCELLED = 'CANCELLED';

    public const string EXPIRED = 'EXPIRED';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Pending and not yet past its expiry (the scheduled sweep may not have marked it yet).
     */
    public function isOpen(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isFuture();
    }
}
