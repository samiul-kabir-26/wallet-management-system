<?php

namespace Modules\Transactions\Resources;

use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PaymentRequest $resource
 */
class PaymentRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Each side sees the other's name masked and phone number in full (they already know it).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewerId = (int) $request->user()?->id;
        $status = $this->resource->status === PaymentRequest::PENDING && ! $this->resource->expires_at->isFuture()
            ? PaymentRequest::EXPIRED
            : $this->resource->status;

        return [
            'id' => $this->resource->id,
            'direction' => (int) $this->resource->payer_id === $viewerId ? 'incoming' : 'outgoing',
            'requester' => $this->party($this->resource->requester),
            'payer' => $this->party($this->resource->payer),
            'amount' => (float) $this->resource->amount,
            'note' => $this->resource->note,
            'status' => $status,
            'transaction_id' => $this->resource->transaction_id,
            'expires_at' => $this->resource->expires_at,
            'responded_at' => $this->resource->responded_at,
            'created_at' => $this->resource->created_at,
        ];
    }

    /**
     * @return array{id: int, name: string, phone_number: string|null}|null
     */
    private function party(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->maskedName(), 'phone_number' => $user->phone_number] : null;
    }
}
