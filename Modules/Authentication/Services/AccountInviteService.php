<?php

namespace Modules\Authentication\Services;

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

class AccountInviteService
{
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
     */
    public function grant(User $caller, User $target, string $email, ?string $tempPassword = null): string
    {
        // 1. Authorize: Caller must be a SUPER_ADMIN
        if (! $caller->hasRole('SUPER_ADMIN')) {
            throw new InsufficientRoleException;
        }

        // 2. Business rule: Target user's phone must be verified
        if (! $target->is_verified) {
            throw new UnverifiedAccountException;
        }

        $password = $tempPassword ?? Str::password(16);

        // 3. Atomically update credentials, clear password_changed_at, and attach ADMIN role
        DB::transaction(function () use ($caller, $target, $email, $password): void {
            $target->forceFill([
                'email' => $email,
                'password' => Hash::make($password),
                'password_changed_at' => null, // Marks password as temporary / not owned
            ])->save();

            $adminRole = Role::where('name', 'ADMIN')->firstOrFail();

            $target->roles()->syncWithoutDetaching([
                $adminRole->id => [
                    'assigned_at' => now(),
                    'assigned_by' => $caller->id,
                ],
            ]);
        });

        // 4. Generate signed invitation URL (expires in 24 hours)
        $signedUrl = URL::temporarySignedRoute(
            'auth.accept-invite',
            now()->addHours(24),
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
