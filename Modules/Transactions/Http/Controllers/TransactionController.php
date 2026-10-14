<?php

namespace Modules\Transactions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Transactions\Http\Requests\AgentCashInRequest;
use Modules\Transactions\Http\Requests\AgentWithdrawalRequest;
use Modules\Transactions\Http\Requests\CashOutRequest;
use Modules\Transactions\Http\Requests\TopUpRequest;
use Modules\Transactions\Http\Requests\TransferRequest;
use Modules\Transactions\Resources\TransactionResource;
use Modules\Transactions\Services\TransactionService;
use Modules\Wallets\Services\WalletService;

class TransactionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected TransactionService $transactionService,
        protected WalletService $walletService,
    ) {}

    /**
     * Top-up the authenticated user's wallet.
     */
    public function topUp(TopUpRequest $request): JsonResponse
    {
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
            ],
        ], 201);
    }

    /**
     * Agent withdraws accumulated funds from their own wallet.
     */
    public function agentWithdrawal(AgentWithdrawalRequest $request): JsonResponse
    {
        $transaction = $this->transactionService->agentWithdrawal(
            agent: $request->user(),
            amount: $request->validated('amount'),
            idempotencyKey: $request->validated('idempotency_key'),
            pin: (string) $request->validated('pin'),
            description: $request->validated('description'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal completed successfully.',
            'data' => [
                'transaction' => new TransactionResource($transaction),
            ],
        ], 201);
    }

    /**
     * Perform cash-in to a customer by an approved agent.
     */
    public function agentCashIn(AgentCashInRequest $request): JsonResponse
    {
        $transaction = $this->transactionService->cashIn(
            agent: $request->user(),
            customerPhone: $request->validated('customer_phone'),
            amount: $request->validated('amount'),
            idempotencyKey: $request->validated('idempotency_key'),
            pin: (string) $request->validated('pin'),
            description: $request->validated('description'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Cash-in completed successfully.',
            'data' => [
                'transaction' => new TransactionResource($transaction),
            ],
        ], 201);
    }

    /**
     * Perform cash-out via an approved agent.
     */
    public function cashOut(CashOutRequest $request): JsonResponse
    {
        $transaction = $this->transactionService->cashOut(
            user: $request->user(),
            agentPhone: $request->validated('agent_phone'),
            amount: $request->validated('amount'),
            idempotencyKey: $request->validated('idempotency_key'),
            pin: (string) $request->validated('pin'),
            description: $request->validated('description'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Cash-out completed successfully.',
            'data' => [
                'transaction' => new TransactionResource($transaction),
            ],
        ], 201);
    }

    /**
     * Transfer funds to another user.
     */
    public function transfer(TransferRequest $request): JsonResponse
    {
        $transaction = $this->transactionService->transfer(
            sender: $request->user(),
            recipientPhone: (string) $request->validated('recipient_phone'),
            amount: $request->validated('amount'),
            idempotencyKey: $request->validated('idempotency_key'),
            pin: (string) $request->validated('pin'),
            description: $request->validated('description'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Transfer completed successfully.',
            'data' => [
                'transaction' => new TransactionResource($transaction),
            ],
        ], 201);
    }

    /**
     * Get transaction history for the authenticated user.
     */
    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['sometimes', 'string', 'in:TOP_UP,CASH_IN,CASH_OUT,TRANSFER,AGENT_WITHDRAWAL,COMMISSION_PAYOUT'],
            'status' => ['sometimes', 'string', 'in:COMPLETED,PENDING,FAILED,CANCELLED,REVERSED'],
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $actor = $request->user();
        $isStaff = $actor->can('transactions.view_any');
        $perPage = (int) ($validated['per_page'] ?? 15);

        if ($isStaff) {
            $paginator = $this->transactionService->listAllTransactions(
                filters: $validated,
                perPage: $perPage,
            );
        } else {
            $wallet = $this->walletService->findWalletForToken($actor);
            if ($wallet !== null) {
                $request->attributes->set('viewer_wallet_id', $wallet->id);
            }

            $paginator = $this->transactionService->listUserHistory(
                wallet: $wallet,
                filters: $validated,
                perPage: $perPage,
            );
        }

        return $this->paginatedResponse(
            $paginator,
            TransactionResource::collection($paginator),
        );
    }

    /**
     * Get details for a specific transaction.
     */
    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('view', $transaction);

        $actor = $request->user();
        $isStaff = $actor->can('transactions.view_any');

        if (! $isStaff) {
            $wallet = $this->walletService->findWalletForToken($actor);
            if ($wallet !== null) {
                $request->attributes->set('viewer_wallet_id', $wallet->id);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'transaction' => new TransactionResource($transaction),
            ],
        ]);
    }

    /**
     * Admin audit list of all transactions in the system.
     */
    public function adminAll(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Transaction::class);

        $validated = $request->validate([
            'type' => ['sometimes', 'string', 'in:TOP_UP,CASH_IN,CASH_OUT,TRANSFER,AGENT_WITHDRAWAL,COMMISSION_PAYOUT'],
            'status' => ['sometimes', 'string', 'in:COMPLETED,PENDING,FAILED,CANCELLED,REVERSED'],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'sender_id' => ['sometimes', 'integer', 'exists:users,id'],
            'recipient_id' => ['sometimes', 'integer', 'exists:users,id'],
            'agent_id' => ['sometimes', 'integer', 'exists:users,id'],
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);
        $paginator = $this->transactionService->listAllTransactions(
            filters: $validated,
            perPage: $perPage,
        );

        return $this->paginatedResponse(
            $paginator,
            TransactionResource::collection($paginator),
        );
    }
}
