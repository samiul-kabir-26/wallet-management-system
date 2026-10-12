<?php

namespace Modules\Authentication\Services;

use App\Models\OtpToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Exceptions\AccountInactiveException;
use Modules\Authentication\Exceptions\InsufficientRoleException;
use Modules\Authentication\Exceptions\InvalidOtpException;
use Modules\Authentication\Exceptions\PinAlreadySetException;

class SetPinService
{
    public function __construct(
        private OtpService $otpService,
    ) {}

    /**
     * Request a 6-digit OTP for setting a PIN.
     *
     * @throws AccountInactiveException
     * @throws InsufficientRoleException
     * @throws PinAlreadySetException
     */
    public function requestOtp(User $user): OtpToken
    {
        if ($user->pin !== null) {
            throw new PinAlreadySetException;
        }

        if ($user->is_active !== 'ACTIVE') {
            throw new AccountInactiveException;
        }

        if (! $user->hasAdminPanelRole()) {
            throw new InsufficientRoleException;
        }

        $otp = $this->otpService->createOtp($user->id, 'SET_PIN');

        Log::info("Set PIN OTP for [{$user->email}]: {$otp->otp_code}");

        return $otp;
    }

    /**
     * Verify OTP and store the new PIN for the admin.
     *
     * @throws InvalidOtpException
     * @throws AccountInactiveException
     * @throws InsufficientRoleException
     * @throws PinAlreadySetException
     */
    public function setPin(User $user, string $otpCode, string $pin): void
    {
        // 0. Pre-proof check: refuse to overwrite existing PIN
        if ($user->pin !== null) {
            throw new PinAlreadySetException;
        }

        // 1. Verify OTP secret proof first (marks OTP as used on success)
        $this->otpService->verify($user->id, 'SET_PIN', $otpCode);

        // 2. Post-proof freshness check
        if ($user->is_active !== 'ACTIVE') {
            throw new AccountInactiveException;
        }

        if (! $user->hasAdminPanelRole()) {
            throw new InsufficientRoleException;
        }

        // 3. Atomically update PIN only if still null (race-free conditional update)
        $affected = User::where('id', $user->id)
            ->whereNull('pin')
            ->update([
                'pin' => Hash::make($pin),
            ]);

        if ($affected === 0) {
            throw new PinAlreadySetException;
        }

        $user->refresh();
    }
}
