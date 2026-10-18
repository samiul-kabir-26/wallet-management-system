<?php

namespace Modules\Users\Services;

use App\Models\AuditLog;
use App\Models\Cap;
use App\Models\KycSubmission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Users\Exceptions\KycConflictException;
use Modules\Users\Notifications\KycReviewedNotification;
use Throwable;

/**
 * KYC tiers and per-user limits.
 *
 * A tier (config/kyc.php) is a named pair of daily/monthly limits. The limits are written to the user's
 * caps row, which CapService enforces on every cash-out and transfer, so enforcement has one source of
 * truth. Staff can also set custom limits; the next tier change replaces them.
 *
 * Customers raise their tier by sending identity documents, which staff approve (STANDARD) or reject.
 * Staff never act on their own account.
 */
class KycService
{
    /**
     * @return array<string, array{label: string, description: string, daily_cap: float, monthly_cap: float, requires_verification: bool}>
     */
    public function tiers(): array
    {
        return config('kyc.tiers', []);
    }

    /**
     * Store a customer's identity documents for review.
     *
     * @param  array{document_type: string, document_number: string, full_name: string, date_of_birth: string}  $data
     *
     * @throws KycConflictException
     */
    public function submit(User $user, array $data, UploadedFile $front, UploadedFile $back): KycSubmission
    {
        if (KycSubmission::where('user_id', $user->id)->where('status', KycSubmission::PENDING)->exists()) {
            throw new KycConflictException('Your documents are already waiting for review.');
        }

        $directory = KycSubmission::DOCUMENT_DIRECTORY.'/'.$user->id;
        $frontPath = $front->store($directory, 'local');
        $backPath = $back->store($directory, 'local');
        if ($frontPath === false || $backPath === false) {
            Storage::disk('local')->delete(array_filter([$frontPath, $backPath]));

            throw ValidationException::withMessages(['front' => ['The document images could not be saved. Please try again.']]);
        }

        try {
            return DB::transaction(function () use ($user, $data, $frontPath, $backPath): KycSubmission {
                $submission = KycSubmission::create([
                    'user_id' => $user->id,
                    'document_type' => $data['document_type'],
                    'document_number' => strtoupper(preg_replace('/\s+/', '', $data['document_number']) ?? ''),
                    'full_name' => $data['full_name'],
                    'date_of_birth' => $data['date_of_birth'],
                    'front_path' => $frontPath,
                    'back_path' => $backPath,
                    'status' => KycSubmission::PENDING,
                ]);

                $this->audit('KYC_SUBMITTED', $user, $user, ['submission_id' => $submission->id, 'document_type' => $submission->document_type]);

                return $submission;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete([$frontPath, $backPath]);

            throw $e;
        }
    }

    /**
     * Approve the documents: mark the identity verified and raise a BASIC account to STANDARD.
     * Accounts already above STANDARD keep their tier.
     *
     * @throws AuthorizationException
     * @throws KycConflictException
     */
    public function approve(KycSubmission $submission, User $staff, ?string $note = null): KycSubmission
    {
        return DB::transaction(function () use ($submission, $staff, $note): KycSubmission {
            $locked = $this->lockPending($submission);
            $this->assertNotSelf($staff, (int) $locked->user_id);

            $locked->forceFill([
                'status' => KycSubmission::APPROVED,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $user = User::whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            $user->forceFill(['kyc_verified_at' => now()])->save();

            if ($this->rank($user->kyc_tier) < $this->rank('STANDARD')) {
                $this->applyTier($user, 'STANDARD');
            }

            $this->audit('KYC_APPROVED', $staff, $user, ['submission_id' => $locked->id, 'tier' => $user->kyc_tier, 'note' => $note]);

            $user->notify(new KycReviewedNotification(
                $locked,
                config("kyc.tiers.{$user->kyc_tier}.label", $user->kyc_tier),
                (string) $user->cap?->daily_cap,
            ));

            return $locked;
        });
    }

    /**
     * Reject the documents with a reason the customer receives by SMS.
     *
     * @throws AuthorizationException
     * @throws KycConflictException
     */
    public function reject(KycSubmission $submission, User $staff, string $reason): KycSubmission
    {
        return DB::transaction(function () use ($submission, $staff, $reason): KycSubmission {
            $locked = $this->lockPending($submission);
            $this->assertNotSelf($staff, (int) $locked->user_id);

            $locked->forceFill([
                'status' => KycSubmission::REJECTED,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
                'review_note' => $reason,
            ])->save();

            $user = User::findOrFail($locked->user_id);
            $this->audit('KYC_REJECTED', $staff, $user, ['submission_id' => $locked->id, 'reason' => $reason]);
            $user->notify(new KycReviewedNotification($locked));

            return $locked;
        });
    }

    /**
     * Move a customer to another tier and reset their limits to that tier's.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function changeTier(User $target, string $tier, User $staff, string $reason): User
    {
        $this->assertNotSelf($staff, $target->id);

        $config = $this->tiers()[$tier] ?? null;
        if ($config === null) {
            throw ValidationException::withMessages(['tier' => ['Unknown tier.']]);
        }

        return DB::transaction(function () use ($target, $tier, $config, $staff, $reason): User {
            $user = User::whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($config['requires_verification'] && $user->kyc_verified_at === null) {
                throw ValidationException::withMessages([
                    'tier' => ["The {$config['label']} tier needs verified identity documents first."],
                ]);
            }

            $before = $this->limitsOf($user);
            $previousTier = $user->kyc_tier;
            $this->applyTier($user, $tier);

            $this->audit('KYC_TIER_CHANGED', $staff, $user, [
                'from' => $previousTier,
                'to' => $tier,
                'limits_before' => $before,
                'limits_after' => $this->limitsOf($user),
                'reason' => $reason,
            ]);

            return $user->refresh();
        });
    }

    /**
     * Give one customer limits that differ from their tier's.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function setCustomLimits(User $target, string|float $dailyCap, string|float $monthlyCap, User $staff, string $reason): User
    {
        $this->assertNotSelf($staff, $target->id);

        $daily = number_format((float) $dailyCap, 2, '.', '');
        $monthly = number_format((float) $monthlyCap, 2, '.', '');
        if (bccomp($daily, $monthly, 2) === 1) {
            throw ValidationException::withMessages(['daily_cap' => ['The daily limit cannot be higher than the monthly limit.']]);
        }

        return DB::transaction(function () use ($target, $daily, $monthly, $staff, $reason): User {
            $user = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $cap = $this->lockCap($user);
            $before = $this->limitsOf($user);

            $cap->daily_cap = $daily;
            $cap->monthly_cap = $monthly;
            $cap->is_custom = true;
            $cap->save();

            $this->audit('CAPS_CUSTOMIZED', $staff, $user, [
                'tier' => $user->kyc_tier,
                'limits_before' => $before,
                'limits_after' => ['daily_cap' => $daily, 'monthly_cap' => $monthly],
                'reason' => $reason,
            ]);

            return $user->refresh();
        });
    }

    /**
     * Set the tier and write its limits to the locked caps row. Usage counters are untouched.
     */
    private function applyTier(User $user, string $tier): void
    {
        $config = $this->tiers()[$tier];

        $user->forceFill(['kyc_tier' => $tier])->save();

        $cap = $this->lockCap($user);
        $cap->daily_cap = number_format((float) $config['daily_cap'], 2, '.', '');
        $cap->monthly_cap = number_format((float) $config['monthly_cap'], 2, '.', '');
        $cap->is_custom = false;
        $cap->save();
    }

    private function lockCap(User $user): Cap
    {
        return Cap::where('user_id', $user->id)->lockForUpdate()->first()
            ?? $user->cap()->create()->refresh();
    }

    /**
     * @return array{daily_cap: string|null, monthly_cap: string|null, is_custom: bool}
     */
    private function limitsOf(User $user): array
    {
        $cap = Cap::where('user_id', $user->id)->first();

        return [
            'daily_cap' => $cap?->daily_cap,
            'monthly_cap' => $cap?->monthly_cap,
            'is_custom' => (bool) $cap?->is_custom,
        ];
    }

    private function rank(string $tier): int
    {
        $position = array_search($tier, array_keys($this->tiers()), true);

        return $position === false ? -1 : $position;
    }

    private function lockPending(KycSubmission $submission): KycSubmission
    {
        $locked = KycSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== KycSubmission::PENDING) {
            throw new KycConflictException;
        }

        return $locked;
    }

    /**
     * @throws AuthorizationException
     */
    private function assertNotSelf(User $staff, int $userId): void
    {
        if ($staff->id === $userId) {
            throw new AuthorizationException('You cannot change your own verification or limits.');
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(string $action, User $actor, User $subject, array $metadata): void
    {
        AuditLog::create([
            'actor_id' => $actor->id,
            'action' => $action,
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->id,
            'metadata' => $metadata,
        ]);
    }
}
