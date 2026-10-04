<?php

namespace Modules\Authentication\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class AccountInactiveException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 403;

    /**
     * Create a new account inactive exception instance.
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            message: 'Your account is inactive.',
            previous: $previous,
        );
    }
}
