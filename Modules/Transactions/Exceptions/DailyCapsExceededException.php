<?php

namespace Modules\Transactions\Exceptions;

use Throwable;

class DailyCapsExceededException extends CapExceededException
{
    public function __construct(
        string $message = 'Daily transaction cap exceeded.',
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
