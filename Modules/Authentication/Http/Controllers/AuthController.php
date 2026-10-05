<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Modules\Authentication\Exceptions\AccountInactiveException;
use Modules\Authentication\Exceptions\InsufficientRoleException;
use Modules\Authentication\Exceptions\InvalidCredentialsException;
use Modules\Authentication\Http\Requests\AdminLoginRequest;
use Modules\Authentication\Http\Requests\PinLoginRequest;
use Modules\Authentication\Http\Requests\RegisterRequest;
use Modules\Authentication\Http\Requests\VerifyOtpRequest;
use Modules\Authentication\Resources\AuthResource;
use Modules\Authentication\Services\AdminAuthService;
use Modules\Authentication\Services\PinAuthService;
use Modules\Authentication\Services\RegistrationService;

class AuthController extends Controller
{
    public function __construct(
        private PinAuthService $pinAuthService,
        private RegistrationService $registrationService,
        private AdminAuthService $adminAuthService,
    ) {}

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
