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
            ->line('The button asks for the temporary password and then lets you choose your own.')
            ->line("Or sign in through the Staff / Admin tab with this email address and the temporary password; you will be asked to choose a new password first. The invitation expires in {$this->expiresInHours} hour(s).")
            ->line('Your customer wallet, if you have one, keeps working with your PIN as before.');
    }
}
