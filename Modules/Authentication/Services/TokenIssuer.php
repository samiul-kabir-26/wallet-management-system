<?php

namespace Modules\Authentication\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class TokenIssuer
{
    /**
     * Issue a new access token whose absolute lifetime depends on the panel ability it carries.
     *
     * @param  array<int, string>  $abilities
     */
    public function issue(User $user, string $name, array $abilities): NewAccessToken
    {
        return $this->stamp($user->createToken($name, $abilities, $this->expiresAtFor($abilities)));
    }

    /**
     * Replace the given token with a fresh one that keeps the same name, abilities and absolute expiry.
     *
     * Rotation never extends a session: the replacement expires exactly when the original would have.
     */
    public function rotate(User $user, PersonalAccessToken $currentToken): NewAccessToken
    {
        $abilities = $currentToken->abilities ?? [];
        $expiresAt = $currentToken->expires_at ?? $this->expiresAtFor($abilities);

        $currentToken->delete();

        return $this->stamp($user->createToken($currentToken->name, $abilities, $expiresAt));
    }

    /**
     * Remember the IP and browser a session started from, for the session list.
     */
    private function stamp(NewAccessToken $token): NewAccessToken
    {
        $request = request();
        $token->accessToken->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ])->save();

        return $token;
    }

    /**
     * Resolve the absolute expiry for a token carrying the given abilities.
     *
     * When a token carries several abilities, the shortest configured lifetime wins.
     *
     * @param  array<int, string>  $abilities
     */
    public function expiresAtFor(array $abilities): CarbonInterface
    {
        $lifetimes = config('auth_settings.tokens.lifetime_minutes', []);

        $minutes = collect($abilities)
            ->map(fn (string $ability): ?int => isset($lifetimes[$ability]) ? (int) $lifetimes[$ability] : null)
            ->filter()
            ->min() ?? (int) config('auth_settings.tokens.default_lifetime_minutes', 720);

        return now()->addMinutes($minutes);
    }
}
