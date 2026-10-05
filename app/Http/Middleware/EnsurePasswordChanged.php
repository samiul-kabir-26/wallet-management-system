<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Authentication\Exceptions\PasswordChangeRequiredException;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * Handle an incoming request.
     *
     * @throws PasswordChangeRequiredException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->password !== null && $user->password_changed_at === null) {
            if (! $request->is('api/v1/auth/change-password') && ! $request->routeIs('auth.change-password')) {
                throw new PasswordChangeRequiredException;
            }
        }

        return $next($request);
    }
}
