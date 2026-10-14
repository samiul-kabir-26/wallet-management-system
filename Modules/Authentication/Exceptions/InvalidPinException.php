<?php

namespace Modules\Authentication\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class InvalidPinException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 422;

    /**
     * Create a new invalid pin exception instance.
     */
    public function __construct(
        int $attemptsRemaining = 0,
        ?string $message = null,
        ?Throwable $previous = null,
    ) {
        $message = $message ?? sprintf('Wrong PIN. %d attempt(s) left.', $attemptsRemaining);

        parent::__construct(
            message: $message,
            code: 0,
            previous: $previous,
            errors: [
                'pin' => [$message],
            ],
            meta: [
                'attempts_remaining' => $attemptsRemaining,
            ],
        );
    }
}
