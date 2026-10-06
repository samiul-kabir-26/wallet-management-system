<?php

namespace Modules\Users\Exceptions;

use App\Exceptions\ApiException;
use Throwable;

class AgentNotApprovedException extends ApiException
{
    /**
     * The HTTP status code.
     */
    protected int $status = 409;

    /**
     * Create a new agent not approved exception instance.
     */
    public function __construct(
        string $message = 'Agent is not currently approved.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
