<?php

namespace Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Payments\Exceptions\PaymentGatewayException;
use Modules\Payments\Services\SslCommerzClient;
use Modules\Payments\Services\TopUpPaymentService;

/**
 * Receives SSLCommerz's IPN and the customer's browser coming back from the checkout page.
 *
 * Neither request is trusted: each one only tells us which top-up to check, and TopUpPaymentService
 * asks SSLCommerz itself before any money moves.
 */
class SslCommerzCallbackController extends Controller
{
    public function __construct(
        protected TopUpPaymentService $payments,
    ) {}

    /**
     * Instant Payment Notification, posted server to server by SSLCommerz.
     */
    public function ipn(Request $request): JsonResponse
    {
        $this->settleFromRequest($request, failWhenUnpaid: true);

        return response()->json(['success' => true]);
    }

    /**
     * The customer's browser returning from SSLCommerz (success, fail or cancel). Settles the top-up,
     * then sends the customer to the result page in the user panel.
     */
    public function redirect(Request $request, string $outcome): RedirectResponse
    {
        $topUp = $this->settleFromRequest($request, failWhenUnpaid: $outcome !== 'success');

        if (! $topUp) {
            return redirect('/user/wallet');
        }

        return redirect()->route('user.top-up.result', [
            'transaction' => $topUp->id,
            'outcome' => $outcome,
        ]);
    }

    /**
     * Settle the top-up named by the request's tran_id, if there is one.
     */
    private function settleFromRequest(Request $request, bool $failWhenUnpaid): ?Transaction
    {
        $tranId = $request->string('tran_id')->toString();
        $topUp = $tranId !== '' ? $this->payments->findByGatewayReference($tranId) : null;

        if (! $topUp) {
            return null;
        }

        // Only a val_id that comes with a paid status is worth validating; otherwise look the tran_id up
        $valId = in_array($request->input('status'), SslCommerzClient::PAID_STATUSES, true)
            ? ($request->string('val_id')->toString() ?: null)
            : null;

        try {
            return $this->payments->settle($topUp, $valId, $failWhenUnpaid);
        } catch (PaymentGatewayException $e) {
            // The customer can retry from the result page; the top-up stays as it was
            Log::warning('SSLCommerz callback could not reach the gateway', [
                'transaction_id' => $topUp->id,
                'message' => $e->getMessage(),
            ]);

            return $topUp;
        }
    }
}
