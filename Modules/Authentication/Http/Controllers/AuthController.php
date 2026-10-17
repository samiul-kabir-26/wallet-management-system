<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Authentication\Exceptions\AccountHasNoPinException;
use Modules\Authentication\Exceptions\AccountInactiveException;
use Modules\Authentication\Exceptions\InsufficientRoleException;
use Modules\Authentication\Exceptions\InvalidCredentialsException;
use Modules\Authentication\Exceptions\InvalidOtpException;
use Modules\Authentication\Exceptions\InvalidPinException;
use Modules\Authentication\Exceptions\PinLockedException;
use Modules\Authentication\Exceptions\PinResetLinkInvalidException;
use Modules\Authentication\Exceptions\UnverifiedAccountException;
use Modules\Authentication\Exceptions\UserNotFoundException;
use Modules\Authentication\Http\Requests\AcceptInviteRequest;
use Modules\Authentication\Http\Requests\AdminLoginRequest;
use Modules\Authentication\Http\Requests\ChangePasswordRequest;
use Modules\Authentication\Http\Requests\ChangePinRequest;
use Modules\Authentication\Http\Requests\ForgotPasswordRequest;
use Modules\Authentication\Http\Requests\GrantAdminAccessRequest;
use Modules\Authentication\Http\Requests\InitiatePinResetRequest;
use Modules\Authentication\Http\Requests\PinLoginRequest;
use Modules\Authentication\Http\Requests\RegisterOtpRequest;
use Modules\Authentication\Http\Requests\RegisterRequest;
use Modules\Authentication\Http\Requests\ResetPasswordRequest;
use Modules\Authentication\Http\Requests\ResetPinRequest;
use Modules\Authentication\Http\Requests\SetPinRequest;
use Modules\Authentication\Http\Requests\VerifyOtpRequest;
use Modules\Authentication\Resources\AuthResource;
use Modules\Authentication\Services\AccountInviteService;
use Modules\Authentication\Services\AdminAuthService;
use Modules\Authentication\Services\LoginActivityRecorder;
use Modules\Authentication\Services\PinAuthService;
use Modules\Authentication\Services\PinChangeService;
use Modules\Authentication\Services\PinResetService;
use Modules\Authentication\Services\RegistrationService;
use Modules\Authentication\Services\SetPinService;
use Modules\Authentication\Services\TokenIssuer;

class AuthController extends Controller
{
    public function __construct(
        private PinAuthService $pinAuthService,
        private RegistrationService $registrationService,
        private AdminAuthService $adminAuthService,
        private SetPinService $setPinService,
        private AccountInviteService $accountInviteService,
        private PinResetService $pinResetService,
        private TokenIssuer $tokenIssuer,
        private LoginActivityRecorder $loginActivity,
        private PinChangeService $pinChangeService,
    ) {}

    /**
     * Request a password reset OTP.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->adminAuthService->forgotPassword($request->email);

        return response()->json([
            'success' => true,
            'message' => 'If that email address is in our system, we have sent a password reset OTP.',
        ]);
    }

    /**
     * Reset password using OTP and set new password.
     *
     * @throws InvalidOtpException
     * @throws AccountInactiveException
     * @throws InsufficientRoleException
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->adminAuthService->resetPassword(
            email: $request->email,
            otpCode: $request->otp_code,
            newPassword: $request->new_password,
        );

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. Please log in with your new password.',
        ]);
    }

    /**
     * Rotate the current access token, preserving its name, abilities and absolute expiry.
     */
    public function refreshToken(Request $request): AuthResource
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();

        if ($currentToken instanceof PersonalAccessToken) {
            $newToken = $this->tokenIssuer->rotate($user, $currentToken);
        } else {
            // Transient (non-persisted) tokens only occur in tests; issue a fresh token instead
            $newToken = $this->tokenIssuer->issue($user, 'token', $currentToken?->abilities ?? []);
        }

        $abilities = $newToken->accessToken->abilities ?? [];
        $user->load('roles');

        return new AuthResource($user, $newToken->plainTextToken, $abilities, 'Token refreshed');
    }

    /**
     * Revoke every access token the user holds, ending all of their sessions.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out from all sessions.',
        ]);
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $currentToken = $request->user()->currentAccessToken();

        if ($currentToken && method_exists($currentToken, 'delete')) {
            $currentToken->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

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
     * @throws PinResetLinkInvalidException
     */
    public function resetPin(ResetPinRequest $request, User $user): JsonResponse
    {
        $this->pinResetService->resetPin(
            user: $user,
            pin: $request->pin,
            fingerprint: (string) $request->query('fingerprint'),
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

        // Revoke every existing session, including the current restricted token
        $user->tokens()->delete();

        // Issue a full admin token
        $token = $this->tokenIssuer->issue($user, 'admin-login', ['admin'])->plainTextToken;
        $user->load('roles');

        return new AuthResource($user, $token, ['admin'], 'Password changed successfully.');
    }

    /**
     * Change the PIN of a signed-in agent or customer. Other sessions are signed out; this one stays.
     *
     * @throws AccountInactiveException
     * @throws InvalidPinException
     * @throws PinLockedException
     */
    public function changePin(ChangePinRequest $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();

        $this->pinChangeService->changePin(
            user: $user,
            currentPin: $request->string('current_pin')->toString(),
            newPin: $request->string('new_pin')->toString(),
            keepTokenId: $currentToken instanceof PersonalAccessToken ? $currentToken->id : null,
        );

        return response()->json([
            'success' => true,
            'message' => 'PIN changed. Other devices have been signed out.',
        ]);
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

        $token = $this->tokenIssuer->issue($user, 'accept-invite', ['password-change'])->plainTextToken;
        $user->load('roles');

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
            roleName: $request->string('role')->toString(),
        );

        $user->refresh();

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
     * Text a signup code to a phone number that has no account yet (step 1 of public registration).
     */
    public function requestRegisterOtp(RegisterOtpRequest $request): JsonResponse
    {
        $this->registrationService->requestOtp($request->string('phone_number')->toString());

        return response()->json([
            'success' => true,
            'message' => 'We sent a 6-digit code to your phone number.',
            'data' => [
                'expires_in_minutes' => (int) config('auth_settings.otp.expiry_minutes', 5),
            ],
        ]);
    }

    /**
     * Register a new user or agent (step 2: requires the code texted to the phone number).
     *
     * @throws ModelNotFoundException
     * @throws InvalidOtpException
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->registrationService->register($request->validated());

        $ability = strtolower($request->role);
        $token = $this->tokenIssuer->issue($user, "{$ability}-registration", [$ability])->plainTextToken;

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
        $user = $this->loginActivity->track($request, 'agent', (string) $request->phone_number, function () use ($request): User {
            $user = $this->pinAuthService->attempt($request->phone_number, $request->pin);
            $this->assertCanEnterAgentPanel($user);

            return $user;
        });

        $token = $this->tokenIssuer->issue($user, 'agent-login', ['agent'])->plainTextToken;
        $user->load('roles');

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
        $user = $this->loginActivity->track($request, 'user', (string) $request->phone_number, function () use ($request): User {
            $user = $this->pinAuthService->attempt($request->phone_number, $request->pin);
            $this->assertCanEnterUserPanel($user);

            return $user;
        });

        $token = $this->tokenIssuer->issue($user, 'user-login', ['user'])->plainTextToken;
        $user->load('roles');

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
        // Password step: only failures are recorded; success is recorded once the OTP is verified
        $user = $this->loginActivity->track(
            $request,
            'admin',
            (string) $request->identifier,
            fn (): User => $this->adminAuthService->attempt($request->identifier, $request->password),
            recordSuccess: false,
        );

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
        $user = $this->loginActivity->track(
            $request,
            'admin',
            (string) $request->email,
            fn (): User => $this->adminAuthService->verifyOtp($request->email, $request->otp_code),
        );

        $token = $this->tokenIssuer->issue($user, 'admin-login', ['admin'])->plainTextToken;
        $user->load('roles');

        return new AuthResource($user, $token, ['admin'], 'Login successful');
    }

    /**
     * Get the authenticated user profile with roles and effective permissions.
     */
    public function me(Request $request): AuthResource
    {
        $user = $request->user()->load('roles');
        $abilities = $user->currentAccessToken()?->abilities ?? [];

        return new AuthResource(
            resource: $user,
            token: null,
            abilities: $abilities,
            message: 'User profile retrieved successfully',
        );
    }

    /**
     * Reject PIN logins on the agent route unless the account is an approved agent.
     *
     * Runs after the PIN is verified, so a specific message reveals nothing to an unauthenticated caller.
     *
     * @throws InsufficientRoleException
     */
    private function assertCanEnterAgentPanel(User $user): void
    {
        if (! $user->hasRole('AGENT')) {
            throw new InsufficientRoleException($user->hasRole('USER')
                ? 'This number is registered as a customer account. Please sign in through the Customer portal.'
                : 'You do not have permission to access this portal.');
        }

        if ($user->agentInfo?->status !== 'APPROVED') {
            throw new InsufficientRoleException($user->agentInfo?->status === 'SUSPENDED'
                ? 'Your agent account is suspended. Please contact support.'
                : 'Your agent account is awaiting approval.');
        }
    }

    /**
     * Reject PIN logins on the user route unless the account holds the USER role.
     *
     * One number holds one wallet type (as with bKash/Nagad); a personal wallet needs a separate number.
     *
     * @throws InsufficientRoleException
     */
    private function assertCanEnterUserPanel(User $user): void
    {
        if (! $user->hasRole('USER')) {
            throw new InsufficientRoleException($user->hasRole('AGENT')
                ? 'This number is registered as an agent account. Please sign in through the Agent portal; a personal wallet needs a separate number.'
                : 'You do not have permission to access this portal.');
        }
    }
}
