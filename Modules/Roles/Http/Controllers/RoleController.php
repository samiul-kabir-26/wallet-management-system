<?php

namespace Modules\Roles\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Roles\Enums\RolePanel;
use Modules\Roles\Http\Requests\StoreRoleRequest;
use Modules\Roles\Http\Requests\SyncRolePermissionsRequest;
use Modules\Roles\Http\Requests\UpdateRoleRequest;
use Modules\Roles\Resources\RoleResource;
use Modules\Roles\Services\RoleService;

class RoleController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected RoleService $roleService,
    ) {}

    /**
     * Display a paginated listing of roles, with optional panel filter.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $validated = $request->validate([
            'panel' => ['sometimes', 'string', Rule::enum(RolePanel::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Role::query()
            ->with(['permissions'])
            ->withCount(['userRoles as users_count', 'permissions']);

        if (! empty($validated['panel'])) {
            $query->where('panel', $validated['panel']);
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        $paginator = $query->latest('id')->paginate($perPage);

        return $this->paginatedResponse(
            $paginator,
            RoleResource::collection($paginator),
        );
    }

    /**
     * Display details of a specific role.
     */
    public function show(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        $role->load(['permissions'])
            ->loadCount(['userRoles as users_count', 'permissions']);

        return response()->json([
            'success' => true,
            'data' => [
                'role' => new RoleResource($role),
            ],
        ]);
    }

    /**
     * Create a new custom role.
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roleService->createRole(
            data: $request->validated(),
            actor: $request->user(),
        );

        $role->loadCount(['userRoles as users_count', 'permissions']);

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully',
            'data' => [
                'role' => new RoleResource($role),
            ],
        ], 201);
    }

    /**
     * Update an existing role's name or description.
     */
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $updated = $this->roleService->updateRole(
            role: $role,
            data: $request->validated(),
            actor: $request->user(),
        );

        $updated->loadCount(['userRoles as users_count', 'permissions']);

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'data' => [
                'role' => new RoleResource($updated),
            ],
        ]);
    }

    /**
     * Fully replace a role's assigned permissions.
     */
    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $synced = $this->roleService->syncPermissions(
            role: $role,
            requestedPermissionNames: $request->validated('permissions'),
            actor: $request->user(),
        );

        $synced->loadCount(['userRoles as users_count', 'permissions']);

        return response()->json([
            'success' => true,
            'message' => 'Role permissions updated successfully',
            'data' => [
                'role' => new RoleResource($synced),
            ],
        ]);
    }

    /**
     * Delete an unused custom role.
     */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        $this->roleService->deleteRole(
            role: $role,
            actor: $request->user(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully',
        ]);
    }
}
