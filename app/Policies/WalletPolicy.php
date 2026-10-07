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
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can view the specific wallet.
     */
    public function view(User $user, Wallet $wallet): bool
    {
        return $user->id === $wallet->user_id || $user->isAdmin();
    }

    /**
     * Determine whether the user can block the wallet.
     */
    public function block(User $user, Wallet $wallet): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can unblock the wallet.
     */
    public function unblock(User $user, Wallet $wallet): bool
    {
        return $user->isAdmin();
    }
}
