<?php

namespace Modules\Authentication\Services;

use App\Models\OtpToken;
use Illuminate\Support\Carbon;
use Modules\Authentication\Exceptions\InvalidOtpException;

class OtpService
{
    /**
     * Default OTP validity duration in minutes.
     */
    public const int DEFAULT_EXPIRY_MINUTES = 5;

    /**
     * Message when attempt threshold has been exceeded.
     */
    private const string LOCKOUT_MESSAGE = 'Too many failed attempts. Please request a new OTP.';

    /**
     * Generate a cryptographically secure numeric OTP code.
     */
    public function generateOtp(int $length = 6): string
    {
        $min = 0;
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int($min, $max), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Invalidate any active, unused OTP tokens for a user and purpose.
     */
    public function invalidateActiveOtps(int $userId, string $purpose): void
    {
        OtpToken::where('user_id', $userId)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update([
                'used_at' => Carbon::now(),
            ]);
    }

    /**
     * Persist an OTP token for a specific user and purpose.
     */
    public function saveOtp(
        int $userId,
        string $code,
        string $purpose,
        int $validForMinutes = self::DEFAULT_EXPIRY_MINUTES,
    ): OtpToken {
        return OtpToken::create([
            'user_id' => $userId,
            'otp_code' => $code,
            'purpose' => $purpose,
            'expires_at' => Carbon::now()->addMinutes($validForMinutes),
        ]);
    }

    /**
     * Generate and save a new OTP, automatically invalidating any prior active tokens.
     */
    public function createOtp(
        int $userId,
        string $purpose,
        int $validForMinutes = self::DEFAULT_EXPIRY_MINUTES,
    ): OtpToken {
        $this->invalidateActiveOtps($userId, $purpose);

        $code = $this->generateOtp();

        return $this->saveOtp($userId, $code, $purpose, $validForMinutes);
    }

    /**
     * Mark an OTP token as used.
     */
    public function markOtpAsUsed(OtpToken $otpToken): void
    {
        $otpToken->update([
            'used_at' => Carbon::now(),
        ]);
    }

    /**
     * Increment the attempt count for an OTP token.
     */
    public function incrementAttempts(OtpToken $otpToken): void
    {
        $otpToken->increment('attempt_count');
    }

    /**
     * Validate an OTP token, enforce lockout and expiry, and mark as used on success.
     *
     * @throws InvalidOtpException
     */
    public function verify(int $userId, string $purpose, string $code): OtpToken
    {
        // 1. Locate the latest active (unused) OTP for this user and purpose
        $otpToken = OtpToken::where('user_id', $userId)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if (! $otpToken) {
            throw new InvalidOtpException('Invalid or expired OTP.');
        }

        // 2. Lockout check: already exhausted attempts
        if ($otpToken->attempt_count >= $otpToken->max_attempts) {
            throw new InvalidOtpException(self::LOCKOUT_MESSAGE);
        }

        // 3. Expiration check
        if ($otpToken->expires_at->isPast()) {
            throw new InvalidOtpException('OTP has expired. Please request a new one.');
        }

        // 4. Code comparison (timing-safe)
        if (! hash_equals($otpToken->otp_code, $code)) {
            $this->incrementAttempts($otpToken);

            if ($otpToken->attempt_count >= $otpToken->max_attempts) {
                throw new InvalidOtpException(self::LOCKOUT_MESSAGE);
            }

            throw new InvalidOtpException('Invalid OTP code.');
        }

        // 5. Success: mark used and return
        $this->markOtpAsUsed($otpToken);

        return $otpToken;
    }
}
