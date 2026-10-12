<?php

namespace Modules\Users\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class UserRequiresPasswordForStaffRoleException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 422;

    /**
     * Create a new exception instance.
     */
    public function __construct(
        string $message = 'Cannot assign a staff role to a user without a password. Grant admin access via invite instead.',
        ?Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
