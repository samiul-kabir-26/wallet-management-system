<?php

namespace Modules\Authentication\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class UserNotFoundException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 404;

    /**
     * Create a new user not found exception instance.
     */
    public function __construct(
        string $message = 'User not found with the provided phone number.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
