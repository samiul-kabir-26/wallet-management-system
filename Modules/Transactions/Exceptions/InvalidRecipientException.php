<?php

namespace Modules\Transactions\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class InvalidRecipientException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 422;

    /**
     * Create a new invalid recipient exception instance.
     */
    public function __construct(
        string $message = 'Invalid recipient for transfer.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
