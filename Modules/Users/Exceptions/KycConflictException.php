<?php

namespace Modules\Users\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class KycConflictException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 409;

    /**
     * Create a new KYC conflict exception instance.
     */
    public function __construct(
        string $message = 'This identity submission has already been reviewed.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
