<?php

namespace Modules\Roles\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class PermissionEscalationException extends ApiException
{
    protected int $status = 403;

    public function __construct(
        string $message = 'You cannot grant, modify, or assign permissions or roles that exceed your own authority.',
        ?Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
