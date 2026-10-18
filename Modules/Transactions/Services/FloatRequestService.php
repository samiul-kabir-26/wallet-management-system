<?php

namespace Modules\Transactions\Services;

use App\Models\AgentInfo;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\FloatRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Approvals\Exceptions\ApprovalConflictException;
use Modules\Approvals\Services\ApprovalService;
use Modules\Transactions\Notifications\FloatRequestReviewedNotification;
use Modules\Users\Exceptions\AgentNotApprovedException;
use Modules\Wallets\Exceptions\WalletBlockedException;
use Modules\Wallets\Exceptions\WalletNotFoundException;
use Throwable;

/**
 * Agents ask for e-money float against a bank deposit; staff holding agents.issue_float check the slip
 * against the bank statement and approve or reject.
 *
 * Approval issues float through TransactionService::issueFloat() with the bank reference as the
 * transaction's unique external_reference, so one deposit can never be credited twice. When
 * maker-checker is on, approving forwards a FLOAT_ISSUE approval request instead, and the float request
 * is completed when a second staff member approves that (ApprovalService calls recordIssued()).
 */
class FloatRequestService
{
    public function __construct(
        protected TransactionService $transactionService,
        protected ApprovalService $approvals,
    ) {}

    /**
     * Record an agent's float request and store the deposit slip on the private disk.
     *
     * @param  array{amount: string|float, bank_reference: string, deposited_at: string, note?: string|null}  $data
     *
     * @throws AgentNotApprovedException
     * @throws WalletNotFoundException
     * @throws WalletBlockedException
     * @throws ValidationException
     */
    public function submit(User $agent, array $data, UploadedFile $slip): FloatRequest
    {
        if (AgentInfo::where('user_id', $agent->id)->value('status') !== 'APPROVED') {
            throw new AgentNotApprovedException;
        }

        $wallet = Wallet::where('user_id', $agent->id)->where('kind', Wallet::KIND_AGENT)->first();
        if (! $wallet) {
            throw new WalletNotFoundException;
        }
        if ($wallet->is_blocked) {
            throw new WalletBlockedException;
        }

        $reference = trim($data['bank_reference']);
        if (FloatRequest::where('bank_reference', $reference)->where('status', FloatRequest::PENDING)->exists()) {
            throw ValidationException::withMessages([
                'bank_reference' => ['A float request with this bank reference is already waiting for review.'],
            ]);
        }

        $path = $slip->store(FloatRequest::SLIP_DIRECTORY.'/'.$agent->id, 'local');
        if ($path === false) {
            throw ValidationException::withMessages(['slip' => ['The deposit slip could not be saved. Please try again.']]);
        }

        try {
            return DB::transaction(function () use ($agent, $data, $reference, $slip, $path): FloatRequest {
                $floatRequest = FloatRequest::create([
                    'agent_id' => $agent->id,
                    'amount' => number_format((float) $data['amount'], 2, '.', ''),
                    'bank_reference' => $reference,
                    'deposited_at' => $data['deposited_at'],
                    'slip_path' => $path,
                    'slip_original_name' => mb_substr($slip->getClientOriginalName(), 0, 255),
                    'slip_mime_type' => (string) $slip->getMimeType(),
                    'note' => $data['note'] ?? null,
                    'status' => FloatRequest::PENDING,
                ]);

                $this->audit('FLOAT_REQUEST_SUBMITTED', $floatRequest, $agent);

                return $floatRequest;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }
    }

    /**
     * Let the agent withdraw a request nobody has acted on yet.
     *
     * @throws AuthorizationException
     * @throws ApprovalConflictException
     */
    public function cancel(FloatRequest $floatRequest, User $agent): FloatRequest
    {
        return DB::transaction(function () use ($floatRequest, $agent): FloatRequest {
            $locked = $this->lockPending($floatRequest);

            if ((int) $locked->agent_id !== (int) $agent->id) {
                throw new AuthorizationException('You can only cancel your own float requests.');
            }
            if ($this->isAwaitingSecondApproval($locked)) {
                throw new ApprovalConflictException('Staff have already approved this request; it is waiting for a second approval.');
            }

            $locked->forceFill(['status' => FloatRequest::CANCELLED])->save();
            $this->audit('FLOAT_REQUEST_CANCELLED', $locked, $agent);

            return $locked;
        });
    }

    /**
     * Approve a request: issue the float now, or forward it for a second approval under maker-checker.
     *
     * @return array{float_request: FloatRequest, approval_request: ApprovalRequest|null}
     *
     * @throws AuthorizationException
     * @throws ApprovalConflictException
     */
    public function approve(FloatRequest $floatRequest, User $staff, ?string $note = null): array
    {
        return DB::transaction(function () use ($floatRequest, $staff, $note): array {
            $locked = $this->lockPending($floatRequest);
            $this->assertNotOwn($locked, $staff);

            if ($this->isAwaitingSecondApproval($locked)) {
                throw new ApprovalConflictException('This float request is already waiting for a second approval.');
            }

            $agent = User::findOrFail($locked->agent_id);

            if ($this->approvals->isRequired()) {
                $approval = $this->approvals->requestFloatIssue($staff, $agent, [
                    'amount' => (string) $locked->amount,
                    'external_reference' => $locked->bank_reference,
                    'description' => "Float request #{$locked->id}",
                    'float_request_id' => $locked->id,
                    'review_note' => $note,
                ]);

                $locked->forceFill(['approval_request_id' => $approval->id])->save();

                return ['float_request' => $locked, 'approval_request' => $approval];
            }

            $transaction = $this->transactionService->issueFloat(
                admin: $staff,
                agent: $agent,
                amount: (string) $locked->amount,
                externalReference: $locked->bank_reference,
                idempotencyKey: "float-request:{$locked->id}",
                description: "Float request #{$locked->id}",
            );

            return ['float_request' => $this->recordIssued($locked, $staff, $transaction, $note), 'approval_request' => null];
        });
    }

    /**
     * Reject a request; nothing is credited and the agent is told why.
     *
     * @throws AuthorizationException
     * @throws ApprovalConflictException
     */
    public function reject(FloatRequest $floatRequest, User $staff, string $reason): FloatRequest
    {
        return DB::transaction(function () use ($floatRequest, $staff, $reason): FloatRequest {
            $locked = $this->lockPending($floatRequest);
            $this->assertNotOwn($locked, $staff);

            if ($this->isAwaitingSecondApproval($locked)) {
                throw new ApprovalConflictException('This float request is waiting for a second approval; reject it from Approvals instead.');
            }

            $locked->forceFill([
                'status' => FloatRequest::REJECTED,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
                'review_note' => $reason,
            ])->save();

            $this->audit('FLOAT_REQUEST_REJECTED', $locked, $staff, ['reason' => $reason]);
            $locked->agent?->notify(new FloatRequestReviewedNotification($locked));

            return $locked;
        });
    }

    /**
     * Mark a request approved once its FLOAT_ISSUE transaction exists. Called directly, or by
     * ApprovalService when a second staff member approves a forwarded request.
     */
    public function recordIssued(FloatRequest $floatRequest, User $reviewer, Transaction $transaction, ?string $note = null): FloatRequest
    {
        $locked = FloatRequest::whereKey($floatRequest->id)->lockForUpdate()->firstOrFail();

        $locked->forceFill([
            'status' => FloatRequest::APPROVED,
            'transaction_id' => $transaction->id,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        $this->audit('FLOAT_REQUEST_APPROVED', $locked, $reviewer, ['transaction_id' => $transaction->id]);
        $locked->agent?->notify(new FloatRequestReviewedNotification($locked));

        return $locked;
    }

    /**
     * Put a forwarded request back in the review queue after its approval request was rejected or cancelled.
     */
    public function releaseFromApproval(ApprovalRequest $approval): void
    {
        FloatRequest::where('approval_request_id', $approval->id)
            ->where('status', FloatRequest::PENDING)
            ->update(['approval_request_id' => null]);
    }

    /**
     * Whether staff approved this request and it now waits for the second approver.
     */
    public function isAwaitingSecondApproval(FloatRequest $floatRequest): bool
    {
        return $floatRequest->approval_request_id !== null
            && ApprovalRequest::whereKey($floatRequest->approval_request_id)->where('status', ApprovalRequest::PENDING)->exists();
    }

    private function lockPending(FloatRequest $floatRequest): FloatRequest
    {
        $locked = FloatRequest::whereKey($floatRequest->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== FloatRequest::PENDING) {
            throw new ApprovalConflictException('This float request has already been reviewed.');
        }

        return $locked;
    }

    /**
     * Staff who are also the requesting agent cannot review their own request.
     *
     * @throws AuthorizationException
     */
    private function assertNotOwn(FloatRequest $floatRequest, User $staff): void
    {
        if ((int) $floatRequest->agent_id === (int) $staff->id) {
            throw new AuthorizationException('You cannot review your own float request.');
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(string $action, FloatRequest $floatRequest, User $actor, array $extra = []): void
    {
        AuditLog::create([
            'actor_id' => $actor->id,
            'action' => $action,
            'auditable_type' => (new User)->getMorphClass(),
            'auditable_id' => $floatRequest->agent_id,
            'metadata' => [
                'float_request_id' => $floatRequest->id,
                'amount' => (string) $floatRequest->amount,
                'bank_reference' => $floatRequest->bank_reference,
                ...$extra,
            ],
        ]);
    }
}
