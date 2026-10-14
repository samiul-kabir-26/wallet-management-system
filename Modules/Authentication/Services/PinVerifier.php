<?php

namespace Modules\Authentication\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Authentication\Exceptions\InvalidPinException;
use Modules\Authentication\Exceptions\PinLockedException;

class PinVerifier
{
    /**
     * Get the rate limiter throttle key for a user's PIN attempts.
     */
    public static function getThrottleKey(int $userId): string
    {
        return "pin-attempts:{$userId}";
    }

    /**
     * Verify that the user's PIN matches and enforce atomic brute-force protection.
     *
     * @throws PinLockedException
     * @throws InvalidPinException
     */
    public function verify(User $user, string $pin): void
    {
        $key = self::getThrottleKey($user->id);
        $maxAttempts = (int) config('auth_settings.pin.max_attempts', 5);
        $decaySeconds = (int) config('auth_settings.pin.decay_seconds', 900);

        // 1. If already locked out, reject immediately
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw $this->buildLockoutException($key);
        }

        // 2. Increment atomically BEFORE running Hash::check (bcrypt)
        $currentAttempts = RateLimiter::hit($key, $decaySeconds);

        // 3. If this hit pushed the counter over the max limit, reject immediately
        if ($currentAttempts > $maxAttempts) {
            throw $this->buildLockoutException($key);
        }

        // 4. Verify PIN hash
        if (empty($user->pin) || ! Hash::check($pin, $user->pin)) {
            $attemptsLeft = max(0, $maxAttempts - $currentAttempts);

            if ($attemptsLeft === 0) {
                throw $this->buildLockoutException($key);
            }

            throw new InvalidPinException(
                attemptsRemaining: $attemptsLeft,
                message: "Wrong PIN. {$attemptsLeft} attempt(s) left.",
            );
        }

        // 5. Success clears the rate limiter counter
        RateLimiter::clear($key);
    }

    /**
     * Build a PinLockedException populated with retry timing for the given key.
     */
    private function buildLockoutException(string $key): PinLockedException
    {
        $secondsRemaining = RateLimiter::availableIn($key);
        $minutes = (int) ceil($secondsRemaining / 60);

        return new PinLockedException(
            retryAfter: $secondsRemaining,
            message: "Too many incorrect PIN attempts. Your account is temporarily locked. Please try again in {$minutes} minute(s).",
        );
    }
}
