<?php

namespace Modules\Users\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AuditLog $resource
 */
class AuditLogResource extends JsonResource
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
        return [
            'id' => $this->resource->id,
            'action' => $this->resource->action,
            'actor' => $this->resource->actor === null ? null : [
                'id' => $this->resource->actor->id,
                'name' => $this->resource->actor->name,
            ],
            'auditable_type' => $this->resource->auditable_type,
            'auditable_id' => $this->resource->auditable_id,
            'metadata' => $this->resource->metadata,
            'created_at' => $this->resource->created_at,
        ];
    }
}
