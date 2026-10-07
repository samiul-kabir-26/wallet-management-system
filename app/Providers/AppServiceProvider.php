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
