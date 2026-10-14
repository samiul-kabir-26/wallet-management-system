<?php

namespace Modules\Users\Resources;

use App\Models\LoginActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read LoginActivity $resource
 */
class LoginActivityResource extends JsonResource
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
            'panel' => $this->resource->panel,
            'identifier' => $this->resource->identifier,
            'status' => $this->resource->status,
            'failure_reason' => $this->resource->failure_reason,
            'ip_address' => $this->resource->ip_address,
            'user_agent' => $this->resource->user_agent,
            'created_at' => $this->resource->created_at,
        ];
    }
}
