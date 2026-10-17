<?php

namespace Modules\Users\Services;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Roles\Enums\RolePanel;
use Modules\Roles\Exceptions\PermissionEscalationException;
use Modules\Roles\Services\RoleService;
use Modules\SystemSettings\Services\SystemSettingService;
use Modules\Users\Exceptions\AgentNotApprovedException;
use Modules\Users\Exceptions\AgentNotPendingException;
use Modules\Users\Exceptions\UserHasNoPhoneException;
use Modules\Users\Exceptions\UserInactiveException;
use Modules\Users\Exceptions\UserNotAnAgentException;
use Modules\Users\Exceptions\UserRequiresPasswordForStaffRoleException;

class UserService
{
    public function __construct(
        protected SystemSettingService $settingService,
        protected RoleService $roleService,
    ) {}

    /**
     * Register a new user, admin, moderator, or agent with default wallet, caps, and assigned role.
     *
     * @param  array{name: string, role: string, email?: string, password?: string, phone_number?: string, pin?: string, address?: string|null}  $data
     */
    public function register(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $role = Role::where('name', $data['role'])->firstOrFail();

            // Anti-escalation check (D6 / R5): actor can only assign roles whose permissions they possess
            $this->roleService->assertCanAssignRole($actor, $role);

            $isAdminPanel = ($role->panel instanceof RolePanel ? $role->panel->value : $role->panel) === RolePanel::Admin->value;

            // 1. Prepare user attributes based on credential track
            $userData = [
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
            ];

            if ($isAdminPanel) {
                $userData['email'] = $data['email'];
                $userData['password'] = $data['password'];
            } else {
                $userData['phone_number'] = $data['phone_number'];
                $userData['pin'] = $data['pin'];
            }

            // Password and PIN are automatically hashed via User::casts()
            $user = User::create($userData);

            // 2. Create the initial wallet only for non-staff accounts (schema defaults: balance 50.00, currency BDT)
            if (! $isAdminPanel) {
                $kind = $data['role'] === 'AGENT' ? Wallet::KIND_AGENT : Wallet::KIND_USER;
                $user->wallets()->create([
                    'kind' => $kind,
                ]);

                // 3. Create transaction cap limits (schema defaults: daily 10,000.00, monthly 50,000.00)
                $user->cap()->create();
            }

            // 4. If AGENT, create agent_info record (schema default: status PENDING)
            if ($data['role'] === 'AGENT') {
                $user->agentInfo()->create();
            }

            // 5. Assign the requested role with audit tracking
            $user->roles()->attach($role->id, [
                'assigned_at' => now(),
                'assigned_by' => $actor->id,
            ]);

            // 6. Eager load roles so the resulting model is fully hydrated for resources
            return $user->refresh()->load('roles');
        });
    }

    /**
     * Assign an additional role to a user.
     * Supports customer roles (AGENT, USER) and admin-panel roles for existing staff.
     *
     * @throws \InvalidArgumentException
     * @throws UserInactiveException
     * @throws AuthorizationException
     * @throws UserRequiresPasswordForStaffRoleException
     * @throws PermissionEscalationException
     */
    public function assignRole(User $user, string $roleName, ?User $actor = null): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();

        $isCustomerRole = in_array($roleName, ['AGENT', 'USER'], true);
        $isAdminPanelRole = $role->panel === RolePanel::Admin;

        if (! $isCustomerRole && ! $isAdminPanelRole) {
            throw new \InvalidArgumentException("Role [{$roleName}] cannot be assigned via assignRole. Only customer roles (AGENT, USER) and admin-panel roles are supported.");
        }

        if ($actor !== null && $actor->id === $user->id) {
            throw new AuthorizationException('Administrators cannot assign roles to themselves.');
        }

        if ($isAdminPanelRole) {
            if ($user->password === null) {
                throw new UserRequiresPasswordForStaffRoleException;
            }

            if ($actor !== null) {
                $this->roleService->assertCanAssignRole($actor, $role);
            }
        }

        return DB::transaction(function () use ($user, $role, $isAdminPanelRole, $actor): User {
            /** @var User $lockedUser */
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            if ($lockedUser->is_active !== 'ACTIVE') {
                throw new UserInactiveException;
            }

            if ($isAdminPanelRole) {
                if ($lockedUser->password === null) {
                    throw new UserRequiresPasswordForStaffRoleException;
                }

                if ($actor !== null) {
                    $this->roleService->assertCanAssignRole($actor, $role);
                }
            }

            $alreadyHadRole = $lockedUser->roles()->where('roles.id', $role->id)->exists();

            if (! $alreadyHadRole) {
                $lockedUser->roles()->attach($role->id, [
                    'assigned_at' => now(),
                    'assigned_by' => $actor?->id,
                ]);
            }

            if ($role->name === 'AGENT') {
                if (! $lockedUser->agentInfo()->exists()) {
                    $lockedUser->agentInfo()->create();
                }

                if (! $lockedUser->cap()->exists()) {
                    $lockedUser->cap()->create();
                }

                if (! $lockedUser->wallets()->where('kind', Wallet::KIND_AGENT)->exists()) {
                    $wallet = $lockedUser->wallets()->create([
                        'kind' => Wallet::KIND_AGENT,
                    ]);
                    $wallet->balance = '0.00';
                    $wallet->save();
                }
            } elseif ($role->name === 'USER') {
                if (! $lockedUser->cap()->exists()) {
                    $lockedUser->cap()->create();
                }

                if (! $lockedUser->wallets()->where('kind', Wallet::KIND_USER)->exists()) {
                    $wallet = $lockedUser->wallets()->create([
                        'kind' => Wallet::KIND_USER,
                    ]);
                    $wallet->balance = '0.00';
                    $wallet->save();
                }
            }

            if (! $alreadyHadRole) {
                AuditLog::create([
                    'actor_id' => $actor?->id,
                    'action' => 'ROLE_ASSIGNED',
                    'auditable_type' => $lockedUser->getMorphClass(),
                    'auditable_id' => $lockedUser->id,
                    'metadata' => [
                        'role' => $role->name,
                        'assigned_by' => $actor?->id,
                    ],
                ]);
            }

            return $lockedUser->refresh()->load(['roles', 'wallets', 'cap', 'agentInfo']);
        });
    }

    /**
     * Approve a pending agent and set their commission rate.
     *
     * @throws AgentNotPendingException
     * @throws UserNotAnAgentException
     * @throws AuthorizationException
     */
    public function approveAgent(User $agentUser, ?float $commissionRate, User $actor): User
    {
        if ($actor->id === $agentUser->id) {
            throw new AuthorizationException('Administrators cannot approve their own agent status.');
        }

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

    /**
     * Mark a user's phone number as verified.
     *
     * $method records how ownership was proven: STAFF (confirmed out of band) or OTP (SMS code).
     * Idempotent: an already-verified account is returned unchanged and no new audit entry is written.
     *
     * @throws UserHasNoPhoneException
     */
    public function verifyPhone(User $user, User $actor, string $method = 'STAFF'): User
    {
        return DB::transaction(function () use ($user, $actor, $method): User {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($lockedUser->phone_number === null) {
                throw new UserHasNoPhoneException;
            }

            if ($lockedUser->is_verified) {
                return $lockedUser;
            }

            $lockedUser->forceFill(['is_verified' => true])->save();

            AuditLog::create([
                'actor_id' => $actor->id,
                'action' => 'PHONE_VERIFIED',
                'auditable_type' => $lockedUser->getMorphClass(),
                'auditable_id' => $lockedUser->id,
                'metadata' => [
                    'phone_number' => $lockedUser->phone_number,
                    'method' => $method,
                ],
            ]);

            return $lockedUser;
        });
    }
}
