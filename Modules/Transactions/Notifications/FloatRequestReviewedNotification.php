<?php

namespace Modules\Transactions\Notifications;

use App\Models\FloatRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Authentication\Notifications\Channels\SmsChannel;

/**
 * Tells an agent their float request was approved (float credited) or rejected (with the reason).
 * Queued after commit, so a rolled-back review sends nothing.
 */
class FloatRequestReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Attempts before giving up (the SMS provider may be briefly unavailable).
     */
    public int $tries = 3;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public FloatRequest $floatRequest,
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
        $request = $this->floatRequest;
        $amount = number_format((float) $request->amount, 2);
        $prefix = "WalletMS: Your float request #{$request->id} for Tk {$amount} (ref {$request->bank_reference})";

        return $request->status === FloatRequest::APPROVED
            ? "{$prefix} was approved and the float is in your agent wallet."
            : "{$prefix} was rejected: {$request->review_note}";
    }
}
