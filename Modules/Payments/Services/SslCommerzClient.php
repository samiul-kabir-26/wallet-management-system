<?php

namespace Modules\Payments\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Payments\Exceptions\PaymentGatewayException;

/**
 * Thin HTTP client for the SSLCommerz v4 API: session creation, order validation and lookup by tran_id.
 *
 * It only talks to SSLCommerz and returns its decoded responses. Deciding what a response means for the
 * ledger is TopUpPaymentService's job.
 */
class SslCommerzClient
{
    /**
     * Statuses SSLCommerz uses for a successful payment. VALIDATED means it was already validated before.
     *
     * @var list<string>
     */
    public const array PAID_STATUSES = ['VALID', 'VALIDATED'];

    /**
     * Statuses after which the customer has definitely not paid. INVALID is what a lookup returns for a
     * tran_id SSLCommerz has never seen. An opened but unfinished checkout reports PROCESSING.
     *
     * @var list<string>
     */
    public const array UNPAID_STATUSES = ['FAILED', 'CANCELLED', 'EXPIRED', 'UNATTEMPTED', 'INVALID'];

    /**
     * Create a checkout session and return the SSLCommerz response (GatewayPageURL, sessionkey).
     *
     * @param  array<string, mixed>  $payload  Session fields other than the store credentials.
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    public function createSession(array $payload): array
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http
            ->asForm()
            ->post($this->url('/gwprocess/v4/api.php'), [...$this->credentials(), ...$payload]));

        $body = $response->json() ?? [];

        if (($body['status'] ?? null) !== 'SUCCESS' || empty($body['GatewayPageURL'])) {
            throw new PaymentGatewayException(
                'The payment gateway rejected the request: '.($body['failedreason'] ?? 'unknown reason').'.'
            );
        }

        return $body;
    }

    /**
     * Ask SSLCommerz whether a val_id belongs to a successful payment.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    public function validate(string $valId): array
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http
            ->get($this->url('/validator/api/validationserverAPI.php'), [
                ...$this->credentials(),
                'val_id' => $valId,
                'format' => 'json',
            ]));

        return $response->json() ?? [];
    }

    /**
     * Look up every payment attempt SSLCommerz recorded for one of our tran_ids.
     *
     * @return list<array<string, mixed>>
     *
     * @throws PaymentGatewayException
     */
    public function findByTranId(string $tranId): array
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http
            ->get($this->url('/validator/api/merchantTransIDvalidationAPI.php'), [
                ...$this->credentials(),
                'tran_id' => $tranId,
                'format' => 'json',
            ]));

        $elements = $response->json('element');

        return is_array($elements) ? array_values($elements) : [];
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     *
     * @throws PaymentGatewayException
     */
    private function send(callable $request): Response
    {
        try {
            $response = $request(Http::timeout((int) config('payments.sslcommerz.timeout_seconds', 15)));
        } catch (ConnectionException $e) {
            throw new PaymentGatewayException(previous: $e);
        }

        if ($response->failed()) {
            throw new PaymentGatewayException;
        }

        return $response;
    }

    /**
     * @return array{store_id: string, store_passwd: string}
     */
    private function credentials(): array
    {
        return [
            'store_id' => (string) config('payments.sslcommerz.store_id'),
            'store_passwd' => (string) config('payments.sslcommerz.store_password'),
        ];
    }

    private function url(string $path): string
    {
        $base = config('payments.sslcommerz.sandbox')
            ? config('payments.sslcommerz.sandbox_url')
            : config('payments.sslcommerz.live_url');

        return rtrim((string) $base, '/').$path;
    }
}
