<?php

namespace Modules\Transactions\Resources;

use App\Models\FloatRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Transactions\Services\FloatRequestService;

/**
 * @property-read FloatRequest $resource
 */
class FloatRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $agent = $this->resource->relationLoaded('agent') ? $this->resource->agent : null;
        $reviewer = $this->resource->relationLoaded('reviewer') ? $this->resource->reviewer : null;

        return [
            'id' => $this->resource->id,
            'agent' => $agent ? ['id' => $agent->id, 'name' => $agent->name, 'phone_number' => $agent->phone_number] : null,
            'amount' => (float) $this->resource->amount,
            'bank_reference' => $this->resource->bank_reference,
            'deposited_at' => $this->resource->deposited_at->toDateString(),
            'note' => $this->resource->note,
            'slip' => [
                'name' => $this->resource->slip_original_name,
                'mime_type' => $this->resource->slip_mime_type,
            ],
            'status' => $this->resource->status,
            'awaiting_second_approval' => $this->resource->status === FloatRequest::PENDING
                && app(FloatRequestService::class)->isAwaitingSecondApproval($this->resource),
            'approval_request_id' => $this->resource->approval_request_id,
            'transaction_id' => $this->resource->transaction_id,
            'reviewed_by' => $reviewer ? ['id' => $reviewer->id, 'name' => $reviewer->name] : null,
            'reviewed_at' => $this->resource->reviewed_at,
            'review_note' => $this->resource->review_note,
            'created_at' => $this->resource->created_at,
        ];
    }
}
