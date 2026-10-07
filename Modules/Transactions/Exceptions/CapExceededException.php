<?php

namespace Modules\Transactions\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class CapExceededException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 422;

    public function __construct(
        string $message = 'Transaction cap exceeded.',
        ?Throwable $previous = null,
        array $errors = [],
    ) {
        parent::__construct(
            message: $message,
            code: 0,
            previous: $previous,
            errors: $errors,
        );
    }
}
