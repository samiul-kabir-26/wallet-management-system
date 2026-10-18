<?php

namespace Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\KycSubmission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Roles\Enums\Permission;
use Modules\Transactions\Resources\CapResource;
use Modules\Transactions\Services\CapService;
use Modules\Users\Http\Requests\SubmitKycRequest;
use Modules\Users\Resources\KycSubmissionResource;
use Modules\Users\Resources\UserResource;
use Modules\Users\Services\KycService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class KycController extends Controller
{
    public function __construct(
        protected KycService $kyc,
        protected CapService $capService,
    ) {}

    /**
     * The signed-in customer's tier, limits, tier ladder and latest submission.
     */
    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $cap = $user->cap ?? $user->cap()->create()->refresh();
        $latest = KycSubmission::where('user_id', $user->id)->latest('id')->first();

        return response()->json([
            'success' => true,
            'data' => [
                'tier' => $user->kyc_tier,
                'label' => config("kyc.tiers.{$user->kyc_tier}.label", $user->kyc_tier),
                'verified_at' => $user->kyc_verified_at,
                'custom_limits' => (bool) $cap->is_custom,
                'caps' => new CapResource($this->capService->usageSnapshot($cap)),
                'tiers' => $this->tierLadder(),
                'latest_submission' => $latest ? new KycSubmissionResource($latest) : null,
            ],
        ]);
    }

    /**
     * Send identity documents for verification.
     */
    public function submit(SubmitKycRequest $request): JsonResponse
    {
        $submission = $this->kyc->submit(
            user: $request->user(),
            data: $request->safe()->except(['front', 'back']),
            front: $request->file('front'),
            back: $request->file('back'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Documents submitted. You will get an SMS once they are reviewed.',
            'data' => ['submission' => new KycSubmissionResource($submission)],
        ], 201);
    }

    /**
     * Submissions for staff review.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in([KycSubmission::PENDING, KycSubmission::APPROVED, KycSubmission::REJECTED])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = KycSubmission::with(['user', 'reviewer']);
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        ($validated['status'] ?? null) === KycSubmission::PENDING ? $query->oldest('id') : $query->latest('id');

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 15));

        return $this->paginatedResponse($paginator, KycSubmissionResource::collection($paginator));
    }

    /**
     * One side of a submitted document, for staff only.
     */
    public function document(Request $request, KycSubmission $submission, string $side): StreamedResponse
    {
        $this->authorizeStaff($request);

        $path = $side === 'front' ? $submission->front_path : $submission->back_path;
        if (! Storage::disk('local')->exists($path)) {
            throw new NotFoundHttpException;
        }

        return Storage::disk('local')->response($path, "kyc-{$submission->id}-{$side}", [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Approve a submission.
     */
    public function approve(Request $request, KycSubmission $submission): JsonResponse
    {
        $this->authorizeStaff($request);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        $approved = $this->kyc->approve($submission, $request->user(), $note);

        return $this->submissionResponse($approved, 'Identity verified.');
    }

    /**
     * Reject a submission.
     */
    public function reject(Request $request, KycSubmission $submission): JsonResponse
    {
        $this->authorizeStaff($request);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        return $this->submissionResponse($this->kyc->reject($submission, $request->user(), $reason), 'Documents rejected.');
    }

    /**
     * Move a customer to another tier.
     */
    public function changeTier(Request $request, User $user): JsonResponse
    {
        $this->authorizeStaff($request);
        $validated = $request->validate([
            'tier' => ['required', 'string', Rule::in(array_keys(config('kyc.tiers', [])))],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $updated = $this->kyc->changeTier($user, $validated['tier'], $request->user(), $validated['reason']);

        return $this->userResponse($updated, 'Tier changed.');
    }

    /**
     * Give a customer custom limits.
     */
    public function setLimits(Request $request, User $user): JsonResponse
    {
        $this->authorizeStaff($request);
        $validated = $request->validate([
            'daily_cap' => ['required', 'decimal:0,2', 'min:0', 'max:'.config('kyc.max_custom_daily_cap', 100000)],
            'monthly_cap' => ['required', 'decimal:0,2', 'min:0', 'max:'.config('kyc.max_custom_monthly_cap', 500000)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $updated = $this->kyc->setCustomLimits($user, $validated['daily_cap'], $validated['monthly_cap'], $request->user(), $validated['reason']);

        return $this->userResponse($updated, 'Limits updated.');
    }

    /**
     * @return list<array{tier: string, label: string, description: string, daily_cap: float, monthly_cap: float, requires_verification: bool}>
     */
    private function tierLadder(): array
    {
        return collect(config('kyc.tiers', []))
            ->map(fn (array $tier, string $key): array => [
                'tier' => $key,
                'label' => $tier['label'],
                'description' => $tier['description'],
                'daily_cap' => (float) $tier['daily_cap'],
                'monthly_cap' => (float) $tier['monthly_cap'],
                'requires_verification' => (bool) $tier['requires_verification'],
            ])
            ->values()
            ->all();
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeStaff(Request $request): void
    {
        if (! $request->user()->can(Permission::UsersManageKyc->value)) {
            throw new AuthorizationException('You do not have permission to manage KYC.');
        }
    }

    private function submissionResponse(KycSubmission $submission, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => ['submission' => new KycSubmissionResource($submission->load(['user', 'reviewer']))],
        ]);
    }

    private function userResponse(User $user, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => ['user' => new UserResource($user->load(['roles', 'cap']))],
        ]);
    }
}
