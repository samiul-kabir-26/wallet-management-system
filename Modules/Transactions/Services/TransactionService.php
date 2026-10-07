<?php

namespace Modules\Transactions\Services;

use App\Models\AgentInfo;
use App\Models\Cap;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Exceptions\UserNotFoundException;
use Modules\Transactions\Exceptions\DailyCapsExceededException;
use Modules\Transactions\Exceptions\InvalidRecipientException;
use Modules\Transactions\Exceptions\MonthlyCapsExceededException;
use Modules\Users\Exceptions\AgentNotApprovedException;
use Modules\Users\Exceptions\UserNotAnAgentException;
use Modules\Wallets\Exceptions\InsufficientBalanceException;
use Modules\Wallets\Exceptions\WalletBlockedException;
use Modules\Wallets\Exceptions\WalletNotFoundException;
use Modules\Wallets\Services\WalletService;

class TransactionService
{
    public function __construct(
        protected WalletService $walletService,
        protected FeeCalculator $feeCalculator,
        protected CommissionCalculator $commissionCalculator,
        protected CapService $capService,
    ) {}

    /**
     * Top-up user's own wallet balance.
     */
    public function topUp(User $user, string|float $amount, string $idempotencyKey, ?string $description = null): Transaction
    {
        $existing = Transaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $formattedAmount = number_format((float) $amount, 2, '.', '');

        try {
            return DB::transaction(function () use ($user, $formattedAmount, $idempotencyKey, $description): Transaction {
                $existing = Transaction::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }

                $updatedWallet = $this->walletService->updateBalance($user->id, $formattedAmount, 'CREDIT');

                return Transaction::create([
                    'user_id' => $user->id,
                    'sender_id' => null,
                    'recipient_id' => $user->id,
                    'agent_id' => null,
                    'initiated_by' => $user->id,
                    'type' => 'TOP_UP',
                    'amount' => $formattedAmount,
                    'system_fee_amount' => '0.00',
                    'system_fee_rate' => '0.0000',
                    'agent_commission_amount' => '0.00',
                    'agent_commission_rate' => '0.0000',
                    'currency' => $updatedWallet->currency ?? 'BDT',
                    'description' => $description,
                    'status' => 'COMPLETED',
                    'idempotency_key' => $idempotencyKey,
                    'sender_wallet_balance_after' => null,
                    'recipient_wallet_balance_after' => $updatedWallet->balance,
                    'agent_wallet_balance_after' => null,
                    'meta' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $existing = Transaction::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Agent withdraws accumulated funds from their own wallet.
     *
     * @throws UserNotAnAgentException
     * @throws AgentNotApprovedException
     */
    public function agentWithdrawal(User $agent, string|float $amount, string $idempotencyKey, ?string $description = null): Transaction
    {
        if (! $agent->isAgent()) {
            throw new UserNotAnAgentException;
        }

        if (! $agent->agentInfo || $agent->agentInfo->status !== 'APPROVED') {
            throw new AgentNotApprovedException;
        }

        $existing = Transaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $formattedAmount = number_format((float) $amount, 2, '.', '');

        try {
            return DB::transaction(function () use ($agent, $formattedAmount, $idempotencyKey, $description): Transaction {
                $existing = Transaction::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }

                $updatedWallet = $this->walletService->updateBalance($agent->id, $formattedAmount, 'DEBIT');

                return Transaction::create([
                    'user_id' => $agent->id,
                    'sender_id' => $agent->id,
                    'recipient_id' => null,
                    'agent_id' => $agent->id,
                    'initiated_by' => $agent->id,
                    'type' => 'AGENT_WITHDRAWAL',
                    'amount' => $formattedAmount,
                    'system_fee_amount' => '0.00',
                    'system_fee_rate' => '0.0000',
                    'agent_commission_amount' => '0.00',
                    'agent_commission_rate' => '0.0000',
                    'currency' => $updatedWallet->currency ?? 'BDT',
                    'description' => $description,
                    'status' => 'COMPLETED',
                    'idempotency_key' => $idempotencyKey,
                    'sender_wallet_balance_after' => $updatedWallet->balance,
                    'recipient_wallet_balance_after' => null,
                    'agent_wallet_balance_after' => null,
                    'meta' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $existing = Transaction::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Perform a cash-in deposit from an approved agent to a user.
     *
     * Zero fee, zero commission. Agent debited, user credited 1:1.
     *
     * @throws UserNotFoundException
     * @throws UserNotAnAgentException
     * @throws AgentNotApprovedException
     * @throws WalletNotFoundException
     * @throws WalletBlockedException
     * @throws InsufficientBalanceException
     */
    public function cashIn(User $user, int $agentId, string|float $amount, string $idempotencyKey, ?string $description = null): Transaction
    {
        $agent = User::with('agentInfo')->find($agentId);
        if (! $agent) {
            throw new UserNotFoundException('Agent user not found.');
        }

        if (! $agent->isAgent()) {
            throw new UserNotAnAgentException;
        }

        if (! $agent->agentInfo || $agent->agentInfo->status !== 'APPROVED') {
            throw new AgentNotApprovedException;
        }

        $existing = Transaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $formattedAmount = number_format((float) $amount, 2, '.', '');

        try {
            return DB::transaction(function () use ($user, $agent, $formattedAmount, $idempotencyKey, $description): Transaction {
                $existing = Transaction::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }

                // Deterministically lock both wallets ordered by user_id
                $wallets = $this->walletService->lockWalletsForUsers([$user->id, $agent->id])->keyBy('user_id');

                $userWallet = $wallets->get($user->id);
                $agentWallet = $wallets->get($agent->id);

                if (! $userWallet || ! $agentWallet) {
                    throw new WalletNotFoundException;
                }

                if ($userWallet->is_blocked || $agentWallet->is_blocked) {
                    throw new WalletBlockedException;
                }

                $agentBalance = number_format((float) $agentWallet->balance, 2, '.', '');
                if (bccomp($agentBalance, $formattedAmount, 2) === -1) {
                    throw new InsufficientBalanceException('Agent has insufficient wallet balance for cash-in.');
                }

                $userBalance = number_format((float) $userWallet->balance, 2, '.', '');
                $newUserBalance = bcadd($userBalance, $formattedAmount, 2);
                $userWallet->balance = $newUserBalance;
                $userWallet->save();

                $newAgentBalance = bcsub($agentBalance, $formattedAmount, 2);
                $agentWallet->balance = $newAgentBalance;
                $agentWallet->save();

                return Transaction::create([
                    'user_id' => $user->id,
                    'sender_id' => null,
                    'recipient_id' => $user->id,
                    'agent_id' => $agent->id,
                    'initiated_by' => $user->id,
                    'type' => 'CASH_IN',
                    'amount' => $formattedAmount,
                    'system_fee_amount' => '0.00',
                    'system_fee_rate' => '0.0000',
                    'agent_commission_amount' => '0.00',
                    'agent_commission_rate' => '0.0000',
                    'currency' => $userWallet->currency ?? 'BDT',
                    'description' => $description,
                    'status' => 'COMPLETED',
                    'idempotency_key' => $idempotencyKey,
                    'sender_wallet_balance_after' => null,
                    'recipient_wallet_balance_after' => $newUserBalance,
                    'agent_wallet_balance_after' => $newAgentBalance,
                    'meta' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $existing = Transaction::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Perform cash-out withdrawal via an approved agent.
     *
     * User pays system fee. Agent commission comes from system fee.
     * Writes CASH_OUT row and linked COMMISSION_PAYOUT row atomically.
     * Enforces daily/monthly transaction caps on the user.
     *
     * @throws UserNotFoundException
     * @throws UserNotAnAgentException
     * @throws AgentNotApprovedException
     * @throws WalletNotFoundException
     * @throws WalletBlockedException
     * @throws InsufficientBalanceException
     */
    public function cashOut(User $user, int $agentId, string|float $amount, string $idempotencyKey, ?string $description = null): Transaction
    {
        $agent = User::with('agentInfo')->find($agentId);
        if (! $agent) {
            throw new UserNotFoundException('Agent user not found.');
        }

        if (! $agent->isAgent()) {
            throw new UserNotAnAgentException;
        }

        if (! $agent->agentInfo || $agent->agentInfo->status !== 'APPROVED') {
            throw new AgentNotApprovedException;
        }

        $existing = Transaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $formattedAmount = number_format((float) $amount, 2, '.', '');

        $fee = $this->feeCalculator->calculate($formattedAmount);
        $commission = $this->commissionCalculator->calculate($formattedAmount, $agent->agentInfo, $fee['amount']);

        $totalUserDebit = bcadd($formattedAmount, $fee['amount'], 2);
        $totalAgentCredit = bcadd($formattedAmount, $commission['amount'], 2);

        try {
            return DB::transaction(function () use (
                $user,
                $agent,
                $formattedAmount,
                $fee,
                $commission,
                $totalUserDebit,
                $totalAgentCredit,
                $idempotencyKey,
                $description,
            ): Transaction {
                $existing = Transaction::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }

                // 1. Lock user's Cap row inside transaction
                $lockedCap = Cap::where('user_id', $user->id)->lockForUpdate()->first();
                if (! $lockedCap) {
                    $lockedCap = Cap::factory()->for($user)->create();
                    $lockedCap = Cap::where('id', $lockedCap->id)->lockForUpdate()->first();
                }

                // 2. Assert within caps (throws DailyCapsExceededException or MonthlyCapsExceededException)
                $this->capService->assertWithinCaps($lockedCap, $formattedAmount);

                // 3. Deterministically lock wallets ordered by user_id
                $wallets = $this->walletService->lockWalletsForUsers([$user->id, $agent->id])->keyBy('user_id');

                $userWallet = $wallets->get($user->id);
                $agentWallet = $wallets->get($agent->id);

                if (! $userWallet || ! $agentWallet) {
                    throw new WalletNotFoundException;
                }

                if ($userWallet->is_blocked || $agentWallet->is_blocked) {
                    throw new WalletBlockedException;
                }

                // 4. Lock agent_info row (strict ordering: Cap -> Wallets -> AgentInfo)
                $lockedAgentInfo = AgentInfo::where('user_id', $agent->id)->lockForUpdate()->first();
                if (! $lockedAgentInfo || $lockedAgentInfo->status !== 'APPROVED') {
                    throw new AgentNotApprovedException;
                }

                // 5. Verify user has enough balance for principal + system fee
                $userBalance = number_format((float) $userWallet->balance, 2, '.', '');
                if (bccomp($userBalance, $totalUserDebit, 2) === -1) {
                    throw new InsufficientBalanceException('Insufficient wallet balance to cover withdrawal amount and fee.');
                }

                // 6. Mutate balances
                $newUserBalance = bcsub($userBalance, $totalUserDebit, 2);
                $userWallet->balance = $newUserBalance;
                $userWallet->save();

                $agentBalance = number_format((float) $agentWallet->balance, 2, '.', '');
                $newAgentBalance = bcadd($agentBalance, $totalAgentCredit, 2);
                $agentWallet->balance = $newAgentBalance;
                $agentWallet->save();

                // 7. Increment cap usage
                $this->capService->increment($lockedCap, $formattedAmount);

                // 8. Update agent cumulative commission using locked instance
                $lockedAgentInfo->total_commission = bcadd(
                    number_format((float) $lockedAgentInfo->total_commission, 2, '.', ''),
                    $commission['amount'],
                    2
                );
                $lockedAgentInfo->save();

                // 8. Write CASH_OUT row
                $cashOut = Transaction::create([
                    'user_id' => $user->id,
                    'sender_id' => $user->id,
                    'recipient_id' => null,
                    'agent_id' => $agent->id,
                    'initiated_by' => $user->id,
                    'type' => 'CASH_OUT',
                    'amount' => $formattedAmount,
                    'system_fee_amount' => $fee['amount'],
                    'system_fee_rate' => $fee['rate'],
                    'agent_commission_amount' => $commission['amount'],
                    'agent_commission_rate' => $commission['rate'],
                    'currency' => $userWallet->currency ?? 'BDT',
                    'description' => $description,
                    'status' => 'COMPLETED',
                    'idempotency_key' => $idempotencyKey,
                    'sender_wallet_balance_after' => $newUserBalance,
                    'recipient_wallet_balance_after' => null,
                    'agent_wallet_balance_after' => $newAgentBalance,
                    'meta' => null,
                ]);

                // 9. Write linked COMMISSION_PAYOUT row
                Transaction::create([
                    'user_id' => null,
                    'sender_id' => null,
                    'recipient_id' => $agent->id,
                    'agent_id' => $agent->id,
                    'initiated_by' => $user->id,
                    'type' => 'COMMISSION_PAYOUT',
                    'amount' => $commission['amount'],
                    'system_fee_amount' => '0.00',
                    'system_fee_rate' => '0.0000',
                    'agent_commission_amount' => '0.00',
                    'agent_commission_rate' => '0.0000',
                    'currency' => $agentWallet->currency ?? 'BDT',
                    'description' => "Commission payout for cash-out #{$cashOut->id}",
                    'status' => 'COMPLETED',
                    'idempotency_key' => "commission-payout-{$idempotencyKey}",
                    'sender_wallet_balance_after' => null,
                    'recipient_wallet_balance_after' => $newAgentBalance,
                    'agent_wallet_balance_after' => $newAgentBalance,
                    'meta' => [
                        'related_transaction_id' => $cashOut->id,
                    ],
                ]);

                return $cashOut;
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $existing = Transaction::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Transfer funds between two users.
     *
     * @throws UserNotFoundException
     * @throws InvalidRecipientException
     * @throws WalletNotFoundException
     * @throws WalletBlockedException
     * @throws InsufficientBalanceException
     * @throws DailyCapsExceededException
     * @throws MonthlyCapsExceededException
     */
    public function transfer(User $sender, int $recipientId, string|float $amount, string $idempotencyKey, ?string $description = null): Transaction
    {
        $recipient = User::find($recipientId);
        if (! $recipient) {
            throw new UserNotFoundException('Recipient user not found.');
        }

        if ($recipient->id === $sender->id) {
            throw new InvalidRecipientException('You cannot transfer money to yourself.');
        }

        if (! $recipient->isUser()) {
            throw new InvalidRecipientException('Recipient must have a user account.');
        }

        $existing = Transaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $formattedAmount = number_format((float) $amount, 2, '.', '');

        try {
            return DB::transaction(function () use (
                $sender,
                $recipient,
                $formattedAmount,
                $idempotencyKey,
                $description,
            ): Transaction {
                $existing = Transaction::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }

                // 1. Lock sender's Cap row inside transaction (strict table lock order: Cap -> Wallets)
                $lockedCap = Cap::where('user_id', $sender->id)->lockForUpdate()->first();
                if (! $lockedCap) {
                    $lockedCap = Cap::factory()->for($sender)->create();
                    $lockedCap = Cap::where('id', $lockedCap->id)->lockForUpdate()->first();
                }

                // 2. Assert within caps (sender outward caps)
                $this->capService->assertWithinCaps($lockedCap, $formattedAmount);

                // 3. Deterministically lock wallets ordered by user_id
                $wallets = $this->walletService->lockWalletsForUsers([$sender->id, $recipient->id])->keyBy('user_id');

                $senderWallet = $wallets->get($sender->id);
                $recipientWallet = $wallets->get($recipient->id);

                if (! $senderWallet || ! $recipientWallet) {
                    throw new WalletNotFoundException;
                }

                if ($senderWallet->is_blocked || $recipientWallet->is_blocked) {
                    throw new WalletBlockedException;
                }

                // 4. Verify sender balance >= amount
                $senderBalance = number_format((float) $senderWallet->balance, 2, '.', '');
                if (bccomp($senderBalance, $formattedAmount, 2) === -1) {
                    throw new InsufficientBalanceException('Insufficient wallet balance to complete transfer.');
                }

                // 5. Mutate balances 1:1 (zero fee, zero commission)
                $newSenderBalance = bcsub($senderBalance, $formattedAmount, 2);
                $senderWallet->balance = $newSenderBalance;
                $senderWallet->save();

                $recipientBalance = number_format((float) $recipientWallet->balance, 2, '.', '');
                $newRecipientBalance = bcadd($recipientBalance, $formattedAmount, 2);
                $recipientWallet->balance = $newRecipientBalance;
                $recipientWallet->save();

                // 6. Increment sender cap usage
                $this->capService->increment($lockedCap, $formattedAmount);

                // 7. Write TRANSFER transaction row
                return Transaction::create([
                    'user_id' => $sender->id,
                    'sender_id' => $sender->id,
                    'recipient_id' => $recipient->id,
                    'agent_id' => null,
                    'initiated_by' => $sender->id,
                    'type' => 'TRANSFER',
                    'amount' => $formattedAmount,
                    'system_fee_amount' => '0.00',
                    'system_fee_rate' => '0.0000',
                    'agent_commission_amount' => '0.00',
                    'agent_commission_rate' => '0.0000',
                    'currency' => $senderWallet->currency ?? 'BDT',
                    'description' => $description,
                    'status' => 'COMPLETED',
                    'idempotency_key' => $idempotencyKey,
                    'sender_wallet_balance_after' => $newSenderBalance,
                    'recipient_wallet_balance_after' => $newRecipientBalance,
                    'agent_wallet_balance_after' => null,
                    'meta' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $existing = Transaction::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Get transaction history for a user, filtered and paginated.
     */
    public function listUserHistory(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Transaction::query()->with(['sender', 'recipient', 'agent', 'user']);

        if (! ($user->isAdmin() && $user->tokenCan('admin'))) {
            $query->where(function (Builder $q) use ($user): void {
                $q->where('user_id', $user->id)
                    ->orWhere('sender_id', $user->id)
                    ->orWhere('recipient_id', $user->id)
                    ->orWhere('agent_id', $user->id)
                    ->orWhere('initiated_by', $user->id);
            });
        }

        $this->applyFilters($query, $filters);

        return $query->latest('id')->paginate($perPage);
    }

    /**
     * Get all transactions for administrative audit, filtered and paginated.
     */
    public function listAllTransactions(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Transaction::query()->with(['sender', 'recipient', 'agent', 'user']);

        $this->applyFilters($query, $filters);

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['sender_id'])) {
            $query->where('sender_id', (int) $filters['sender_id']);
        }

        if (! empty($filters['recipient_id'])) {
            $query->where('recipient_id', (int) $filters['recipient_id']);
        }

        if (! empty($filters['agent_id'])) {
            $query->where('agent_id', (int) $filters['agent_id']);
        }

        return $query->latest('id')->paginate($perPage);
    }

    /**
     * Apply common transaction filters (type, status, date range).
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['type'])) {
            $query->where('type', strtoupper((string) $filters['type']));
        }

        if (! empty($filters['status'])) {
            $query->where('status', strtoupper((string) $filters['status']));
        }

        $fromDate = $filters['from_date'] ?? $filters['date_from'] ?? null;
        if (! empty($fromDate)) {
            $query->where('created_at', '>=', Carbon::parse($fromDate)->startOfDay());
        }

        $toDate = $filters['to_date'] ?? $filters['date_to'] ?? null;
        if (! empty($toDate)) {
            $query->where('created_at', '<=', Carbon::parse($toDate)->endOfDay());
        }
    }
}
