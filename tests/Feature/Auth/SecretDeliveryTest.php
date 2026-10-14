<?php

use App\Models\OtpToken;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Notifications\OtpCodeNotification;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function createAdminForSecretDelivery(array $attributes = []): User
{
    $admin = User::factory()->create(array_merge(['password' => Hash::make('secret_admin_pass')], $attributes));
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    return $admin;
}

test('admin login OTP is emailed to the admin and never written to the log', function () {
    Notification::fake();
    Log::spy();

    $admin = createAdminForSecretDelivery();

    $this->postJson('/api/v1/auth/admin/login', [
        'identifier' => $admin->email,
        'password' => 'secret_admin_pass',
    ])->assertOk();

    $otp = OtpToken::where('user_id', $admin->id)->where('purpose', 'LOGIN')->firstOrFail();

    Notification::assertSentTo($admin, OtpCodeNotification::class, fn (OtpCodeNotification $notification) => $notification->code === $otp->otp_code
        && $notification->purpose === 'LOGIN');

    Log::shouldNotHaveReceived('info');
});

test('password reset OTP is emailed and never written to the log', function () {
    Notification::fake();
    Log::spy();

    $admin = createAdminForSecretDelivery();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $admin->email])->assertOk();

    Notification::assertSentTo($admin, OtpCodeNotification::class, fn (OtpCodeNotification $notification) => $notification->purpose === 'PASSWORD_RESET');

    Log::shouldNotHaveReceived('info');
});

test('set PIN OTP is emailed and never written to the log', function () {
    Notification::fake();
    Log::spy();

    $admin = createAdminForSecretDelivery(['pin' => null]);
    Sanctum::actingAs($admin, ['admin']);

    $this->postJson('/api/v1/auth/set-pin/request-otp')->assertOk();

    Notification::assertSentTo($admin, OtpCodeNotification::class, fn (OtpCodeNotification $notification) => $notification->purpose === 'SET_PIN');

    Log::shouldNotHaveReceived('info');
});
