<?php

namespace Modules\Authentication\Services;

use App\Models\AuditLog;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Modules\Authentication\Exceptions\AccountHasNoPinException;
use Modules\Authentication\Exceptions\AccountInactiveException;
use Modules\Authentication\Exceptions\InsufficientRoleException;
use Modules\Authentication\Exceptions\PinResetLinkInvalidException;
use Modules\Authentication\Exceptions\UserNotFoundException;
use Modules\Authentication\Notifications\PinResetLinkNotification;

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
        // 1. Authorize: requires pin_reset.initiate permission
        if (! $staffMember->can('pin_reset.initiate')) {
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

        // 5. Atomically attach email and write immutable audit log
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

        // 6. Generate the one-time signed recovery link and deliver it to the attached email
        $expiryMinutes = (int) config('auth_settings.pin_reset.expiry_minutes', 15);
        $signedUrl = self::makeResetUrl($target, $expiryMinutes);

        // The email opens the web page, which posts the signed query string to the API route
        $target->notify(new PinResetLinkNotification(self::resetPageUrl($target, $signedUrl), $expiryMinutes));

        return $signedUrl;
    }

    /**
     * Build a signed PIN reset URL bound to the user's current PIN hash.
     *
     * The fingerprint changes as soon as the PIN is replaced, so the link works only once.
     */
    public static function makeResetUrl(User $user, int $expiryMinutes): string
    {
        return URL::temporarySignedRoute(
            'auth.reset-pin',
            now()->addMinutes($expiryMinutes),
            ['user' => $user->id, 'fingerprint' => self::pinFingerprint($user)],
        );
    }

    /**
     * Browser page for a signed reset link: /reset-pin/{user} carrying the API signature's query string.
     */
    public static function resetPageUrl(User $user, string $signedApiUrl): string
    {
        return url("/reset-pin/{$user->id}").'?'.parse_url($signedApiUrl, PHP_URL_QUERY);
    }

    /**
     * Derive a non-reversible fingerprint of the user's current PIN hash.
     */
    public static function pinFingerprint(User $user): string
    {
        return hash_hmac('sha256', (string) $user->pin, (string) config('app.key'));
    }

    /**
     * Reset the target user's PIN via the consumed recovery link.
     *
     * @throws AccountInactiveException
     * @throws AccountHasNoPinException
     * @throws PinResetLinkInvalidException
     */
    public function resetPin(User $user, string $pin, string $fingerprint): void
    {
        // 1. Ensure target account is active
        if ($user->is_active !== 'ACTIVE') {
            throw new AccountInactiveException;
        }

        // 2. Defensive check: re-verify target account still has a PIN to reset
        if ($user->pin === null) {
            throw new AccountHasNoPinException;
        }

        // 3. Lock the row so two submissions of the same link cannot both pass the fingerprint check
        DB::transaction(function () use ($user, $pin, $fingerprint): void {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! hash_equals(self::pinFingerprint($lockedUser), $fingerprint)) {
                throw new PinResetLinkInvalidException;
            }

            // Hash and store new PIN (no general token issued per spec)
            $lockedUser->forceFill([
                'pin' => Hash::make($pin),
            ])->save();

            // A reset PIN means the old one may be known: every device must prove itself again
            TrustedDevice::where('user_id', $user->id)->delete();

            // Any session opened with the old PIN must not survive the reset
            $lockedUser->tokens()->delete();

            AuditLog::create([
                'actor_id' => $lockedUser->id,
                'action' => 'PIN_RESET_COMPLETED',
                'auditable_type' => $lockedUser->getMorphClass(),
                'auditable_id' => $lockedUser->id,
                'metadata' => [
                    'phone_number' => $lockedUser->phone_number,
                ],
            ]);
        });

        // A human-verified reset lifts any brute-force lockout on the old PIN
        RateLimiter::clear(PinVerifier::getThrottleKey($user->id));
    }
}
