<?php

namespace Modules\Authentication\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Development driver: writes each message to a log channel instead of sending it, the same way
 * MAIL_MAILER=log works for email. Message bodies (including OTP codes) appear in the log, so never
 * use it in production.
 */
class LogSmsSender implements SmsSender
{
    public function __construct(
        private ?string $channel = null,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function send(string $phoneNumber, string $message): ?string
    {
        Log::channel($this->channel ?: config('logging.default'))
            ->info('SMS message', [
                'to' => $phoneNumber,
                'message' => $message,
            ]);

        return null;
    }
}
