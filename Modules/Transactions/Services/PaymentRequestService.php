<?php

namespace Modules\Transactions\Services;

use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Exceptions\UserNotFoundException;
use Modules\Transactions\Exceptions\PaymentRequestClosedException;
use Modules\Transactions\Notifications\PaymentRequestNotification;

/**
 * Customers ask each other for money.
 *
 * Paying a request runs the normal TransactionService::transfer() (PIN, caps, balance, locks, receipts)
 * inside the transaction that marks the request PAID, so a failed transfer leaves the request open and
 * a request can only ever be settled by one transfer (unique transaction_id).
 */
class PaymentRequestService
{
    public function __construct(
        protected TransactionService $transactionService,
    ) {}

    /**
     * Ask another customer for money.
     *
     * @throws UserNotFoundException
     * @throws ValidationException
     */
    public function create(User $requester, string $payerPhone, string|float $amount, ?string $note = null): PaymentRequest
    {
        $payer = User::where('phone_number', $payerPhone)->where('is_deleted', false)->first();
        if (! $payer) {
            throw new UserNotFoundException('No customer has this phone number.');
        }
        if ($payer->id === $requester->id) {
            throw ValidationException::withMessages(['payer_phone' => ['You cannot request money from yourself.']]);
        }
        // The payer must be able to send money, which needs a customer account
        if (! $payer->isUser()) {
            throw ValidationException::withMessages(['payer_phone' => ['This number does not belong to a customer account.']]);
        }

        $limits = config('transactions.payment_requests');
        $pendingToPayer = PaymentRequest::where('requester_id', $requester->id)
            ->where('payer_id', $payer->id)
            ->where('status', PaymentRequest::PENDING)
            ->where('expires_at', '>', now())
            ->count();
        if ($pendingToPayer >= (int) $limits['max_pending_per_payer']) {
            throw ValidationException::withMessages(['payer_phone' => ['You already have the maximum number of open requests to this customer.']]);
        }

        $pendingTotal = PaymentRequest::where('requester_id', $requester->id)
            ->where('status', PaymentRequest::PENDING)
            ->where('expires_at', '>', now())
            ->count();
        if ($pendingTotal >= (int) $limits['max_pending_total']) {
            throw ValidationException::withMessages(['payer_phone' => ['You have too many open requests. Cancel some before asking again.']]);
        }

        return DB::transaction(function () use ($requester, $payer, $amount, $note, $limits): PaymentRequest {
            $request = PaymentRequest::create([
                'requester_id' => $requester->id,
                'payer_id' => $payer->id,
                'amount' => number_format((float) $amount, 2, '.', ''),
                'note' => $note,
                'status' => PaymentRequest::PENDING,
                'expires_at' => now()->addDays((int) $limits['expire_days']),
            ]);

            $payer->notify(new PaymentRequestNotification($request, PaymentRequestNotification::EVENT_REQUESTED));

            return $request;
        });
    }

    /**
     * Pay a request addressed to the caller, as a normal PIN-verified transfer to the requester.
     *
     * @throws AuthorizationException
     * @throws PaymentRequestClosedException
     */
    public function pay(PaymentRequest $paymentRequest, User $payer, string $pin, string $idempotencyKey): PaymentRequest
    {
        return DB::transaction(function () use ($paymentRequest, $payer, $pin, $idempotencyKey): PaymentRequest {
            $locked = $this->lockOpen($paymentRequest);

            if ((int) $locked->payer_id !== (int) $payer->id) {
                throw new AuthorizationException('Only the customer this request was sent to can pay it.');
            }

            $requester = User::findOrFail($locked->requester_id);
            $transfer = $this->transactionService->transfer(
                sender: $payer,
                recipientPhone: (string) $requester->phone_number,
                amount: (string) $locked->amount,
                idempotencyKey: $idempotencyKey,
                pin: $pin,
                description: "Payment request #{$locked->id}".($locked->note ? ": {$locked->note}" : ''),
            );

            $transfer->forceFill(['meta' => [...($transfer->meta ?? []), 'payment_request_id' => $locked->id]])->save();

            $locked->forceFill([
                'status' => PaymentRequest::PAID,
                'transaction_id' => $transfer->id,
                'responded_at' => now(),
            ])->save();

            $requester->notify(new PaymentRequestNotification($locked, PaymentRequestNotification::EVENT_PAID));

            return $locked;
        });
    }

    /**
     * Decline a request addressed to the caller.
     *
     * @throws AuthorizationException
     * @throws PaymentRequestClosedException
     */
    public function decline(PaymentRequest $paymentRequest, User $payer): PaymentRequest
    {
        return DB::transaction(function () use ($paymentRequest, $payer): PaymentRequest {
            $locked = $this->lockOpen($paymentRequest);

            if ((int) $locked->payer_id !== (int) $payer->id) {
                throw new AuthorizationException('Only the customer this request was sent to can decline it.');
            }

            $locked->forceFill(['status' => PaymentRequest::DECLINED, 'responded_at' => now()])->save();
            $locked->requester?->notify(new PaymentRequestNotification($locked, PaymentRequestNotification::EVENT_DECLINED));

            return $locked;
        });
    }

    /**
     * Withdraw one of the caller's own open requests.
     *
     * @throws AuthorizationException
     * @throws PaymentRequestClosedException
     */
    public function cancel(PaymentRequest $paymentRequest, User $requester): PaymentRequest
    {
        return DB::transaction(function () use ($paymentRequest, $requester): PaymentRequest {
            $locked = $this->lockOpen($paymentRequest);

            if ((int) $locked->requester_id !== (int) $requester->id) {
                throw new AuthorizationException('Only the customer who made this request can cancel it.');
            }

            $locked->forceFill(['status' => PaymentRequest::CANCELLED, 'responded_at' => now()])->save();

            return $locked;
        });
    }

    /**
     * Mark requests past their expiry as EXPIRED. Returns how many were closed.
     */
    public function expireOverdue(): int
    {
        return PaymentRequest::where('status', PaymentRequest::PENDING)
            ->where('expires_at', '<=', now())
            ->update(['status' => PaymentRequest::EXPIRED, 'updated_at' => now()]);
    }

    private function lockOpen(PaymentRequest $paymentRequest): PaymentRequest
    {
        $locked = PaymentRequest::whereKey($paymentRequest->id)->lockForUpdate()->firstOrFail();

        if ($locked->status === PaymentRequest::PENDING && ! $locked->expires_at->isFuture()) {
            throw new PaymentRequestClosedException('This payment request has expired.');
        }
        if ($locked->status !== PaymentRequest::PENDING) {
            throw new PaymentRequestClosedException;
        }

        return $locked;
    }
}
