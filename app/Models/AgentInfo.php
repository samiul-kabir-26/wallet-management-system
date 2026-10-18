<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $status
 * @property numeric-string $commission_rate
 * @property numeric-string $total_commission
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property Carbon|null $suspended_at
 * @property int|null $suspended_by
 */
#[Fillable([
    'user_id',
    'status',
    'commission_rate',
    'total_commission',
    'approved_at',
    'approved_by',
    'suspended_at',
    'suspended_by',
])]
class AgentInfo extends Model
{
    protected $table = 'agent_info';

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:4',
            'total_commission' => 'decimal:2',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }
}
