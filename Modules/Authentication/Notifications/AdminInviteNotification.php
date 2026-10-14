<?php

namespace Modules\Authentication\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminInviteNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $url,
        public string $temporaryPassword,
        public int $expiresInHours,
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
            ->subject('You have been granted staff access')
            ->line('You have been granted access to the admin panel.')
            ->line("Temporary password: {$this->temporaryPassword}")
            ->action('Accept invitation', $this->url)
            ->line("This invitation expires in {$this->expiresInHours} hour(s). You must change the password on first login.");
    }
}
