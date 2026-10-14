<?php

namespace Modules\Transactions\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Presents a cap usage snapshot produced by CapService::usageSnapshot().
 *
 * @property-read array{daily_limit: string, daily_used: string, daily_remaining: string, monthly_limit: string, monthly_used: string, monthly_remaining: string} $resource
 */
class CapResource extends JsonResource
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
     * @return array<string, float>
     */
    public function toArray(Request $request): array
    {
        return [
            'daily_limit' => (float) $this->resource['daily_limit'],
            'daily_used' => (float) $this->resource['daily_used'],
            'daily_remaining' => (float) $this->resource['daily_remaining'],
            'monthly_limit' => (float) $this->resource['monthly_limit'],
            'monthly_used' => (float) $this->resource['monthly_used'],
            'monthly_remaining' => (float) $this->resource['monthly_remaining'],
        ];
    }
}
