<?php

namespace Modules\Authentication\Services;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Modules\Authentication\Exceptions\InvalidOtpException;
use Modules\Authentication\Notifications\PhoneVerificationCodeNotification;
use Modules\Transactions\Services\SignupBonusService;

class RegistrationService
{
    public const string OTP_PURPOSE = 'REGISTRATION';

    public function __construct(
        private OtpService $otpService,
        private SignupBonusService $signupBonusService,
    ) {}

    /**
     * Text a signup code to a number that has no account yet.
     */
    public function requestOtp(string $phoneNumber): void
    {
        $otp = $this->otpService->createForTarget(self::OTP_PURPOSE, $phoneNumber);

        Notification::route('sms', $phoneNumber)->notify(new PhoneVerificationCodeNotification(
            $otp->otp_code,
            (int) config('auth_settings.otp.expiry_minutes', OtpService::DEFAULT_EXPIRY_MINUTES),
        ));
    }

    /**
     * Register a new user or agent with associated wallet, cap, and role.
     *
     * The signup code proves the caller holds the phone number, so the account starts verified.
     *
     * @param  array{name: string, phone_number: string, pin: string, role: string, otp_code: string}  $data
     *
     * @throws InvalidOtpException
     */
    public function register(array $data): User
    {
        // Checked before the write transaction so failed attempts are counted even though registration aborts
        $this->otpService->verifyForTarget(self::OTP_PURPOSE, $data['phone_number'], $data['otp_code']);

        return DB::transaction(function () use ($data): User {
            // 1. Create the user
            $user = User::create([
                'name' => $data['name'],
                'phone_number' => $data['phone_number'],
                'pin' => Hash::make($data['pin']),
            ]);
            $user->forceFill(['is_verified' => true])->save();

            // 2. Open the wallet (opens at 0.00 BDT) and credit the signup bonus, if enabled
            $kind = $data['role'] === 'AGENT' ? Wallet::KIND_AGENT : Wallet::KIND_USER;
            $wallet = $user->wallets()->create([
                'kind' => $kind,
            ]);
            $this->signupBonusService->credit($wallet);

            // 3. Create transaction cap limits (schema defaults: daily 10,000.00, monthly 50,000.00)
            $user->cap()->create();

            // 4. If AGENT, create agent_info record (schema default: status PENDING)
            if ($data['role'] === 'AGENT') {
                $user->agentInfo()->create();
            }

            // 5. Assign the requested role
            $role = Role::where('name', $data['role'])->firstOrFail();
            $user->roles()->attach($role->id, [
                'assigned_at' => now(),
            ]);

            // 6. Eager load roles so the resulting model is fully hydrated for resources
            return $user->refresh()->load('roles');
        });
    }
}
