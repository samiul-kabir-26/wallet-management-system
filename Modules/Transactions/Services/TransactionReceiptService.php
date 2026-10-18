<?php

namespace Modules\Transactions\Services;

use App\Models\Transaction;
use App\Models\User;
use Modules\Transactions\Notifications\TransactionReceiptNotification;

/**
 * Sends a receipt to each party of a completed transfer, cash-in or cash-out.
 *
 * Call it inside the transaction that wrote the row: receipts are queued after commit, so a rollback
 * cancels them, and an idempotent replay (which returns the existing row without writing) never calls it.
 */
class TransactionReceiptService
{
    /**
     * Queue the receipts for a newly written transaction.
     */
    public function queueFor(Transaction $transaction): void
    {
        if ($transaction->status !== 'COMPLETED') {
            return;
        }

        $parties = match ($transaction->type) {
            'TRANSFER' => [
                TransactionReceiptNotification::PARTY_SENDER => $transaction->sender_id,
                TransactionReceiptNotification::PARTY_RECIPIENT => $transaction->recipient_id,
            ],
            'CASH_IN' => [
                TransactionReceiptNotification::PARTY_RECIPIENT => $transaction->recipient_id,
                TransactionReceiptNotification::PARTY_AGENT => $transaction->agent_id,
            ],
            'CASH_OUT' => [
                TransactionReceiptNotification::PARTY_SENDER => $transaction->sender_id,
                TransactionReceiptNotification::PARTY_AGENT => $transaction->agent_id,
            ],
            default => [],
        };

        $users = User::whereKey(array_filter($parties))->get()->keyBy('id');

        foreach ($parties as $party => $userId) {
            $users->get($userId)?->notify(new TransactionReceiptNotification($transaction, $party));
        }
    }
}
