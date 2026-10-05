<?php

namespace Modules\Authentication\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class PasswordChangeRequiredException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 403;

    /**
     * Create a new password change required exception instance.
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            message: 'You must change your password before proceeding.',
            previous: $previous,
        );
    }
}
