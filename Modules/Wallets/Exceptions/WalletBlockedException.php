<?php

namespace Modules\Wallets\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class WalletBlockedException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 403;

    /**
     * Create a new wallet blocked exception instance.
     */
    public function __construct(
        string $message = 'This wallet is blocked and cannot perform operations.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
