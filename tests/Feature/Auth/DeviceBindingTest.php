<?php

use App\Models\LoginActivity;
use App\Models\Role;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Modules\Authentication\Notifications\DeviceVerificationCodeNotification;
use Modules\Authentication\Services\PinResetService;

const DEVICE_A = 'device-aaaaaaaaaaaaaaaaaaaaaaaa';
const DEVICE_B = 'device-bbbbbbbbbbbbbbbbbbbbbbbb';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    config(['auth_settings.device_binding.enabled' => true]);
    Notification::fake();
});

function bindingCustomer(): User
{
    $user = User::factory()->create(['pin' => Hash::make('12345'), 'phone_number' => '01712345678']);
    $user->roles()->attach(Role::where('name', 'USER')->firstOrFail()->id, ['assigned_at' => now()]);
    Wallet::factory()->for($user)->create();

    return $user;
}

function pinLogin(object $test, ?string $deviceId, string $pin = '12345')
{
    return $test->postJson('/api/v1/auth/user/login', array_filter([
        'phone_number' => '01712345678',
        'pin' => $pin,
        'device_id' => $deviceId,
    ]));
}

function sentDeviceCode(User $user): string
{
    return Notification::sent($user, DeviceVerificationCodeNotification::class)->last()->code;
}

test('a correct PIN from a new device gets an SMS challenge instead of a token', function () {
    $user = bindingCustomer();

    pinLogin($this, DEVICE_A)
        ->assertStatus(202)
        ->assertJsonPath('data.requires_device_verification', true)
        ->assertJsonPath('data.phone_number', '017*****678')
        ->assertJsonMissingPath('data.token');

    Notification::assertSentTo($user, DeviceVerificationCodeNotification::class);
    expect($user->tokens()->count())->toBe(0)
        ->and(LoginActivity::where('status', 'SUCCESS')->exists())->toBeFalse();
});

test('the SMS code completes the login and trusts the device for next time', function () {
    $user = bindingCustomer();
    $challenge = pinLogin($this, DEVICE_A)->json('data.challenge_id');

    $this->postJson('/api/v1/auth/verify-device', ['challenge_id' => $challenge, 'otp_code' => sentDeviceCode($user)])
        ->assertOk()
        ->assertJsonPath('data.abilities', ['user'])
        ->assertJsonStructure(['data' => ['token']]);

    expect(TrustedDevice::where('user_id', $user->id)->count())->toBe(1)
        ->and(TrustedDevice::first()->device_hash)->not->toBe(DEVICE_A)
        ->and(LoginActivity::where('status', 'SUCCESS')->count())->toBe(1);

    pinLogin($this, DEVICE_A)->assertOk()->assertJsonStructure(['data' => ['token']]);
    pinLogin($this, DEVICE_B)->assertStatus(202);
});

test('a wrong code or a used challenge does not sign in', function () {
    $user = bindingCustomer();
    $challenge = pinLogin($this, DEVICE_A)->json('data.challenge_id');
    $code = sentDeviceCode($user);
    $wrong = $code === '000000' ? '111111' : '000000';

    $this->postJson('/api/v1/auth/verify-device', ['challenge_id' => $challenge, 'otp_code' => $wrong])->assertUnauthorized();
    $this->postJson('/api/v1/auth/verify-device', ['challenge_id' => $challenge, 'otp_code' => $code])->assertOk();
    $this->postJson('/api/v1/auth/verify-device', ['challenge_id' => $challenge, 'otp_code' => $code])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'This sign-in request has expired. Please sign in again.');
});

test('a wrong PIN never reaches the SMS step', function () {
    bindingCustomer();

    pinLogin($this, DEVICE_A, '99999')->assertUnauthorized();

    Notification::assertNothingSent();
});

test('logins without a device ID are always challenged', function () {
    $user = bindingCustomer();
    $challenge = pinLogin($this, null)->json('data.challenge_id');
    $this->postJson('/api/v1/auth/verify-device', ['challenge_id' => $challenge, 'otp_code' => sentDeviceCode($user)])->assertOk();

    pinLogin($this, null)->assertStatus(202);
});

test('the device used to register is trusted straight away', function () {
    $phone = '01798765432';
    $this->postJson('/api/v1/auth/register', [
        'name' => 'New Customer',
        'phone_number' => $phone,
        'otp_code' => $this->registrationOtpFor($phone),
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => 'USER',
        'device_id' => DEVICE_A,
    ])->assertCreated();

    $this->postJson('/api/v1/auth/user/login', ['phone_number' => $phone, 'pin' => '12345', 'device_id' => DEVICE_A])->assertOk();
});

test('a PIN reset forgets every trusted device', function () {
    $user = bindingCustomer();
    TrustedDevice::create(['user_id' => $user->id, 'device_hash' => TrustedDevice::hash(DEVICE_A)]);

    app(PinResetService::class)->resetPin($user, '54321', PinResetService::pinFingerprint($user));

    expect(TrustedDevice::where('user_id', $user->id)->exists())->toBeFalse();
});

test('the account can list and forget its trusted devices', function () {
    $user = bindingCustomer();
    $challenge = pinLogin($this, DEVICE_A)->json('data.challenge_id');
    $token = $this->postJson('/api/v1/auth/verify-device', ['challenge_id' => $challenge, 'otp_code' => sentDeviceCode($user)])->json('data.token');
    $headers = ['Authorization' => "Bearer {$token}"];

    $deviceId = $this->getJson('/api/v1/auth/devices', $headers)->assertOk()->assertJsonCount(1, 'data.devices')->json('data.devices.0.id');
    $this->deleteJson("/api/v1/auth/devices/{$deviceId}", [], $headers)->assertOk();

    expect(TrustedDevice::count())->toBe(0);
});
