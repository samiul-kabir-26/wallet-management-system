<?php

namespace App\Policies;

use App\Models\User;

class SystemSettingPolicy
{
    /**
     * Determine whether the user can view any system settings.
     */
    public function viewAny(User $user): bool
    {
        return $user->tokenCan('admin') || $user->tokenCan('agent') || $user->tokenCan('user');
    }

    /**
     * Determine whether the user can view system settings.
     */
    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can update system settings.
     */
    public function update(User $user): bool
    {
        return $user->can('settings.update');
    }

    /**
     * Determine whether the user can change the per-panel idle auto-logout timeouts.
     */
    public function manageSessionTimeouts(User $user): bool
    {
        return $user->can('settings.manage_session_timeouts');
    }
}
