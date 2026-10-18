<?php

namespace Modules\Payments\Services;

use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Modules\Payments\Exceptions\PaymentGatewayException;
use Modules\Transactions\Services\TransactionService;

/**
 * Finishes gateway top-ups whose IPN or browser return never arrived.
 *
 * Each open top-up is looked up with the gateway by tran_id and settled by the same idempotent path as
 * the callbacks, so running this next to a late IPN can never credit twice. A top-up the gateway still
 * has no payment for after the expiry window is marked FAILED. Nothing is expired when the gateway
 * cannot be reached, because an unanswered lookup proves nothing about the payment.
 */
class TopUpReconciler
{
    public function __construct(
        protected TopUpPaymentService $payments,
        protected TransactionService $transactionService,
    ) {}

    /**
     * Gateway top-ups the reconciler may act on: awaiting payment, or paid but held only because the
     * wallet was blocked (credited automatically once it is unblocked). Other holds wait for staff.
     *
     * @return Builder<Transaction>
     */
    public static function openTopUps(): Builder
    {
        return Transaction::query()
            ->where('type', 'TOP_UP')
            ->where('status', 'PENDING')
            ->whereNotNull('external_reference')
            ->where(fn (Builder $query) => $query
                ->whereNull('meta->on_hold')
                ->orWhere('meta->on_hold->reason', 'WALLET_BLOCKED'));
    }

    /**
     * Reconcile every open top-up older than the check delay.
     *
     * @return array{checked: int, completed: int, failed: int, held: int, pending: int, errors: int}
     */
    public function run(): array
    {
        $summary = ['checked' => 0, 'completed' => 0, 'failed' => 0, 'held' => 0, 'pending' => 0, 'errors' => 0];
        $checkBefore = now()->subMinutes((int) config('payments.reconciliation.check_after_minutes', 10));

        self::openTopUps()
            ->where('created_at', '<=', $checkBefore)
            ->chunkById(100, function ($topUps) use (&$summary): void {
                foreach ($topUps as $topUp) {
                    $summary['checked']++;

                    try {
                        $result = $this->reconcile($topUp);
                    } catch (PaymentGatewayException $e) {
                        $summary['errors']++;
                        Log::warning('Top-up reconciliation could not reach the gateway', [
                            'transaction_id' => $topUp->id,
                            'message' => $e->getMessage(),
                        ]);

                        continue;
                    }

                    $outcome = match (true) {
                        $result->status === 'COMPLETED' => 'completed',
                        $result->status === 'FAILED' => 'failed',
                        isset($result->meta['on_hold']) => 'held',
                        default => 'pending',
                    };
                    $summary[$outcome]++;
                }
            });

        if ($summary['checked'] > 0) {
            Log::info('Top-up reconciliation finished', $summary);
        }

        return $summary;
    }

    /**
     * Settle one top-up with the gateway, expiring it if it has stayed unpaid past the expiry window.
     *
     * @throws PaymentGatewayException
     */
    public function reconcile(Transaction $topUp): Transaction
    {
        $settled = $this->payments->settle($topUp, failWhenUnpaid: true);

        if ($settled->status === 'PENDING'
            && ! isset($settled->meta['on_hold'])
            && $this->hasExpired($settled->created_at)) {
            return $this->transactionService->failPendingTopUp($settled, 'EXPIRED', [
                'expired_after_hours' => (int) config('payments.reconciliation.expire_after_hours', 24),
            ]);
        }

        return $settled;
    }

    private function hasExpired(?CarbonInterface $createdAt): bool
    {
        $hours = (int) config('payments.reconciliation.expire_after_hours', 24);

        return $createdAt !== null && $createdAt->lte(now()->subHours($hours));
    }
}
