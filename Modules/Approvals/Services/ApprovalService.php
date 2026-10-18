<?php

namespace Modules\Approvals\Services;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Approvals\Exceptions\ApprovalConflictException;
use Modules\Roles\Enums\Permission;
use Modules\SystemSettings\Services\SystemSettingService;
use Modules\Transactions\Services\ReversalService;
use Modules\Transactions\Services\TransactionService;
use Modules\Users\Exceptions\AgentNotApprovedException;
use Modules\Users\Exceptions\UserNotAnAgentException;
use Modules\Users\Services\UserService;

/**
 * Maker-checker for sensitive staff actions.
 *
 * The maker's request is validated and stored; nothing changes until a different staff member who holds
 * the action's permission approves it. Approval runs the existing domain service inside the same
 * database transaction that marks the request APPROVED, so a failing action (e.g. the agent was
 * suspended meanwhile) rolls back and the request stays PENDING for the checker to reject.
 *
 * The maker stays the actor of the resulting change (initiated_by, audit actor); the checker is
 * recorded on the request and in the APPROVAL_APPROVED audit entry.
 */
class ApprovalService
{
    /**
     * The permission a checker needs for each action, which is the same permission the maker needs.
     *
     * @var array<string, Permission>
     */
    private const array PERMISSIONS = [
        ApprovalRequest::FLOAT_ISSUE => Permission::AgentsIssueFloat,
        ApprovalRequest::SETTINGS_UPDATE => Permission::SettingsUpdate,
        ApprovalRequest::TRANSACTION_REVERSAL => Permission::TransactionsReverse,
        ApprovalRequest::COMMISSION_RATE_UPDATE => Permission::AgentsUpdateCommission,
    ];

    public function __construct(
        protected TransactionService $transactionService,
        protected SystemSettingService $settingService,
        protected ReversalService $reversalService,
        protected UserService $userService,
    ) {}

    /**
     * Whether sensitive actions need a second approver. Always true in production.
     */
    public function isRequired(): bool
    {
        return app()->isProduction() || (bool) config('approvals.enabled', true);
    }

    /**
     * The permission that both maker and checker need for an action.
     */
    public static function permissionFor(string $action): Permission
    {
        return self::PERMISSIONS[$action];
    }

    /**
     * Actions this staff member may review.
     *
     * @return list<string>
     */
    public function reviewableActions(User $user): array
    {
        return array_keys(array_filter(
            self::PERMISSIONS,
            fn (Permission $permission): bool => $user->can($permission->value),
        ));
    }

    /**
     * Requests this staff member can see: ones for actions they may review, plus their own.
     *
     * @return Builder<ApprovalRequest>
     */
    public function visibleTo(User $user): Builder
    {
        $actions = $this->reviewableActions($user);

        return ApprovalRequest::query()->where(fn (Builder $query) => $query
            ->whereIn('action', $actions === [] ? ['__none__'] : $actions)
            ->orWhere('requested_by', $user->id));
    }

    /**
     * Store a float issuance for approval.
     *
     * @param  array{amount: string, external_reference: string, description?: string|null}  $details
     */
    public function requestFloatIssue(User $maker, User $agent, array $details): ApprovalRequest
    {
        if ($maker->id === $agent->id) {
            throw new AuthorizationException('You cannot issue float to yourself.');
        }

        $reference = $details['external_reference'];
        $alreadyPending = ApprovalRequest::where('action', ApprovalRequest::FLOAT_ISSUE)
            ->where('status', ApprovalRequest::PENDING)
            ->where('payload->external_reference', $reference)
            ->exists();
        if ($alreadyPending) {
            throw new ApprovalConflictException("A float issue with reference {$reference} is already awaiting approval.");
        }

        $amount = number_format((float) $details['amount'], 2, '.', '');

        return $this->store(
            action: ApprovalRequest::FLOAT_ISSUE,
            maker: $maker,
            payload: [
                'agent_id' => $agent->id,
                'amount' => $amount,
                'external_reference' => $reference,
                'description' => $details['description'] ?? null,
            ],
            summary: "Issue Tk {$amount} float to {$agent->name} (ref {$reference})",
            subject: $agent,
        );
    }

    /**
     * Store a settings change for approval. Only keys whose value actually changes are kept; returns null
     * when nothing would change.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $currentSettings  The values in force, to show the checker before and after.
     */
    public function requestSettingsUpdate(User $maker, array $settings, array $currentSettings): ?ApprovalRequest
    {
        $changes = [];
        foreach ($settings as $key => $value) {
            $before = $currentSettings[$key] ?? null;
            if ($before === null || (float) $before !== (float) $value) {
                $changes[$key] = ['before' => $before, 'after' => is_bool($value) ? $value : (float) $value];
            }
        }

        if ($changes === []) {
            return null;
        }

        return $this->store(
            action: ApprovalRequest::SETTINGS_UPDATE,
            maker: $maker,
            payload: ['changes' => $changes],
            summary: 'Change '.implode(', ', array_keys($changes)),
        );
    }

    /**
     * Store a transaction reversal for approval.
     */
    public function requestReversal(User $maker, Transaction $transaction, string $reason): ApprovalRequest
    {
        $this->reversalService->assertReversible($transaction);
        $this->assertNoPendingFor(ApprovalRequest::TRANSACTION_REVERSAL, $transaction, 'A reversal of this transaction is already awaiting approval.');

        return $this->store(
            action: ApprovalRequest::TRANSACTION_REVERSAL,
            maker: $maker,
            payload: ['transaction_id' => $transaction->id, 'reason' => $reason],
            summary: "Reverse {$transaction->type} #{$transaction->id} (Tk ".number_format((float) $transaction->amount, 2).')',
            subject: $transaction,
        );
    }

    /**
     * Store an agent commission-rate change for approval.
     */
    public function requestCommissionRateUpdate(User $maker, User $agent, string $rate): ApprovalRequest
    {
        if ($maker->id === $agent->id) {
            throw new AuthorizationException('Administrators cannot change their own commission rate.');
        }

        $agentInfo = $agent->agentInfo;
        if (! $agentInfo) {
            throw new UserNotAnAgentException;
        }
        if ($agentInfo->status === 'PENDING') {
            throw new AgentNotApprovedException('Approve the agent before changing their commission rate.');
        }

        $this->assertNoPendingFor(ApprovalRequest::COMMISSION_RATE_UPDATE, $agent, 'A commission-rate change for this agent is already awaiting approval.');

        $newRate = number_format((float) $rate, 4, '.', '');
        $currentRate = $agentInfo->commission_rate;

        return $this->store(
            action: ApprovalRequest::COMMISSION_RATE_UPDATE,
            maker: $maker,
            payload: [
                'agent_id' => $agent->id,
                'previous_rate' => $currentRate !== null ? number_format((float) $currentRate, 4, '.', '') : null,
                'commission_rate' => $newRate,
            ],
            summary: "Change {$agent->name}'s commission rate to {$newRate}",
            subject: $agent,
        );
    }

    /**
     * Approve a request and apply it.
     *
     * @throws ApprovalConflictException
     * @throws AuthorizationException
     */
    public function approve(ApprovalRequest $request, User $checker, ?string $note = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $checker, $note): ApprovalRequest {
            $locked = $this->lockPending($request);
            $this->assertCanReview($locked, $checker);

            $result = $this->apply($locked, $checker);

            $locked->forceFill([
                'status' => ApprovalRequest::APPROVED,
                'reviewed_by' => $checker->id,
                'reviewed_at' => now(),
                'review_note' => $note,
                'result' => $result,
            ])->save();

            $this->audit('APPROVAL_APPROVED', $locked, $checker, $note);

            return $locked;
        });
    }

    /**
     * Reject a request; nothing is applied.
     *
     * @throws ApprovalConflictException
     * @throws AuthorizationException
     */
    public function reject(ApprovalRequest $request, User $checker, string $note): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $checker, $note): ApprovalRequest {
            $locked = $this->lockPending($request);
            $this->assertCanReview($locked, $checker);

            $locked->forceFill([
                'status' => ApprovalRequest::REJECTED,
                'reviewed_by' => $checker->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->audit('APPROVAL_REJECTED', $locked, $checker, $note);

            return $locked;
        });
    }

    /**
     * Withdraw one's own pending request.
     *
     * @throws ApprovalConflictException
     * @throws AuthorizationException
     */
    public function cancel(ApprovalRequest $request, User $maker): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $maker): ApprovalRequest {
            $locked = $this->lockPending($request);

            if ((int) $locked->requested_by !== (int) $maker->id) {
                throw new AuthorizationException('Only the staff member who made this request can cancel it.');
            }

            $locked->forceFill(['status' => ApprovalRequest::CANCELLED])->save();
            $this->audit('APPROVAL_CANCELLED', $locked, $maker, null);

            return $locked;
        });
    }

    /**
     * Whether this staff member may approve or reject the request.
     */
    public function canReview(ApprovalRequest $request, User $user): bool
    {
        try {
            $this->assertCanReview($request, $user);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function assertCanReview(ApprovalRequest $request, User $checker): void
    {
        if ((int) $request->requested_by === (int) $checker->id) {
            throw new AuthorizationException('A different staff member must approve this request.');
        }

        if (! $checker->can(self::permissionFor($request->action)->value)) {
            throw new AuthorizationException('You do not have permission to review this request.');
        }

        // The checker must not be a party to the money they approve
        $agentId = $request->payload['agent_id'] ?? null;
        if ($agentId !== null && (int) $agentId === (int) $checker->id) {
            throw new AuthorizationException('You cannot approve a change to your own account.');
        }

        if ($request->action === ApprovalRequest::TRANSACTION_REVERSAL) {
            $transaction = Transaction::find($request->payload['transaction_id'] ?? null);
            if ($transaction && ! $checker->can('reverse', $transaction)) {
                throw new AuthorizationException('You cannot approve the reversal of a transaction you took part in.');
            }
        }
    }

    /**
     * Run the stored action with the existing domain services. The maker remains the actor.
     *
     * @return array<string, mixed>
     */
    private function apply(ApprovalRequest $request, User $checker): array
    {
        $maker = User::findOrFail($request->requested_by);
        $payload = $request->payload;

        return match ($request->action) {
            ApprovalRequest::FLOAT_ISSUE => [
                'transaction_id' => $this->transactionService->issueFloat(
                    admin: $maker,
                    agent: User::where('is_deleted', false)->findOrFail($payload['agent_id']),
                    amount: $payload['amount'],
                    externalReference: $payload['external_reference'],
                    idempotencyKey: "approval:{$request->id}",
                    description: $payload['description'] ?? null,
                )->id,
            ],
            ApprovalRequest::SETTINGS_UPDATE => [
                'settings' => $this->settingService->updateSettings(
                    settings: array_map(fn (array $change): mixed => $change['after'], $payload['changes']),
                    user: $maker,
                ),
            ],
            ApprovalRequest::TRANSACTION_REVERSAL => [
                'transaction_id' => $this->reversalService->reverse(
                    original: Transaction::findOrFail($payload['transaction_id']),
                    staff: $maker,
                    reason: $payload['reason'],
                )->id,
            ],
            ApprovalRequest::COMMISSION_RATE_UPDATE => [
                'commission_rate' => $this->userService->updateCommissionRate(
                    agentUser: User::findOrFail($payload['agent_id']),
                    commissionRate: $payload['commission_rate'],
                    actor: $maker,
                )->agentInfo?->commission_rate,
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function store(string $action, User $maker, array $payload, string $summary, ?Model $subject = null): ApprovalRequest
    {
        $request = ApprovalRequest::create([
            'action' => $action,
            'status' => ApprovalRequest::PENDING,
            'payload' => $payload,
            'summary' => mb_substr($summary, 0, 255),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'requested_by' => $maker->id,
        ]);

        $this->audit('APPROVAL_REQUESTED', $request, $maker, null);

        return $request;
    }

    private function assertNoPendingFor(string $action, Model $subject, string $message): void
    {
        $exists = ApprovalRequest::where('action', $action)
            ->where('status', ApprovalRequest::PENDING)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->exists();

        if ($exists) {
            throw new ApprovalConflictException($message);
        }
    }

    private function lockPending(ApprovalRequest $request): ApprovalRequest
    {
        $locked = ApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== ApprovalRequest::PENDING) {
            throw new ApprovalConflictException;
        }

        return $locked;
    }

    private function audit(string $action, ApprovalRequest $request, User $actor, ?string $note): void
    {
        AuditLog::create([
            'actor_id' => $actor->id,
            'action' => $action,
            'auditable_type' => $request->getMorphClass(),
            'auditable_id' => $request->id,
            'metadata' => [
                'approval_action' => $request->action,
                'summary' => $request->summary,
                'requested_by' => $request->requested_by,
                'note' => $note,
                'result' => $request->result,
            ],
        ]);
    }
}
