<?php

namespace Modules\Authentication\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class AccountHasNoPinException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 422;

    /**
     * Create a new account has no PIN exception instance.
     */
    public function __construct(
        string $message = 'Target account does not have a PIN configured to reset.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
