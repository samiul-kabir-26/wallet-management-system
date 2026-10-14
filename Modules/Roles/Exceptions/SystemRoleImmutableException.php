<?php

namespace Modules\Roles\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class SystemRoleImmutableException extends ApiException
{
    protected int $status = 403;

    public function __construct(
        string $message = 'System roles are immutable and cannot be renamed, deleted, or modified without appropriate authority.',
        ?Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
