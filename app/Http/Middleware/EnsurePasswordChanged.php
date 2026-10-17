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

        // Only sessions opened with the password are blocked: a temporary password was chosen by someone
        // else, so it must not be usable. Agent/customer sessions were opened with the PIN and are unaffected.
        if ($user && $user->tokenCan('admin') && $user->mustChangePassword()) {
            if (! $request->is('api/v1/auth/change-password') && ! $request->routeIs('auth.change-password')) {
                throw new PasswordChangeRequiredException;
            }
        }

        return $next($request);
    }
}
