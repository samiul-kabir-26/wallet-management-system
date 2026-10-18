<?php

namespace Modules\Users\Resources;

use App\Models\KycSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Roles\Enums\Permission;

/**
 * @property-read KycSubmission $resource
 */
class KycSubmissionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Staff who review documents see the full document number; the customer sees it masked.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isReviewer = (bool) $request->user()?->can(Permission::UsersManageKyc->value);
        $user = $this->resource->relationLoaded('user') ? $this->resource->user : null;
        $reviewer = $this->resource->relationLoaded('reviewer') ? $this->resource->reviewer : null;

        return [
            'id' => $this->resource->id,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'phone_number' => $user->phone_number,
                'kyc_tier' => $user->kyc_tier,
            ] : null,
            'document_type' => $this->resource->document_type,
            'document_number' => $isReviewer ? $this->resource->document_number : $this->resource->maskedDocumentNumber(),
            'full_name' => $this->resource->full_name,
            'date_of_birth' => $this->resource->date_of_birth?->toDateString(),
            'status' => $this->resource->status,
            'reviewed_by' => $reviewer ? ['id' => $reviewer->id, 'name' => $reviewer->name] : null,
            'reviewed_at' => $this->resource->reviewed_at,
            'review_note' => $this->resource->review_note,
            'created_at' => $this->resource->created_at,
        ];
    }
}
