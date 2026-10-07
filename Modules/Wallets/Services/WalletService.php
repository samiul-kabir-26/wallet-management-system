<?php

namespace Modules\Wallets\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Wallets\Exceptions\InsufficientBalanceException;
use Modules\Wallets\Exceptions\WalletBlockedException;
use Modules\Wallets\Exceptions\WalletNotFoundException;

class WalletService
{
    /**
     * Get the wallet associated with the given user.
     *
     * @throws WalletNotFoundException
     */
    public function getWalletForUser(User $user): Wallet
    {
        $wallet = $user->wallet;

        if (! $wallet) {
            throw new WalletNotFoundException;
        }

        return $wallet;
    }

    /**
     * List paginated wallets with optional filtering.
     *
     * @param  array{is_blocked?: bool|string, user_id?: int}  $filters
     */
    public function listWallets(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Wallet::query()->latest('id');

        if (isset($filters['is_blocked'])) {
            $isBlocked = filter_var($filters['is_blocked'], FILTER_VALIDATE_BOOLEAN);
            $query->where('is_blocked', $isBlocked);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Block a wallet and record an immutable audit log.
     *
     * State transition is idempotent: if the wallet is already blocked,
     * blocked_by and blocked_at retain the original block event's data.
     * However, each administrative block action is recorded in the audit log
     * to preserve accountability and capture every stated reason.
     */
    public function blockWallet(Wallet $wallet, string $reason, User $actor): Wallet
    {
        return DB::transaction(function () use ($wallet, $reason, $actor): Wallet {
            /** @var Wallet $lockedWallet */
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            if (! $lockedWallet->is_blocked) {
                $lockedWallet->update([
                    'is_blocked' => true,
                    'blocked_by' => $actor->id,
                    'blocked_at' => now(),
                ]);
            }

            AuditLog::create([
                'actor_id' => $actor->id,
                'action' => 'WALLET_BLOCKED',
                'auditable_type' => $lockedWallet->getMorphClass(),
                'auditable_id' => $lockedWallet->id,
                'metadata' => [
                    'reason' => $reason,
                ],
            ]);

            return $lockedWallet->refresh();
        });
    }

    /**
     * Unblock a wallet and record an immutable audit log.
     */
    public function unblockWallet(Wallet $wallet, User $actor): Wallet
    {
        return DB::transaction(function () use ($wallet, $actor): Wallet {
            /** @var Wallet $lockedWallet */
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            if ($lockedWallet->is_blocked) {
                $lockedWallet->update([
                    'is_blocked' => false,
                ]);

                AuditLog::create([
                    'actor_id' => $actor->id,
                    'action' => 'WALLET_UNBLOCKED',
                    'auditable_type' => $lockedWallet->getMorphClass(),
                    'auditable_id' => $lockedWallet->id,
                    'metadata' => [
                        'unblocked_by' => $actor->id,
                    ],
                ]);
            }

            return $lockedWallet->refresh();
        });
    }

    /**
     * Atomically update a user's wallet balance with row-level locking.
     *
     *
     * @throws WalletNotFoundException
     * @throws WalletBlockedException
     * @throws InsufficientBalanceException
     */
    public function updateBalance(int $userId, string|float $amount, string $type = 'CREDIT'): Wallet
    {
        $type = strtoupper($type);
        if (! in_array($type, ['CREDIT', 'DEBIT'], true)) {
            throw new InvalidArgumentException("Invalid transaction type [{$type}]. Allowed: CREDIT, DEBIT.");
        }

        $formattedAmount = number_format((float) $amount, 2, '.', '');

        return DB::transaction(function () use ($userId, $formattedAmount, $type): Wallet {
            /** @var Wallet|null $wallet */
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();

            if (! $wallet) {
                throw new WalletNotFoundException;
            }

            if ($wallet->is_blocked) {
                throw new WalletBlockedException;
            }

            $currentBalance = number_format((float) $wallet->balance, 2, '.', '');

            if ($type === 'DEBIT' && bccomp($currentBalance, $formattedAmount, 2) === -1) {
                throw new InsufficientBalanceException;
            }

            $newBalance = $type === 'CREDIT'
                ? bcadd($currentBalance, $formattedAmount, 2)
                : bcsub($currentBalance, $formattedAmount, 2);

            $wallet->balance = $newBalance;
            $wallet->save();

            return $wallet->refresh();
        });
    }
}
