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

test('non-existent email returns generic 401 error', function () {
    $response = $this->postJson('/api/v1/auth/admin/verify-otp', [
        'email' => 'unknown@example.com',
        'otp_code' => '123456',
    ]);

    $response->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Invalid or expired OTP.',
            'errors' => [],
        ]);
});

test('inactive admin with no active OTP receives generic 401 error without leaking status', function () {
    $admin = User::factory()->create([
        'is_active' => 'INACTIVE',
        'password' => Hash::make('password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    $response = $this->postJson('/api/v1/auth/admin/verify-otp', [
        'email' => $admin->email,
        'otp_code' => '123456',
    ]);

    // Must be generic OTP failure, NOT "Your account is inactive."
    $response->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Invalid or expired OTP.',
            'errors' => [],
        ]);
});

test('admin deactivated after requesting OTP is rejected with 403 only after providing valid OTP', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    // Active OTP exists
    OtpToken::create([
        'user_id' => $admin->id,
        'otp_code' => '654321',
        'purpose' => 'LOGIN',
        'expires_at' => now()->addMinutes(5),
    ]);

    // Admin is deactivated in the interim
    $admin->forceFill(['is_active' => 'INACTIVE'])->save();

    $response = $this->postJson('/api/v1/auth/admin/verify-otp', [
        'email' => $admin->email,
        'otp_code' => '654321',
    ]);

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'Your account is inactive.',
            'errors' => [],
        ]);
});

test('admin demoted after requesting OTP is rejected with 403 only after providing valid OTP', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    OtpToken::create([
        'user_id' => $admin->id,
        'otp_code' => '654321',
        'purpose' => 'LOGIN',
        'expires_at' => now()->addMinutes(5),
    ]);

    // Role stripped in the interim
    $admin->roles()->detach();

    $response = $this->postJson('/api/v1/auth/admin/verify-otp', [
        'email' => $admin->email,
        'otp_code' => '654321',
    ]);

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'You do not have permission to access this portal.',
            'errors' => [],
        ]);
});

test('wrong OTP code returns 401 and increments attempt count', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    $otp = OtpToken::create([
        'user_id' => $admin->id,
        'otp_code' => '112233',
        'purpose' => 'LOGIN',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/admin/verify-otp', [
        'email' => $admin->email,
        'otp_code' => '999999',
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
        'password' => Hash::make('password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    $otp = OtpToken::forceCreate([
        'user_id' => $admin->id,
        'otp_code' => '112233',
        'purpose' => 'LOGIN',
        'attempt_count' => 4,
        'expires_at' => now()->addMinutes(5),
    ]);

    // 5th attempt (fails)
    $response5 = $this->postJson('/api/v1/auth/admin/verify-otp', [
        'email' => $admin->email,
        'otp_code' => '000000',
    ]);

    $response5->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Too many failed attempts. Please request a new OTP.',
            'errors' => [],
        ]);

    // 6th attempt with CORRECT code -> MUST STILL BE REJECTED
    $response6 = $this->postJson('/api/v1/auth/admin/verify-otp', [
        'email' => $admin->email,
        'otp_code' => '112233',
    ]);

    $response6->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Too many failed attempts. Please request a new OTP.',
            'errors' => [],
        ]);
});

test('expired OTP returns 401 expired message', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    OtpToken::create([
        'user_id' => $admin->id,
        'otp_code' => '112233',
        'purpose' => 'LOGIN',
        'expires_at' => Carbon::now()->subMinute(), // Expired 1 min ago
    ]);

    $response = $this->postJson('/api/v1/auth/admin/verify-otp', [
        'email' => $admin->email,
        'otp_code' => '112233',
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
        'password' => Hash::make('password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    OtpToken::create([
        'user_id' => $admin->id,
        'otp_code' => '112233',
        'purpose' => 'LOGIN',
        'used_at' => Carbon::now()->subMinute(), // Already used
        'expires_at' => Carbon::now()->addMinutes(4),
    ]);

    $response = $this->postJson('/api/v1/auth/admin/verify-otp', [
        'email' => $admin->email,
        'otp_code' => '112233',
    ]);

    $response->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Invalid or expired OTP.',
            'errors' => [],
        ]);
});

test('valid OTP marks token used and returns 200 with scoped admin token', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    $otp = OtpToken::create([
        'user_id' => $admin->id,
        'otp_code' => '112233',
        'purpose' => 'LOGIN',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/admin/verify-otp', [
        'email' => $admin->email,
        'otp_code' => '112233',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Login successful')
        ->assertJsonPath('data.user.id', $admin->id)
        ->assertJsonPath('data.user.email', $admin->email)
        ->assertJsonPath('data.user.roles', ['ADMIN'])
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.abilities', ['admin']);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();

    $otp->refresh();
    expect($otp->used_at)->not->toBeNull();
});
