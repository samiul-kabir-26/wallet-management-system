<?php

namespace Modules\Wallets\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class WalletNotFoundException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 404;

    /**
     * Create a new wallet not found exception instance.
     */
    public function __construct(
        string $message = 'Wallet not found.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
