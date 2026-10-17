<?php

namespace Modules\Authentication\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SystemSettings\Services\SessionTimeoutService;

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
                    'email' => $this->resource->email,
                    'roles' => $this->whenLoaded('roles', fn () => $this->resource->roles->pluck('name')->values()->all()),
                    'permissions' => (in_array('admin', $this->abilities, true) || $this->resource->tokenCan('admin'))
                        ? $this->resource->permissions()->values()->all()
                        : [],
                    'is_verified' => (bool) $this->resource->is_verified,
                    'is_active' => $this->resource->is_active,
                    'avatar_url' => $this->resource->avatarUrl(),
                    // Admin clients send the user to the change-password form until this is false
                    'must_change_password' => $this->resource->mustChangePassword(),
                ],
                'token' => $this->token,
                'token_type' => $this->token ? 'Bearer' : null,
                'abilities' => $this->abilities,
                // Lets clients log out on inactivity before the server rejects the idle token
                'idle_timeout_minutes' => $this->idleTimeoutMinutes(),
            ],
        ];
    }

    /**
     * Idle timeout for the panel this token was issued for, or null when it has no panel ability.
     */
    private function idleTimeoutMinutes(): ?int
    {
        $sessionTimeouts = app(SessionTimeoutService::class);
        $panel = $sessionTimeouts->panelForAbilities($this->abilities);

        return $panel !== null ? $sessionTimeouts->minutesFor($panel) : null;
    }
}
