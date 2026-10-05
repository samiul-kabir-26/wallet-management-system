<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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

            return Limit::perMinute(5)
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
            return Limit::perMinute(5)
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
            return Limit::perMinute(5)
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
}
