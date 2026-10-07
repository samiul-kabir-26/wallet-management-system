<?php

namespace Modules\Users\Services;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\SystemSettings\Services\SystemSettingService;
use Modules\Users\Exceptions\AgentNotApprovedException;
use Modules\Users\Exceptions\AgentNotPendingException;
use Modules\Users\Exceptions\UserNotAnAgentException;

class UserService
{
    public function __construct(
        protected SystemSettingService $settingService,
    ) {}

    /**
     * Register a new user, admin, moderator, or agent with default wallet, caps, and assigned role.
     *
     * @param  array{name: string, role: string, email?: string, password?: string, phone_number?: string, pin?: string, address?: string|null}  $data
     */
    public function register(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            // 1. Prepare user attributes based on credential track
            $userData = [
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
            ];

            if (in_array($data['role'], ['ADMIN', 'MODERATOR'], true)) {
                $userData['email'] = $data['email'];
                $userData['password'] = $data['password'];
            } else {
                $userData['phone_number'] = $data['phone_number'];
                $userData['pin'] = $data['pin'];
            }

            // Password and PIN are automatically hashed via User::casts()
            $user = User::create($userData);

            // 2. Create the initial wallet (schema defaults: balance 50.00, currency BDT)
            $user->wallet()->create();

            // 3. Create transaction cap limits (schema defaults: daily 10,000.00, monthly 50,000.00)
            $user->cap()->create();

            // 4. If AGENT, create agent_info record (schema default: status PENDING)
            if ($data['role'] === 'AGENT') {
                $user->agentInfo()->create();
            }

            // 5. Assign the requested role with audit tracking
            $role = Role::where('name', $data['role'])->firstOrFail();
            $user->roles()->attach($role->id, [
                'assigned_at' => now(),
                'assigned_by' => $actor->id,
            ]);

            // 6. Eager load roles so the resulting model is fully hydrated for resources
            return $user->refresh()->load('roles');
        });
    }

    /**
     * Approve a pending agent and set their commission rate.
     *
     * @throws AgentNotPendingException
     * @throws UserNotAnAgentException
     */
    public function approveAgent(User $agentUser, ?float $commissionRate, User $actor): User
    {
        return DB::transaction(function () use ($agentUser, $commissionRate, $actor): User {
            $agentInfo = $agentUser->agentInfo()->lockForUpdate()->first();

            if (! $agentInfo) {
                throw new UserNotAnAgentException;
            }

            if ($agentInfo->status !== 'PENDING') {
                throw new AgentNotPendingException;
            }

            $defaultCommissionRate = (float) $this->settingService->get('agent_commission_rate', 0.0100);

            $agentInfo->update([
                'status' => 'APPROVED',
                'commission_rate' => $commissionRate ?? $defaultCommissionRate,
                'approved_at' => now(),
                'approved_by' => $actor->id,
            ]);

            return $agentUser->refresh()->load('agentInfo');
        });
    }

    /**
     * Suspend an active agent with an audited reason.
     *
     * @throws AgentNotApprovedException
     * @throws UserNotAnAgentException
     */
    public function suspendAgent(User $agentUser, string $reason, User $actor): User
    {
        return DB::transaction(function () use ($agentUser, $reason, $actor): User {
            $agentInfo = $agentUser->agentInfo()->lockForUpdate()->first();

            if (! $agentInfo) {
                throw new UserNotAnAgentException;
            }

            if ($agentInfo->status !== 'APPROVED') {
                throw new AgentNotApprovedException;
            }

            $agentInfo->update([
                'status' => 'SUSPENDED',
                'suspended_at' => now(),
                'suspended_by' => $actor->id,
            ]);

            AuditLog::create([
                'actor_id' => $actor->id,
                'action' => 'AGENT_SUSPENDED',
                'auditable_type' => $agentUser->getMorphClass(),
                'auditable_id' => $agentUser->id,
                'metadata' => [
                    'reason' => $reason,
                    'previous_status' => 'APPROVED',
                ],
            ]);

            return $agentUser->refresh()->load('agentInfo');
        });
    }
}
