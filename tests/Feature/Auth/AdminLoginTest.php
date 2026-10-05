<?php

use App\Models\OtpToken;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('non-existent identifier returns 401 with generic admin error message', function () {
    $response = $this->postJson('/api/v1/auth/admin/login', [
        'identifier' => 'unknown@example.com',
        'password' => 'wrong_password',
    ]);

    $response->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Invalid email/phone number or password.',
            'errors' => [],
        ]);
});

test('correct identifier but wrong password returns identical 401 response body for anti-enumeration', function () {
    // 1. Capture non-existent response
    $nonExistentResponse = $this->postJson('/api/v1/auth/admin/login', [
        'identifier' => 'unknown@example.com',
        'password' => 'wrong_password',
    ]);

    // 2. Create existing admin user
    $admin = User::factory()->create([
        'password' => Hash::make('secret_admin_pass'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    // 3. Submit wrong password
    $wrongPasswordResponse = $this->postJson('/api/v1/auth/admin/login', [
        'identifier' => $admin->email,
        'password' => 'wrong_password',
    ]);

    $wrongPasswordResponse->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Invalid email/phone number or password.',
            'errors' => [],
        ]);

    // Assert exact parity between the two branches
    expect($wrongPasswordResponse->json())->toEqual($nonExistentResponse->json());
});

test('inactive admin returns 403 account inactive response', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('secret_admin_pass'),
        'is_active' => 'INACTIVE',
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    $response = $this->postJson('/api/v1/auth/admin/login', [
        'identifier' => $admin->email,
        'password' => 'secret_admin_pass',
    ]);

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'Your account is inactive.',
            'errors' => [],
        ]);
});

test('user without admin role is rejected on admin login route with 403', function () {
    // User has USER role only
    $user = User::factory()->create([
        'password' => Hash::make('user_password'),
    ]);
    $user->roles()->attach(Role::where('name', 'USER')->first()->id, ['assigned_at' => now()]);

    $response = $this->postJson('/api/v1/auth/admin/login', [
        'identifier' => $user->email,
        'password' => 'user_password',
    ]);

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'You do not have permission to access this portal.',
            'errors' => [],
        ]);
});

test('valid admin credentials with email dispatches OTP and returns 200', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('secret_admin_pass'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    $response = $this->postJson('/api/v1/auth/admin/login', [
        'identifier' => $admin->email,
        'password' => 'secret_admin_pass',
    ]);

    $response->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'OTP sent to your email. Please check your inbox.',
            'data' => [
                'email' => $admin->email,
            ],
        ]);

    $otp = OtpToken::where('user_id', $admin->id)->where('purpose', 'LOGIN')->first();
    expect($otp)->not->toBeNull();
    expect($otp->otp_code)->toHaveLength(6);
    expect($otp->used_at)->toBeNull();
    expect($otp->expires_at->isFuture())->toBeTrue();
});

test('valid admin credentials with phone number resolves admin and returns 200', function () {
    $admin = User::factory()->create([
        'phone_number' => '01711223344',
        'password' => Hash::make('secret_admin_pass'),
    ]);
    $admin->roles()->attach(Role::where('name', 'SUPER_ADMIN')->first()->id, ['assigned_at' => now()]);

    $response = $this->postJson('/api/v1/auth/admin/login', [
        'identifier' => '01711223344',
        'password' => 'secret_admin_pass',
    ]);

    $response->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'OTP sent to your email. Please check your inbox.',
            'data' => [
                'email' => $admin->email,
            ],
        ]);
});

test('repeat login request invalidates previously active OTP', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('secret_admin_pass'),
    ]);
    $admin->roles()->attach(Role::where('name', 'MODERATOR')->first()->id, ['assigned_at' => now()]);

    // First request
    $this->postJson('/api/v1/auth/admin/login', [
        'identifier' => $admin->email,
        'password' => 'secret_admin_pass',
    ])->assertOk();

    $firstOtp = OtpToken::where('user_id', $admin->id)->where('purpose', 'LOGIN')->first();
    expect($firstOtp->used_at)->toBeNull();

    // Second request
    $this->postJson('/api/v1/auth/admin/login', [
        'identifier' => $admin->email,
        'password' => 'secret_admin_pass',
    ])->assertOk();

    // First OTP is now marked as used/invalidated
    $firstOtp->refresh();
    expect($firstOtp->used_at)->not->toBeNull();

    // A fresh active OTP exists
    $activeOtp = OtpToken::where('user_id', $admin->id)
        ->where('purpose', 'LOGIN')
        ->whereNull('used_at')
        ->first();
    expect($activeOtp)->not->toBeNull();
    expect($activeOtp->id)->not->toEqual($firstOtp->id);
});
