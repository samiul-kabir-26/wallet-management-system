<?php

namespace Modules\Authentication\Services;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Authentication\Exceptions\AccountInactiveException;
use Modules\Authentication\Exceptions\InsufficientRoleException;
use Modules\Authentication\Exceptions\InvalidCredentialsException;
use Modules\Authentication\Exceptions\UnverifiedAccountException;
use Modules\Authentication\Exceptions\UserAlreadyHasStaffAccessException;
use Modules\Roles\Exceptions\PermissionEscalationException;
use Modules\Roles\Services\RoleService;

class AccountInviteService
{
    public function __construct(
        protected RoleService $roleService,
    ) {}

    /**
     * Change a user's password, verify current password, and mark password as changed.
     *
     * @throws InvalidCredentialsException
     * @throws AccountInactiveException
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        // 1. Verify current credentials
        if (! $user->password || ! Hash::check($currentPassword, $user->password)) {
            throw new InvalidCredentialsException('The current password provided is incorrect.');
        }

        // 2. Ensure account is active
        if ($user->is_active !== 'ACTIVE') {
            throw new AccountInactiveException;
        }

        // 3. Atomically update password and set password_changed_at timestamp
        $user->forceFill([
            'password' => Hash::make($newPassword),
            'password_changed_at' => now(),
        ])->save();
    }

    private const string INVALID_INVITE_MESSAGE = 'Invalid or already-used invite credentials.';

    /**
     * Grant admin access to an existing verified user and generate a signed invite link.
     *
     * @throws InsufficientRoleException
     * @throws UnverifiedAccountException
     * @throws UserAlreadyHasStaffAccessException
     * @throws PermissionEscalationException
     */
    public function grant(
        User $caller,
        User $target,
        string $email,
        string $roleName = 'ADMIN',
        ?string $tempPassword = null
    ): string {
        // 1. Authorize: Caller cannot grant admin access to themselves
        if ($caller->id === $target->id) {
            throw new InsufficientRoleException('You cannot grant admin access to yourself.');
        }

        // Caller must have users.grant_admin_access permission
        if (! $caller->can('users.grant_admin_access')) {
            throw new InsufficientRoleException;
        }

        // 2. Target check: Target must NOT already have a password or an admin-panel role
        if ($target->password !== null || $target->roles()->where('panel', 'admin')->exists()) {
            throw new UserAlreadyHasStaffAccessException('This user already has staff access; use role assignment instead.');
        }

        // 3. Business rule: Target user's phone must be verified
        if (! $target->is_verified) {
            throw new UnverifiedAccountException;
        }

        // 4. Anti-escalation check (D6 / R5): actor can only assign roles whose permissions they possess
        $role = Role::where('name', $roleName)->where('panel', 'admin')->firstOrFail();
        $this->roleService->assertCanAssignRole($caller, $role);

        $passwordLength = (int) config('auth_settings.invites.temp_password_length', 16);
        $password = $tempPassword ?? Str::password($passwordLength);

        // 5. Atomically update credentials, clear password_changed_at, attach role, and record audit log
        DB::transaction(function () use ($caller, $target, $email, $password, $role): void {
            /** @var User $lockedTarget */
            $lockedTarget = User::where('id', $target->id)->lockForUpdate()->firstOrFail();

            if ($lockedTarget->password !== null || $lockedTarget->roles()->where('panel', 'admin')->exists()) {
                throw new UserAlreadyHasStaffAccessException('This user already has staff access; use role assignment instead.');
            }

            $lockedTarget->forceFill([
                'email' => $email,
                'password' => Hash::make($password),
                'password_changed_at' => null, // Marks password as temporary / not owned
            ])->save();

            $lockedTarget->roles()->syncWithoutDetaching([
                $role->id => [
                    'assigned_at' => now(),
                    'assigned_by' => $caller->id,
                ],
            ]);

            AuditLog::create([
                'actor_id' => $caller->id,
                'action' => 'user.admin_access_granted',
                'auditable_type' => $lockedTarget->getMorphClass(),
                'auditable_id' => $lockedTarget->id,
                'metadata' => [
                    'role' => $role->name,
                    'email' => $email,
                    'method' => 'invite',
                ],
            ]);
        });

        // 4. Generate signed invitation URL (expires in 24 hours)
        $expiryHours = (int) config('auth_settings.invites.expiry_hours', 24);
        $signedUrl = URL::temporarySignedRoute(
            'auth.accept-invite',
            now()->addHours($expiryHours),
            ['user' => $target->id],
        );

        // 5. Stub email delivery (logged for local dev and testing)
        Log::info("Admin invite sent to [{$email}]", [
            'target_id' => $target->id,
            'url' => $signedUrl,
            'temp_password' => $password,
        ]);

        return $signedUrl;
    }

    /**
     * Accept an admin invitation using the temporary password.
     *
     * @throws InvalidCredentialsException
     * @throws AccountInactiveException
     */
    public function acceptInvite(User $user, string $password): User
    {
        // 1. Single-use check: Link is invalid once password_changed_at has been set
        if ($user->password_changed_at !== null) {
            throw new InvalidCredentialsException(self::INVALID_INVITE_MESSAGE);
        }

        // 2. Verify identity with the temporary password
        if (! $user->password || ! Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException(self::INVALID_INVITE_MESSAGE);
        }

        // 3. Ensure the account is active
        if ($user->is_active !== 'ACTIVE') {
            throw new AccountInactiveException;
        }

        return $user;
    }
}
