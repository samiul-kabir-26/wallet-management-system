<?php

namespace Modules\Users\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Exceptions\InvalidOtpException;
use Modules\Authentication\Notifications\PhoneVerificationCodeNotification;
use Modules\Authentication\Services\OtpService;
use Modules\Users\Exceptions\PhoneAlreadySetException;
use Modules\Users\Exceptions\PhoneAlreadyVerifiedException;
use Modules\Users\Exceptions\UserHasNoPhoneException;

/**
 * Self-service changes a signed-in account makes to itself: avatar, adding a phone number and phone verification.
 */
class AccountService
{
    public const string PHONE_VERIFICATION_PURPOSE = 'PHONE_VERIFICATION';

    public const string ADD_PHONE_PURPOSE = 'ADD_PHONE';

    public function __construct(
        protected OtpService $otpService,
        protected UserService $userService,
    ) {}

    /**
     * Store a new avatar on the public disk and remove the previous one.
     */
    public function updateAvatar(User $user, UploadedFile $file): User
    {
        $previous = $user->avatarUrl() !== null ? $user->image : null;

        $path = $file->store(User::AVATAR_DIRECTORY, 'public');

        $user->forceFill(['image' => $path])->save();

        if ($previous !== null) {
            Storage::disk('public')->delete($previous);
        }

        return $user;
    }

    /**
     * Remove the avatar file and clear the reference.
     */
    public function removeAvatar(User $user): User
    {
        if ($user->avatarUrl() !== null) {
            Storage::disk('public')->delete($user->image);
        }

        $user->forceFill(['image' => null])->save();

        return $user;
    }

    /**
     * Send a one-time code by SMS to the account's phone number.
     *
     * @throws UserHasNoPhoneException
     * @throws PhoneAlreadyVerifiedException
     */
    public function requestPhoneVerification(User $user): void
    {
        $this->assertPhoneCanBeVerified($user);

        $otp = $this->otpService->createOtp($user->id, self::PHONE_VERIFICATION_PURPOSE);

        $user->notify(new PhoneVerificationCodeNotification(
            $otp->otp_code,
            (int) config('auth_settings.otp.expiry_minutes', OtpService::DEFAULT_EXPIRY_MINUTES),
        ));
    }

    /**
     * Verify the SMS code and mark the phone number as verified (audited as PHONE_VERIFIED).
     *
     * @throws UserHasNoPhoneException
     * @throws PhoneAlreadyVerifiedException
     * @throws InvalidOtpException
     */
    public function confirmPhoneVerification(User $user, string $code): User
    {
        $this->assertPhoneCanBeVerified($user);

        // Consumed outside the write transaction so failed attempts are counted even though verification throws
        $this->otpService->verify($user->id, self::PHONE_VERIFICATION_PURPOSE, $code);

        return $this->userService->verifyPhone($user, $user, 'OTP');
    }

    /**
     * Text a code to a number the account wants to add. Nothing is saved until the code comes back.
     *
     * Uniqueness is validated by the request; it is checked again on confirmation.
     *
     * @throws PhoneAlreadySetException
     */
    public function requestPhoneAddition(User $user, string $phoneNumber): void
    {
        if ($user->phone_number !== null) {
            throw new PhoneAlreadySetException;
        }

        $otp = $this->otpService->createOtp($user->id, self::ADD_PHONE_PURPOSE, target: $phoneNumber);

        Notification::route('sms', $phoneNumber)->notify(new PhoneVerificationCodeNotification(
            $otp->otp_code,
            (int) config('auth_settings.otp.expiry_minutes', OtpService::DEFAULT_EXPIRY_MINUTES),
        ));
    }

    /**
     * Save the number the confirmed code was sent to, as verified, and audit it as PHONE_ADDED.
     *
     * The account can then sign in to the admin panel with that number and its password.
     *
     * @throws PhoneAlreadySetException
     * @throws InvalidOtpException
     * @throws ValidationException
     */
    public function confirmPhoneAddition(User $user, string $code): User
    {
        if ($user->phone_number !== null) {
            throw new PhoneAlreadySetException;
        }

        $otp = $this->otpService->verify($user->id, self::ADD_PHONE_PURPOSE, $code);
        $phoneNumber = (string) $otp->target;

        try {
            return DB::transaction(function () use ($user, $phoneNumber): User {
                $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

                if ($lockedUser->phone_number !== null) {
                    throw new PhoneAlreadySetException;
                }

                // Another account may have registered the number after the code was sent
                if (User::where('phone_number', $phoneNumber)->exists()) {
                    throw $this->phoneTakenException();
                }

                $lockedUser->forceFill([
                    'phone_number' => $phoneNumber,
                    'is_verified' => true,
                ])->save();

                AuditLog::create([
                    'actor_id' => $lockedUser->id,
                    'action' => 'PHONE_ADDED',
                    'auditable_type' => $lockedUser->getMorphClass(),
                    'auditable_id' => $lockedUser->id,
                    'metadata' => [
                        'phone_number' => $phoneNumber,
                        'method' => 'OTP',
                    ],
                ]);

                return $lockedUser;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->phoneTakenException();
        }
    }

    private function phoneTakenException(): ValidationException
    {
        return ValidationException::withMessages([
            'phone_number' => 'This phone number already belongs to another account.',
        ]);
    }

    /**
     * @throws UserHasNoPhoneException
     * @throws PhoneAlreadyVerifiedException
     */
    private function assertPhoneCanBeVerified(User $user): void
    {
        if ($user->phone_number === null) {
            throw new UserHasNoPhoneException('Your account has no phone number to verify.');
        }

        if ($user->is_verified) {
            throw new PhoneAlreadyVerifiedException;
        }
    }
}
