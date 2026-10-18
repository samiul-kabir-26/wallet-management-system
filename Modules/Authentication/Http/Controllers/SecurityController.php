<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Authentication\Exceptions\InvalidCredentialsException;
use Modules\Authentication\Exceptions\InvalidOtpException;
use Modules\Authentication\Services\TotpService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The signed-in account's own security: active sessions, trusted devices and (staff) authenticator app.
 */
class SecurityController extends Controller
{
    public function __construct(
        protected TotpService $totp,
    ) {}

    /**
     * Sessions (access tokens) that are still valid, newest activity first.
     */
    public function sessions(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()?->getKey();

        $sessions = $request->user()->tokens()
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
            ->get()
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'panel' => $token->abilities[0] ?? null,
                'ip_address' => $token->ip_address,
                'user_agent' => $token->user_agent,
                'created_at' => $token->created_at,
                'last_used_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
                'current' => $token->id === $currentId,
            ]);

        return response()->json(['success' => true, 'data' => ['sessions' => $sessions]]);
    }

    /**
     * Sign out one of the account's sessions (any device, including this one).
     */
    public function revokeSession(Request $request, int $session): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($session)->first() ?? throw new NotFoundHttpException;
        $token->delete();

        $this->audit($request->user(), 'SESSION_REVOKED', ['session_id' => $session, 'panel' => $token->abilities[0] ?? null]);

        return response()->json(['success' => true, 'message' => 'Session signed out.']);
    }

    /**
     * Devices the account proved with an SMS code (device binding).
     */
    public function devices(Request $request): JsonResponse
    {
        $devices = TrustedDevice::where('user_id', $request->user()->id)
            ->latest('last_used_at')
            ->get(['id', 'label', 'ip_address', 'last_used_at', 'created_at']);

        return response()->json(['success' => true, 'data' => ['devices' => $devices]]);
    }

    /**
     * Forget a trusted device; its next PIN login needs an SMS code again.
     */
    public function forgetDevice(Request $request, int $device): JsonResponse
    {
        $deleted = TrustedDevice::where('user_id', $request->user()->id)->whereKey($device)->delete();
        if ($deleted === 0) {
            throw new NotFoundHttpException;
        }

        $this->audit($request->user(), 'TRUSTED_DEVICE_REMOVED', ['device_id' => $device]);

        return response()->json(['success' => true, 'message' => 'Device removed.']);
    }

    /**
     * Whether the staff member uses an authenticator app.
     */
    public function totpStatus(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['enabled' => $request->user()->usesTotp()]]);
    }

    /**
     * Start authenticator-app setup: a new secret the app must prove before it is switched on.
     */
    public function totpSetup(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->usesTotp()) {
            throw new ConflictHttpException('An authenticator app is already set up. Turn it off first to replace it.');
        }

        $secret = $this->totp->generateSecret();
        $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => null, 'totp_last_used_step' => null])->save();

        return response()->json([
            'success' => true,
            'message' => 'Add this key to your authenticator app, then enter the 6-digit code it shows.',
            'data' => [
                'secret' => $secret,
                'otpauth_uri' => $this->totp->provisioningUri($user, $secret),
            ],
        ]);
    }

    /**
     * Switch the authenticator app on once it produces a valid code.
     */
    public function totpConfirm(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'digits:6']])['code'];
        /** @var User $user */
        $user = $request->user();

        if ($user->usesTotp() || $user->totp_secret === null) {
            throw new ConflictHttpException('Start the setup first.');
        }

        $step = $this->totp->match((string) $user->totp_secret, $code);
        if ($step === null) {
            throw new InvalidOtpException('That code does not match. Check the time on your phone and try again.');
        }

        $user->forceFill(['totp_confirmed_at' => now(), 'totp_last_used_step' => $step])->save();
        $this->audit($user, 'TOTP_ENABLED', []);

        return response()->json(['success' => true, 'message' => 'Authenticator app turned on. Sign-in codes now come from the app instead of email.']);
    }

    /**
     * Switch the authenticator app off. Needs the password and a current code.
     */
    public function totpDisable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'digits:6'],
        ]);
        /** @var User $user */
        $user = $request->user();

        if (! $user->usesTotp()) {
            throw new ConflictHttpException('No authenticator app is set up.');
        }
        if (! Hash::check($validated['password'], (string) $user->password)) {
            throw new InvalidCredentialsException('The password is incorrect.');
        }
        if ($this->totp->match((string) $user->totp_secret, $validated['code'], $user->totp_last_used_step) === null) {
            throw new InvalidOtpException('Invalid authenticator code.');
        }

        $user->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_used_step' => null])->save();
        $this->audit($user, 'TOTP_DISABLED', []);

        return response()->json(['success' => true, 'message' => 'Authenticator app turned off. Sign-in codes will be emailed again.']);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(User $user, string $action, array $metadata): void
    {
        AuditLog::create([
            'actor_id' => $user->id,
            'action' => $action,
            'auditable_type' => $user->getMorphClass(),
            'auditable_id' => $user->id,
            'metadata' => [...$metadata, 'ip' => request()->ip()],
        ]);
    }
}
