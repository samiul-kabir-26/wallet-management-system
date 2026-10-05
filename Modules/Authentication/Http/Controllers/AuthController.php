<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authentication\Exceptions\AccountHasNoPinException;
use Modules\Authentication\Exceptions\AccountInactiveException;
use Modules\Authentication\Exceptions\InsufficientRoleException;
use Modules\Authentication\Exceptions\InvalidCredentialsException;
use Modules\Authentication\Exceptions\InvalidOtpException;
use Modules\Authentication\Exceptions\UnverifiedAccountException;
use Modules\Authentication\Exceptions\UserNotFoundException;
use Modules\Authentication\Http\Requests\AcceptInviteRequest;
use Modules\Authentication\Http\Requests\AdminLoginRequest;
use Modules\Authentication\Http\Requests\ChangePasswordRequest;
use Modules\Authentication\Http\Requests\GrantAdminAccessRequest;
use Modules\Authentication\Http\Requests\InitiatePinResetRequest;
use Modules\Authentication\Http\Requests\PinLoginRequest;
use Modules\Authentication\Http\Requests\RegisterRequest;
use Modules\Authentication\Http\Requests\ResetPinRequest;
use Modules\Authentication\Http\Requests\SetPinRequest;
use Modules\Authentication\Http\Requests\VerifyOtpRequest;
use Modules\Authentication\Resources\AuthResource;
use Modules\Authentication\Services\AccountInviteService;
use Modules\Authentication\Services\AdminAuthService;
use Modules\Authentication\Services\PinAuthService;
use Modules\Authentication\Services\PinResetService;
use Modules\Authentication\Services\RegistrationService;
use Modules\Authentication\Services\SetPinService;

class AuthController extends Controller
{
    public function __construct(
        private PinAuthService $pinAuthService,
        private RegistrationService $registrationService,
        private AdminAuthService $adminAuthService,
        private SetPinService $setPinService,
        private AccountInviteService $accountInviteService,
        private PinResetService $pinResetService,
    ) {}

    /**
     * Initiate a customer PIN reset by authorized staff.
     *
     * @throws InsufficientRoleException
     * @throws UserNotFoundException
     * @throws AccountHasNoPinException
     * @throws AccountInactiveException
     */
    public function initiatePinReset(InitiatePinResetRequest $request): JsonResponse
    {
        $this->pinResetService->initiate(
            staffMember: $request->user(),
            phoneNumber: $request->phone_number,
            email: $request->email,
        );

        return response()->json([
            'success' => true,
            'message' => 'PIN reset link generated and sent.',
        ]);
    }

    /**
     * Reset a user's PIN using a signed recovery link.
     *
     * @throws AccountInactiveException
     */
    public function resetPin(ResetPinRequest $request, User $user): JsonResponse
    {
        $this->pinResetService->resetPin(
            user: $user,
            pin: $request->pin,
        );

        return response()->json([
            'success' => true,
            'message' => 'PIN reset successfully. Please log in with your new PIN.',
        ]);
    }

    /**
     * Change password, revoke the restricted token, and issue an admin token.
     *
     * @throws InvalidCredentialsException
     * @throws AccountInactiveException
     */
    public function changePassword(ChangePasswordRequest $request): AuthResource
    {
        $user = $request->user();

        $this->accountInviteService->changePassword(
            user: $user,
            currentPassword: $request->current_password,
            newPassword: $request->new_password,
        );

        // Revoke the current token (safe for both PersonalAccessToken and test TransientToken)
        $currentAccessToken = $user->currentAccessToken();
        if ($currentAccessToken && method_exists($currentAccessToken, 'delete')) {
            $currentAccessToken->delete();
        }

        // Issue a full admin token
        $token = $user->createToken('admin-login', ['admin'])->plainTextToken;

        return new AuthResource($user, $token, ['admin'], 'Password changed successfully.');
    }

    /**
     * Accept an invitation with the temporary password and issue a restricted password-change token.
     *
     * @throws InvalidCredentialsException
     * @throws AccountInactiveException
     */
    public function acceptInvite(AcceptInviteRequest $request, User $user): AuthResource
    {
        $user = $this->accountInviteService->acceptInvite($user, $request->password);

        $token = $user->createToken('accept-invite', ['password-change'])->plainTextToken;

        return new AuthResource($user, $token, ['password-change'], 'Please change your password to continue.');
    }

    /**
     * Grant admin access to a user and generate a signed invite.
     *
     * @throws InsufficientRoleException
     * @throws UnverifiedAccountException
     */
    public function grantAdminAccess(GrantAdminAccessRequest $request, User $user): JsonResponse
    {
        $this->accountInviteService->grant(
            caller: $request->user(),
            target: $user,
            email: $request->email,
        );

        return response()->json([
            'success' => true,
            'message' => 'Admin access granted and invitation sent.',
            'data' => [
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Request an OTP to set or update a PIN for an authenticated admin.
     *
     * @throws AccountInactiveException
     * @throws InsufficientRoleException
     */
    public function requestSetPinOtp(Request $request): JsonResponse
    {
        $this->setPinService->requestOtp($request->user());

        return response()->json([
            'success' => true,
            'message' => 'OTP sent to your email. Please check your inbox.',
        ]);
    }

    /**
     * Set a PIN for an authenticated admin using verified OTP.
     *
     * @throws InvalidOtpException
     * @throws AccountInactiveException
     * @throws InsufficientRoleException
     */
    public function setPin(SetPinRequest $request): JsonResponse
    {
        $this->setPinService->setPin(
            $request->user(),
            $request->otp_code,
            $request->pin,
        );

        return response()->json([
            'success' => true,
            'message' => 'PIN set successfully.',
        ]);
    }

    /**
     * Register a new user or agent.
     *
     * @throws ModelNotFoundException
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->registrationService->register($request->validated());

        $ability = strtolower($request->role);
        $token = $user->createToken("{$ability}-registration", [$ability])->plainTextToken;

        return (new AuthResource($user, $token, [$ability], 'User registered successfully'))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Authenticate an agent using phone number and PIN.
     *
     * @throws InvalidCredentialsException
     * @throws AccountInactiveException
     * @throws InsufficientRoleException
     */
    public function loginAgent(PinLoginRequest $request): AuthResource
    {
        $user = $this->pinAuthService->attempt($request->phone_number, $request->pin);

        if (! $user->hasRole('AGENT') || $user->agentInfo?->status !== 'APPROVED') {
            throw new InsufficientRoleException;
        }

        $token = $user->createToken('agent-login', ['agent'])->plainTextToken;

        return new AuthResource($user, $token, ['agent'], 'Login successful');
    }

    /**
     * Authenticate a regular user using phone number and PIN.
     *
     * @throws InvalidCredentialsException
     * @throws AccountInactiveException
     * @throws InsufficientRoleException
     */
    public function loginUser(PinLoginRequest $request): AuthResource
    {
        $user = $this->pinAuthService->attempt($request->phone_number, $request->pin);

        if (! $user->hasRole('USER')) {
            throw new InsufficientRoleException;
        }

        $token = $user->createToken('user-login', ['user'])->plainTextToken;

        return new AuthResource($user, $token, ['user'], 'Login successful');
    }

    /**
     * Request an OTP for administrative login.
     *
     * @throws InvalidCredentialsException
     * @throws AccountInactiveException
     * @throws InsufficientRoleException
     */
    public function adminLoginRequestOtp(AdminLoginRequest $request): JsonResponse
    {
        $user = $this->adminAuthService->attempt($request->identifier, $request->password);

        return response()->json([
            'success' => true,
            'message' => 'OTP sent to your email. Please check your inbox.',
            'data' => [
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Complete admin login by verifying OTP and issuing an admin token.
     *
     * @throws InvalidOtpException
     * @throws AccountInactiveException
     * @throws InsufficientRoleException
     */
    public function adminVerifyOtp(VerifyOtpRequest $request): AuthResource
    {
        $user = $this->adminAuthService->verifyOtp($request->email, $request->otp_code);

        $token = $user->createToken('admin-login', ['admin'])->plainTextToken;

        return new AuthResource($user, $token, ['admin'], 'Login successful');
    }
}
