<?php

namespace Modules\Transactions\Services;

use App\Models\AgentInfo;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Transactions\Exceptions\TransactionAlreadyReversedException;
use Modules\Transactions\Exceptions\TransactionNotReversibleException;
use Modules\Wallets\Exceptions\InsufficientBalanceException;
use Modules\Wallets\Exceptions\WalletNotFoundException;

/**
 * Undoes a completed transfer, cash-in, cash-out or signup bonus with a new REVERSAL row. The original is never edited.
 *
 * The reversal mirrors the original exactly:
 *
 * | Original | Money taken back from                        | Money returned to                     |
 * |----------|----------------------------------------------|---------------------------------------|
 * | TRANSFER | recipient: amount                            | sender: amount + fee                  |
 * | CASH_IN  | customer: amount                             | agent: amount                         |
 * | CASH_OUT | agent: amount + commission (also deducted    | customer: amount + fee                |
 * |          | from agent_info.total_commission)            |                                       |
 * | SIGNUP_BONUS | customer: amount (clawback)              | nobody: the money returns to the platform |
 *
 * A signup bonus can only be clawed back within transactions.signup_bonus.clawback_days of being paid,
 * and never a backfilled opening balance (that money was the customer's from the start).
 *
 * On the REVERSAL row the debited wallet is the sender slot and the credited wallet the recipient slot;
 * system_fee_amount is the fee refunded and agent_commission_amount the commission clawed back, so the
 * ledger reads sender −(amount + commission) and recipient +(amount + fee).
 *
 * If the debited party has already spent the money the reversal fails: balances never go negative.
 * Blocked wallets and suspended agents do not stop a reversal, because correcting money is exactly
 * what staff do while an account is frozen. Daily and monthly cap usage is not given back.
 */
class ReversalService
{
    /**
     * @var list<string>
     */
    public const array REVERSIBLE_TYPES = ['TRANSFER', 'CASH_IN', 'CASH_OUT', 'SIGNUP_BONUS'];

    public function __construct(
        protected TransactionReceiptService $receipts,
    ) {}

    /**
     * Reverse a transaction on behalf of a staff member.
     *
     * @throws TransactionNotReversibleException
     * @throws TransactionAlreadyReversedException
     * @throws InsufficientBalanceException
     * @throws WalletNotFoundException
     */
    public function reverse(Transaction $original, User $staff, string $reason): Transaction
    {
        try {
            return DB::transaction(function () use ($original, $staff, $reason): Transaction {
                $locked = Transaction::whereKey($original->id)->lockForUpdate()->firstOrFail();

                $this->assertReversible($locked);

                $legs = $this->legs($locked);
                $wallets = Wallet::whereKey(array_filter([$legs['debit_wallet_id'], $legs['credit_wallet_id']]))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // A clawed-back bonus has no credited wallet: the money goes back to the platform
                $debitWallet = $wallets->get($legs['debit_wallet_id']);
                $creditWallet = $legs['credit_wallet_id'] !== null ? $wallets->get($legs['credit_wallet_id']) : null;
                if (! $debitWallet || ($legs['credit_wallet_id'] !== null && ! $creditWallet)) {
                    throw new WalletNotFoundException;
                }

                $debitBalance = number_format((float) $debitWallet->balance, 2, '.', '');
                if (bccomp($debitBalance, $legs['debit_amount'], 2) === -1) {
                    throw new InsufficientBalanceException(
                        'The '.$legs['debit_party'].' no longer has enough balance to return this money.'
                    );
                }

                $debitWallet->balance = bcsub($debitBalance, $legs['debit_amount'], 2);
                $debitWallet->save();

                if ($creditWallet) {
                    $creditWallet->balance = bcadd(number_format((float) $creditWallet->balance, 2, '.', ''), $legs['credit_amount'], 2);
                    $creditWallet->save();
                }

                if ($locked->type === 'CASH_OUT' && bccomp($legs['commission'], '0.00', 2) === 1) {
                    $this->clawBackCommission((int) $locked->agent_id, $legs['commission']);
                }

                $reversal = Transaction::create([
                    'user_id' => $locked->user_id,
                    'sender_id' => $legs['debit_user_id'],
                    'recipient_id' => $legs['credit_user_id'],
                    'agent_id' => $locked->agent_id,
                    'initiated_by' => $staff->id,
                    'type' => 'REVERSAL',
                    'reversed_transaction_id' => $locked->id,
                    'amount' => number_format((float) $locked->amount, 2, '.', ''),
                    'system_fee_amount' => $legs['fee'],
                    'system_fee_rate' => $locked->system_fee_rate,
                    'agent_commission_amount' => $legs['commission'],
                    'agent_commission_rate' => $locked->agent_commission_rate,
                    'currency' => $locked->currency,
                    'description' => "Reversal of #{$locked->id}: {$reason}",
                    'status' => 'COMPLETED',
                    'idempotency_key' => "reversal:{$locked->id}",
                    'sender_wallet_balance_after' => $debitWallet->balance,
                    'recipient_wallet_balance_after' => $creditWallet?->balance,
                    'agent_wallet_balance_after' => null,
                    'sender_wallet_id' => $debitWallet->id,
                    'recipient_wallet_id' => $creditWallet?->id,
                    'agent_wallet_id' => null,
                    'meta' => [
                        'reverses' => ['id' => $locked->id, 'type' => $locked->type],
                        'reason' => $reason,
                        'reversed_by' => $staff->id,
                    ],
                ]);

                AuditLog::create([
                    'actor_id' => $staff->id,
                    'action' => 'TRANSACTION_REVERSED',
                    'auditable_type' => $locked->getMorphClass(),
                    'auditable_id' => $locked->id,
                    'metadata' => [
                        'reversal_id' => $reversal->id,
                        'original_type' => $locked->type,
                        'amount' => $reversal->amount,
                        'debited_wallet_id' => $debitWallet->id,
                        'debited' => $legs['debit_amount'],
                        'credited_wallet_id' => $creditWallet?->id,
                        'credited' => $legs['credit_amount'],
                        'reason' => $reason,
                    ],
                ]);

                $this->receipts->queueFor($reversal);

                return $reversal;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent reversal of the same transaction committed first
            throw new TransactionAlreadyReversedException(previous: $e);
        }
    }

    /**
     * Fail early when a transaction cannot be reversed: wrong type or status, or already reversed.
     *
     * @throws TransactionNotReversibleException
     * @throws TransactionAlreadyReversedException
     */
    public function assertReversible(Transaction $transaction): void
    {
        if (! in_array($transaction->type, self::REVERSIBLE_TYPES, true) || $transaction->status !== 'COMPLETED') {
            throw new TransactionNotReversibleException;
        }

        if (Transaction::where('reversed_transaction_id', $transaction->id)->exists()) {
            throw new TransactionAlreadyReversedException;
        }

        if ($transaction->type === 'SIGNUP_BONUS') {
            if (($transaction->meta['backfilled'] ?? false) === true) {
                throw new TransactionNotReversibleException('A backfilled opening balance cannot be clawed back.');
            }

            $days = (int) config('transactions.signup_bonus.clawback_days', 30);
            if ($transaction->created_at !== null && $transaction->created_at->lt(now()->subDays($days))) {
                throw new TransactionNotReversibleException("Signup bonuses can only be clawed back within {$days} days.");
            }
        }
    }

    /**
     * Who gives money back, who gets it, and how much, for a locked original transaction.
     *
     * @return array{debit_wallet_id: int, debit_user_id: int, debit_party: string, debit_amount: numeric-string, credit_wallet_id: int|null, credit_user_id: int|null, credit_amount: numeric-string, fee: numeric-string, commission: numeric-string}
     */
    private function legs(Transaction $original): array
    {
        $amount = number_format((float) $original->amount, 2, '.', '');
        $fee = number_format((float) $original->system_fee_amount, 2, '.', '');
        $commission = number_format((float) $original->agent_commission_amount, 2, '.', '');

        return match ($original->type) {
            'TRANSFER' => [
                'debit_wallet_id' => (int) $original->recipient_wallet_id,
                'debit_user_id' => (int) $original->recipient_id,
                'debit_party' => 'recipient',
                'debit_amount' => $amount,
                'credit_wallet_id' => (int) $original->sender_wallet_id,
                'credit_user_id' => (int) $original->sender_id,
                'credit_amount' => bcadd($amount, $fee, 2),
                'fee' => $fee,
                'commission' => '0.00',
            ],
            'CASH_IN' => [
                'debit_wallet_id' => (int) $original->recipient_wallet_id,
                'debit_user_id' => (int) $original->recipient_id,
                'debit_party' => 'customer',
                'debit_amount' => $amount,
                'credit_wallet_id' => (int) $original->agent_wallet_id,
                'credit_user_id' => (int) $original->agent_id,
                'credit_amount' => $amount,
                'fee' => '0.00',
                'commission' => '0.00',
            ],
            'SIGNUP_BONUS' => [
                'debit_wallet_id' => (int) $original->recipient_wallet_id,
                'debit_user_id' => (int) $original->recipient_id,
                'debit_party' => 'customer',
                'debit_amount' => $amount,
                'credit_wallet_id' => null,
                'credit_user_id' => null,
                'credit_amount' => '0.00',
                'fee' => '0.00',
                'commission' => '0.00',
            ],
            'CASH_OUT' => [
                'debit_wallet_id' => (int) $original->agent_wallet_id,
                'debit_user_id' => (int) $original->agent_id,
                'debit_party' => 'agent',
                'debit_amount' => bcadd($amount, $commission, 2),
                'credit_wallet_id' => (int) $original->sender_wallet_id,
                'credit_user_id' => (int) $original->sender_id,
                'credit_amount' => bcadd($amount, $fee, 2),
                'fee' => $fee,
                'commission' => $commission,
            ],
            default => throw new TransactionNotReversibleException,
        };
    }

    /**
     * @param  numeric-string  $commission
     */
    private function clawBackCommission(int $agentUserId, string $commission): void
    {
        $agentInfo = AgentInfo::where('user_id', $agentUserId)->lockForUpdate()->first();
        if (! $agentInfo) {
            return;
        }

        $agentInfo->total_commission = bcsub(number_format((float) $agentInfo->total_commission, 2, '.', ''), $commission, 2);
        $agentInfo->save();
    }
}
