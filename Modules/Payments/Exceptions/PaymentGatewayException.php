<?php

namespace Modules\Payments\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class PaymentGatewayException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 502;

    /**
     * Create a new payment gateway exception instance.
     */
    public function __construct(
        string $message = 'The payment gateway is unavailable. Please try again shortly.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
