<?php

namespace Modules\Users\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read User $resource
 */
class UserResource extends JsonResource
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
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'phone_number' => $this->resource->phone_number,
            'address' => $this->resource->address,
            'receipt_emails_enabled' => $this->resource->receipt_emails_enabled !== false,
            'avatar_url' => $this->resource->avatarUrl(),
            'roles' => $this->whenLoaded('roles', fn () => $this->resource->roles->pluck('name')),
            'is_active' => $this->resource->is_active,
            'is_verified' => (bool) $this->resource->is_verified,
            'wallets' => $this->whenLoaded('wallets'),
            'caps' => $this->whenLoaded('cap'),
            'agent_info' => $this->whenLoaded('agentInfo', fn () => $this->resource->agentInfo ? new AgentResource($this->resource->agentInfo) : null),
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
        ];
    }
}
