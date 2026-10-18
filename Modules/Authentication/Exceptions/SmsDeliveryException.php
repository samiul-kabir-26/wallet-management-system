<?php

namespace Modules\Authentication\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class SmsDeliveryException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 502;

    /**
     * Create a new SMS delivery exception instance.
     *
     * The provider's reason is for operators (logs, sms:test); API clients only see the generic message.
     */
    public function __construct(
        string $message = 'We could not send the text message. Please try again shortly.',
        ?Throwable $previous = null,
        public readonly ?string $providerReason = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
