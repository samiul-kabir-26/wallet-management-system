<?php

namespace Modules\Authentication\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read User $resource
 */
class AuthResource extends JsonResource
{
    /**
     * Disable default top-level data wrapping.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Create a new resource instance.
     *
     * @param  User  $resource
     * @param  array<int, string>  $abilities
     */
    public function __construct(
        $resource,
        protected ?string $token = null,
        protected array $abilities = [],
        protected string $message = 'Login successful',
    ) {
        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'success' => true,
            'message' => $this->message,
            'data' => [
                'user' => [
                    'id' => $this->resource->id,
                    'name' => $this->resource->name,
                    'phone_number' => $this->resource->phone_number,
                    'email' => $this->whenNotNull($this->resource->email),
                    'roles' => $this->whenLoaded('roles', fn () => $this->resource->roles->pluck('name')),
                    'is_verified' => (bool) $this->resource->is_verified,
                    'is_active' => $this->resource->is_active,
                ],
                'token' => $this->token,
                'token_type' => 'Bearer',
                'abilities' => $this->abilities,
            ],
        ];
    }
}
