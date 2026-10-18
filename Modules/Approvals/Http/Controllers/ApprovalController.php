<?php

namespace Modules\Approvals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Approvals\Resources\ApprovalRequestResource;
use Modules\Approvals\Services\ApprovalService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ApprovalController extends Controller
{
    public function __construct(
        protected ApprovalService $approvals,
    ) {}

    /**
     * Requests the caller may review, plus the caller's own requests.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:PENDING,APPROVED,REJECTED,CANCELLED'],
            'action' => ['sometimes', 'string', 'in:FLOAT_ISSUE,SETTINGS_UPDATE,TRANSACTION_REVERSAL,COMMISSION_RATE_UPDATE'],
            'mine' => ['sometimes', 'boolean'],
            'reviewable' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = $this->approvals->visibleTo($request->user())
            ->with(['requester', 'reviewer'])
            ->latest('id');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }
        if ($request->boolean('mine')) {
            $query->where('requested_by', $request->user()->id);
        }
        if ($request->boolean('reviewable')) {
            $query->where('status', ApprovalRequest::PENDING)
                ->where('requested_by', '!=', $request->user()->id)
                ->whereIn('action', $this->approvals->reviewableActions($request->user()) ?: ['__none__']);
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 15));

        return $this->paginatedResponse($paginator, ApprovalRequestResource::collection($paginator), [
            'meta' => ['required' => $this->approvals->isRequired()],
        ]);
    }

    /**
     * One request the caller can see.
     */
    public function show(Request $request, int $approval): JsonResponse
    {
        return $this->respond($request, $this->find($request, $approval));
    }

    /**
     * Approve and apply a request.
     */
    public function approve(Request $request, int $approval): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        $approved = $this->approvals->approve($this->find($request, $approval), $request->user(), $note);

        return $this->respond($request, $approved, 'Request approved and applied.');
    }

    /**
     * Reject a request.
     */
    public function reject(Request $request, int $approval): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']])['note'];

        $rejected = $this->approvals->reject($this->find($request, $approval), $request->user(), $note);

        return $this->respond($request, $rejected, 'Request rejected.');
    }

    /**
     * Cancel one's own pending request.
     */
    public function cancel(Request $request, int $approval): JsonResponse
    {
        $cancelled = $this->approvals->cancel($this->find($request, $approval), $request->user());

        return $this->respond($request, $cancelled, 'Request cancelled.');
    }

    private function find(Request $request, int $id): ApprovalRequest
    {
        return $this->approvals->visibleTo($request->user())->whereKey($id)->first()
            ?? throw new NotFoundHttpException;
    }

    private function respond(Request $request, ApprovalRequest $approval, ?string $message = null): JsonResponse
    {
        return response()->json(array_filter([
            'success' => true,
            'message' => $message,
            'data' => [
                'approval_request' => new ApprovalRequestResource($approval->load(['requester', 'reviewer'])),
            ],
        ], fn ($value): bool => $value !== null));
    }
}
