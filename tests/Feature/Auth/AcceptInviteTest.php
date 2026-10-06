<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('accept-invite fails with 403 on invalid or tampered signature', function () {
    $user = User::factory()->create([
        'password' => Hash::make('temp-secret-123'),
        'password_changed_at' => null,
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'auth.accept-invite',
        now()->addHour(),
        ['user' => $user->id],
    );

    // Tamper the signature parameter
    $tamperedUrl = $signedUrl.'tampered';

    $this->postJson($tamperedUrl, [
        'password' => 'temp-secret-123',
    ])->assertStatus(403);
});

test('accept-invite fails with 403 when signature is expired', function () {
    $user = User::factory()->create([
        'password' => Hash::make('temp-secret-123'),
        'password_changed_at' => null,
    ]);

    $expiredUrl = URL::temporarySignedRoute(
        'auth.accept-invite',
        now()->subMinute(),
        ['user' => $user->id],
    );

    $this->postJson($expiredUrl, [
        'password' => 'temp-secret-123',
    ])->assertStatus(403);
});

test('accept-invite fails with 401 when wrong temporary password is provided', function () {
    $user = User::factory()->create([
        'password' => Hash::make('correct-temp-password'),
        'password_changed_at' => null,
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'auth.accept-invite',
        now()->addHour(),
        ['user' => $user->id],
    );

    $response = $this->postJson($signedUrl, [
        'password' => 'wrong-temp-password',
    ]);

    $response->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Invalid or already-used invite credentials.',
            'errors' => [],
        ]);
});

test('already-consumed invite fails with exact same 401 message as wrong credentials', function () {
    // User already set their password
    $user = User::factory()->create([
        'password' => Hash::make('correct-temp-password'),
        'password_changed_at' => now(),
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'auth.accept-invite',
        now()->addHour(),
        ['user' => $user->id],
    );

    $response = $this->postJson($signedUrl, [
        'password' => 'correct-temp-password',
    ]);

    $response->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Invalid or already-used invite credentials.',
            'errors' => [],
        ]);
});

test('accept-invite fails with 403 when account is inactive', function () {
    $user = User::factory()->create([
        'password' => Hash::make('correct-temp-password'),
        'password_changed_at' => null,
        'is_active' => 'INACTIVE',
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'auth.accept-invite',
        now()->addHour(),
        ['user' => $user->id],
    );

    $response = $this->postJson($signedUrl, [
        'password' => 'correct-temp-password',
    ]);

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'Your account is inactive.',
            'errors' => [],
        ]);
});

test('accept-invite returns 200 and issues token with password-change ability only', function () {
    $user = User::factory()->create([
        'password' => Hash::make('correct-temp-password'),
        'password_changed_at' => null,
    ]);
    $user->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    $signedUrl = URL::temporarySignedRoute(
        'auth.accept-invite',
        now()->addHour(),
        ['user' => $user->id],
    );

    $response = $this->postJson($signedUrl, [
        'password' => 'correct-temp-password',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'user',
                'token',
                'abilities',
            ],
        ]);

    $abilities = $response->json('data.abilities');
    expect($abilities)->toEqual(['password-change']);
    expect($abilities)->not->toContain('admin');

    // Token stored in DB also has only password-change
    $token = $user->tokens()->first();
    expect($token)->not->toBeNull();
    expect($token->abilities)->toEqual(['password-change']);

    // password_changed_at must remain NULL until change-password is executed
    expect($user->fresh()->password_changed_at)->toBeNull();
});
