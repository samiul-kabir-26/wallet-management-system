<?php

namespace Modules\Roles\Resources;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Roles\Enums\Permission as PermissionEnum;

/**
 * @property-read Permission $resource
 */
class PermissionResource extends JsonResource
{
    /**
     * Disable default top-level data wrapping.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $enum = PermissionEnum::tryFrom($this->resource->name);

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'group' => $this->resource->group,
            'label' => $this->resource->description,
            'description' => $this->resource->description,
            'is_super_admin_only' => $enum?->isSuperAdminOnly() ?? false,
        ];
    }
}
