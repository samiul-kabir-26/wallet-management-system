<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A sensitive staff action waiting for a second staff member's approval (maker-checker).
 *
 * @property int $id
 * @property string $action
 * @property string $status
 * @property array<string, mixed> $payload
 * @property string $summary
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int $requested_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property array<string, mixed>|null $result
 */
#[Fillable([
    'action',
    'status',
    'payload',
    'summary',
    'subject_type',
    'subject_id',
    'requested_by',
    'reviewed_by',
    'reviewed_at',
    'review_note',
    'result',
])]
class ApprovalRequest extends Model
{
    public const string FLOAT_ISSUE = 'FLOAT_ISSUE';

    public const string SETTINGS_UPDATE = 'SETTINGS_UPDATE';

    public const string TRANSACTION_REVERSAL = 'TRANSACTION_REVERSAL';

    public const string COMMISSION_RATE_UPDATE = 'COMMISSION_RATE_UPDATE';

    public const string PENDING = 'PENDING';

    public const string APPROVED = 'APPROVED';

    public const string REJECTED = 'REJECTED';

    public const string CANCELLED = 'CANCELLED';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
