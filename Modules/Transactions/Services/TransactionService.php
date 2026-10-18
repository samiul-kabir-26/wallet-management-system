<?php

namespace Modules\Transactions\Services;

use App\Models\AgentInfo;
use App\Models\AuditLog;
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
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Authentication\Exceptions\UserNotFoundException;
use Modules\Authentication\Services\PinVerifier;
use Modules\Transactions\Exceptions\DailyCapsExceededException;
use Modules\Transactions\Exceptions\IdempotencyConflictException;
use Modules\Transactions\Exceptions\InvalidAmountException;
use Modules\Transactions\Exceptions\InvalidRecipientException;
use Modules\Transactions\Exceptions\MonthlyCapsExceededException;
use Modules\Transactions\Exceptions\WithdrawalNotPendingException;
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
        protected TransactionReceiptService $receipts,
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
        ?string $expectedExternalReference = null,
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
        if ($expectedExternalReference !== null) {
            $counterpartyMatches = $counterpartyMatches && $existing->external_reference === $expectedExternalReference;
        }

        if (! $typeMatches || ! $amountMatches || ! $counterpartyMatches) {
            throw new IdempotencyConflictException('Idempotency key reused with different parameters.');
        }
    }

    /**
     * Guard that the formatted transaction amount is strictly greater than 0.00.
     *
     * @throws InvalidAmountException
     */
    protected function assertValidAmount(string $formattedAmount): void
    {
        if (bccomp($formattedAmount, '0.00', 2) <= 0) {
            throw new InvalidAmountException('Transaction amount must be greater than 0.00.');
        }
    }

    /**
     * Credit the user's own wallet immediately, with no payment behind it.
     *
     * Only used by the "simulated" top-up gateway (tests and offline demos). Real top-ups go through
     * createPendingTopUp() and completePendingTopUp(). No PIN is asked: a top-up only brings money in.
     */
    public function topUp(User $user, string|float $amount, string $idempotencyKey, ?string $description = null): Transaction
    {
        $formattedAmount = number_format((float) $amount, 2, '.', '');
        $this->assertValidAmount($formattedAmount);

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
     * Record a top-up the customer is about to pay through a payment gateway. Nothing is credited yet.
     *
     * The row is PENDING with no balance effect, and the gateway reference is stored in
     * external_reference (unique), so a payment can only ever settle one row. Replaying the same
     * idempotency key returns the existing row.
     *
     * @throws WalletNotFoundException
     * @throws WalletBlockedException
     * @throws IdempotencyConflictException
     */
    public function createPendingTopUp(
        User $user,
        string|float $amount,
        string $idempotencyKey,
        string $gatewayReference,
        string $gateway,
        ?string $description = null,
    ): Transaction {
        $formattedAmount = number_format((float) $amount, 2, '.', '');
        $this->assertValidAmount($formattedAmount);

        $existing = $this->findByIdempotencyKey($user, $idempotencyKey);
        if ($existing) {
            $this->assertIdempotentParametersMatch($existing, 'TOP_UP', $formattedAmount);

            return $existing;
        }

        // Refuse up front: a blocked wallet cannot receive, so the customer must not be sent to pay
        $wallet = Wallet::where('user_id', $user->id)->where('kind', Wallet::KIND_USER)->first();
        if (! $wallet) {
            throw new WalletNotFoundException;
        }
        if ($wallet->is_blocked) {
            throw new WalletBlockedException;
        }

        try {
            return Transaction::create([
                'user_id' => $user->id,
                'sender_id' => null,
                'recipient_id' => $user->id,
                'agent_id' => null,
                'initiated_by' => $user->id,
                'type' => 'TOP_UP',
                'external_reference' => $gatewayReference,
                'amount' => $formattedAmount,
                'system_fee_amount' => '0.00',
                'system_fee_rate' => '0.0000',
                'agent_commission_amount' => '0.00',
                'agent_commission_rate' => '0.0000',
                'currency' => $wallet->currency ?? 'BDT',
                'description' => $description,
                'status' => 'PENDING',
                'idempotency_key' => $idempotencyKey,
                'sender_wallet_balance_after' => null,
                'recipient_wallet_balance_after' => null,
                'agent_wallet_balance_after' => null,
                'sender_wallet_id' => null,
                'recipient_wallet_id' => $wallet->id,
                'agent_wallet_id' => null,
                'meta' => ['gateway' => $gateway],
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->findByIdempotencyKey($user, $idempotencyKey);
            if ($existing) {
                $this->assertIdempotentParametersMatch($existing, 'TOP_UP', $formattedAmount);

                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Credit a gateway top-up whose payment has been confirmed by the gateway.
     *
     * Safe to call any number of times and from concurrent callbacks: the row is locked and a
     * COMPLETED top-up is returned unchanged, so the wallet is credited exactly once. A FAILED row is
     * still credited, because a confirmed payment outranks an earlier failure signal (e.g. the
     * customer retried on the gateway page after a decline). If the wallet was blocked while the
     * customer was paying, the money is held instead: the row stays PENDING and is flagged for staff.
     *
     * @param  array<string, mixed>  $payment  Gateway details to keep in meta.payment.
     *
     * @throws WalletNotFoundException
     */
    public function completePendingTopUp(Transaction $topUp, array $payment): Transaction
    {
        return DB::transaction(function () use ($topUp, $payment): Transaction {
            $locked = $this->lockGatewayTopUp($topUp);

            if ($locked->status === 'COMPLETED') {
                return $locked;
            }

            $wallet = Wallet::whereKey($locked->recipient_wallet_id)->lockForUpdate()->first();
            if (! $wallet) {
                throw new WalletNotFoundException;
            }

            if ($wallet->is_blocked) {
                return $this->putTopUpOnHold($locked, 'WALLET_BLOCKED', $payment);
            }

            $balanceBefore = number_format((float) $wallet->balance, 2, '.', '');
            $balanceAfter = bcadd($balanceBefore, number_format((float) $locked->amount, 2, '.', ''), 2);
            $wallet->balance = $balanceAfter;
            $wallet->save();

            $meta = $locked->meta ?? [];
            unset($meta['on_hold']);

            $locked->forceFill([
                'status' => 'COMPLETED',
                'recipient_wallet_balance_after' => $balanceAfter,
                'meta' => [...$meta, 'payment' => $payment],
            ])->save();

            return $locked;
        });
    }

    /**
     * Keep a paid gateway top-up uncredited for staff review (amount mismatch, high-risk payment,
     * blocked wallet). The customer has paid, so the row must never be marked FAILED from here on.
     *
     * @param  array<string, mixed>  $payment
     */
    public function holdPendingTopUp(Transaction $topUp, string $reason, array $payment): Transaction
    {
        return DB::transaction(function () use ($topUp, $reason, $payment): Transaction {
            $locked = $this->lockGatewayTopUp($topUp);

            if ($locked->status === 'COMPLETED') {
                return $locked;
            }

            return $this->putTopUpOnHold($locked, $reason, $payment);
        });
    }

    /**
     * Mark an unpaid gateway top-up as FAILED. Rows that are completed or on hold (paid) are left alone.
     *
     * @param  array<string, mixed>  $details
     */
    public function failPendingTopUp(Transaction $topUp, string $reason, array $details = []): Transaction
    {
        return DB::transaction(function () use ($topUp, $reason, $details): Transaction {
            $locked = $this->lockGatewayTopUp($topUp);

            if ($locked->status !== 'PENDING' || isset($locked->meta['on_hold'])) {
                return $locked;
            }

            $locked->forceFill([
                'status' => 'FAILED',
                'meta' => [
                    ...($locked->meta ?? []),
                    'failure' => [
                        'reason' => $reason,
                        'failed_at' => now()->toIso8601String(),
                        ...$details,
                    ],
                ],
            ])->save();

            return $locked;
        });
    }

    /**
     * Lock a gateway top-up row for a status change.
     */
    private function lockGatewayTopUp(Transaction $topUp): Transaction
    {
        $locked = Transaction::whereKey($topUp->id)->lockForUpdate()->firstOrFail();

        if ($locked->type !== 'TOP_UP' || $locked->external_reference === null) {
            throw new InvalidArgumentException('Only gateway top-ups can be settled.');
        }

        return $locked;
    }

    /**
     * Flag a locked top-up as paid but not credited, auditing the first time each reason is raised.
     *
     * @param  array<string, mixed>  $payment
     */
    private function putTopUpOnHold(Transaction $locked, string $reason, array $payment): Transaction
    {
        $previousReason = $locked->meta['on_hold']['reason'] ?? null;

        $locked->forceFill([
            'status' => 'PENDING',
            'meta' => [
                ...($locked->meta ?? []),
                'payment' => $payment,
                'on_hold' => [
                    'reason' => $reason,
                    'held_at' => now()->toIso8601String(),
                ],
            ],
        ])->save();

        if ($previousReason !== $reason) {
            AuditLog::create([
                'actor_id' => null,
                'action' => 'TOP_UP_ON_HOLD',
                'auditable_type' => (new User)->getMorphClass(),
                'auditable_id' => $locked->user_id,
                'metadata' => [
                    'transaction_id' => $locked->id,
                    'amount' => $locked->amount,
                    'reason' => $reason,
                    'external_reference' => $locked->external_reference,
                ],
            ]);
        }

        return $locked;
    }

    /**
     * Agent requests a bank withdrawal from their own wallet.
     *
     * The amount is debited immediately so it cannot be spent twice, and the transaction stays
     * PENDING until staff approve (bank payout sent) or reject (funds returned) it.
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
        $this->assertValidAmount($formattedAmount);

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
                    // Funds are held (already debited) until staff confirm the bank payout
                    'status' => 'PENDING',
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
    public function cashIn(User $agent, string $customerPhone, string|float $amount, string $idempotencyKey, string $pin, ?string $description = null): Transaction
    {
        if (! $agent->isAgent()) {
            throw new UserNotAnAgentException;
        }

        if (! $agent->agentInfo || $agent->agentInfo->status !== 'APPROVED') {
            throw new AgentNotApprovedException;
        }

        $customer = User::where('phone_number', $customerPhone)->where('is_deleted', false)->first();
        if (! $customer) {
            throw new UserNotFoundException('Customer user not found.');
        }

        if ($customer->id === $agent->id) {
            throw new InvalidRecipientException('You cannot cash-in to your own account.');
        }

        if (! $customer->isUser()) {
            throw new InvalidRecipientException('Customer must have a user account.');
        }

        $formattedAmount = number_format((float) $amount, 2, '.', '');
        $this->assertValidAmount($formattedAmount);

        $existing = $this->findByIdempotencyKey($agent, $idempotencyKey);
        if ($existing) {
            $this->assertIdempotentParametersMatch($existing, 'CASH_IN', $formattedAmount, expectedRecipientId: $customer->id, expectedAgentId: $agent->id);

            return $existing;
        }

        $this->pinVerifier->verify($agent, $pin);

        try {
            return DB::transaction(function () use ($agent, $customer, $formattedAmount, $idempotencyKey, $description): Transaction {
                $existing = $this->findByIdempotencyKey($agent, $idempotencyKey, lock: true);
                if ($existing) {
                    $this->assertIdempotentParametersMatch($existing, 'CASH_IN', $formattedAmount, expectedRecipientId: $customer->id, expectedAgentId: $agent->id);

                    return $existing;
                }

                // Deterministically lock both wallets ordered by wallet id
                $wallets = $this->walletService->lockWalletsForUsers([
                    ['user_id' => $customer->id, 'kind' => Wallet::KIND_USER],
                    ['user_id' => $agent->id, 'kind' => Wallet::KIND_AGENT],
                ]);

                $customerWallet = $wallets->first(fn ($w) => (int) $w->user_id === (int) $customer->id && $w->kind === Wallet::KIND_USER);
                $agentWallet = $wallets->first(fn ($w) => (int) $w->user_id === (int) $agent->id && $w->kind === Wallet::KIND_AGENT);

                if (! $customerWallet || ! $agentWallet) {
                    throw new WalletNotFoundException;
                }

                if ($customerWallet->is_blocked || $agentWallet->is_blocked) {
                    throw new WalletBlockedException;
                }

                $agentBalance = number_format((float) $agentWallet->balance, 2, '.', '');
                if (bccomp($agentBalance, $formattedAmount, 2) === -1) {
                    throw new InsufficientBalanceException('Agent has insufficient float balance for cash-in.');
                }

                $customerBalance = number_format((float) $customerWallet->balance, 2, '.', '');
                $newCustomerBalance = bcadd($customerBalance, $formattedAmount, 2);
                $customerWallet->balance = $newCustomerBalance;
                $customerWallet->save();

                $newAgentBalance = bcsub($agentBalance, $formattedAmount, 2);
                $agentWallet->balance = $newAgentBalance;
                $agentWallet->save();

                $cashIn = Transaction::create([
                    'user_id' => $customer->id,
                    'sender_id' => null,
                    'recipient_id' => $customer->id,
                    'agent_id' => $agent->id,
                    'initiated_by' => $agent->id,
                    'type' => 'CASH_IN',
                    'amount' => $formattedAmount,
                    'system_fee_amount' => '0.00',
                    'system_fee_rate' => '0.0000',
                    'agent_commission_amount' => '0.00',
                    'agent_commission_rate' => '0.0000',
                    'currency' => $customerWallet->currency ?? 'BDT',
                    'description' => $description,
                    'status' => 'COMPLETED',
                    'idempotency_key' => $idempotencyKey,
                    'sender_wallet_balance_after' => null,
                    'recipient_wallet_balance_after' => $newCustomerBalance,
                    'agent_wallet_balance_after' => $newAgentBalance,
                    'sender_wallet_id' => null,
                    'recipient_wallet_id' => $customerWallet->id,
                    'agent_wallet_id' => $agentWallet->id,
                    'meta' => null,
                ]);

                $this->receipts->queueFor($cashIn);

                return $cashIn;
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $existing = $this->findByIdempotencyKey($agent, $idempotencyKey);
            if ($existing) {
                $this->assertIdempotentParametersMatch($existing, 'CASH_IN', $formattedAmount, expectedRecipientId: $customer->id, expectedAgentId: $agent->id);

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
        $agent = User::with('agentInfo')->where('phone_number', $agentPhone)->where('is_deleted', false)->first();
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
        $this->assertValidAmount($formattedAmount);

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
                    $createdCap = $user->cap()->create();
                    $lockedCap = Cap::where('id', $createdCap->id)->lockForUpdate()->first();
                }

                // Roll counters over if the business day or month changed since they were last anchored
                $this->capService->resetStalePeriods($lockedCap);

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

                $this->receipts->queueFor($cashOut);

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
        $recipient = User::where('phone_number', $recipientPhone)->where('is_deleted', false)->first();
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
        $this->assertValidAmount($formattedAmount);

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
                    $createdCap = $sender->cap()->create();
                    $lockedCap = Cap::where('id', $createdCap->id)->lockForUpdate()->first();
                }

                // Roll counters over if the business day or month changed since they were last anchored
                $this->capService->resetStalePeriods($lockedCap);

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
                $transfer = Transaction::create([
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

                $this->receipts->queueFor($transfer);

                return $transfer;
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
        $query = Transaction::query()->with(['sender', 'recipient', 'agent', 'user', 'reversal:id,reversed_transaction_id']);

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
        return $this->adminLedgerQuery($filters)
            ->with(['sender', 'recipient', 'agent', 'user', 'reversal:id,reversed_transaction_id'])
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * The admin ledger with its filters applied, shared by the paginated list and the CSV export so
     * both always show the same rows.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    public function adminLedgerQuery(array $filters = []): Builder
    {
        $query = Transaction::query();

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

        if (! empty($filters['party_id'])) {
            $partyId = (int) $filters['party_id'];
            $query->where(fn (Builder $q) => $q
                ->where('user_id', $partyId)
                ->orWhere('sender_id', $partyId)
                ->orWhere('recipient_id', $partyId)
                ->orWhere('agent_id', $partyId));
        }

        if (! empty($filters['search'])) {
            $this->applySearch($query, trim((string) $filters['search']));
        }

        return $query;
    }

    /**
     * Match a free-text term against the transaction ID, external reference, and the name,
     * phone number or email of any party to the transaction.
     */
    protected function applySearch(Builder $query, string $term): void
    {
        $like = '%'.addcslashes($term, '%_\\').'%';
        $transactionId = ltrim($term, '#');

        $matchingUserIds = User::query()
            ->select('id')
            ->where(fn (Builder $q) => $q
                ->where('name', 'like', $like)
                ->orWhere('phone_number', 'like', $like)
                ->orWhere('email', 'like', $like));

        $query->where(function (Builder $q) use ($like, $transactionId, $matchingUserIds): void {
            $q->whereIn('user_id', $matchingUserIds)
                ->orWhereIn('sender_id', $matchingUserIds)
                ->orWhereIn('recipient_id', $matchingUserIds)
                ->orWhereIn('agent_id', $matchingUserIds)
                ->orWhere('external_reference', 'like', $like);

            if (ctype_digit($transactionId)) {
                $q->orWhere('id', (int) $transactionId);
            }
        });
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

    /**
     * Issue e-money float to an approved agent by an authorized admin.
     *
     * @throws InvalidRecipientException
     * @throws UserNotAnAgentException
     * @throws AgentNotApprovedException
     * @throws WalletNotFoundException
     * @throws WalletBlockedException
     * @throws InvalidAmountException
     * @throws IdempotencyConflictException
     */
    public function issueFloat(
        User $admin,
        User $agent,
        string|float $amount,
        string $externalReference,
        string $idempotencyKey,
        ?string $description = null
    ): Transaction {
        if ($admin->id === $agent->id) {
            throw new InvalidRecipientException('Cannot issue float to yourself.');
        }

        if ($agent->is_deleted) {
            throw new UserNotFoundException('Agent user not found.');
        }

        if (! $agent->isAgent()) {
            throw new UserNotAnAgentException;
        }

        if (! $agent->agentInfo || $agent->agentInfo->status !== 'APPROVED') {
            throw new AgentNotApprovedException;
        }

        $formattedAmount = number_format((float) $amount, 2, '.', '');
        $this->assertValidAmount($formattedAmount);

        $existing = $this->findByIdempotencyKey($admin, $idempotencyKey);
        if ($existing) {
            $this->assertIdempotentParametersMatch(
                existing: $existing,
                expectedType: 'FLOAT_ISSUE',
                expectedAmount: $formattedAmount,
                expectedRecipientId: $agent->id,
                expectedAgentId: $agent->id,
                expectedExternalReference: $externalReference,
            );

            return $existing;
        }

        try {
            return DB::transaction(function () use ($admin, $agent, $formattedAmount, $externalReference, $idempotencyKey, $description): Transaction {
                $existing = $this->findByIdempotencyKey($admin, $idempotencyKey, lock: true);
                if ($existing) {
                    $this->assertIdempotentParametersMatch(
                        existing: $existing,
                        expectedType: 'FLOAT_ISSUE',
                        expectedAmount: $formattedAmount,
                        expectedRecipientId: $agent->id,
                        expectedAgentId: $agent->id,
                        expectedExternalReference: $externalReference,
                    );

                    return $existing;
                }

                $wallets = $this->walletService->lockWalletsForUsers([
                    ['user_id' => $agent->id, 'kind' => Wallet::KIND_AGENT],
                ]);

                $agentWallet = $wallets->first();
                if (! $agentWallet) {
                    throw new WalletNotFoundException;
                }

                if ($agentWallet->is_blocked) {
                    throw new WalletBlockedException;
                }

                $currentBalance = number_format((float) $agentWallet->balance, 2, '.', '');
                $newBalance = bcadd($currentBalance, $formattedAmount, 2);
                $agentWallet->balance = $newBalance;
                $agentWallet->save();

                $transaction = Transaction::create([
                    'user_id' => $agent->id,
                    'sender_id' => null,
                    'recipient_id' => $agent->id,
                    'agent_id' => $agent->id,
                    'initiated_by' => $admin->id,
                    'type' => 'FLOAT_ISSUE',
                    'amount' => $formattedAmount,
                    'system_fee_amount' => '0.00',
                    'system_fee_rate' => '0.0000',
                    'agent_commission_amount' => '0.00',
                    'agent_commission_rate' => '0.0000',
                    'currency' => $agentWallet->currency ?? 'BDT',
                    'description' => $description,
                    'status' => 'COMPLETED',
                    'idempotency_key' => $idempotencyKey,
                    'external_reference' => $externalReference,
                    'sender_wallet_balance_after' => null,
                    'recipient_wallet_balance_after' => $newBalance,
                    'agent_wallet_balance_after' => $newBalance,
                    'sender_wallet_id' => null,
                    'recipient_wallet_id' => $agentWallet->id,
                    'agent_wallet_id' => $agentWallet->id,
                    'meta' => [
                        'bank_deposit_reference' => $externalReference,
                    ],
                ]);

                AuditLog::create([
                    'actor_id' => $admin->id,
                    'action' => 'FLOAT_ISSUED',
                    'auditable_type' => $agent->getMorphClass(),
                    'auditable_id' => $agent->id,
                    'metadata' => [
                        'transaction_id' => $transaction->id,
                        'external_reference' => $externalReference,
                        'amount' => $formattedAmount,
                        'agent_wallet_id' => $agentWallet->id,
                        'balance_before' => $currentBalance,
                        'balance_after' => $newBalance,
                        'description' => $description,
                    ],
                ]);

                return $transaction;
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $existing = $this->findByIdempotencyKey($admin, $idempotencyKey);
            if ($existing) {
                $this->assertIdempotentParametersMatch(
                    existing: $existing,
                    expectedType: 'FLOAT_ISSUE',
                    expectedAmount: $formattedAmount,
                    expectedRecipientId: $agent->id,
                    expectedAgentId: $agent->id,
                    expectedExternalReference: $externalReference,
                );

                return $existing;
            }

            // A concurrent request under a different idempotency key claimed the same bank reference.
            if ($e instanceof UniqueConstraintViolationException && str_contains($e->getMessage(), 'external_reference')) {
                throw ValidationException::withMessages([
                    'external_reference' => ['This external reference has already been used.'],
                ]);
            }

            throw $e;
        }
    }

    /**
     * Approve a pending agent bank withdrawal once the bank payout has been sent.
     *
     * The funds were already debited when the agent made the request, so only the status changes.
     *
     * @throws WithdrawalNotPendingException
     * @throws ValidationException
     */
    public function approveWithdrawal(Transaction $withdrawal, User $reviewer, string $externalReference, ?string $note = null): Transaction
    {
        try {
            return DB::transaction(function () use ($withdrawal, $reviewer, $externalReference, $note): Transaction {
                $locked = $this->lockPendingWithdrawal($withdrawal);

                $locked->forceFill([
                    'status' => 'COMPLETED',
                    'external_reference' => $externalReference,
                    'meta' => array_merge($locked->meta ?? [], [
                        'review' => [
                            'decision' => 'APPROVED',
                            'reviewed_by' => $reviewer->id,
                            'reviewed_at' => now()->toIso8601String(),
                            'bank_transfer_reference' => $externalReference,
                            'note' => $note,
                        ],
                    ]),
                ])->save();

                AuditLog::create([
                    'actor_id' => $reviewer->id,
                    'action' => 'WITHDRAWAL_APPROVED',
                    'auditable_type' => (new User)->getMorphClass(),
                    'auditable_id' => $locked->agent_id,
                    'metadata' => [
                        'transaction_id' => $locked->id,
                        'amount' => $locked->amount,
                        'external_reference' => $externalReference,
                        'note' => $note,
                    ],
                ]);

                return $locked;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent review claimed the same bank reference after validation passed
            if (str_contains($e->getMessage(), 'external_reference')) {
                throw ValidationException::withMessages([
                    'external_reference' => ['This external reference has already been used.'],
                ]);
            }

            throw $e;
        }
    }

    /**
     * Reject a pending agent bank withdrawal and return the held funds to the agent wallet.
     *
     * The refund ignores a wallet block: the money never left the platform, so returning it is
     * not a new movement the block is meant to stop. A REJECTED row nets to zero in the ledger.
     *
     * @throws WithdrawalNotPendingException
     * @throws WalletNotFoundException
     */
    public function rejectWithdrawal(Transaction $withdrawal, User $reviewer, string $reason): Transaction
    {
        return DB::transaction(function () use ($withdrawal, $reviewer, $reason): Transaction {
            $locked = $this->lockPendingWithdrawal($withdrawal);

            $wallet = Wallet::whereKey($locked->sender_wallet_id)->lockForUpdate()->first();
            if (! $wallet) {
                throw new WalletNotFoundException;
            }

            $balanceBefore = number_format((float) $wallet->balance, 2, '.', '');
            $balanceAfter = bcadd($balanceBefore, number_format((float) $locked->amount, 2, '.', ''), 2);
            $wallet->balance = $balanceAfter;
            $wallet->save();

            $locked->forceFill([
                'status' => 'REJECTED',
                'meta' => array_merge($locked->meta ?? [], [
                    'review' => [
                        'decision' => 'REJECTED',
                        'reviewed_by' => $reviewer->id,
                        'reviewed_at' => now()->toIso8601String(),
                        'reason' => $reason,
                        'refunded_wallet_id' => $wallet->id,
                        'refund_balance_after' => $balanceAfter,
                    ],
                ]),
            ])->save();

            AuditLog::create([
                'actor_id' => $reviewer->id,
                'action' => 'WITHDRAWAL_REJECTED',
                'auditable_type' => (new User)->getMorphClass(),
                'auditable_id' => $locked->agent_id,
                'metadata' => [
                    'transaction_id' => $locked->id,
                    'amount' => $locked->amount,
                    'reason' => $reason,
                    'wallet_id' => $wallet->id,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                ],
            ]);

            return $locked;
        });
    }

    /**
     * Lock an agent withdrawal row and guard that it is still awaiting review.
     *
     * @throws WithdrawalNotPendingException
     */
    private function lockPendingWithdrawal(Transaction $withdrawal): Transaction
    {
        /** @var Transaction $locked */
        $locked = Transaction::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

        if ($locked->type !== 'AGENT_WITHDRAWAL' || $locked->status !== 'PENDING') {
            throw new WithdrawalNotPendingException;
        }

        return $locked;
    }
}
