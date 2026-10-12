<?php

namespace Modules\Authentication\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class PinAlreadySetException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 409;

    /**
     * Create a new PIN already set exception instance.
     */
    public function __construct(
        string $message = 'A PIN is already set on this account. Use staff-assisted PIN recovery to reset your PIN.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
