<?php

namespace Modules\Authentication\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a staff member when their account signs in from an IP and browser it has not used before.
 */
class AdminLoginAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $ipAddress,
        public string $userAgent,
        public string $signedInAt,
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
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New sign-in to your WalletMS admin account')
            ->line('Your admin account was just signed in from a device or network it has not used before.')
            ->line("Time: {$this->signedInAt}")
            ->line("IP address: {$this->ipAddress}")
            ->line("Browser: {$this->userAgent}")
            ->line('If this was you, no action is needed. If not, change your password now and sign out every session from My Account.');
    }
}
