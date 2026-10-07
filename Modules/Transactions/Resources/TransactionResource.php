<?php

namespace Modules\Transactions\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Transaction $resource
 */
class TransactionResource extends JsonResource
{
    /**
     * Disable default top-level data wrapping.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'user_id' => $this->resource->user_id,
            'agent_id' => $this->resource->agent_id,
            'sender_id' => $this->resource->sender_id,
            'recipient_id' => $this->resource->recipient_id,
            'initiated_by' => $this->resource->initiated_by,
            'type' => $this->resource->type,
            'amount' => (float) $this->resource->amount,
            'system_fee_amount' => (float) $this->resource->system_fee_amount,
            'system_fee_rate' => (float) $this->resource->system_fee_rate,
            'agent_commission_amount' => (float) $this->resource->agent_commission_amount,
            'agent_commission_rate' => (float) $this->resource->agent_commission_rate,
            'currency' => $this->resource->currency,
            'description' => $this->resource->description,
            'status' => $this->resource->status,
            'idempotency_key' => $this->resource->idempotency_key,
            'sender_wallet_balance_after' => $this->resource->sender_wallet_balance_after !== null
                ? (float) $this->resource->sender_wallet_balance_after
                : null,
            'recipient_wallet_balance_after' => $this->resource->recipient_wallet_balance_after !== null
                ? (float) $this->resource->recipient_wallet_balance_after
                : null,
            'agent_wallet_balance_after' => $this->resource->agent_wallet_balance_after !== null
                ? (float) $this->resource->agent_wallet_balance_after
                : null,
            'meta' => $this->resource->meta,
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
        ];
    }
}
