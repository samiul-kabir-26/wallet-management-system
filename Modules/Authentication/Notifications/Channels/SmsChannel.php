<?php

namespace Modules\Authentication\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Delivers text messages to a notifiable's phone number.
 *
 * No SMS gateway is configured yet, so messages are written to the log channel named by
 * services.sms.log_channel (the default log when unset), the same way MAIL_MAILER=log works for email.
 * Swap the body of send() for a gateway client when one is chosen.
 */
class SmsChannel
{
    /**
     * Send the given notification.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        $phoneNumber = $notifiable->routeNotificationFor('sms', $notification);

        if (! $phoneNumber || ! method_exists($notification, 'toSms')) {
            return;
        }

        Log::channel(config('services.sms.log_channel') ?: config('logging.default'))
            ->info('SMS message', [
                'to' => $phoneNumber,
                'message' => $notification->toSms($notifiable),
            ]);
    }
}
