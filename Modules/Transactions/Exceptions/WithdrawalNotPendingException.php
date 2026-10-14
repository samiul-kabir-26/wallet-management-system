<?php

namespace Modules\Transactions\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class WithdrawalNotPendingException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 409;

    /**
     * Create a new withdrawal not pending exception instance.
     */
    public function __construct(
        string $message = 'This withdrawal has already been reviewed.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
