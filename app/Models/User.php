<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Modules\Roles\Enums\Permission as PermissionEnum;
use Modules\Roles\Enums\RolePanel;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'email',
    'password',
    'phone_number',
    'pin',
    'image',
    'address',
])]
#[Hidden(['password', 'pin'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Directory on the public disk that holds uploaded avatars.
     */
    public const string AVATAR_DIRECTORY = 'avatars';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'pin' => 'hashed',
            'password_changed_at' => 'datetime',
        ];
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('assigned_at', 'assigned_by')
            ->withTimestamps();
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function userWallet(): HasOne
    {
        return $this->hasOne(Wallet::class)->where('kind', Wallet::KIND_USER);
    }

    public function agentWallet(): HasOne
    {
        return $this->hasOne(Wallet::class)->where('kind', Wallet::KIND_AGENT);
    }

    public function cap(): HasOne
    {
        return $this->hasOne(Cap::class);
    }

    public function agentInfo(): HasOne
    {
        return $this->hasOne(AgentInfo::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function sentTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'sender_id');
    }

    public function receivedTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'recipient_id');
    }

    public function agentTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'agent_id');
    }

    public function initiatedTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'initiated_by');
    }

    public function assignedRoles(): HasMany
    {
        return $this->hasMany(UserRole::class, 'assigned_by');
    }

    public function updatedSettings(): HasMany
    {
        return $this->hasMany(SystemSetting::class, 'updated_by');
    }

    public function createdOtps(): HasMany
    {
        return $this->hasMany(OtpToken::class);
    }

    public function authProviders(): HasMany
    {
        return $this->hasMany(AuthProvider::class);
    }

    public function loginActivities(): HasMany
    {
        return $this->hasMany(LoginActivity::class);
    }

    /**
     * Route SMS notifications to the account's phone number.
     */
    public function routeNotificationForSms(): ?string
    {
        return $this->phone_number;
    }

    /**
     * Public URL of the uploaded avatar, if any. Only paths written by the avatar upload are served.
     */
    public function avatarUrl(): ?string
    {
        if (! is_string($this->image) || ! str_starts_with($this->image, self::AVATAR_DIRECTORY.'/')) {
            return null;
        }

        return Storage::disk('public')->url($this->image);
    }

    /**
     * Whether the account holds a temporary password that must be replaced before anything else.
     */
    public function mustChangePassword(): bool
    {
        return $this->password !== null && $this->password_changed_at === null;
    }

    /**
     * In-memory memoized role names for the current instance.
     *
     * @var Collection<int, string>|null
     */
    protected ?Collection $roleNamesMemo = null;

    /**
     * Determine if the user holds the given role(s).
     *
     * @param  string|array<int, string>  $role
     */
    public function hasRole(string|array $role): bool
    {
        $roles = (array) $role;

        if ($this->relationLoaded('roles')) {
            return $this->roles->whereIn('name', $roles)->isNotEmpty();
        }

        if ($this->roleNamesMemo !== null) {
            return $this->roleNamesMemo->intersect($roles)->isNotEmpty();
        }

        $this->roleNamesMemo = $this->roles()->pluck('name');

        return $this->roleNamesMemo->intersect($roles)->isNotEmpty();
    }

    /**
     * Determine if the user holds an administrative role (ADMIN or SUPER_ADMIN).
     */
    public function isAdmin(): bool
    {
        return $this->hasRole(['ADMIN', 'SUPER_ADMIN']);
    }

    /**
     * Determine if the user holds the SUPER_ADMIN role.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('SUPER_ADMIN');
    }

    /**
     * Determine if the user holds the AGENT role.
     */
    public function isAgent(): bool
    {
        return $this->hasRole('AGENT');
    }

    /**
     * Determine if the user holds the USER role.
     */
    public function isUser(): bool
    {
        return $this->hasRole('USER');
    }

    /**
     * Determine if the user is eligible to receive wallet transfers.
     */
    public function canReceiveTransfers(?User $sender = null): bool
    {
        if ($this->is_deleted) {
            return false;
        }

        if ($sender !== null && (int) $this->id === (int) $sender->id) {
            return false;
        }

        $hasUserRole = $this->relationLoaded('roles')
            ? $this->roles->contains('name', 'USER')
            : $this->isUser();

        if (! $hasUserRole) {
            return false;
        }

        $userWallet = $this->relationLoaded('wallets')
            ? $this->wallets->firstWhere('kind', Wallet::KIND_USER)
            : ($this->relationLoaded('userWallet')
                ? $this->userWallet
                : $this->userWallet()->first());

        if (! $userWallet || $userWallet->is_blocked) {
            return false;
        }

        return true;
    }

    /**
     * Determine the wallet kind associated with the current token ability, if any.
     *
     * Returns Wallet::KIND_AGENT for agent ability, Wallet::KIND_USER for user ability,
     * or null for tokens without wallet scoping (such as admin tokens).
     */
    public function currentWalletKind(): ?string
    {
        return match (true) {
            $this->tokenCan('agent') => Wallet::KIND_AGENT,
            $this->tokenCan('user') => Wallet::KIND_USER,
            default => null,
        };
    }

    /**
     * In-memory memoized effective permissions for the current instance.
     *
     * @var Collection<int, string>|null
     */
    protected ?Collection $effectivePermissionsMemo = null;

    /**
     * Resolve the effective permission names for this user.
     *
     * Memoized per model instance (D9).
     *
     * @return Collection<int, string>
     */
    public function permissions(): Collection
    {
        if ($this->effectivePermissionsMemo !== null) {
            return $this->effectivePermissionsMemo;
        }

        if ($this->isSuperAdmin()) {
            return $this->effectivePermissionsMemo = collect(PermissionEnum::cases())
                ->map(fn (PermissionEnum $p): string => $p->value)
                ->values();
        }

        if ($this->relationLoaded('roles') && $this->roles->every(fn (Role $r): bool => $r->relationLoaded('permissions'))) {
            return $this->effectivePermissionsMemo = $this->roles
                ->flatMap(fn (Role $role): Collection => $role->permissions->pluck('name'))
                ->filter(fn (string $name): bool => ! (PermissionEnum::tryFrom($name)?->isSuperAdminOnly() ?? false))
                ->unique()
                ->values();
        }

        return $this->effectivePermissionsMemo = Permission::query()
            ->whereHas('roles', function ($query): void {
                $query->whereIn('roles.id', $this->roles()->select('roles.id'));
            })
            ->pluck('name')
            ->filter(fn (string $name): bool => ! (PermissionEnum::tryFrom($name)?->isSuperAdminOnly() ?? false))
            ->unique()
            ->values();
    }

    /**
     * Determine if the user holds the given permission.
     */
    public function hasPermission(PermissionEnum|string $permission): bool
    {
        $name = $permission instanceof PermissionEnum ? $permission->value : $permission;

        return $this->permissions()->contains($name);
    }

    /**
     * Flush the effective permissions memoization (useful in tests and role assignments).
     */
    public function flushPermissionsMemo(): void
    {
        $this->effectivePermissionsMemo = null;
        $this->roleNamesMemo = null;
    }

    /**
     * Determine if the user holds any role belonging to the admin panel.
     */
    public function hasAdminPanelRole(): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->contains(function (Role $role): bool {
                $panel = $role->panel instanceof RolePanel ? $role->panel->value : $role->panel;

                return $panel === RolePanel::Admin->value;
            });
        }

        return $this->roles()->where('panel', RolePanel::Admin->value)->exists();
    }

    /**
     * Determine if the user holds an administrative staff role (derives from panel = admin).
     */
    public function isStaff(): bool
    {
        return $this->hasAdminPanelRole();
    }

    /**
     * Determine if the user holds a staff role and is authenticated with administrative token ability.
     */
    public function isStaffWithAdminAccess(): bool
    {
        return $this->isStaff() && $this->tokenCan('admin');
    }
}
