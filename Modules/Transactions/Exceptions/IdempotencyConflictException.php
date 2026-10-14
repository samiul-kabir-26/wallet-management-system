<?php

namespace Modules\Transactions\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class IdempotencyConflictException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 409;

    /**
     * Create a new idempotency conflict exception instance.
     */
    public function __construct(
        string $message = 'Idempotency key reused with different parameters.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            code: 0,
            previous: $previous,
            errors: [],
        );
    }
}
