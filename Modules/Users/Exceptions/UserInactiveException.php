<?php

namespace Modules\Users\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class UserInactiveException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 403;

    /**
     * Create a new user inactive exception instance.
     */
    public function __construct(
        string $message = 'Target user account is inactive.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
