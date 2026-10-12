<?php

namespace Modules\Authentication\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class UserAlreadyHasStaffAccessException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 409;

    /**
     * Create a new exception instance.
     */
    public function __construct(
        string $message = 'This user already has staff access; use role assignment instead.',
        ?Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
