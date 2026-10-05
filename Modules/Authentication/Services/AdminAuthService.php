<?php

namespace Modules\Authentication\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Exceptions\AccountInactiveException;
use Modules\Authentication\Exceptions\InsufficientRoleException;
use Modules\Authentication\Exceptions\InvalidCredentialsException;
use Modules\Authentication\Exceptions\InvalidOtpException;

class AdminAuthService
{
    /**
     * Precomputed valid bcrypt hash used to equalize execution timing
     * when a user is not found or has no password set.
     */
    private const string DUMMY_HASH = '$2y$12$xn5OszU/1GZvtORdddVo2.dXALwyzJcbpB6JlSmyvPK.DUrKdJFra';

    private const string INVALID_CREDENTIALS_MESSAGE = 'Invalid email/phone number or password.';

    public function __construct(
        private OtpService $otpService,
    ) {}

    /**
     * Attempt the first phase of admin authentication (credentials -> OTP).
     *
     * @throws InvalidCredentialsException
     * @throws AccountInactiveException
     * @throws InsufficientRoleException
     */
    public function attempt(string $identifier, string $password): User
    {
        $column = str_contains($identifier, '@') ? 'email' : 'phone_number';
        $user = User::where($column, $identifier)->first();

        if (! $user || ! $user->password) {
            Hash::check($password, self::DUMMY_HASH);

            throw new InvalidCredentialsException(self::INVALID_CREDENTIALS_MESSAGE);
        }

        if (! Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException(self::INVALID_CREDENTIALS_MESSAGE);
        }

        if ($user->is_active !== 'ACTIVE') {
            throw new AccountInactiveException;
        }

        if (! $user->hasRole(['SUPER_ADMIN', 'ADMIN', 'MODERATOR'])) {
            throw new InsufficientRoleException;
        }

        $otp = $this->otpService->createOtp($user->id, 'LOGIN');

        Log::info("Admin login OTP for [{$user->email}]: {$otp->otp_code}");

        return $user;
    }

    /**
     * Verify an OTP for an admin user.
     *
     * @throws InvalidOtpException
     * @throws AccountInactiveException
     * @throws InsufficientRoleException
     */
    public function verifyOtp(string $email, string $otpCode): User
    {
        $user = User::where('email', $email)->first();

        // 1. If email not found, reuse generic message to prevent email enumeration
        if (! $user) {
            throw new InvalidOtpException('Invalid or expired OTP.');
        }

        // 2. Secret proof first: verify active token existence, lockout, expiry, and code
        $this->otpService->verify($user->id, 'LOGIN', $otpCode);

        // 3. Post-proof freshness checks (only evaluated after secret has been proven)
        if ($user->is_active !== 'ACTIVE') {
            throw new AccountInactiveException;
        }

        if (! $user->hasRole(['SUPER_ADMIN', 'ADMIN', 'MODERATOR'])) {
            throw new InsufficientRoleException;
        }

        return $user;
    }
}
