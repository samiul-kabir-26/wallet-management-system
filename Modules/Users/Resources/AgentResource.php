<?php

namespace Modules\Users\Resources;

use App\Models\AgentInfo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AgentInfo $resource
 */
class AgentResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->resource->user_id,
            'status' => $this->resource->status,
            'commission_rate' => (float) $this->resource->commission_rate,
            'total_commission' => (float) $this->resource->total_commission,
            'approved_at' => $this->resource->approved_at,
            'approved_by' => $this->resource->approved_by,
            'suspended_at' => $this->resource->suspended_at,
            'suspended_by' => $this->resource->suspended_by,
        ];
    }
}
