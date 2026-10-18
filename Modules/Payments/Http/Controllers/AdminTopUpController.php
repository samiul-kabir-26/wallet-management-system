<?php

namespace Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payments\Services\TopUpReconciler;
use Modules\Transactions\Resources\TransactionResource;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AdminTopUpController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected TopUpReconciler $reconciler,
    ) {}

    /**
     * Gateway top-ups that are not finished: awaiting payment, or paid and held for review.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Transaction::class);

        $validated = $request->validate([
            'state' => ['sometimes', 'string', 'in:all,awaiting_payment,on_hold'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Transaction::query()
            ->with(['sender', 'recipient', 'agent', 'user'])
            ->where('type', 'TOP_UP')
            ->where('status', 'PENDING')
            ->whereNotNull('external_reference')
            ->oldest('id');

        match ($validated['state'] ?? 'all') {
            'awaiting_payment' => $query->whereNull('meta->on_hold'),
            'on_hold' => $query->whereNotNull('meta->on_hold'),
            default => null,
        };

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 15));

        return $this->paginatedResponse($paginator, TransactionResource::collection($paginator));
    }

    /**
     * Check one top-up with the gateway now, using the same rules as the scheduled reconciliation.
     */
    public function reconcile(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('viewAny', Transaction::class);

        if ($transaction->type !== 'TOP_UP' || $transaction->external_reference === null) {
            throw new NotFoundHttpException;
        }

        $statusBefore = $transaction->status;
        $holdBefore = $transaction->meta['on_hold']['reason'] ?? null;

        $reconciled = $this->reconciler->reconcile($transaction);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'TOP_UP_RECONCILED',
            'auditable_type' => (new User)->getMorphClass(),
            'auditable_id' => $reconciled->user_id,
            'metadata' => [
                'transaction_id' => $reconciled->id,
                'status_before' => $statusBefore,
                'status_after' => $reconciled->status,
                'hold_before' => $holdBefore,
                'hold_after' => $reconciled->meta['on_hold']['reason'] ?? null,
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Top-up checked with the payment gateway.',
            'data' => [
                'transaction' => new TransactionResource($reconciled->load(['sender', 'recipient', 'agent', 'user'])),
            ],
        ]);
    }
}
