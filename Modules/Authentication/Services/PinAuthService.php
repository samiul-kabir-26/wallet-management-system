<?php

namespace Modules\Authentication\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Exceptions\AccountInactiveException;
use Modules\Authentication\Exceptions\InvalidCredentialsException;

class PinAuthService
{
    /**
     * Precomputed valid bcrypt hash used to equalize execution timing
     * when a user is not found or has no PIN set.
     */
    private const string DUMMY_HASH = '$2y$12$xn5OszU/1GZvtORdddVo2.dXALwyzJcbpB6JlSmyvPK.DUrKdJFra';

    /**
     * Attempt to authenticate a user using their phone number and PIN.
     *
     * @throws InvalidCredentialsException
     * @throws AccountInactiveException
     */
    public function attempt(string $phoneNumber, string $pin): User
    {
        $user = User::with('roles')->where('phone_number', $phoneNumber)->first();

        // 1. Missing user or user has no PIN (e.g. admin-only account)
        // Run dummy Hash::check() to consume equivalent bcrypt cycles before throwing.
        if (! $user || ! $user->pin) {
            Hash::check($pin, self::DUMMY_HASH);

            Log::warning('Failed login attempt', [
                'phone_number' => $phoneNumber,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            throw new InvalidCredentialsException;
        }

        // 2. Incorrect PIN check
        if (! Hash::check($pin, $user->pin)) {
            Log::warning('Failed login attempt', [
                'phone_number' => $phoneNumber,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            throw new InvalidCredentialsException;
        }

        // 3. Inactive account check
        if ($user->is_active !== 'ACTIVE') {
            throw new AccountInactiveException;
        }

        return $user;
    }
}
