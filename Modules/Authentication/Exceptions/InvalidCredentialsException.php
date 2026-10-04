<?php

namespace Modules\Authentication\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class InvalidCredentialsException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 401;

    /**
     * Create a new invalid credentials exception instance.
     */
    public function __construct(
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: 'Invalid phone number or PIN.',
            previous: $previous,
        );
    }
}
