<?php

namespace Modules\Transactions\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class TransactionNotReversibleException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 409;

    /**
     * Create a new exception instance.
     */
    public function __construct(
        string $message = 'Only completed transfers, cash-ins and cash-outs can be reversed.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
