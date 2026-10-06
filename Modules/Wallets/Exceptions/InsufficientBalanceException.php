<?php

namespace Modules\Wallets\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class InsufficientBalanceException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 422;

    /**
     * Create a new insufficient balance exception instance.
     */
    public function __construct(
        string $message = 'Insufficient wallet balance for this operation.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
