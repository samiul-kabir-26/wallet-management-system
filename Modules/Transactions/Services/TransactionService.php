<?php

namespace Modules\Transactions\Services;

use App\Models\AgentInfo;
use App\Models\Cap;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Exceptions\UserNotFoundException;
use Modules\Authentication\Services\PinVerifier;
use Modules\Transactions\Exceptions\DailyCapsExceededException;
use Modules\Transactions\Exceptions\IdempotencyConflictException;
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
        protected PinVerifier $pinVerifier,
    ) {}

    /**
     * Find a transaction previously created by the given initiator under this idempotency key.
     *
     * Keys are scoped per initiator, so a key can never resolve to another user's transaction.
     */
    private function findByIdempotencyKey(User $initiator, string $idempotencyKey, bool $lock = false): ?Transaction
    {
        $query = Transaction::where('initiated_by', $initiator->id)
            ->where('idempotency_key', $idempotencyKey);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Assert that the existing transaction found by idempotency key matches request parameters.
     *
     * @throws IdempotencyConflictException
     */
    private function assertIdempotentParametersMatch(
        Transaction $existing,
        string $expectedType,
        string $expectedAmount,
        ?int $expectedRecipientId = null,
        ?int $expectedAgentId = null,
    ): void {
        $amountMatches = bccomp((string) $existing->amount, (string) $expectedAmount, 2) === 0;
        $typeMatches = $existing->type === $expectedType;

        $counterpartyMatches = true;
        if ($expectedRecipientId !== null) {
            $counterpartyMatches = (int) $existing->recipient_id === (int) $expectedRecipientId;
        }
        if ($expectedAgentId !== null) {
            $counterpartyMatches = $counterpartyMatches && (int) $existing->agent_id === (int) $expectedAgentId;
        }

        if (! $typeMatches || ! $amountMatches || ! $counterpartyMatches) {
            throw new IdempotencyConflictException('Idempotency key reused with different parameters.');
        }
    }

    /**
     * Top-up user's own wallet balance.
     */
    public function topUp(User $user, string|float $amount, string $idempotencyKey, ?string $description = null): Transaction
    {
        $formattedAmount = number_format((float) $amount, 2, '.', '');

        $existing = $this->findByIdempotencyKey($user, $idempotencyKey);
        if ($existing) {
            $this->assertIdempotentParametersMatch($existing, 'TOP_UP', $formattedAmount);

            return $existing;
        }

        try {
            return DB::transaction(function () use ($user, $formattedAmount, $idempotencyKey, $description): Transaction {
                $existing = $this->findByIdempotencyKey($user, $idempotencyKey, lock: true);
                if ($existing) {
                    $this->assertIdempotentParametersMatch($existing, 'TOP_UP', $formattedAmount);

                    return $existing;
                }

                $updatedWallet = $this->walletService->updateBalance($user->id, $formattedAmount, 'CREDIT', Wallet::KIND_USER);

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
                    'sender_wallet_id' => null,
                    'recipient_wallet_id' => $updatedWallet->id,
                    'agent_wallet_id' => null,
                    'meta' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $existing = $this->findByIdempotencyKey($user, $idempotencyKey);
            if ($existing) {
                $this->assertIdempotentParametersMatch($existing, 'TOP_UP', $formattedAmount);

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
    public function agentWithdrawal(User $agent, string|float $amount, string $idempotencyKey, string $pin, ?string $description = null): Transaction
    {
        if (! $agent->isAgent()) {
            throw new UserNotAnAgentException;
        }

        if (! $agent->agentInfo || $agent->agentInfo->status !== 'APPROVED') {
            throw new AgentNotApprovedException;
        }

        $formattedAmount = number_format((float) $amount, 2, '.', '');

        $existing = $this->findByIdempotencyKey($agent, $idempotencyKey);
        if ($existing) {
            $this->assertIdempotentParametersMatch($existing, 'AGENT_WITHDRAWAL', $formattedAmount);

            return $existing;
        }

        $this->pinVerifier->verify($agent, $pin);

        try {
            return DB::transaction(function () use ($agent, $formattedAmount, $idempotencyKey, $description): Transaction {
                $existing = $this->findByIdempotencyKey($agent, $idempotencyKey, lock: true);
                if ($existing) {
                    $this->assertIdempotentParametersMatch($existing, 'AGENT_WITHDRAWAL', $formattedAmount);

                    return $existing;
                }

                $updatedWallet = $this->walletService->updateBalance($agent->id, $formattedAmount, 'DEBIT', Wallet::KIND_AGENT);

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
                    'sender_wallet_id' => $updatedWallet->id,
                    'recipient_wallet_id' => null,
                    'agent_wallet_id' => null,
                    'meta' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $existing = $this->findByIdempotencyKey($agent, $idempotencyKey);
            if ($existing) {
                $this->assertIdempotentParametersMatch($existing, 'AGENT_WITHDRAWAL', $formattedAmount);

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
    public function cashIn(User $user, string $agentPhone, string|float $amount, string $idempotencyKey, ?string $description = null): Transaction
    {
        $agent = User::with('agentInfo')->where('phone_number', $agentPhone)->first();
        if (! $agent) {
            throw new UserNotFoundException('Agent user not found.');
        }

        if (! $agent->isAgent()) {
            throw new UserNotAnAgentException;
        }

        if (! $agent->agentInfo || $agent->agentInfo->status !== 'APPROVED') {
            throw new AgentNotApprovedException;
        }

        $formattedAmount = number_format((float) $amount, 2, '.', '');

        $existing = $this->findByIdempotencyKey($user, $idempotencyKey);
        if ($existing) {
            $this->assertIdempotentParametersMatch($existing, 'CASH_IN', $formattedAmount, expectedAgentId: $agent->id);

            return $existing;
        }

        try {
            return DB::transaction(function () use ($user, $agent, $formattedAmount, $idempotencyKey, $description): Transaction {
                $existing = $this->findByIdempotencyKey($user, $idempotencyKey, lock: true);
                if ($existing) {
                    $this->assertIdempotentParametersMatch($existing, 'CASH_IN', $formattedAmount, expectedAgentId: $agent->id);

                    return $existing;
                }

                // Deterministically lock both wallets ordered by wallet id
                $wallets = $this->walletService->lockWalletsForUsers([
                    ['user_id' => $user->id, 'kind' => Wallet::KIND_USER],
                    ['user_id' => $agent->id, 'kind' => Wallet::KIND_AGENT],
                ]);

                $userWallet = $wallets->first(fn ($w) => (int) $w->user_id === (int) $user->id && $w->kind === Wallet::KIND_USER);
                $agentWallet = $wallets->first(fn ($w) => (int) $w->user_id === (int) $agent->id && $w->kind === Wallet::KIND_AGENT);

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
                    'sender_wallet_id' => null,
                    'recipient_wallet_id' => $userWallet->id,
                    'agent_wallet_id' => $agentWallet->id,
                    'meta' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $existing = $this->findByIdempotencyKey($user, $idempotencyKey);
            if ($existing) {
                $this->assertIdempotentParametersMatch($existing, 'CASH_IN', $formattedAmount, expectedAgentId: $agent->id);

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
    public function cashOut(User $user, string $agentPhone, string|float $amount, string $idempotencyKey, string $pin, ?string $description = null): Transaction
    {
        $agent = User::with('agentInfo')->where('phone_number', $agentPhone)->first();
        if (! $agent) {
            throw new UserNotFoundException('Agent user not found.');
        }

        if (! $agent->isAgent()) {
            throw new UserNotAnAgentException;
        }

        if (! $agent->agentInfo || $agent->agentInfo->status !== 'APPROVED') {
            throw new AgentNotApprovedException;
        }

        $formattedAmount = number_format((float) $amount, 2, '.', '');

        $existing = $this->findByIdempotencyKey($user, $idempotencyKey);
        if ($existing) {
            $this->assertIdempotentParametersMatch($existing, 'CASH_OUT', $formattedAmount, expectedAgentId: $agent->id);

            return $existing;
        }

        $this->pinVerifier->verify($user, $pin);

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
                $existing = $this->findByIdempotencyKey($user, $idempotencyKey, lock: true);
                if ($existing) {
                    $this->assertIdempotentParametersMatch($existing, 'CASH_OUT', $formattedAmount, expectedAgentId: $agent->id);

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

                // 3. Deterministically lock wallets ordered by wallet id
                $wallets = $this->walletService->lockWalletsForUsers([
                    ['user_id' => $user->id, 'kind' => Wallet::KIND_USER],
                    ['user_id' => $agent->id, 'kind' => Wallet::KIND_AGENT],
                ]);

                $userWallet = $wallets->first(fn ($w) => (int) $w->user_id === (int) $user->id && $w->kind === Wallet::KIND_USER);
                $agentWallet = $wallets->first(fn ($w) => (int) $w->user_id === (int) $agent->id && $w->kind === Wallet::KIND_AGENT);

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
                    'sender_wallet_id' => $userWallet->id,
                    'recipient_wallet_id' => null,
                    'agent_wallet_id' => $agentWallet->id,
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
                    'sender_wallet_id' => null,
                    'recipient_wallet_id' => $agentWallet->id,
                    'agent_wallet_id' => $agentWallet->id,
                    'meta' => [
                        'related_transaction_id' => $cashOut->id,
                    ],
                ]);

                return $cashOut;
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $existing = $this->findByIdempotencyKey($user, $idempotencyKey);
            if ($existing) {
                $this->assertIdempotentParametersMatch($existing, 'CASH_OUT', $formattedAmount, expectedAgentId: $agent->id);

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
    public function transfer(User $sender, string $recipientPhone, string|float $amount, string $idempotencyKey, string $pin, ?string $description = null): Transaction
    {
        $recipient = User::where('phone_number', $recipientPhone)->first();
        if (! $recipient || $recipient->is_deleted) {
            throw new UserNotFoundException('Recipient user not found.');
        }

        if (! $recipient->canReceiveTransfers($sender)) {
            if ($recipient->id === $sender->id) {
                throw new InvalidRecipientException('You cannot transfer money to yourself.');
            }

            if (! $recipient->isUser()) {
                throw new InvalidRecipientException('Recipient must have a user account.');
            }

            if (! $recipient->userWallet || $recipient->userWallet->is_blocked) {
                throw new WalletBlockedException;
            }

            throw new InvalidRecipientException('Recipient cannot receive transfers.');
        }

        $formattedAmount = number_format((float) $amount, 2, '.', '');

        $existing = $this->findByIdempotencyKey($sender, $idempotencyKey);
        if ($existing) {
            $this->assertIdempotentParametersMatch($existing, 'TRANSFER', $formattedAmount, expectedRecipientId: $recipient->id);

            return $existing;
        }

        $this->pinVerifier->verify($sender, $pin);

        try {
            return DB::transaction(function () use (
                $sender,
                $recipient,
                $formattedAmount,
                $idempotencyKey,
                $description,
            ): Transaction {
                $existing = $this->findByIdempotencyKey($sender, $idempotencyKey, lock: true);
                if ($existing) {
                    $this->assertIdempotentParametersMatch($existing, 'TRANSFER', $formattedAmount, expectedRecipientId: $recipient->id);

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

                // 3. Deterministically lock wallets ordered by wallet id
                $wallets = $this->walletService->lockWalletsForUsers([
                    ['user_id' => $sender->id, 'kind' => Wallet::KIND_USER],
                    ['user_id' => $recipient->id, 'kind' => Wallet::KIND_USER],
                ]);

                $senderWallet = $wallets->first(fn ($w) => (int) $w->user_id === (int) $sender->id && $w->kind === Wallet::KIND_USER);
                $recipientWallet = $wallets->first(fn ($w) => (int) $w->user_id === (int) $recipient->id && $w->kind === Wallet::KIND_USER);

                if (! $senderWallet) {
                    throw new WalletNotFoundException;
                }

                if (! $recipientWallet) {
                    throw new InvalidRecipientException('Recipient does not have a user wallet.');
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
                    'sender_wallet_id' => $senderWallet->id,
                    'recipient_wallet_id' => $recipientWallet->id,
                    'agent_wallet_id' => null,
                    'meta' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $existing = $this->findByIdempotencyKey($sender, $idempotencyKey);
            if ($existing) {
                $this->assertIdempotentParametersMatch($existing, 'TRANSFER', $formattedAmount, expectedRecipientId: $recipient->id);

                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Get transaction history for a specific wallet, filtered and paginated.
     */
    public function listUserHistory(
        ?Wallet $wallet = null,
        array $filters = [],
        int $perPage = 15,
    ): LengthAwarePaginator {
        $query = Transaction::query()->with(['sender', 'recipient', 'agent', 'user']);

        if ($wallet !== null) {
            $query->involvingWallet($wallet);
        } else {
            $query->whereRaw('1 = 0');
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
