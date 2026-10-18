<?php

namespace Modules\Authentication\Services;

use App\Models\LoginActivity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Authentication\Exceptions\AccountInactiveException;
use Modules\Authentication\Exceptions\InsufficientRoleException;
use Modules\Authentication\Exceptions\InvalidCredentialsException;
use Modules\Authentication\Exceptions\InvalidOtpException;
use Modules\Authentication\Notifications\AdminLoginAlertNotification;
use Modules\Authentication\Notifications\OtpCodeNotification;

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
        private TotpService $totp,
    ) {}

    /**
     * Request an OTP to reset password (anti-enumeration: silently returns if not found or unauthorized).
     */
    public function forgotPassword(string $email): void
    {
        $user = User::where('email', $email)->first();

        if (! $user || $user->is_active !== 'ACTIVE' || ! $user->hasAdminPanelRole()) {
            return;
        }

        $otp = $this->otpService->createOtp($user->id, 'PASSWORD_RESET');

        $user->notify(new OtpCodeNotification($otp->otp_code, 'PASSWORD_RESET', (int) config('auth_settings.otp.expiry_minutes', 5)));
    }

    /**
     * Reset an admin user's password using a verified OTP.
     *
     * @throws InvalidOtpException
     * @throws AccountInactiveException
     * @throws InsufficientRoleException
     */
    public function resetPassword(string $email, string $otpCode, string $newPassword): void
    {
        $user = User::where('email', $email)->first();

        // 1. If email not found, reuse generic message to prevent email enumeration
        if (! $user) {
            throw new InvalidOtpException('Invalid or expired OTP.');
        }

        // 2. Secret proof first: verify active token existence, lockout, expiry, and code
        $this->otpService->verify($user->id, 'PASSWORD_RESET', $otpCode);

        // 3. Post-proof freshness checks (only evaluated after secret has been proven)
        if ($user->is_active !== 'ACTIVE') {
            throw new AccountInactiveException;
        }

        if (! $user->hasAdminPanelRole()) {
            throw new InsufficientRoleException;
        }

        $user->forceFill([
            'password' => Hash::make($newPassword),
            'password_changed_at' => now(),
        ])->save();

        // Revoke all existing tokens/sessions on password reset
        $user->tokens()->delete();
    }

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

            Log::warning('Failed login attempt', [
                'identifier' => $identifier,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            throw new InvalidCredentialsException(self::INVALID_CREDENTIALS_MESSAGE);
        }

        if (! Hash::check($password, $user->password)) {
            Log::warning('Failed login attempt', [
                'identifier' => $identifier,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            throw new InvalidCredentialsException(self::INVALID_CREDENTIALS_MESSAGE);
        }

        if ($user->is_active !== 'ACTIVE') {
            throw new AccountInactiveException;
        }

        if (! $user->hasAdminPanelRole()) {
            throw new InsufficientRoleException;
        }

        // Authenticator-app users get no email; the code step must still follow a correct password
        if ($user->usesTotp()) {
            Cache::put($this->passwordProvenKey($user), true, now()->addMinutes((int) config('auth_settings.otp.expiry_minutes', 5)));

            return $user;
        }

        $otp = $this->otpService->createOtp($user->id, 'LOGIN');

        $user->notify(new OtpCodeNotification($otp->otp_code, 'LOGIN', (int) config('auth_settings.otp.expiry_minutes', 5)));

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
        $user = User::with('roles')->where('email', $email)->first();

        // 1. If email not found, reuse generic message to prevent email enumeration
        if (! $user) {
            throw new InvalidOtpException('Invalid or expired OTP.');
        }

        // 2. Secret proof first: the authenticator code (after a correct password step) or the emailed code
        if ($user->usesTotp()) {
            if (! Cache::has($this->passwordProvenKey($user))) {
                throw new InvalidOtpException('Invalid or expired OTP.');
            }
            $this->totp->verifyForLogin($user, $otpCode);
            Cache::forget($this->passwordProvenKey($user));
        } else {
            $this->otpService->verify($user->id, 'LOGIN', $otpCode);
        }

        // 3. Post-proof freshness checks (only evaluated after secret has been proven)
        if ($user->is_active !== 'ACTIVE') {
            throw new AccountInactiveException;
        }

        if (! $user->hasAdminPanelRole()) {
            throw new InsufficientRoleException;
        }

        return $user;
    }

    /**
     * Email the staff member when this sign-in comes from an IP and browser pair their account has never
     * signed in from before. The first sign-in ever is not alerted (there is nothing to compare with).
     * Call after the successful sign-in has been recorded.
     */
    public function alertOnNewDevice(User $user, Request $request): void
    {
        if (! $user->email) {
            return;
        }

        $successes = LoginActivity::where('user_id', $user->id)->where('panel', 'admin')->where('status', 'SUCCESS');
        $sameDevice = (clone $successes)
            ->where('ip_address', $request->ip())
            ->where('user_agent', $request->userAgent())
            ->count();

        if ($successes->count() > 1 && $sameDevice <= 1) {
            $user->notify(new AdminLoginAlertNotification(
                (string) $request->ip(),
                Str::limit((string) $request->userAgent(), 200),
                now(config('transactions.business_timezone', 'Asia/Dhaka'))->format('d M Y, H:i T'),
            ));
        }
    }

    private function passwordProvenKey(User $user): string
    {
        return "admin-password-proven:{$user->id}";
    }
}
