<?php

namespace Modules\Authentication\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class UnverifiedAccountException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 422;

    /**
     * Create a new unverified account exception instance.
     */
    public function __construct(
        string $message = 'Target user phone number must be verified before granting admin access.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
