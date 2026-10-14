<?php

namespace Modules\Roles\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Roles\Enums\RolePanel;

/**
 * @property-read Role $resource
 */
class RoleResource extends JsonResource
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
        $panelValue = $this->resource->panel instanceof RolePanel
            ? $this->resource->panel->value
            : $this->resource->panel;

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'panel' => $panelValue,
            'is_system' => (bool) $this->resource->is_system,
            'created_by' => $this->resource->created_by,
            'users_count' => (int) ($this->resource->users_count ?? 0),
            'permissions_count' => (int) ($this->resource->permissions_count ?? ($this->resource->relationLoaded('permissions') ? $this->resource->permissions->count() : 0)),
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->resource->permissions->pluck('name')->values()->all()
            ),
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
