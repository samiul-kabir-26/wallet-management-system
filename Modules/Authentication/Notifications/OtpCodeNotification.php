<?php

namespace Modules\Authentication\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OtpCodeNotification extends Notification
{
    use Queueable;

    /**
     * Human-readable subjects keyed by OTP purpose.
     *
     * @var array<string, string>
     */
    private const array SUBJECTS = [
        'LOGIN' => 'Your admin login code',
        'PASSWORD_RESET' => 'Your password reset code',
        'SET_PIN' => 'Your PIN setup code',
    ];

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $code,
        public string $purpose,
        public int $expiresInMinutes,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(self::SUBJECTS[$this->purpose] ?? 'Your verification code')
            ->line("Your verification code is: {$this->code}")
            ->line("This code expires in {$this->expiresInMinutes} minute(s).")
            ->line('If you did not request this code, contact support immediately. Never share it with anyone.');
    }
}
