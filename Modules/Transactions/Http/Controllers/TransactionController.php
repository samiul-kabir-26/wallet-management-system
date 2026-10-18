<?php

namespace Modules\Transactions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Transactions\Http\Requests\AgentCashInRequest;
use Modules\Transactions\Http\Requests\AgentWithdrawalRequest;
use Modules\Transactions\Http\Requests\ApproveWithdrawalRequest;
use Modules\Transactions\Http\Requests\CashOutRequest;
use Modules\Transactions\Http\Requests\IssueFloatRequest;
use Modules\Transactions\Http\Requests\RejectWithdrawalRequest;
use Modules\Transactions\Http\Requests\ReverseTransactionRequest;
use Modules\Transactions\Http\Requests\TransferRequest;
use Modules\Transactions\Resources\CapResource;
use Modules\Transactions\Resources\TransactionResource;
use Modules\Transactions\Services\CapService;
use Modules\Transactions\Services\FeeCalculator;
use Modules\Transactions\Services\ReversalService;
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
     * Show the authenticated user's daily and monthly cap usage as it applies right now.
     */
    public function myCaps(Request $request, CapService $capService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Older accounts may predate cap rows; refresh() loads the database defaults after creation
        $cap = $user->cap ?? $user->cap()->create()->refresh();

        return response()->json([
            'success' => true,
            'data' => [
                'caps' => new CapResource($capService->usageSnapshot($cap)),
            ],
        ]);
    }

    /**
     * Preview what a cash-out or transfer will cost, using the same fee rules the transaction applies.
     */
    public function quote(Request $request, FeeCalculator $feeCalculator): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:CASH_OUT,TRANSFER'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
        ]);

        $amount = number_format((float) $validated['amount'], 2, '.', '');
        $fee = $validated['type'] === 'CASH_OUT'
            ? $feeCalculator->calculate($amount)
            : ['rate' => '0.0000', 'amount' => '0.00'];

        return response()->json([
            'success' => true,
            'data' => [
                'quote' => [
                    'type' => $validated['type'],
                    'amount' => (float) $amount,
                    'fee_rate' => (float) $fee['rate'],
                    'fee_amount' => (float) $fee['amount'],
                    'total' => (float) bcadd($amount, $fee['amount'], 2),
                ],
            ],
        ]);
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
            'message' => 'Withdrawal request submitted and awaiting approval.',
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
            'type' => ['sometimes', 'string', 'in:TOP_UP,CASH_IN,CASH_OUT,TRANSFER,AGENT_WITHDRAWAL,COMMISSION_PAYOUT,FLOAT_ISSUE,SIGNUP_BONUS,REVERSAL'],
            'status' => ['sometimes', 'string', 'in:COMPLETED,PENDING,FAILED,REJECTED'],
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
            'type' => ['sometimes', 'string', 'in:TOP_UP,CASH_IN,CASH_OUT,TRANSFER,AGENT_WITHDRAWAL,COMMISSION_PAYOUT,FLOAT_ISSUE,SIGNUP_BONUS,REVERSAL'],
            'status' => ['sometimes', 'string', 'in:COMPLETED,PENDING,FAILED,REJECTED'],
            'search' => ['sometimes', 'string', 'max:100'],
            'party_id' => ['sometimes', 'integer', 'exists:users,id'],
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

    /**
     * Issue e-money float to an approved agent by an authorized admin.
     */
    public function adminIssueFloat(IssueFloatRequest $request): JsonResponse
    {
        $agentId = $request->validated('agent_id');
        if (! $agentId && $request->filled('agent_phone')) {
            $agent = User::where('phone_number', $request->validated('agent_phone'))
                ->where('is_deleted', false)
                ->firstOrFail();
        } else {
            $agent = User::where('id', $agentId)
                ->where('is_deleted', false)
                ->firstOrFail();
        }

        $transaction = $this->transactionService->issueFloat(
            admin: $request->user(),
            agent: $agent,
            amount: $request->validated('amount'),
            externalReference: $request->validated('external_reference'),
            idempotencyKey: $request->validated('idempotency_key'),
            description: $request->validated('description'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Float issued successfully.',
            'data' => [
                'transaction' => new TransactionResource($transaction),
            ],
        ], 201);
    }

    /**
     * Approve a pending agent bank withdrawal after the payout was sent.
     */
    public function approveWithdrawal(ApproveWithdrawalRequest $request, Transaction $transaction): JsonResponse
    {
        $approved = $this->transactionService->approveWithdrawal(
            withdrawal: $transaction,
            reviewer: $request->user(),
            externalReference: $request->validated('external_reference'),
            note: $request->validated('note'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal approved.',
            'data' => [
                'transaction' => new TransactionResource($approved),
            ],
        ]);
    }

    /**
     * Reject a pending agent bank withdrawal and refund the held amount.
     */
    public function rejectWithdrawal(RejectWithdrawalRequest $request, Transaction $transaction): JsonResponse
    {
        $rejected = $this->transactionService->rejectWithdrawal(
            withdrawal: $transaction,
            reviewer: $request->user(),
            reason: $request->validated('reason'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal rejected and funds returned to the agent wallet.',
            'data' => [
                'transaction' => new TransactionResource($rejected),
            ],
        ]);
    }

    /**
     * Reverse a completed transfer, cash-in or cash-out with a new REVERSAL transaction.
     */
    public function reverse(ReverseTransactionRequest $request, Transaction $transaction, ReversalService $reversals): JsonResponse
    {
        $reversal = $reversals->reverse(
            original: $transaction,
            staff: $request->user(),
            reason: $request->validated('reason'),
        );

        return response()->json([
            'success' => true,
            'message' => "Transaction #{$transaction->id} reversed.",
            'data' => [
                'transaction' => new TransactionResource($reversal->load(['sender', 'recipient', 'agent', 'user'])),
            ],
        ], 201);
    }
}
