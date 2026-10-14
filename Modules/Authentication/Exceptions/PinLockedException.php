<?php

namespace Modules\Authentication\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class PinLockedException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 429;

    /**
     * Create a new pin locked exception instance.
     */
    public function __construct(
        int $retryAfter,
        string $message = 'Too many incorrect PIN attempts. Your account has been temporarily locked.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            code: 0,
            previous: $previous,
            errors: [
                'pin' => [$message],
            ],
            meta: [
                'locked' => true,
                'retry_after' => $retryAfter,
            ],
            headers: [
                'Retry-After' => (string) $retryAfter,
            ],
        );
    }
}
