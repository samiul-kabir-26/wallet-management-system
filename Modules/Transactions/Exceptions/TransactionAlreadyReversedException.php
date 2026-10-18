<?php

namespace Modules\Transactions\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class TransactionAlreadyReversedException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 409;

    /**
     * Create a new exception instance.
     */
    public function __construct(
        string $message = 'This transaction has already been reversed.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
