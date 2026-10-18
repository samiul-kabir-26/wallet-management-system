<?php

namespace Modules\Users\Notifications;

use App\Models\KycSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Authentication\Notifications\Channels\SmsChannel;

/**
 * Tells a customer their identity documents were approved (with their new limits) or rejected.
 */
class KycReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public KycSubmission $submission,
        public ?string $tierLabel = null,
        public ?string $dailyCap = null,
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
        if ($this->submission->status === KycSubmission::APPROVED) {
            $limits = $this->dailyCap !== null ? ' Daily limit: Tk '.number_format((float) $this->dailyCap, 2).'.' : '';

            return "WalletMS: Your identity is verified. Your account is now {$this->tierLabel}.{$limits}";
        }

        return "WalletMS: We could not verify your identity documents: {$this->submission->review_note} Please submit them again from My Account.";
    }
}
