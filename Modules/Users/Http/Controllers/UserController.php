<?php

namespace Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Users\Http\Requests\ApproveAgentRequest;
use Modules\Users\Http\Requests\AssignRoleRequest;
use Modules\Users\Http\Requests\LookupRecipientRequest;
use Modules\Users\Http\Requests\RegisterUserRequest;
use Modules\Users\Http\Requests\SuspendAgentRequest;
use Modules\Users\Http\Requests\UpdateUserRequest;
use Modules\Users\Resources\AgentResource;
use Modules\Users\Resources\AuditLogResource;
use Modules\Users\Resources\LoginActivityResource;
use Modules\Users\Resources\RecipientLookupResource;
use Modules\Users\Resources\UserResource;
use Modules\Users\Services\UserService;

class UserController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private UserService $userService,
    ) {}

    /**
     * Display a listing of users with optional filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $validated = $request->validate([
            'role' => ['sometimes', 'string', Rule::exists('roles', 'name')],
            'is_active' => ['sometimes', 'string', Rule::in(['ACTIVE', 'INACTIVE'])],
            'is_verified' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);

        $query = User::query()
            ->with(['roles', 'agentInfo'])
            ->where('is_deleted', false)
            ->latest('id');

        if (! empty($validated['role'])) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $validated['role']));
        }

        if (! empty($validated['is_active'])) {
            $query->where('is_active', $validated['is_active']);
        }

        if (isset($validated['is_verified'])) {
            $query->where('is_verified', filter_var($validated['is_verified'], FILTER_VALIDATE_BOOLEAN));
        }

        $paginator = $query->paginate($perPage);

        return $this->paginatedResponse(
            $paginator,
            UserResource::collection($paginator),
        );
    }

    /**
     * Register a new user in the system.
     */
    public function register(RegisterUserRequest $request): JsonResponse
    {
        $user = $this->userService->register(
            data: $request->validated(),
            actor: $request->user(),
        );

        return response()->json([
            'success' => true,
            'message' => 'User registered successfully',
            'data' => [
                'user' => new UserResource($user),
            ],
        ], 201);
    }

    /**
     * Display the specified user details.
     */
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $user->load(['roles', 'wallets', 'cap', 'agentInfo']);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
            ],
        ]);
    }

    /**
     * Update the specified user details.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user->update($request->validated());

        $user->load(['roles', 'wallets', 'cap', 'agentInfo']);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
            ],
        ]);
    }

    /**
     * Approve a pending agent.
     */
    public function approveAgent(ApproveAgentRequest $request, User $user): JsonResponse
    {
        $updatedUser = $this->userService->approveAgent(
            agentUser: $user,
            commissionRate: $request->has('commission_rate') ? (float) $request->input('commission_rate') : null,
            actor: $request->user(),
        );

        $agentInfo = $updatedUser->agentInfo;

        return response()->json([
            'success' => true,
            'message' => 'Agent approved successfully',
            'data' => [
                'agent' => new AgentResource($agentInfo),
            ],
        ]);
    }

    /**
     * Suspend an approved agent.
     */
    public function suspendAgent(SuspendAgentRequest $request, User $user): JsonResponse
    {
        $updatedUser = $this->userService->suspendAgent(
            agentUser: $user,
            reason: $request->validated('reason'),
            actor: $request->user(),
        );

        $agentInfo = $updatedUser->agentInfo;

        return response()->json([
            'success' => true,
            'message' => 'Agent suspended successfully',
            'data' => [
                'agent' => new AgentResource($agentInfo),
            ],
        ]);
    }

    /**
     * Assign an additional role (AGENT or USER) to the specified user.
     */
    public function assignRole(AssignRoleRequest $request, User $user): JsonResponse
    {
        $updatedUser = $this->userService->assignRole(
            user: $user,
            roleName: $request->validated('role'),
            actor: $request->user(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Role assigned successfully',
            'data' => [
                'user' => new UserResource($updatedUser),
            ],
        ]);
    }

    /**
     * Look up a user by phone number and return a masked name and eligibility.
     */
    public function lookup(LookupRecipientRequest $request): JsonResponse
    {
        $user = User::with(['roles', 'wallets'])
            ->where('phone_number', $request->validated('phone_number'))
            ->where('is_deleted', false)
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => (new RecipientLookupResource($user))->resolve($request),
        ]);
    }

    /**
     * Mark the user's phone number as verified after an out-of-band identity check.
     */
    public function verifyPhone(Request $request, User $user): JsonResponse
    {
        $this->authorize('verifyPhone', $user);

        $verifiedUser = $this->userService->verifyPhone($user, $request->user());
        $verifiedUser->load(['roles', 'agentInfo']);

        return response()->json([
            'success' => true,
            'message' => 'Phone number marked as verified.',
            'data' => [
                'user' => new UserResource($verifiedUser),
            ],
        ]);
    }

    /**
     * List the login attempts recorded against the user, newest first.
     */
    public function loginActivity(Request $request, User $user): JsonResponse
    {
        $this->authorize('viewActivity', $user);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $paginator = $user->loginActivities()
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 15));

        return $this->paginatedResponse(
            $paginator,
            LoginActivityResource::collection($paginator),
        );
    }

    /**
     * List audit entries performed by the user or recorded against the user, newest first.
     */
    public function auditTrail(Request $request, User $user): JsonResponse
    {
        $this->authorize('viewActivity', $user);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $walletIds = $user->wallets()->pluck('id');

        // Actions the user performed, actions taken on the account, and actions taken on its wallets
        $paginator = AuditLog::query()
            ->with('actor:id,name')
            ->where(function ($query) use ($user, $walletIds): void {
                $query->where('actor_id', $user->id)
                    ->orWhere(fn ($q) => $q
                        ->where('auditable_type', $user->getMorphClass())
                        ->where('auditable_id', $user->id))
                    ->orWhere(fn ($q) => $q
                        ->where('auditable_type', (new Wallet)->getMorphClass())
                        ->whereIn('auditable_id', $walletIds));
            })
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 15));

        return $this->paginatedResponse(
            $paginator,
            AuditLogResource::collection($paginator),
        );
    }
}
