<?php

namespace Modules\Roles\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class RoleInUseException extends ApiException
{
    protected int $status = 409;

    public function __construct(
        string $message = 'Cannot delete a role that currently has active user assignments. Please unassign users before deletion.',
        ?Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
