<?php

namespace Modules\Authentication\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Authentication\Exceptions\InvalidOtpException;
use Modules\Authentication\Notifications\DeviceVerificationCodeNotification;

/**
 * Device binding for PIN logins.
 *
 * A correct PIN from a device the account has not proved before does not get a token. Instead an SMS
 * code goes to the account's phone and a short-lived challenge is returned; the code plus the challenge
 * trusts the device and completes the login. A stolen PIN alone is therefore not enough from a new
 * phone or browser.
 */
class DeviceBindingService
{
    public const string OTP_PURPOSE = 'DEVICE_VERIFICATION';

    public function __construct(
        protected OtpService $otpService,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('auth_settings.device_binding.enabled', true);
    }

    /**
     * Whether this device is already trusted for the account; refreshes its last use when it is.
     */
    public function isTrusted(User $user, ?string $deviceId): bool
    {
        if ($deviceId === null || $deviceId === '') {
            return false;
        }

        $device = TrustedDevice::where('user_id', $user->id)->where('device_hash', TrustedDevice::hash($deviceId))->first();
        $device?->forceFill(['last_used_at' => now(), 'ip_address' => request()->ip()])->save();

        return $device !== null;
    }

    /**
     * Text a code and return a challenge ID that, with the code, completes the login.
     */
    public function challenge(User $user, string $panel, ?string $deviceId, Request $request): string
    {
        $minutes = (int) config('auth_settings.device_binding.challenge_minutes', 10);
        $otp = $this->otpService->createOtp($user->id, self::OTP_PURPOSE, $minutes);

        $user->notify(new DeviceVerificationCodeNotification($otp->otp_code, $minutes));

        $challengeId = Str::random(48);
        Cache::put($this->cacheKey($challengeId), [
            'user_id' => $user->id,
            'panel' => $panel,
            'device_hash' => $deviceId ? TrustedDevice::hash($deviceId) : null,
            'label' => Str::limit((string) $request->userAgent(), 250, ''),
        ], now()->addMinutes($minutes));

        return $challengeId;
    }

    /**
     * Check the code for a challenge, trust the device, and return the user and panel to sign in to.
     *
     * @return array{user: User, panel: 'agent'|'user'}
     *
     * @throws InvalidOtpException
     */
    public function complete(string $challengeId, string $code, Request $request): array
    {
        $challenge = Cache::get($this->cacheKey($challengeId));
        if (! is_array($challenge)) {
            throw new InvalidOtpException('This sign-in request has expired. Please sign in again.');
        }

        $user = User::query()->findOrFail((int) $challenge['user_id']);
        $this->otpService->verify($user->id, self::OTP_PURPOSE, $code);
        Cache::forget($this->cacheKey($challengeId));

        if ($challenge['device_hash'] !== null) {
            TrustedDevice::updateOrCreate(
                ['user_id' => $user->id, 'device_hash' => $challenge['device_hash']],
                ['label' => $challenge['label'], 'ip_address' => $request->ip(), 'last_used_at' => now()],
            );
        }

        return ['user' => $user, 'panel' => $challenge['panel'] === 'agent' ? 'agent' : 'user'];
    }

    /**
     * Trust the device an account was just registered on (the phone was proved with a code).
     */
    public function trust(User $user, ?string $deviceId, Request $request): void
    {
        if ($deviceId === null || $deviceId === '') {
            return;
        }

        TrustedDevice::updateOrCreate(
            ['user_id' => $user->id, 'device_hash' => TrustedDevice::hash($deviceId)],
            ['label' => Str::limit((string) $request->userAgent(), 250, ''), 'ip_address' => $request->ip(), 'last_used_at' => now()],
        );
    }

    /**
     * Forget every trusted device, e.g. after a PIN reset.
     */
    public function forgetAll(User $user): void
    {
        TrustedDevice::where('user_id', $user->id)->delete();
    }

    private function cacheKey(string $challengeId): string
    {
        return 'device-challenge:'.hash('sha256', $challengeId);
    }
}
