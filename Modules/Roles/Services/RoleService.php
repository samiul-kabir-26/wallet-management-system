<?php

namespace Modules\Roles\Services;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Roles\Enums\Permission as PermissionEnum;
use Modules\Roles\Enums\RolePanel;
use Modules\Roles\Exceptions\PermissionEscalationException;
use Modules\Roles\Exceptions\RoleInUseException;
use Modules\Roles\Exceptions\SystemRoleImmutableException;

class RoleService
{
    /**
     * Create a new custom role with initial permissions.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws PermissionEscalationException
     */
    public function createRole(array $data, User $actor): Role
    {
        $permissionNames = array_values(array_unique($data['permissions'] ?? []));

        // Super-admin-only permissions cannot be assigned to roles by anyone (even SUPER_ADMIN)
        foreach ($permissionNames as $permName) {
            $enumCase = PermissionEnum::tryFrom($permName);
            if ($enumCase?->isSuperAdminOnly()) {
                throw new PermissionEscalationException(
                    "Permission [{$permName}] is restricted exclusively to Super Administrators and cannot be assigned to roles."
                );
            }
        }

        // R3: Anti-escalation check (requested_permissions ⊆ actor_effective_permissions)
        if (! $actor->isSuperAdmin()) {
            $actorPermissions = $actor->permissions()->all();
            $unauthorized = array_values(array_diff($permissionNames, $actorPermissions));

            if (! empty($unauthorized)) {
                throw new PermissionEscalationException(
                    'You cannot create a role with permissions you do not possess: '.implode(', ', $unauthorized)
                );
            }
        }

        return DB::transaction(function () use ($data, $permissionNames, $actor): Role {
            $role = Role::create([
                'name' => strtoupper(trim($data['name'])),
                'description' => $data['description'] ?? null,
                'panel' => RolePanel::Admin, // R2: Forced to admin panel
                'is_system' => false,
                'created_by' => $actor->id,
            ]);

            if (! empty($permissionNames)) {
                $permissionIds = Permission::whereIn('name', $permissionNames)->pluck('id')->all();
                $role->permissions()->syncWithPivotValues($permissionIds, [
                    'granted_by' => $actor->id,
                ]);
            }

            AuditLog::create([
                'actor_id' => $actor->id,
                'action' => 'role.created',
                'auditable_type' => $role->getMorphClass(),
                'auditable_id' => $role->id,
                'metadata' => [
                    'name' => $role->name,
                    'description' => $role->description,
                    'panel' => RolePanel::Admin->value,
                    'permissions' => $permissionNames,
                ],
            ]);

            return $role->load(['permissions']);
        });
    }

    /**
     * Update a role's metadata (name/description).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws SystemRoleImmutableException
     * @throws PermissionEscalationException
     */
    public function updateRole(Role $role, array $data, User $actor): Role
    {
        // R1: System roles cannot be renamed or modified
        if ($role->is_system) {
            throw new SystemRoleImmutableException('System roles cannot be renamed or modified.');
        }

        // R4: Actor cannot edit a role they currently hold
        if ($actor->hasRole($role->name)) {
            throw new PermissionEscalationException('You cannot edit a role you currently hold.');
        }

        return DB::transaction(function () use ($role, $data, $actor): Role {
            /** @var Role $lockedRole */
            $lockedRole = Role::where('id', $role->id)->lockForUpdate()->firstOrFail();

            $before = [
                'name' => $lockedRole->name,
                'description' => $lockedRole->description,
            ];

            $lockedRole->update([
                'name' => isset($data['name']) ? strtoupper(trim($data['name'])) : $lockedRole->name,
                'description' => $data['description'] ?? $lockedRole->description,
            ]);

            $after = [
                'name' => $lockedRole->name,
                'description' => $lockedRole->description,
            ];

            AuditLog::create([
                'actor_id' => $actor->id,
                'action' => 'role.updated',
                'auditable_type' => $lockedRole->getMorphClass(),
                'auditable_id' => $lockedRole->id,
                'metadata' => [
                    'before' => $before,
                    'after' => $after,
                ],
            ]);

            return $lockedRole;
        });
    }

    /**
     * Replace a role's permissions completely (idempotent PUT sync).
     *
     * @param  array<int, string>  $requestedPermissionNames
     *
     * @throws SystemRoleImmutableException
     * @throws PermissionEscalationException
     */
    public function syncPermissions(Role $role, array $requestedPermissionNames, User $actor): Role
    {
        // R2: Permissions can only be assigned to admin-panel roles
        $panel = $role->panel instanceof RolePanel ? $role->panel : RolePanel::tryFrom((string) $role->panel);
        if ($panel !== RolePanel::Admin) {
            throw new SystemRoleImmutableException('Permissions can only be assigned to admin-panel roles.');
        }

        // R1: SUPER_ADMIN permissions cannot be edited
        if ($role->name === 'SUPER_ADMIN') {
            throw new SystemRoleImmutableException('The permissions of the SUPER_ADMIN role cannot be modified.');
        }

        // R1: Only SUPER_ADMIN can modify permissions for system roles (such as ADMIN)
        if ($role->is_system && ! $actor->isSuperAdmin()) {
            throw new SystemRoleImmutableException('Only super administrators can modify permissions for system roles.');
        }

        // R4: Actor cannot edit or sync permissions of any role they currently hold
        if ($actor->hasRole($role->name)) {
            throw new PermissionEscalationException('You cannot edit or sync the permissions of a role you currently hold.');
        }

        return DB::transaction(function () use ($role, $requestedPermissionNames, $actor): Role {
            /** @var Role $lockedRole */
            $lockedRole = Role::where('id', $role->id)->lockForUpdate()->firstOrFail();

            $currentPermissions = $lockedRole->permissions()->pluck('name')->all();
            $targetPermissions = array_values(array_unique($requestedPermissionNames));

            $added = array_values(array_diff($targetPermissions, $currentPermissions));
            $removed = array_values(array_diff($currentPermissions, $targetPermissions));

            // Super-admin-only permissions cannot be assigned to roles by anyone (even SUPER_ADMIN)
            foreach ($targetPermissions as $permName) {
                $enumCase = PermissionEnum::tryFrom($permName);
                if ($enumCase?->isSuperAdminOnly()) {
                    throw new PermissionEscalationException(
                        "Permission [{$permName}] is restricted exclusively to Super Administrators and cannot be assigned to roles."
                    );
                }
            }

            // R3: Anti-escalation check: added ∪ removed ⊆ actor_effective_permissions
            if (! $actor->isSuperAdmin()) {
                $actorPermissions = $actor->permissions()->all();
                $touched = array_unique(array_merge($added, $removed));
                $unauthorized = array_values(array_diff($touched, $actorPermissions));

                if (! empty($unauthorized)) {
                    throw new PermissionEscalationException(
                        'You cannot grant or revoke permissions you do not possess: '.implode(', ', $unauthorized)
                    );
                }
            }

            $permissionIds = Permission::whereIn('name', $targetPermissions)->pluck('id')->all();

            $lockedRole->permissions()->syncWithPivotValues($permissionIds, [
                'granted_by' => $actor->id,
            ]);

            AuditLog::create([
                'actor_id' => $actor->id,
                'action' => 'role.permissions_synced',
                'auditable_type' => $lockedRole->getMorphClass(),
                'auditable_id' => $lockedRole->id,
                'metadata' => [
                    'role' => $lockedRole->name,
                    'added' => $added,
                    'removed' => $removed,
                    'before' => $currentPermissions,
                    'after' => $targetPermissions,
                ],
            ]);

            return $lockedRole->refresh()->load(['permissions']);
        });
    }

    /**
     * Delete an unused custom role.
     *
     * @throws SystemRoleImmutableException
     * @throws PermissionEscalationException
     * @throws RoleInUseException
     */
    public function deleteRole(Role $role, User $actor): void
    {
        // R1: System roles cannot be deleted
        if ($role->is_system) {
            throw new SystemRoleImmutableException('System roles cannot be deleted.');
        }

        // R4: Actor cannot delete a role they currently hold
        if ($actor->hasRole($role->name)) {
            throw new PermissionEscalationException('You cannot delete a role you currently hold.');
        }

        DB::transaction(function () use ($role, $actor): void {
            /** @var Role $lockedRole */
            $lockedRole = Role::where('id', $role->id)->lockForUpdate()->firstOrFail();

            // R7: Delete only if unused (zero user assignments)
            $userCount = $lockedRole->userRoles()->count();
            if ($userCount > 0) {
                throw new RoleInUseException(
                    "Cannot delete role [{$lockedRole->name}] because it currently has {$userCount} assigned users."
                );
            }

            AuditLog::create([
                'actor_id' => $actor->id,
                'action' => 'role.deleted',
                'auditable_type' => $lockedRole->getMorphClass(),
                'auditable_id' => $lockedRole->id,
                'metadata' => [
                    'name' => $lockedRole->name,
                    'panel' => $lockedRole->panel instanceof RolePanel ? $lockedRole->panel->value : $lockedRole->panel,
                ],
            ]);

            $lockedRole->permissions()->detach();
            $lockedRole->delete();
        });
    }

    /**
     * Check if the actor is permitted to assign the given role to a user (R5).
     *
     * @throws PermissionEscalationException
     */
    public function assertCanAssignRole(User $actor, Role $role): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if ($role->name === 'SUPER_ADMIN') {
            throw new PermissionEscalationException('Only super administrators can assign the SUPER_ADMIN role.');
        }

        if ($role->name === 'ADMIN') {
            throw new PermissionEscalationException('Only super administrators can assign the ADMIN role.');
        }

        // The assigned role must not contain super-admin-only permissions
        $rolePermissions = $role->permissions()->pluck('name')->all();
        foreach ($rolePermissions as $permName) {
            $enumCase = PermissionEnum::tryFrom($permName);
            if ($enumCase?->isSuperAdminOnly()) {
                throw new PermissionEscalationException(
                    "Only super administrators can assign a role containing [{$permName}]."
                );
            }
        }

        // The assigned role's permissions must be a subset of the actor's permissions
        $actorPermissions = $actor->permissions()->all();

        $excess = array_values(array_diff($rolePermissions, $actorPermissions));
        if (! empty($excess)) {
            throw new PermissionEscalationException(
                'You cannot assign a role containing permissions you do not possess: '.implode(', ', $excess)
            );
        }
    }
}
