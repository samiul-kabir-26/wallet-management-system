<?php

namespace Modules\Transactions\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class InvalidAmountException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 422;

    /**
     * Create a new invalid amount exception instance.
     */
    public function __construct(
        string $message = 'Transaction amount must be greater than 0.00.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            code: 0,
            previous: $previous,
            errors: [
                'amount' => [$message],
            ],
        );
    }
}
