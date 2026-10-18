<?php

namespace Modules\Authentication\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Exceptions\AccountInactiveException;
use Modules\Authentication\Exceptions\InvalidPinException;
use Modules\Authentication\Exceptions\PinLockedException;

/**
 * A signed-in agent or customer replacing a PIN they still know.
 *
 * A forgotten PIN is not handled here: that is the staff-initiated reset (Case C).
 */
class PinChangeService
{
    public function __construct(
        private PinVerifier $pinVerifier,
    ) {}

    /**
     * Verify the current PIN, store the new one, and sign out every other session.
     *
     * The current PIN check shares the login lockout counter, so this cannot be used to guess a PIN.
     *
     * @throws AccountInactiveException
     * @throws InvalidPinException
     * @throws PinLockedException
     */
    public function changePin(User $user, string $currentPin, string $newPin, ?int $keepTokenId = null): void
    {
        if ($user->is_active !== 'ACTIVE') {
            throw new AccountInactiveException;
        }

        $this->pinVerifier->verify($user, $currentPin);

        DB::transaction(function () use ($user, $newPin, $keepTokenId): void {
            // The 'hashed' cast hashes the new PIN on save
            $user->forceFill(['pin' => $newPin])->save();

            $revoked = $user->tokens()
                ->when($keepTokenId !== null, fn ($query) => $query->whereKeyNot($keepTokenId))
                ->delete();

            AuditLog::create([
                'actor_id' => $user->id,
                'action' => 'PIN_CHANGED',
                'auditable_type' => $user->getMorphClass(),
                'auditable_id' => $user->id,
                'metadata' => [
                    'other_sessions_revoked' => $revoked,
                ],
            ]);
        });
    }
}
