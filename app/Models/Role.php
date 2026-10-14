<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Roles\Enums\RolePanel;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property RolePanel|string $panel
 * @property bool $is_system
 * @property int|null $created_by
 * @property int|null $users_count
 * @property int|null $permissions_count
 * @property Collection<int, Permission> $permissions
 */
#[Fillable([
    'name',
    'description',
    'panel',
    'is_system',
    'created_by',
])]
class Role extends Model
{
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'panel' => RolePanel::class,
        ];
    }

    /**
     * The user assignments for this role (fixes P1).
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * The permissions assigned to this role.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withPivot('granted_by')
            ->withTimestamps();
    }

    /**
     * The users holding this role.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot('assigned_at', 'assigned_by')
            ->withTimestamps();
    }

    /**
     * The staff user who created this role, if custom.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope query to roles belonging to the admin panel.
     */
    public function scopeAdminPanel(Builder $query): Builder
    {
        return $query->where('panel', RolePanel::Admin->value);
    }

    /**
     * Scope query to custom (non-system) roles.
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }
}
