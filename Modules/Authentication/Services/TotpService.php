<?php

namespace Modules\Authentication\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Authentication\Exceptions\InvalidOtpException;

/**
 * Time-based one-time passwords (RFC 6238: HMAC-SHA1, 30-second steps, 6 digits) for staff, compatible
 * with Google Authenticator, Microsoft Authenticator, Authy and similar apps.
 *
 * Implemented here rather than with a package: the algorithm is short and fixed, and keeping it in the
 * codebase avoids a dependency for a security-critical path. A code is accepted for the current step
 * and one step either side (clock drift), and never twice: the last accepted step is stored.
 */
class TotpService
{
    private const int PERIOD = 30;

    private const int DIGITS = 6;

    private const string BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A new random secret, base32-encoded (160 bits, as RFC 4226 recommends).
     */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /**
     * The otpauth:// URI authenticator apps import.
     */
    public function provisioningUri(User $user, string $secret): string
    {
        $issuer = (string) config('auth_settings.totp.issuer', 'WalletMS');
        $label = rawurlencode($issuer.':'.($user->email ?? $user->phone_number ?? (string) $user->id));

        return "otpauth://totp/{$label}?".http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
    }

    /**
     * Check a code against a secret. Returns the matched time step, or null.
     */
    public function match(string $secret, string $code, ?int $lastUsedStep = null, ?int $timestamp = null): ?int
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        $current = intdiv($timestamp ?? time(), self::PERIOD);
        $key = $this->base32Decode($secret);

        foreach ([0, -1, 1] as $offset) {
            $step = $current + $offset;
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }
            if (hash_equals($this->codeAt($key, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Verify a staff member's code during login and remember the step so it cannot be replayed.
     *
     * @throws InvalidOtpException
     */
    public function verifyForLogin(User $user, string $code): void
    {
        $limiterKey = "totp:{$user->id}";
        if (RateLimiter::tooManyAttempts($limiterKey, (int) config('auth_settings.totp.max_attempts', 5))) {
            throw new InvalidOtpException('Too many failed attempts. Wait a few minutes and try again.');
        }

        DB::transaction(function () use ($user, $code, $limiterKey): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $step = $this->match((string) $locked->totp_secret, $code, $locked->totp_last_used_step);

            if ($step === null) {
                RateLimiter::hit($limiterKey, 600);

                throw new InvalidOtpException('Invalid authenticator code.');
            }

            $locked->forceFill(['totp_last_used_step' => $step])->save();
            RateLimiter::clear($limiterKey);
        });
    }

    /**
     * The 6-digit code for one time step (RFC 4226 dynamic truncation).
     */
    public function codeAt(string $key, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public function base32Decode(string $secret): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');
        $bits = '';
        foreach (str_split($clean) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::BASE32, $char)), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr((int) bindec($byte) & 0xFF);
            }
        }

        return $bytes;
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }
}
