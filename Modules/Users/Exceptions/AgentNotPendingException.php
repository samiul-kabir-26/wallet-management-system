<?php

namespace Modules\Users\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class AgentNotPendingException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 409;

    /**
     * Create a new agent not pending exception instance.
     */
    public function __construct(
        string $message = 'Agent is not pending approval.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
