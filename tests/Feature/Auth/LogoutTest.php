<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('unauthenticated caller cannot access logout route', function () {
    $this->postJson('/api/v1/auth/logout')
        ->assertStatus(401);
});

test('logging out revokes the current access token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('session-1', ['user'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/auth/logout');

    $response->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);

    // Token is unusable on subsequent requests
    Auth::forgetGuards();
    $this->withToken($token)
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(401);
});

test('logging out revokes only the current session token, leaving other sessions active', function () {
    $user = User::factory()->create();

    // Simulate two concurrent sessions (e.g. mobile app and web panel)
    $mobileToken = $user->createToken('mobile-session', ['user'])->plainTextToken;
    $webToken = $user->createToken('web-session', ['admin'])->plainTextToken;

    expect($user->tokens()->count())->toBe(2);

    // Log out of mobile session
    $this->withToken($mobileToken)
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    // Mobile token is now dead
    Auth::forgetGuards();
    $this->withToken($mobileToken)
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(401);

    // Web token is still active and valid
    Auth::forgetGuards();
    $this->withToken($webToken)
        ->postJson('/api/v1/auth/refresh-token')
        ->assertOk();

    expect($user->tokens()->count())->toBe(1);
});
