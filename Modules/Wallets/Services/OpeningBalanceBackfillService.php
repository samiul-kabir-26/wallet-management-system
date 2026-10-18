<?php

namespace Modules\Wallets\Services;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Modules\Transactions\Services\SignupBonusService;

/**
 * Gives legacy wallets the ledger row their opening balance never had.
 *
 * Wallets opened before the signup bonus became a ledger entry got 50.00 from a column default, so
 * their balance is 50.00 more than their transactions explain. This writes the missing SIGNUP_BONUS
 * row (flagged meta.backfilled) without touching the balance: the money was always there, only its
 * record was missing.
 *
 * The row is dated when it is written, not back-dated. meta.effective_at carries the wallet's opening
 * date, so history stays in the order things were recorded and nothing pretends to be older than it is.
 */
class OpeningBalanceBackfillService
{
    public function __construct(
        protected LedgerIntegrityService $ledger,
    ) {}

    /**
     * Wallets that still carry the legacy opening balance with no ledger row.
     *
     * @return list<array{wallet_id: int, user_id: int, kind: string, stored_balance: string, ledger_balance: string, difference: string, classification: string}>
     */
    public function candidates(): array
    {
        return array_values(array_filter(
            $this->ledger->verify()['wallet_mismatches'],
            fn (array $mismatch): bool => $mismatch['classification'] === LedgerIntegrityService::LEGACY_OPENING_BALANCE,
        ));
    }

    /**
     * Write the missing opening-balance row for one wallet.
     *
     * Re-checks the gap with the wallet row locked, so a wallet that moved money since the candidate
     * list was built is judged on its current state. Returns null when the wallet no longer qualifies.
     * Running it twice for the same wallet is harmless: the signup-bonus idempotency key allows one row.
     */
    public function backfill(int $walletId, ?int $actorId = null): ?Transaction
    {
        return DB::transaction(function () use ($walletId, $actorId): ?Transaction {
            $wallet = Wallet::whereKey($walletId)->lockForUpdate()->first();
            if (! $wallet) {
                return null;
            }

            $idempotencyKey = SignupBonusService::idempotencyKey($wallet);
            $alreadyRecorded = Transaction::where('type', SignupBonusService::TYPE)
                ->where('recipient_wallet_id', $wallet->id)
                ->exists();
            if ($alreadyRecorded) {
                return null;
            }

            $legacyAmount = number_format((float) config('transactions.ledger_check.legacy_opening_balance', 50.00), 2, '.', '');
            $stored = number_format((float) $wallet->balance, 2, '.', '');
            $ledgerBalance = $this->ledger->ledgerBalances([$wallet->id])[$wallet->id] ?? '0.00';

            if (bccomp(bcsub($stored, $ledgerBalance, 2), $legacyAmount, 2) !== 0) {
                return null;
            }

            $sourceLabel = (string) config('transactions.signup_bonus.source_label', 'bKash');

            $transaction = Transaction::create([
                'user_id' => $wallet->user_id,
                'sender_id' => null,
                'recipient_id' => $wallet->user_id,
                'agent_id' => null,
                'initiated_by' => $wallet->user_id,
                'type' => SignupBonusService::TYPE,
                'amount' => $legacyAmount,
                'system_fee_amount' => '0.00',
                'system_fee_rate' => '0.0000',
                'agent_commission_amount' => '0.00',
                'agent_commission_rate' => '0.0000',
                'currency' => $wallet->currency ?? 'BDT',
                'description' => "Opening balance from {$sourceLabel} (recorded for an existing wallet)",
                'status' => 'COMPLETED',
                'idempotency_key' => $idempotencyKey,
                'sender_wallet_balance_after' => null,
                // Nothing moves now, so the balance after this row is today's balance
                'recipient_wallet_balance_after' => $stored,
                'agent_wallet_balance_after' => null,
                'sender_wallet_id' => null,
                'recipient_wallet_id' => $wallet->id,
                'agent_wallet_id' => null,
                'meta' => [
                    'source' => 'SYSTEM',
                    'source_label' => $sourceLabel,
                    'wallet_kind' => $wallet->kind,
                    'backfilled' => true,
                    'reason' => 'LEGACY_OPENING_BALANCE',
                    'effective_at' => $wallet->created_at?->toIso8601String(),
                ],
            ]);

            AuditLog::create([
                'actor_id' => $actorId,
                'action' => 'OPENING_BALANCE_BACKFILLED',
                'auditable_type' => $wallet->getMorphClass(),
                'auditable_id' => $wallet->id,
                'metadata' => [
                    'transaction_id' => $transaction->id,
                    'user_id' => $wallet->user_id,
                    'amount' => $legacyAmount,
                    'balance' => $stored,
                    'ledger_balance_before' => $ledgerBalance,
                ],
            ]);

            return $transaction;
        });
    }
}
