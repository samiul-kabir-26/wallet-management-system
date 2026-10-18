<?php

namespace Modules\Approvals\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class ApprovalConflictException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 409;

    /**
     * Create a new approval conflict exception instance.
     */
    public function __construct(
        string $message = 'This request is no longer awaiting approval.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
