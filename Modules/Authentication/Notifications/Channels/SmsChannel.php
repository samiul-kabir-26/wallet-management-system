<?php

namespace Modules\Authentication\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Modules\Authentication\Exceptions\SmsDeliveryException;
use Modules\Authentication\Sms\SmsSender;

/**
 * Delivers text messages to a notifiable's phone number through the configured SmsSender
 * (services.sms.driver: "log" for development, "twilio" for real delivery).
 */
class SmsChannel
{
    public function __construct(
        private SmsSender $sender,
    ) {}

    /**
     * Send the given notification.
     *
     * @throws SmsDeliveryException
     */
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notifiable, 'routeNotificationFor')) {
            return;
        }

        $phoneNumber = $notifiable->routeNotificationFor('sms', $notification);

        if (! $phoneNumber || ! method_exists($notification, 'toSms')) {
            return;
        }

        $this->sender->send($phoneNumber, $notification->toSms($notifiable));
    }
}
