<?php

namespace Modules\Users\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class UserNotAnAgentException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 404;

    /**
     * Create a new user not an agent exception instance.
     */
    public function __construct(
        string $message = 'Target user does not have an agent profile.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
