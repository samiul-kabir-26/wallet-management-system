<?php

namespace Modules\Transactions\Notifications;

use App\Models\PaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Authentication\Notifications\Channels\SmsChannel;

/**
 * SMS about a payment request: a new one to the payer, the outcome to the requester. Queued after commit.
 * The other party's name is masked like the recipient lookup.
 */
class PaymentRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const string EVENT_REQUESTED = 'REQUESTED';

    public const string EVENT_PAID = 'PAID';

    public const string EVENT_DECLINED = 'DECLINED';

    public int $tries = 3;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public PaymentRequest $paymentRequest,
        public string $event,
    ) {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [SmsChannel::class];
    }

    /**
     * Get the SMS text for the notification.
     */
    public function toSms(object $notifiable): string
    {
        $request = $this->paymentRequest->loadMissing(['requester', 'payer']);
        $amount = 'Tk '.number_format((float) $request->amount, 2);
        $note = $request->note ? " ({$request->note})" : '';

        return match ($this->event) {
            self::EVENT_REQUESTED => "WalletMS: {$request->requester?->maskedName()} requests {$amount}{$note}. Open WalletMS to pay or decline request #{$request->id}.",
            self::EVENT_PAID => "WalletMS: {$request->payer?->maskedName()} paid your request #{$request->id} for {$amount}.",
            default => "WalletMS: {$request->payer?->maskedName()} declined your request #{$request->id} for {$amount}.",
        };
    }
}
