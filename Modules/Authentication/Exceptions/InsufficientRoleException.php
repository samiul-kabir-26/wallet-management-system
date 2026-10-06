<?php

namespace Modules\Authentication\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class InsufficientRoleException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 403;

    /**
     * Create a new insufficient role exception instance.
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            message: 'You do not have permission to access this portal.',
            previous: $previous,
        );
    }
}
