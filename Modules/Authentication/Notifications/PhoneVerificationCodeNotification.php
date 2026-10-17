<?php

namespace Modules\Authentication\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Authentication\Notifications\Channels\SmsChannel;

class PhoneVerificationCodeNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $code,
        public int $expiresInMinutes,
    ) {}

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
        return "WalletMS: {$this->code} is your phone verification code. It expires in {$this->expiresInMinutes} minute(s). Never share it with anyone.";
    }
}
