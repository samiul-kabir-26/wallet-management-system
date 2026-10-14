<?php

namespace Modules\Authentication\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class PinResetLinkInvalidException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 403;

    /**
     * Create a new PIN reset link invalid exception instance.
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            message: 'This PIN reset link has already been used or is no longer valid.',
            previous: $previous,
        );
    }
}
