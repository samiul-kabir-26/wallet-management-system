<?php

namespace Modules\Transactions\Exceptions;

use Throwable;

class MonthlyCapsExceededException extends CapExceededException
{
    public function __construct(
        string $message = 'Monthly transaction cap exceeded.',
        ?Throwable $previous = null,
        array $errors = [],
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
            errors: $errors,
        );
    }
}
