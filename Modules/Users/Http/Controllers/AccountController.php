<?php

namespace Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Exceptions\InvalidOtpException;
use Modules\Users\Exceptions\PhoneAlreadySetException;
use Modules\Users\Exceptions\PhoneAlreadyVerifiedException;
use Modules\Users\Exceptions\UserHasNoPhoneException;
use Modules\Users\Http\Requests\AddPhoneRequest;
use Modules\Users\Http\Requests\ConfirmPhoneVerificationRequest;
use Modules\Users\Http\Requests\UpdateAccountRequest;
use Modules\Users\Http\Requests\UpdateAvatarRequest;
use Modules\Users\Resources\UserResource;
use Modules\Users\Services\AccountService;

/**
 * The signed-in account managing itself, on any panel.
 */
class AccountController extends Controller
{
    public function __construct(
        private AccountService $accountService,
    ) {}

    /**
     * Show the signed-in account's profile.
     */
    public function show(Request $request): JsonResponse
    {
        return $this->profileResponse($request->user());
    }

    /**
     * Update the signed-in account's name or address.
     */
    public function update(UpdateAccountRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return $this->profileResponse($user, 'Profile updated.');
    }

    /**
     * Upload a new avatar, replacing the previous one.
     */
    public function updateAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        $user = $this->accountService->updateAvatar($request->user(), $request->file('avatar'));

        return $this->profileResponse($user, 'Profile photo updated.');
    }

    /**
     * Remove the avatar.
     */
    public function removeAvatar(Request $request): JsonResponse
    {
        $user = $this->accountService->removeAvatar($request->user());

        return $this->profileResponse($user, 'Profile photo removed.');
    }

    /**
     * Send a verification code by SMS to the account's phone number.
     *
     * @throws UserHasNoPhoneException
     * @throws PhoneAlreadyVerifiedException
     */
    public function requestPhoneVerification(Request $request): JsonResponse
    {
        $this->accountService->requestPhoneVerification($request->user());

        return response()->json([
            'success' => true,
            'message' => 'We sent a 6-digit code to your phone number.',
            'data' => [
                'expires_in_minutes' => (int) config('auth_settings.otp.expiry_minutes', 5),
            ],
        ]);
    }

    /**
     * Confirm the SMS code and mark the phone number as verified.
     *
     * @throws UserHasNoPhoneException
     * @throws PhoneAlreadyVerifiedException
     * @throws InvalidOtpException
     */
    public function confirmPhoneVerification(ConfirmPhoneVerificationRequest $request): JsonResponse
    {
        $user = $this->accountService->confirmPhoneVerification($request->user(), $request->string('otp_code')->toString());

        return $this->profileResponse($user, 'Your phone number is verified.');
    }

    /**
     * Text a code to a phone number the account wants to add.
     *
     * @throws PhoneAlreadySetException
     */
    public function requestPhoneAddition(AddPhoneRequest $request): JsonResponse
    {
        $this->accountService->requestPhoneAddition($request->user(), $request->string('phone_number')->toString());

        return response()->json([
            'success' => true,
            'message' => 'We sent a 6-digit code to that number.',
            'data' => [
                'expires_in_minutes' => (int) config('auth_settings.otp.expiry_minutes', 5),
            ],
        ]);
    }

    /**
     * Confirm the code and save the phone number as verified.
     *
     * @throws PhoneAlreadySetException
     * @throws InvalidOtpException
     * @throws ValidationException
     */
    public function confirmPhoneAddition(ConfirmPhoneVerificationRequest $request): JsonResponse
    {
        $user = $this->accountService->confirmPhoneAddition($request->user(), $request->string('otp_code')->toString());

        return $this->profileResponse($user, 'Phone number added and verified.');
    }

    private function profileResponse(User $user, ?string $message = null): JsonResponse
    {
        $user->load('roles');

        return response()->json(array_filter([
            'success' => true,
            'message' => $message,
            'data' => [
                'user' => new UserResource($user),
            ],
        ], fn ($value) => $value !== null));
    }
}
