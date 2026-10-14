<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;
use Modules\Roles\Enums\Permission;
use Modules\Wallets\Services\WalletService;

class TransactionPolicy
{
    /**
     * Determine whether the user can view any transactions (admin audit list).
     */
    public function viewAny(User $user): bool
    {
        return $user->can('transactions.view_any');
    }

    /**
     * Determine whether the user can view the specific transaction.
     */
    public function view(User $user, Transaction $transaction): bool
    {
        if ($user->can('transactions.view_any')) {
            return true;
        }

        $wallet = app(WalletService::class)->findWalletForToken($user);
        if (! $wallet) {
            return false;
        }

        return Transaction::query()
            ->where('id', $transaction->id)
            ->involvingWallet($wallet)
            ->exists();
    }

    /**
     * Determine whether the user can initiate a top-up.
     */
    public function topUp(User $user): bool
    {
        return $user->isUser();
    }

    /**
     * Determine whether the agent can initiate a cash-in.
     */
    public function cashIn(User $user): bool
    {
        return $user->isAgent();
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

    /**
     * Determine whether the user can issue float to an agent.
     */
    public function issueFloat(User $user): bool
    {
        return $user->can(Permission::AgentsIssueFloat->value);
    }
}
