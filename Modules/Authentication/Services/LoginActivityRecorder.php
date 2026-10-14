<?php

namespace Modules\Authentication\Services;

use App\Exceptions\ApiException;
use App\Models\LoginActivity;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class LoginActivityRecorder
{
    /**
     * Run a login step and record its outcome, re-throwing any failure unchanged.
     *
     * @template TResult
     *
     * @param  'admin'|'agent'|'user'  $panel
     * @param  Closure(): TResult  $attempt
     * @param  (Closure(TResult): User)|null  $resolveUser  Maps the step's result to the authenticated user.
     * @param  bool  $recordSuccess  False for intermediate steps (admin password check before the OTP).
     * @return TResult
     */
    public function track(
        Request $request,
        string $panel,
        string $identifier,
        Closure $attempt,
        ?Closure $resolveUser = null,
        bool $recordSuccess = true,
    ): mixed {
        try {
            $result = $attempt();
        } catch (Throwable $e) {
            // Only API exceptions carry user-safe messages; anything else may contain internals
            $reason = $e instanceof ApiException ? $e->getMessage() : 'Unexpected error';
            $this->record($request, $panel, $identifier, 'FAILED', $this->findUser($identifier), $reason);

            throw $e;
        }

        if ($recordSuccess) {
            $user = $resolveUser !== null ? $resolveUser($result) : $result;
            $this->record($request, $panel, $identifier, 'SUCCESS', $user);
        }

        return $result;
    }

    /**
     * Persist one login attempt.
     *
     * @param  'admin'|'agent'|'user'  $panel
     * @param  'SUCCESS'|'FAILED'  $status
     */
    private function record(Request $request, string $panel, string $identifier, string $status, ?User $user, ?string $reason = null): void
    {
        LoginActivity::create([
            'user_id' => $user?->id,
            'panel' => $panel,
            'identifier' => Str::limit($identifier, 250, ''),
            'status' => $status,
            'failure_reason' => $reason !== null ? Str::limit($reason, 250) : null,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);
    }

    /**
     * Resolve the account an identifier refers to, using the same email-or-phone rule as admin login.
     */
    private function findUser(string $identifier): ?User
    {
        $column = str_contains($identifier, '@') ? 'email' : 'phone_number';

        return User::where($column, $identifier)->first();
    }
}
