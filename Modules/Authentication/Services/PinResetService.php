<?php

namespace Modules\Authentication\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Modules\Authentication\Exceptions\AccountHasNoPinException;
use Modules\Authentication\Exceptions\AccountInactiveException;
use Modules\Authentication\Exceptions\InsufficientRoleException;
use Modules\Authentication\Exceptions\UserNotFoundException;

class PinResetService
{
    /**
     * Initiate a PIN reset flow for a customer by staff.
     *
     * @throws InsufficientRoleException
     * @throws UserNotFoundException
     * @throws AccountHasNoPinException
     * @throws AccountInactiveException
     */
    public function initiate(User $staffMember, string $phoneNumber, string $email): string
    {
        // 1. Authorize: MODERATOR, ADMIN, or SUPER_ADMIN
        if (! $staffMember->hasRole(['SUPER_ADMIN', 'ADMIN', 'MODERATOR'])) {
            throw new InsufficientRoleException;
        }

        // 2. Find target user by phone
        $target = User::where('phone_number', $phoneNumber)->first();
        if (! $target) {
            throw new UserNotFoundException;
        }

        // 3. Confirm target account actually has a PIN configured
        if ($target->pin === null) {
            throw new AccountHasNoPinException;
        }

        // 4. Ensure target account is active
        if ($target->is_active !== 'ACTIVE') {
            throw new AccountInactiveException;
        }

        // 5. Generate signed recovery link (valid for 15 minutes)
        $signedUrl = URL::temporarySignedRoute(
            'auth.reset-pin',
            now()->addMinutes(15),
            ['user' => $target->id],
        );

        // 6. Atomically attach email and write immutable audit log
        DB::transaction(function () use ($staffMember, $target, $email): void {
            $target->forceFill([
                'email' => $email,
            ])->save();

            AuditLog::create([
                'actor_id' => $staffMember->id,
                'action' => 'PIN_RESET_INITIATED',
                'auditable_type' => $target->getMorphClass(),
                'auditable_id' => $target->id,
                'metadata' => [
                    'attached_email' => $email,
                    'phone_number' => $target->phone_number,
                ],
            ]);
        });

        // 7. Stub email delivery (logged for development and testing)
        Log::info("PIN reset link sent to [{$email}]", [
            'target_id' => $target->id,
            'url' => $signedUrl,
        ]);

        return $signedUrl;
    }

    /**
     * Reset the target user's PIN via the consumed recovery link.
     *
     * @throws AccountInactiveException
     * @throws AccountHasNoPinException
     */
    public function resetPin(User $user, string $pin): void
    {
        // 1. Ensure target account is active
        if ($user->is_active !== 'ACTIVE') {
            throw new AccountInactiveException;
        }

        // 2. Defensive check: re-verify target account still has a PIN to reset
        if ($user->pin === null) {
            throw new AccountHasNoPinException;
        }

        // 3. Hash and store new PIN (no general token issued per spec)
        $user->forceFill([
            'pin' => Hash::make($pin),
        ])->save();
    }
}
