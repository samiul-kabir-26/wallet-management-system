<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('users.view_any');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return $user->id === $model->id || $user->can('users.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $user->id === $model->id || $user->can('users.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can create an ADMIN user specifically.
     */
    public function createAdmin(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can approve agents.
     */
    public function approveAgent(User $user, User $agent): bool
    {
        return $user->can('agents.approve') && $user->id !== $agent->id;
    }

    /**
     * Determine whether the user can suspend agents.
     */
    public function suspendAgent(User $user, User $agent): bool
    {
        return $user->can('agents.suspend');
    }

    /**
     * Determine whether the user can assign additional roles to a user.
     */
    public function assignRole(User $user, User $model): bool
    {
        return $user->can('users.assign_role') && $user->id !== $model->id;
    }

    /**
     * Determine whether the user can grant administrative access to a user.
     */
    public function grantAdminAccess(User $user, User $model): bool
    {
        return $user->tokenCan('admin') && $user->can('users.grant_admin_access') && $user->id !== $model->id;
    }

    /**
     * Determine whether the user can mark another account's phone number as verified.
     */
    public function verifyPhone(User $user, User $model): bool
    {
        return $user->can('users.verify_phone') && $user->id !== $model->id;
    }

    /**
     * Determine whether the user can view another account's login records and audit trail.
     */
    public function viewActivity(User $user, User $model): bool
    {
        return $user->can('users.view_activity');
    }
}
