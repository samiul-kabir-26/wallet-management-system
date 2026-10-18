<?php

namespace Modules\Transactions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FloatRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Approvals\Resources\ApprovalRequestResource;
use Modules\Roles\Enums\Permission;
use Modules\Transactions\Http\Requests\SubmitFloatRequestRequest;
use Modules\Transactions\Resources\FloatRequestResource;
use Modules\Transactions\Services\FloatRequestService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FloatRequestController extends Controller
{
    public function __construct(
        protected FloatRequestService $floatRequests,
    ) {}

    /**
     * The signed-in agent's own float requests.
     */
    public function agentIndex(Request $request): JsonResponse
    {
        $paginator = FloatRequest::where('agent_id', $request->user()->id)
            ->with('reviewer')
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->paginatedResponse($paginator, FloatRequestResource::collection($paginator));
    }

    /**
     * Submit a float request with the deposit slip.
     */
    public function store(SubmitFloatRequestRequest $request): JsonResponse
    {
        $floatRequest = $this->floatRequests->submit(
            agent: $request->user(),
            data: [
                'amount' => (string) $request->validated('amount'),
                'bank_reference' => (string) $request->validated('bank_reference'),
                'deposited_at' => (string) $request->validated('deposited_at'),
                'note' => $request->validated('note'),
            ],
            slip: $request->file('slip'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Float request submitted. Staff will check it against the bank statement.',
            'data' => ['float_request' => new FloatRequestResource($floatRequest)],
        ], 201);
    }

    /**
     * Withdraw one of the agent's own pending requests.
     */
    public function cancel(Request $request, FloatRequest $floatRequest): JsonResponse
    {
        $cancelled = $this->floatRequests->cancel($floatRequest, $request->user());

        return $this->respond($cancelled, 'Float request cancelled.');
    }

    /**
     * The agent's own deposit slip.
     */
    public function agentSlip(Request $request, FloatRequest $floatRequest): StreamedResponse
    {
        if ((int) $floatRequest->agent_id !== (int) $request->user()->id) {
            throw new NotFoundHttpException;
        }

        return $this->slipResponse($floatRequest);
    }

    /**
     * Float requests for staff review.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:PENDING,APPROVED,REJECTED,CANCELLED'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = FloatRequest::with(['agent', 'reviewer']);
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        // Oldest first while waiting, newest first in the history lists
        ($validated['status'] ?? null) === FloatRequest::PENDING ? $query->oldest('id') : $query->latest('id');

        $paginator = $query->paginate($this->perPage($request));

        return $this->paginatedResponse($paginator, FloatRequestResource::collection($paginator));
    }

    /**
     * Any agent's deposit slip, for staff checking it against the bank statement.
     */
    public function adminSlip(Request $request, FloatRequest $floatRequest): StreamedResponse
    {
        $this->authorizeStaff($request);

        return $this->slipResponse($floatRequest);
    }

    /**
     * Approve: issue the float, or forward it for a second approval under maker-checker.
     */
    public function approve(Request $request, FloatRequest $floatRequest): JsonResponse
    {
        $this->authorizeStaff($request);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        $result = $this->floatRequests->approve($floatRequest, $request->user(), $note);

        if ($result['approval_request'] !== null) {
            return ApprovalRequestResource::pendingResponse($result['approval_request']);
        }

        return $this->respond($result['float_request'], 'Float issued to the agent.');
    }

    /**
     * Reject with a reason the agent will see.
     */
    public function reject(Request $request, FloatRequest $floatRequest): JsonResponse
    {
        $this->authorizeStaff($request);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        return $this->respond($this->floatRequests->reject($floatRequest, $request->user(), $reason), 'Float request rejected.');
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeStaff(Request $request): void
    {
        if (! $request->user()->can(Permission::AgentsIssueFloat->value)) {
            throw new AuthorizationException('You do not have permission to review float requests.');
        }
    }

    private function slipResponse(FloatRequest $floatRequest): StreamedResponse
    {
        if (! Storage::disk('local')->exists($floatRequest->slip_path)) {
            throw new NotFoundHttpException;
        }

        return Storage::disk('local')->response($floatRequest->slip_path, $floatRequest->slip_original_name, [
            'Content-Type' => $floatRequest->slip_mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function respond(FloatRequest $floatRequest, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => ['float_request' => new FloatRequestResource($floatRequest->load(['agent', 'reviewer']))],
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->query('per_page', 15)));
    }
}
