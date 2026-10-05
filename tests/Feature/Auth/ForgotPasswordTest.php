<?php

use App\Models\OtpToken;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('non-existent email returns generic 200 response without creating OTP', function () {
    $response = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'nonexistent@example.com',
    ]);

    $response->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'If that email address is in our system, we have sent a password reset OTP.',
        ]);

    expect(OtpToken::count())->toBe(0);
});

test('inactive admin returns generic 200 response without creating OTP', function () {
    $admin = User::factory()->create([
        'email' => 'inactive.admin@example.com',
        'is_active' => 'INACTIVE',
        'password' => Hash::make('password123'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    $response = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => $admin->email,
    ]);

    $response->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'If that email address is in our system, we have sent a password reset OTP.',
        ]);

    expect(OtpToken::where('user_id', $admin->id)->count())->toBe(0);
});

test('user without administrative role returns generic 200 response without creating OTP', function () {
    $user = User::factory()->create([
        'email' => 'customer@example.com',
        'pin' => Hash::make('12345'),
    ]);
    $user->roles()->attach(Role::where('name', 'USER')->first()->id, ['assigned_at' => now()]);

    $response = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => $user->email,
    ]);

    $response->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'If that email address is in our system, we have sent a password reset OTP.',
        ]);

    expect(OtpToken::where('user_id', $user->id)->count())->toBe(0);
});

test('active admin with valid email generates PASSWORD_RESET OTP and returns 200', function () {
    $admin = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => Hash::make('password123'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    $response = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => $admin->email,
    ]);

    $response->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'If that email address is in our system, we have sent a password reset OTP.',
        ]);

    $otp = OtpToken::where('user_id', $admin->id)
        ->where('purpose', 'PASSWORD_RESET')
        ->first();

    expect($otp)->not->toBeNull();
    expect($otp->otp_code)->toHaveLength(6);
    expect($otp->used_at)->toBeNull();
    expect($otp->expires_at->isFuture())->toBeTrue();
});

test('repeat forgot password request invalidates previous active OTP', function () {
    $admin = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => Hash::make('password123'),
    ]);
    $admin->roles()->attach(Role::where('name', 'MODERATOR')->first()->id, ['assigned_at' => now()]);

    $this->postJson('/api/v1/auth/forgot-password', [
        'email' => $admin->email,
    ])->assertOk();

    $firstOtp = OtpToken::where('user_id', $admin->id)
        ->where('purpose', 'PASSWORD_RESET')
        ->first();
    expect($firstOtp->used_at)->toBeNull();

    $this->postJson('/api/v1/auth/forgot-password', [
        'email' => $admin->email,
    ])->assertOk();

    $firstOtp->refresh();
    expect($firstOtp->used_at)->not->toBeNull();

    $secondOtp = OtpToken::where('user_id', $admin->id)
        ->where('purpose', 'PASSWORD_RESET')
        ->whereNull('used_at')
        ->first();
    expect($secondOtp)->not->toBeNull();
    expect($secondOtp->id)->not->toEqual($firstOtp->id);
});

test('email validation fails when email is missing or malformed', function () {
    $this->postJson('/api/v1/auth/forgot-password', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});
