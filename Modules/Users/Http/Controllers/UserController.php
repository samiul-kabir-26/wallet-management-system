<?php

namespace Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Users\Http\Requests\ApproveAgentRequest;
use Modules\Users\Http\Requests\RegisterUserRequest;
use Modules\Users\Http\Requests\SuspendAgentRequest;
use Modules\Users\Http\Requests\UpdateUserRequest;
use Modules\Users\Resources\AgentResource;
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
            'role' => ['sometimes', 'string', Rule::in(['SUPER_ADMIN', 'ADMIN', 'MODERATOR', 'AGENT', 'USER'])],
            'is_active' => ['sometimes', 'string', Rule::in(['ACTIVE', 'INACTIVE'])],
            'is_verified' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);

        $query = User::query()
            ->with('roles')
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

        return response()->json([
            'success' => true,
            'data' => [
                'users' => UserResource::collection($paginator),
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ],
        ]);
    }

    /**
     * Register a new user in the system.
     */
    public function register(RegisterUserRequest $request): JsonResponse
    {
        // Escalation check: Only SUPER_ADMIN can register an account with role=ADMIN.
        // Runs safely AFTER FormRequest validation has confirmed 'role' is valid.
        if ($request->validated('role') === 'ADMIN') {
            $this->authorize('createAdmin', User::class);
        }

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

        $user->load(['roles', 'wallet', 'cap', 'agentInfo']);

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

        $user->load(['roles', 'wallet', 'cap', 'agentInfo']);

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
}
