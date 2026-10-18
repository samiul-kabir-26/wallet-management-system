<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Modules\Roles\Enums\Permission as PermissionEnum;
use Modules\SystemSettings\Services\SessionTimeoutService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureAuthorizationLogging();
        $this->configurePermissionsAndGates();
        $this->configureTokenIdleTimeout();
    }

    /**
     * Reject access tokens that have been idle longer than the configured timeout.
     *
     * Sanctum calls this before it refreshes last_used_at, so the timestamp still
     * reflects the previous request. Absolute expiry is enforced by Sanctum via expires_at.
     */
    protected function configureTokenIdleTimeout(): void
    {
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $accessToken, bool $isValid): bool {
            $idleMinutes = app(SessionTimeoutService::class)->minutesForToken($accessToken);

            if (! $isValid || $idleMinutes <= 0) {
                return $isValid;
            }

            $lastActivity = $accessToken->last_used_at ?? $accessToken->created_at;

            return $lastActivity === null || $lastActivity->gt(now()->subMinutes($idleMinutes));
        });
    }

    /**
     * Configure dynamic permission gates and super admin bypass.
     *
     * Two-part authorization check:
     *   1. Token ability is 'admin' (Sanctum defense in depth).
     *   2. User is SUPER_ADMIN -> bypasses via Gate::before (only if 1 holds).
     *   3. Some role the user holds has the permission -> User::hasPermission.
     *
     * Notes:
     *   - Gate::before returning true short-circuits. Returning null falls through.
     *     Never return false from Gate::before, as that would deny non-super-admin gates.
     *   - tokenCan('admin') is checked inside Gate::before and each Gate definition
     *     so that PIN-issued or customer tokens cannot exercise admin permissions.
     */
    protected function configurePermissionsAndGates(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if (PermissionEnum::tryFrom($ability) !== null && $user->tokenCan('admin') && $user->isSuperAdmin()) {
                return true;
            }

            return null;
        });

        foreach (PermissionEnum::cases() as $permission) {
            Gate::define($permission->value, function (User $user) use ($permission): bool {
                if ($permission->isSuperAdminOnly()) {
                    return false;
                }

                return $user->tokenCan('admin') && $user->hasPermission($permission);
            });
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn (): ?Password => app()->isProduction()
                ? Password::min(12)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised()
                : null,
        );
    }

    /**
     * Configure rate limiters for authentication endpoints.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $identifier = $request->input('phone_number')
                ?? $request->input('email')
                ?? $request->input('identifier')
                ?? '';

            $key = Str::transliterate(Str::lower($identifier).'|'.$request->ip());
            $limit = (int) config('auth_settings.rate_limits.login', 5);

            return Limit::perMinute($limit)
                ->by($key)
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Too many attempts. Please try again later.',
                        'errors' => [],
                    ], 429, $headers);
                });
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            $limit = (int) config('auth_settings.rate_limits.forgot_password', 5);

            return Limit::perMinute($limit)
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Too many attempts. Please try again later.',
                        'errors' => [],
                    ], 429, $headers);
                });
        });

        // SMS costs money and can be used to harass a number, so codes are limited per account
        RateLimiter::for('phone-otp', function (Request $request) {
            $limit = (int) config('auth_settings.rate_limits.phone_otp', 3);

            $tooMany = fn (Request $request, array $headers) => response()->json([
                'success' => false,
                'message' => 'Too many codes requested. Please wait a few minutes and try again.',
                'errors' => [],
            ], 429, $headers);

            // Per account, and per destination number when one is supplied, so no account can flood someone else's phone
            // Signed-out callers (signup) are keyed by IP, which many people can share, so that limit is looser
            $requester = $request->user()
                ? Limit::perMinutes(10, $limit)->by('phone-otp:user:'.$request->user()->id)
                : Limit::perMinutes(10, (int) config('auth_settings.rate_limits.phone_otp_per_ip', 10))->by('phone-otp:ip:'.$request->ip());

            return array_filter([
                $requester->response($tooMany),
                is_string($request->input('phone_number'))
                    ? Limit::perMinutes(10, $limit)->by('phone-otp-to:'.$request->input('phone_number'))->response($tooMany)
                    : null,
            ]);
        });

        RateLimiter::for('register', function (Request $request) {
            $limit = (int) config('auth_settings.rate_limits.register', 5);

            return Limit::perMinute($limit)
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Too many attempts. Please try again later.',
                        'errors' => [],
                    ], 429, $headers);
                });
        });
    }

    /**
     * Log authorization denials evaluated by Gates or Policies.
     */
    protected function configureAuthorizationLogging(): void
    {
        Gate::after(function (?User $user, string $ability, bool|Response $result, array $arguments): void {
            $allowed = $result instanceof Response ? $result->allowed() : (bool) $result;

            if (! $allowed) {
                $resourceId = null;
                if (! empty($arguments)) {
                    $first = reset($arguments);
                    $resourceId = is_object($first) && isset($first->id) ? $first->id : (is_scalar($first) ? $first : null);
                }

                Log::warning('Authorization denied', [
                    'user_id' => $user?->id,
                    'action' => $ability,
                    'resource_id' => $resourceId,
                ]);
            }
        });
    }
}
