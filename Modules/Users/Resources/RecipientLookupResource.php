<?php

namespace Modules\Users\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read User $resource
 */
class RecipientLookupResource extends JsonResource
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
            'name' => $this->maskName($this->resource->name),
            'phone_number' => $this->resource->phone_number,
            'can_receive' => $this->resource->canReceiveTransfers($request->user()),
        ];
    }

    /**
     * Mask a full name for privacy (e.g. "Rahul Kumar" -> "R***l K***r").
     */
    private function maskName(string $name): string
    {
        $words = preg_split('/\s+/', trim($name));
        if (empty($words)) {
            return '***';
        }

        $maskedWords = array_map(function (string $word): string {
            $length = mb_strlen($word);
            if ($length <= 1) {
                return '*';
            }
            if ($length === 2) {
                return mb_substr($word, 0, 1).'*';
            }

            return mb_substr($word, 0, 1).str_repeat('*', $length - 2).mb_substr($word, -1, 1);
        }, $words);

        return implode(' ', $maskedWords);
    }
}
