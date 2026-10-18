<?php

namespace Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payments\Services\TopUpPaymentService;
use Modules\Transactions\Http\Requests\TopUpRequest;
use Modules\Transactions\Resources\TransactionResource;
use Modules\Transactions\Services\TransactionService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TopUpController extends Controller
{
    public function __construct(
        protected TopUpPaymentService $payments,
        protected TransactionService $transactionService,
    ) {}

    /**
     * Start a wallet top-up. With SSLCommerz the response carries the checkout URL to send the
     * customer to; the wallet is credited once the payment is validated.
     */
    public function store(TopUpRequest $request): JsonResponse
    {
        if ($this->payments->gateway() === TopUpPaymentService::GATEWAY_SIMULATED) {
            $transaction = $this->transactionService->topUp(
                user: $request->user(),
                amount: $request->validated('amount'),
                idempotencyKey: $request->validated('idempotency_key'),
                description: $request->validated('description'),
            );

            return response()->json([
                'success' => true,
                'message' => 'Top-up completed successfully.',
                'data' => [
                    'transaction' => new TransactionResource($transaction),
                    'payment' => null,
                ],
            ], 201);
        }

        $started = $this->payments->start(
            user: $request->user(),
            amount: $request->validated('amount'),
            idempotencyKey: $request->validated('idempotency_key'),
            description: $request->validated('description'),
        );

        return response()->json([
            'success' => true,
            'message' => $started['redirect_url']
                ? 'Continue to the payment page to complete your top-up.'
                : 'This top-up is no longer awaiting payment.',
            'data' => [
                'transaction' => new TransactionResource($started['transaction']),
                'payment' => [
                    'gateway' => TopUpPaymentService::GATEWAY_SSLCOMMERZ,
                    'redirect_url' => $started['redirect_url'],
                ],
            ],
        ], 201);
    }

    /**
     * Ask the gateway for the latest state of one of the caller's own top-ups.
     */
    public function refresh(Request $request, Transaction $transaction): JsonResponse
    {
        if ($transaction->type !== 'TOP_UP'
            || $transaction->external_reference === null
            || (int) $transaction->initiated_by !== (int) $request->user()->id) {
            throw new NotFoundHttpException;
        }

        $transaction = $this->payments->settle($transaction);

        return response()->json([
            'success' => true,
            'data' => [
                'transaction' => new TransactionResource($transaction),
            ],
        ]);
    }
}
