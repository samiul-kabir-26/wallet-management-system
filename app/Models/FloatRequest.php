<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An agent's request for e-money float against a bank deposit.
 *
 * @property int $id
 * @property int $agent_id
 * @property string $amount
 * @property string $bank_reference
 * @property Carbon $deposited_at
 * @property string $slip_path
 * @property string $slip_original_name
 * @property string $slip_mime_type
 * @property string|null $note
 * @property string $status
 * @property int|null $approval_request_id
 * @property int|null $transaction_id
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 */
#[Fillable([
    'agent_id',
    'amount',
    'bank_reference',
    'deposited_at',
    'slip_path',
    'slip_original_name',
    'slip_mime_type',
    'note',
    'status',
    'approval_request_id',
    'transaction_id',
    'reviewed_by',
    'reviewed_at',
    'review_note',
])]
#[Hidden(['slip_path'])]
class FloatRequest extends Model
{
    public const string PENDING = 'PENDING';

    public const string APPROVED = 'APPROVED';

    public const string REJECTED = 'REJECTED';

    public const string CANCELLED = 'CANCELLED';

    /**
     * Private-disk directory that holds deposit slips. Never served publicly.
     */
    public const string SLIP_DIRECTORY = 'float-slips';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'deposited_at' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
