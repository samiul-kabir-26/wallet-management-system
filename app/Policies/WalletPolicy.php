<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Wallet;

class WalletPolicy
{
    /**
     * Determine whether the user can view any wallets.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('wallets.view_any');
    }

    /**
     * Determine whether the user can view the specific wallet.
     */
    public function view(User $user, Wallet $wallet): bool
    {
        return $user->id === $wallet->user_id || $user->can('wallets.view_any');
    }

    /**
     * Determine whether the user can block the wallet.
     */
    public function block(User $user, Wallet $wallet): bool
    {
        return $user->can('wallets.block');
    }

    /**
     * Determine whether the user can unblock the wallet.
     */
    public function unblock(User $user, Wallet $wallet): bool
    {
        return $user->can('wallets.unblock');
    }
}
