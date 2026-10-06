<?php

use App\Models\OtpToken;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('validation fails when required fields are missing or invalid', function () {
    $this->postJson('/api/v1/auth/reset-password', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'otp_code', 'new_password']);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'admin@example.com',
        'otp_code' => '123', // not 6 digits
        'new_password' => 'short',
        'new_password_confirmation' => 'short',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['otp_code', 'new_password']);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'admin@example.com',
        'otp_code' => '123456',
        'new_password' => 'ValidPassword123!',
        'new_password_confirmation' => 'MismatchPassword123!',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['new_password']);
});

test('non-existent email returns generic 401 OTP error', function () {
    $response = $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'unknown@example.com',
        'otp_code' => '123456',
        'new_password' => 'NewPassword123!',
        'new_password_confirmation' => 'NewPassword123!',
    ]);

    $response->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Invalid or expired OTP.',
            'errors' => [],
        ]);
});

test('wrong OTP code returns 401 and increments attempt count', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('old_password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    $otp = OtpToken::create([
        'user_id' => $admin->id,
        'otp_code' => '112233',
        'purpose' => 'PASSWORD_RESET',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/reset-password', [
        'email' => $admin->email,
        'otp_code' => '999999',
        'new_password' => 'NewPassword123!',
        'new_password_confirmation' => 'NewPassword123!',
    ]);

    $response->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Invalid OTP code.',
            'errors' => [],
        ]);

    $otp->refresh();
    expect($otp->attempt_count)->toBe(1);
    expect($otp->used_at)->toBeNull();
});

test('exceeding 5 failed attempts locks out the OTP and rejects subsequent correct code', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('old_password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    OtpToken::forceCreate([
        'user_id' => $admin->id,
        'otp_code' => '112233',
        'purpose' => 'PASSWORD_RESET',
        'attempt_count' => 4,
        'expires_at' => now()->addMinutes(5),
    ]);

    // 5th attempt fails
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $admin->email,
        'otp_code' => '000000',
        'new_password' => 'NewPassword123!',
        'new_password_confirmation' => 'NewPassword123!',
    ])
        ->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Too many failed attempts. Please request a new OTP.',
            'errors' => [],
        ]);

    // 6th attempt with correct code remains locked out
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $admin->email,
        'otp_code' => '112233',
        'new_password' => 'NewPassword123!',
        'new_password_confirmation' => 'NewPassword123!',
    ])
        ->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Too many failed attempts. Please request a new OTP.',
            'errors' => [],
        ]);
});

test('expired OTP returns 401 expired message', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('old_password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    OtpToken::create([
        'user_id' => $admin->id,
        'otp_code' => '112233',
        'purpose' => 'PASSWORD_RESET',
        'expires_at' => Carbon::now()->subMinute(),
    ]);

    $response = $this->postJson('/api/v1/auth/reset-password', [
        'email' => $admin->email,
        'otp_code' => '112233',
        'new_password' => 'NewPassword123!',
        'new_password_confirmation' => 'NewPassword123!',
    ]);

    $response->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'OTP has expired. Please request a new one.',
            'errors' => [],
        ]);
});

test('re-submitting an already used OTP returns generic 401 error', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('old_password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    OtpToken::create([
        'user_id' => $admin->id,
        'otp_code' => '112233',
        'purpose' => 'PASSWORD_RESET',
        'used_at' => Carbon::now()->subMinute(),
        'expires_at' => Carbon::now()->addMinutes(4),
    ]);

    $response = $this->postJson('/api/v1/auth/reset-password', [
        'email' => $admin->email,
        'otp_code' => '112233',
        'new_password' => 'NewPassword123!',
        'new_password_confirmation' => 'NewPassword123!',
    ]);

    $response->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Invalid or expired OTP.',
            'errors' => [],
        ]);
});

test('admin deactivated after requesting OTP is rejected with 403 only after providing valid OTP', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('old_password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    OtpToken::create([
        'user_id' => $admin->id,
        'otp_code' => '654321',
        'purpose' => 'PASSWORD_RESET',
        'expires_at' => now()->addMinutes(5),
    ]);

    $admin->forceFill(['is_active' => 'INACTIVE'])->save();

    $response = $this->postJson('/api/v1/auth/reset-password', [
        'email' => $admin->email,
        'otp_code' => '654321',
        'new_password' => 'NewPassword123!',
        'new_password_confirmation' => 'NewPassword123!',
    ]);

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'Your account is inactive.',
            'errors' => [],
        ]);
});

test('valid OTP resets password, sets password_changed_at, marks OTP used, and revokes tokens', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('old_password'),
        'password_changed_at' => null,
    ]);
    $admin->roles()->attach(Role::where('name', 'SUPER_ADMIN')->first()->id, ['assigned_at' => now()]);

    // Issue an active token prior to reset
    $token = $admin->createToken('admin-session', ['admin']);
    expect($admin->tokens()->count())->toBe(1);

    $otp = OtpToken::create([
        'user_id' => $admin->id,
        'otp_code' => '112233',
        'purpose' => 'PASSWORD_RESET',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/reset-password', [
        'email' => $admin->email,
        'otp_code' => '112233',
        'new_password' => 'BrandNewPassword123!',
        'new_password_confirmation' => 'BrandNewPassword123!',
    ]);

    $response->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'Password reset successfully. Please log in with your new password.',
        ]);

    $admin->refresh();
    expect(Hash::check('BrandNewPassword123!', $admin->password))->toBeTrue();
    expect($admin->password_changed_at)->not->toBeNull();
    expect($admin->tokens()->count())->toBe(0);

    $otp->refresh();
    expect($otp->used_at)->not->toBeNull();
});

test('can successfully request login OTP with new password after reset', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('old_password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    OtpToken::create([
        'user_id' => $admin->id,
        'otp_code' => '112233',
        'purpose' => 'PASSWORD_RESET',
        'expires_at' => now()->addMinutes(5),
    ]);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $admin->email,
        'otp_code' => '112233',
        'new_password' => 'BrandNewPassword123!',
        'new_password_confirmation' => 'BrandNewPassword123!',
    ])->assertOk();

    // Verify admin can request login OTP with new password
    $this->postJson('/api/v1/auth/admin/login', [
        'identifier' => $admin->email,
        'password' => 'BrandNewPassword123!',
    ])
        ->assertOk()
        ->assertJsonPath('success', true);
});
