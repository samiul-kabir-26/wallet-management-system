<?php

namespace Modules\Authentication\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class InvalidOtpException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 401;

    /**
     * Create a new invalid OTP exception instance.
     */
    public function __construct(
        string $message = 'Invalid or expired OTP.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
