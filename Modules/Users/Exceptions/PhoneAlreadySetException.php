<?php

namespace Modules\Users\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class PhoneAlreadySetException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 409;

    /**
     * Create a new phone already set exception instance.
     */
    public function __construct(
        string $message = 'Your account already has a phone number. Contact support to change it.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
