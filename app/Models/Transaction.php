<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

#[Fillable([
    'user_id',
    'sender_id',
    'recipient_id',
    'agent_id',
    'initiated_by',
    'type',
    'amount',
    'system_fee_amount',
    'system_fee_rate',
    'agent_commission_amount',
    'agent_commission_rate',
    'currency',
    'description',
    'status',
    'idempotency_key',
    'sender_wallet_balance_after',
    'recipient_wallet_balance_after',
    'agent_wallet_balance_after',
    'meta',
])]
class Transaction extends Model
{
    protected static function booted(): void
    {
        static::created(function (Transaction $transaction): void {
            Log::info('Transaction created', [
                'transaction_id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => $transaction->amount,
                'user_id' => $transaction->user_id,
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'system_fee_amount' => 'decimal:2',
            'system_fee_rate' => 'decimal:4',
            'agent_commission_amount' => 'decimal:2',
            'agent_commission_rate' => 'decimal:4',
            'sender_wallet_balance_after' => 'decimal:2',
            'recipient_wallet_balance_after' => 'decimal:2',
            'agent_wallet_balance_after' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
