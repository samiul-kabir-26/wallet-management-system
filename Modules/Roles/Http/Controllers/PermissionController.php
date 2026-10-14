<?php

namespace Modules\Roles\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Roles\Resources\PermissionResource;

class PermissionController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of permissions grouped by domain for the checkbox grid.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $permissions = Permission::all();

        $grouped = $permissions->groupBy('group')->map(function ($items) {
            return PermissionResource::collection($items);
        });

        return response()->json([
            'success' => true,
            'data' => [
                'permissions' => PermissionResource::collection($permissions),
                'grouped' => $grouped,
            ],
        ]);
    }
}
