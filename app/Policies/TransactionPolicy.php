<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    /**
     * Determine whether the user can view any transactions (admin audit list).
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() && $user->tokenCan('admin');
    }

    /**
     * Determine whether the user can view the specific transaction.
     * Allowed if the user is an admin with admin token ability or participates in any of the 5 FK slots:
     * user_id, sender_id, recipient_id, agent_id, initiated_by.
     */
    public function view(User $user, Transaction $transaction): bool
    {
        if ($user->isAdmin() && $user->tokenCan('admin')) {
            return true;
        }

        return in_array($user->id, [
            $transaction->user_id,
            $transaction->sender_id,
            $transaction->recipient_id,
            $transaction->agent_id,
            $transaction->initiated_by,
        ], true);
    }

    /**
     * Determine whether the user can initiate a top-up.
     */
    public function topUp(User $user): bool
    {
        return $user->isUser();
    }

    /**
     * Determine whether the user can initiate a cash-in.
     */
    public function cashIn(User $user): bool
    {
        return $user->isUser();
    }

    /**
     * Determine whether the user can initiate a cash-out.
     */
    public function cashOut(User $user): bool
    {
        return $user->isUser();
    }

    /**
     * Determine whether the user can initiate a transfer.
     */
    public function transfer(User $user): bool
    {
        return $user->isUser();
    }

    /**
     * Determine whether the agent can initiate an agent withdrawal.
     */
    public function agentWithdrawal(User $user): bool
    {
        return $user->isAgent();
    }
}
