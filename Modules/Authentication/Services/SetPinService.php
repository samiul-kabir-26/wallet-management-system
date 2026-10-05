<?php

namespace Modules\Authentication\Services;

use App\Models\OtpToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Exceptions\AccountInactiveException;
use Modules\Authentication\Exceptions\InsufficientRoleException;
use Modules\Authentication\Exceptions\InvalidOtpException;

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
     */
    public function requestOtp(User $user): OtpToken
    {
        if ($user->is_active !== 'ACTIVE') {
            throw new AccountInactiveException;
        }

        if (! $user->hasRole(['SUPER_ADMIN', 'ADMIN', 'MODERATOR'])) {
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
     */
    public function setPin(User $user, string $otpCode, string $pin): void
    {
        // 1. Verify OTP secret proof first (marks OTP as used on success)
        $this->otpService->verify($user->id, 'SET_PIN', $otpCode);

        // 2. Post-proof freshness check
        if ($user->is_active !== 'ACTIVE') {
            throw new AccountInactiveException;
        }

        if (! $user->hasRole(['SUPER_ADMIN', 'ADMIN', 'MODERATOR'])) {
            throw new InsufficientRoleException;
        }

        // 3. Save the new PIN (never issue a new token here)
        $user->forceFill([
            'pin' => Hash::make($pin),
        ])->save();
    }
}
