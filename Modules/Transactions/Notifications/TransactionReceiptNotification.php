<?php

namespace Modules\Transactions\Notifications;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Authentication\Notifications\Channels\SmsChannel;

/**
 * Receipt for one party of a transfer, cash-in, cash-out or reversal.
 *
 * Queued and dispatched only after the database transaction commits, so a rolled-back transaction
 * never produces a receipt. SMS always goes out; email only when the account has one and has not
 * turned email receipts off. The counterparty is masked the same way as the recipient lookup.
 */
class TransactionReceiptNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const string PARTY_SENDER = 'sender';

    public const string PARTY_RECIPIENT = 'recipient';

    public const string PARTY_AGENT = 'agent';

    /**
     * Attempts per channel before the receipt is given up (the SMS provider may be briefly unavailable).
     */
    public int $tries = 3;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Transaction $transaction,
        public string $party,
    ) {
        $this->afterCommit();
    }

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if ($notifiable->routeNotificationFor('sms', $this)) {
            $channels[] = SmsChannel::class;
        }

        if ($notifiable instanceof User && $notifiable->wantsReceiptEmails()) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Get the SMS text for the notification.
     */
    public function toSms(object $notifiable): string
    {
        $receipt = $this->receipt();

        $parts = ["WalletMS: {$receipt['headline']} Tk {$this->money($receipt['amount'])} {$receipt['preposition']} {$receipt['counterparty']}."];
        foreach ($receipt['charges'] as $label => $value) {
            $parts[] = "{$label} Tk {$this->money($value)}.";
        }
        $parts[] = "Balance Tk {$this->money($receipt['balance'])}.";
        $parts[] = "TrxID {$this->transaction->id} at {$receipt['time']}.";

        return implode(' ', $parts);
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $receipt = $this->receipt();

        $message = (new MailMessage)
            ->subject("{$receipt['headline']} Tk {$this->money($receipt['amount'])} — receipt #{$this->transaction->id}")
            ->greeting($receipt['headline'])
            ->line("Amount: Tk {$this->money($receipt['amount'])}")
            ->line(ucfirst($receipt['preposition']).": {$receipt['counterparty']}");

        foreach ($receipt['charges'] as $label => $value) {
            $message->line("{$label}: Tk {$this->money($value)}");
        }

        return $message
            ->line("Balance after: Tk {$this->money($receipt['balance'])}")
            ->line("Transaction ID: {$this->transaction->id}")
            ->line("Time: {$receipt['time']}")
            ->line('If you did not make this transaction, contact support immediately.');
    }

    /**
     * The receipt contents for this party.
     *
     * @return array{headline: string, amount: string, preposition: string, counterparty: string, charges: array<string, string>, balance: string, time: string}
     */
    public function receipt(): array
    {
        $tx = $this->transaction->loadMissing(['sender', 'recipient', 'agent']);
        $amount = (string) $tx->amount;
        $fee = (string) $tx->system_fee_amount;
        $commission = (string) $tx->agent_commission_amount;

        [$headline, $preposition, $counterparty, $charges, $balance] = match ("{$tx->type}:{$this->party}") {
            'TRANSFER:sender' => ['You sent', 'to', $tx->recipient, $this->feeCharges($amount, $fee), $tx->sender_wallet_balance_after],
            'TRANSFER:recipient' => ['You received', 'from', $tx->sender, [], $tx->recipient_wallet_balance_after],
            'CASH_IN:recipient' => ['Cash in', 'from agent', $tx->agent, [], $tx->recipient_wallet_balance_after],
            'CASH_IN:agent' => ['Cash in given', 'to', $tx->recipient, [], $tx->agent_wallet_balance_after],
            'CASH_OUT:sender' => ['Cash out', 'at agent', $tx->agent, $this->feeCharges($amount, $fee), $tx->sender_wallet_balance_after],
            'CASH_OUT:agent' => ['Cash out received', 'from', $tx->sender, ['Commission' => $commission, 'Credited' => bcadd($amount, $commission, 2)], $tx->agent_wallet_balance_after],
            'REVERSAL:sender' => ["Reversal of #{$tx->reversed_transaction_id}: returned", 'to', $tx->recipient, $this->reversalCharges($amount, 'Commission returned', $commission), $tx->sender_wallet_balance_after],
            'REVERSAL:recipient' => ["Reversal of #{$tx->reversed_transaction_id}: you got back", 'from', $tx->sender, $this->reversalCharges($amount, 'Fee refunded', $fee), $tx->recipient_wallet_balance_after],
        };

        return [
            'headline' => $headline,
            'amount' => $amount,
            'preposition' => $preposition,
            'counterparty' => $this->describe($counterparty),
            'charges' => $charges,
            'balance' => (string) $balance,
            'time' => $tx->created_at
                ?->copy()
                ->setTimezone(config('transactions.business_timezone', 'Asia/Dhaka'))
                ->format('d/m/Y H:i') ?? '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function feeCharges(string $amount, string $fee): array
    {
        if (bccomp($fee, '0.00', 2) === 0) {
            return [];
        }

        return ['Fee' => $fee, 'Total' => bcadd($amount, $fee, 2)];
    }

    /**
     * @return array<string, string>
     */
    private function reversalCharges(string $amount, string $label, string $extra): array
    {
        if (bccomp($extra, '0.00', 2) === 0) {
            return [];
        }

        return [$label => $extra, 'Total' => bcadd($amount, $extra, 2)];
    }

    private function describe(?User $counterparty): string
    {
        if (! $counterparty) {
            return 'unknown';
        }

        $phone = $counterparty->maskedPhoneNumber();

        return $phone ? "{$counterparty->maskedName()} ({$phone})" : $counterparty->maskedName();
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2);
    }
}
