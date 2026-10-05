<?php

use App\Models\OtpToken;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('unauthenticated caller cannot access set-pin routes', function () {
    $this->postJson('/api/v1/auth/set-pin/request-otp')
        ->assertStatus(401);

    $this->postJson('/api/v1/auth/set-pin', [
        'otp_code' => '123456',
        'pin' => '12345',
        'pin_confirmation' => '12345',
    ])->assertStatus(401);
});

test('caller with user-ability token is rejected with 403 on set-pin routes', function () {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'USER')->first()->id, ['assigned_at' => now()]);

    // Token has only 'user' ability
    Sanctum::actingAs($user, ['user']);

    $this->postJson('/api/v1/auth/set-pin/request-otp')
        ->assertStatus(403);

    $this->postJson('/api/v1/auth/set-pin', [
        'otp_code' => '123456',
        'pin' => '12345',
        'pin_confirmation' => '12345',
    ])->assertStatus(403);
});

test('admin with admin-ability token can request set-pin OTP', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/auth/set-pin/request-otp');

    $response->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'OTP sent to your email. Please check your inbox.',
        ]);

    $otp = OtpToken::where('user_id', $admin->id)->where('purpose', 'SET_PIN')->first();
    expect($otp)->not->toBeNull();
    expect($otp->otp_code)->toHaveLength(6);
    expect($otp->used_at)->toBeNull();
});

test('set-pin fails validation when pin confirmation does not match', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/auth/set-pin', [
        'otp_code' => '123456',
        'pin' => '12345',
        'pin_confirmation' => '54321',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['pin']);
});

test('set-pin fails with 401 when wrong OTP code is provided', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('password'),
        'pin' => null,
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    OtpToken::create([
        'user_id' => $admin->id,
        'otp_code' => '112233',
        'purpose' => 'SET_PIN',
        'expires_at' => now()->addMinutes(5),
    ]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/auth/set-pin', [
        'otp_code' => '999999',
        'pin' => '98765',
        'pin_confirmation' => '98765',
    ]);

    $response->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Invalid OTP code.',
            'errors' => [],
        ]);

    $admin->refresh();
    expect($admin->pin)->toBeNull();
});

test('set-pin successfully sets pin, marks OTP used, and does not issue a new token', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    $otp = OtpToken::create([
        'user_id' => $admin->id,
        'otp_code' => '112233',
        'purpose' => 'SET_PIN',
        'expires_at' => now()->addMinutes(5),
    ]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/auth/set-pin', [
        'otp_code' => '112233',
        'pin' => '54321',
        'pin_confirmation' => '54321',
    ]);

    $response->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'PIN set successfully.',
        ]);

    // Verify PIN was hashed and stored
    $admin->refresh();
    expect(Hash::check('54321', (string) $admin->pin))->toBeTrue();

    // Verify OTP marked as used
    $otp->refresh();
    expect($otp->used_at)->not->toBeNull();
});

test('set-pin fails with 401 when OTP is expired', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('password'),
        'pin' => null,
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    OtpToken::create([
        'user_id' => $admin->id,
        'otp_code' => '112233',
        'purpose' => 'SET_PIN',
        'expires_at' => now()->subMinute(),
    ]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/auth/set-pin', [
        'otp_code' => '112233',
        'pin' => '98765',
        'pin_confirmation' => '98765',
    ]);

    $response->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'OTP has expired. Please request a new one.',
            'errors' => [],
        ]);

    $admin->refresh();
    expect($admin->pin)->toBeNull();
});
