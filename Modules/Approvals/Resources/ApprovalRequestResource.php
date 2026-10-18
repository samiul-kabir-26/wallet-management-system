<?php

namespace Modules\Approvals\Resources;

use App\Models\ApprovalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Approvals\Services\ApprovalService;

/**
 * @property-read ApprovalRequest $resource
 */
class ApprovalRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isPending = $this->resource->status === ApprovalRequest::PENDING;

        return [
            'id' => $this->resource->id,
            'action' => $this->resource->action,
            'status' => $this->resource->status,
            'summary' => $this->resource->summary,
            'payload' => $this->resource->payload,
            'subject' => $this->resource->subject_type
                ? ['type' => $this->resource->subject_type, 'id' => $this->resource->subject_id]
                : null,
            'requested_by' => $this->person('requester'),
            'reviewed_by' => $this->person('reviewer'),
            'reviewed_at' => $this->resource->reviewed_at,
            'review_note' => $this->resource->review_note,
            'result' => $this->resource->result,
            'can_review' => $isPending && $viewer !== null && app(ApprovalService::class)->canReview($this->resource, $viewer),
            'can_cancel' => $isPending && $viewer !== null && (int) $this->resource->requested_by === (int) $viewer->id,
            'created_at' => $this->resource->created_at,
        ];
    }

    /**
     * The 202 response returned instead of applying an action that now waits for a second approver.
     */
    public static function pendingResponse(ApprovalRequest $approval): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Submitted for approval. A second staff member must approve it before it takes effect.',
            'data' => [
                'approval_request' => new self($approval->load(['requester', 'reviewer'])),
            ],
        ], 202);
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function person(string $relation): ?array
    {
        $user = $this->resource->relationLoaded($relation) ? $this->resource->{$relation} : null;

        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}
