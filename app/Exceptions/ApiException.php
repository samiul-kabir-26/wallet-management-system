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
     * Additional response metadata.
     *
     * @var array<string, mixed>
     */
    protected array $meta = [];

    /**
     * Response headers.
     *
     * @var array<string, string>
     */
    protected array $headers = [];

    /**
     * Create a new API exception instance.
     *
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        array $errors = [],
        array $meta = [],
        array $headers = [],
    ) {
        parent::__construct($message, $code, $previous);

        if (! empty($errors)) {
            $this->errors = $errors;
        }

        if (! empty($meta)) {
            $this->meta = $meta;
        }

        if (! empty($headers)) {
            $this->headers = $headers;
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
        $payload = [
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ];

        if (! empty($this->meta)) {
            $payload['meta'] = $this->meta;
        }

        return response()->json($payload, $this->status, $this->headers);
    }
}
