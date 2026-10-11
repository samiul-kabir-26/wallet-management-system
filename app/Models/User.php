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
use Laravel\Sanctum\HasApiTokens;

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

        return $this->roles()->whereIn('name', $roles)->exists();
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
     * Determine if the user holds an administrative staff role (SUPER_ADMIN, ADMIN, MODERATOR).
     */
    public function isStaff(): bool
    {
        return $this->hasRole(['SUPER_ADMIN', 'ADMIN', 'MODERATOR']);
    }

    /**
     * Determine if the user holds a staff role and is authenticated with administrative token ability.
     */
    public function isStaffWithAdminAccess(): bool
    {
        return $this->isStaff() && $this->tokenCan('admin');
    }
}
