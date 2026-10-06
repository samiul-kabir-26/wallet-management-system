<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Throwable;

abstract class ApiException extends Exception
{
    /**
     * The HTTP status code to return.
     */
    protected int $status = 500;

    /**
     * Additional error details/validation errors.
     *
     * @var array<string, mixed>
     */
    protected array $errors = [];

    /**
     * Create a new API exception instance.
     *
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        string $message = '',
        int $code = 0, ?Throwable $previous = null,
        array $errors = []
    ) {
        parent::__construct($message, $code, $previous);

        if (! empty($errors)) {
            $this->errors = $errors;
        }
    }

    /**
     * Get the HTTP status code.
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Render the exception into an HTTP Response
     */
    public function render(HttpRequest $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ], $this->status);
    }
}
