<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    RateLimiter::clear('login');
    RateLimiter::clear('forgot-password');
    RateLimiter::clear('register');
});

test('login routes enforce 5 attempts per minute per identifier and IP, returning 429', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/user/login', [
            'phone_number' => '01712345678',
            'pin' => '00000',
        ])->assertStatus(401);
    }

    $response = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => '01712345678',
        'pin' => '00000',
    ]);

    $response->assertStatus(429)
        ->assertExactJson([
            'success' => false,
            'message' => 'Too many attempts. Please try again later.',
            'errors' => [],
        ]);
});

test('different identifiers from same IP do not share rate limit bucket on login routes', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/user/login', [
            'phone_number' => '01711111111',
            'pin' => '00000',
        ])->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => '01711111111',
        'pin' => '00000',
    ])->assertStatus(429);

    $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => '01722222222',
        'pin' => '00000',
    ])->assertStatus(401);
});

test('forgot-password route enforces 5 requests per minute per IP, returning 429', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => "user{$i}@example.com",
        ])->assertOk();
    }

    $response = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'another@example.com',
    ]);

    $response->assertStatus(429)
        ->assertExactJson([
            'success' => false,
            'message' => 'Too many attempts. Please try again later.',
            'errors' => [],
        ]);
});

test('register route enforces 5 requests per minute per IP, returning 429', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/register', [
            'name' => "User {$i}",
            'phone_number' => "0171000000{$i}",
            'pin' => '12345',
            'pin_confirmation' => '12345',
            'role' => 'USER',
        ])->assertStatus(201);
    }

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'User 6',
        'phone_number' => '01710000006',
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => 'USER',
    ]);

    $response->assertStatus(429)
        ->assertExactJson([
            'success' => false,
            'message' => 'Too many attempts. Please try again later.',
            'errors' => [],
        ]);
});
