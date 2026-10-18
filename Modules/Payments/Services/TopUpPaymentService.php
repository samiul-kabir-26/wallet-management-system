<?php

namespace Modules\Payments\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Payments\Exceptions\PaymentGatewayException;
use Modules\Transactions\Services\TransactionService;

/**
 * Wallet top-ups paid through SSLCommerz.
 *
 * The wallet is credited only after this server has asked SSLCommerz to validate the payment. Browser
 * redirects and IPN posts are treated as hints to go and check, never as proof of payment.
 */
class TopUpPaymentService
{
    public const string GATEWAY_SSLCOMMERZ = 'sslcommerz';

    public const string GATEWAY_SIMULATED = 'simulated';

    public function __construct(
        protected SslCommerzClient $client,
        protected TransactionService $transactionService,
    ) {}

    /**
     * The gateway top-ups use. Production always uses SSLCommerz, so no configuration mistake can turn
     * top-up back into free money.
     */
    public function gateway(): string
    {
        if (app()->isProduction()) {
            return self::GATEWAY_SSLCOMMERZ;
        }

        return config('payments.top_up_gateway') === self::GATEWAY_SIMULATED
            ? self::GATEWAY_SIMULATED
            : self::GATEWAY_SSLCOMMERZ;
    }

    /**
     * Start a top-up: record it as PENDING and open an SSLCommerz checkout session.
     *
     * Replaying the idempotency key of a top-up that is still awaiting payment returns the same
     * checkout URL, so a double-clicked button never opens two payments.
     *
     * @return array{transaction: Transaction, redirect_url: string|null}
     *
     * @throws PaymentGatewayException
     */
    public function start(User $user, string|float $amount, string $idempotencyKey, ?string $description = null): array
    {
        $transaction = $this->transactionService->createPendingTopUp(
            user: $user,
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            gatewayReference: 'TOPUP-'.Str::upper((string) Str::ulid()),
            gateway: self::GATEWAY_SSLCOMMERZ,
            description: $description,
        );

        if ($transaction->status !== 'PENDING' || isset($transaction->meta['on_hold'])) {
            return ['transaction' => $transaction, 'redirect_url' => null];
        }

        $existingUrl = $transaction->meta['checkout']['redirect_url'] ?? null;
        if ($existingUrl) {
            return ['transaction' => $transaction, 'redirect_url' => $existingUrl];
        }

        try {
            $session = $this->client->createSession($this->sessionPayload($user, $transaction));
        } catch (PaymentGatewayException $e) {
            $this->transactionService->failPendingTopUp($transaction, 'SESSION_FAILED', ['message' => $e->getMessage()]);

            throw $e;
        }

        $transaction->forceFill([
            'meta' => [
                ...($transaction->meta ?? []),
                'checkout' => [
                    'redirect_url' => $session['GatewayPageURL'],
                    'session_key' => $session['sessionkey'] ?? null,
                    'created_at' => now()->toIso8601String(),
                ],
            ],
        ])->save();

        return ['transaction' => $transaction, 'redirect_url' => $session['GatewayPageURL']];
    }

    /**
     * Find the top-up behind an SSLCommerz tran_id.
     */
    public function findByGatewayReference(string $tranId): ?Transaction
    {
        return Transaction::where('external_reference', $tranId)
            ->where('type', 'TOP_UP')
            ->first();
    }

    /**
     * Bring a top-up in line with what SSLCommerz says about it.
     *
     * With a val_id (IPN or success redirect) that val_id is validated. Without one, SSLCommerz is asked
     * for every attempt recorded against the tran_id. The wallet is credited only for a validated
     * payment whose tran_id, amount and currency match the stored row. $failWhenUnpaid marks the
     * top-up FAILED when SSLCommerz confirms nothing was paid; it is set for fail and cancel signals.
     *
     * @throws PaymentGatewayException
     */
    public function settle(Transaction $topUp, ?string $valId = null, bool $failWhenUnpaid = false): Transaction
    {
        if ($topUp->status === 'COMPLETED') {
            return $topUp;
        }

        $tranId = (string) $topUp->external_reference;

        if ($valId === null) {
            $attempts = $this->client->findByTranId($tranId);
            $paid = collect($attempts)->first(
                fn (array $attempt): bool => in_array($attempt['status'] ?? null, SslCommerzClient::PAID_STATUSES, true)
            );

            if (! $paid) {
                $stillOpen = collect($attempts)->contains(
                    fn (array $attempt): bool => ! in_array($attempt['status'] ?? null, SslCommerzClient::UNPAID_STATUSES, true)
                );

                return $failWhenUnpaid && ! $stillOpen
                    ? $this->transactionService->failPendingTopUp($topUp, 'NOT_PAID', [
                        'gateway_statuses' => collect($attempts)->pluck('status')->filter()->values()->all(),
                    ])
                    : $topUp->refresh();
            }

            $valId = (string) ($paid['val_id'] ?? '');
            if ($valId === '') {
                return $topUp->refresh();
            }
        }

        $validation = $this->client->validate($valId);

        // A val_id SSLCommerz does not vouch for proves nothing; it may be forged, so the row is not failed either
        if (! in_array($validation['status'] ?? null, SslCommerzClient::PAID_STATUSES, true)
            || ($validation['tran_id'] ?? null) !== $tranId) {
            Log::warning('SSLCommerz validation did not confirm a top-up', [
                'transaction_id' => $topUp->id,
                'tran_id' => $tranId,
                'validation_status' => $validation['status'] ?? null,
                'validation_tran_id' => $validation['tran_id'] ?? null,
            ]);

            return $topUp->refresh();
        }

        $payment = $this->paymentDetails($validation);

        if (! $this->matchesStoredAmount($topUp, $validation)) {
            Log::warning('SSLCommerz payment amount does not match the top-up', [
                'transaction_id' => $topUp->id,
                'expected' => $topUp->amount,
                'paid' => $validation['amount'] ?? null,
                'currency' => $validation['currency'] ?? null,
            ]);

            return $this->transactionService->holdPendingTopUp($topUp, 'AMOUNT_MISMATCH', $payment);
        }

        if ((string) ($validation['risk_level'] ?? '0') === '1') {
            return $this->transactionService->holdPendingTopUp($topUp, 'HIGH_RISK', $payment);
        }

        return $this->transactionService->completePendingTopUp($topUp, $payment);
    }

    /**
     * @param  array<string, mixed>  $validation
     */
    private function matchesStoredAmount(Transaction $topUp, array $validation): bool
    {
        if (! is_numeric($validation['amount'] ?? null)) {
            return false;
        }

        $paidAmount = number_format((float) $validation['amount'], 2, '.', '');
        $paidCurrency = $validation['currency_type'] ?? $validation['currency'] ?? null;

        return bccomp($paidAmount, number_format((float) $topUp->amount, 2, '.', ''), 2) === 0
            && $paidCurrency === ($topUp->currency ?? 'BDT');
    }

    /**
     * The validated payment details worth keeping. The card number arrives masked from SSLCommerz.
     *
     * @param  array<string, mixed>  $validation
     * @return array<string, mixed>
     */
    private function paymentDetails(array $validation): array
    {
        return [
            'gateway' => self::GATEWAY_SSLCOMMERZ,
            'val_id' => $validation['val_id'] ?? null,
            'bank_tran_id' => $validation['bank_tran_id'] ?? null,
            'amount' => $validation['amount'] ?? null,
            'currency' => $validation['currency_type'] ?? $validation['currency'] ?? null,
            'card_type' => $validation['card_type'] ?? null,
            'card_no' => $validation['card_no'] ?? null,
            'card_brand' => $validation['card_brand'] ?? null,
            'card_issuer' => $validation['card_issuer'] ?? null,
            'risk_level' => $validation['risk_level'] ?? null,
            'tran_date' => $validation['tran_date'] ?? null,
            'validated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The checkout session fields SSLCommerz requires. Customers without an email or address get
     * neutral placeholders, which the sandbox accepts.
     *
     * @return array<string, mixed>
     */
    private function sessionPayload(User $user, Transaction $topUp): array
    {
        return [
            'total_amount' => number_format((float) $topUp->amount, 2, '.', ''),
            'currency' => $topUp->currency ?? 'BDT',
            'tran_id' => $topUp->external_reference,
            'success_url' => route('payments.sslcommerz.redirect', ['outcome' => 'success']),
            'fail_url' => route('payments.sslcommerz.redirect', ['outcome' => 'fail']),
            'cancel_url' => route('payments.sslcommerz.redirect', ['outcome' => 'cancel']),
            'ipn_url' => route('payments.sslcommerz.ipn'),
            'cus_name' => $user->name,
            'cus_email' => $user->email ?: 'customer@example.com',
            'cus_phone' => $user->phone_number ?: '01700000000',
            'cus_add1' => $user->address ?: 'Dhaka',
            'cus_city' => 'Dhaka',
            'cus_postcode' => '1000',
            'cus_country' => 'Bangladesh',
            'shipping_method' => 'NO',
            'num_of_item' => 1,
            'product_name' => 'Wallet top-up',
            'product_category' => 'Wallet',
            'product_profile' => 'non-physical-goods',
            'value_a' => (string) $topUp->id,
        ];
    }
}
