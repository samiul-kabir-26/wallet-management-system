<?php

namespace Modules\Authentication\Sms;

use Modules\Authentication\Exceptions\SmsDeliveryException;

/**
 * Sends one text message. Implementations are chosen by services.sms.driver.
 */
interface SmsSender
{
    /**
     * Send a message to a phone number as stored on the account (e.g. 01712345678).
     *
     * @return string|null The provider's message ID, when it has one.
     *
     * @throws SmsDeliveryException When the provider refuses the message or cannot be reached.
     */
    public function send(string $phoneNumber, string $message): ?string;
}
