<?php

namespace Modules\Wallets\Resources;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Wallet $resource
 */
class WalletResource extends JsonResource
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
            'user_id' => $this->resource->user_id,
            'balance' => (float) $this->resource->balance,
            'currency' => $this->resource->currency,
            'is_blocked' => (bool) $this->resource->is_blocked,
            'blocked_by' => $this->resource->blocked_by,
            'blocked_at' => $this->resource->blocked_at,
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
        ];
    }
}
