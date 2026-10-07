<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }
}
