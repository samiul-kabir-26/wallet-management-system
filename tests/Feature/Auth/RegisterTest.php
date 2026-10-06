<?php

use App\Models\AgentInfo;
use App\Models\Cap;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('valid user registration creates user, wallet, cap, role, and returns 201 with scoped user token', function () {
    $payload = [
        'name' => 'John Doe',
        'phone_number' => '01712345678',
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => 'USER',
    ];

    $response = $this->postJson('/api/v1/auth/register', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'User registered successfully')
        ->assertJsonPath('data.user.name', 'John Doe')
        ->assertJsonPath('data.user.phone_number', '01712345678')
        ->assertJsonPath('data.user.roles', ['USER'])
        ->assertJsonPath('data.user.is_verified', false)
        ->assertJsonPath('data.user.is_active', 'ACTIVE')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.abilities', ['user']);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();

    // 1. Verify User persistence and PIN hashing
    $user = User::where('phone_number', '01712345678')->first();
    expect($user)->not->toBeNull();
    expect(Hash::check('12345', $user->pin))->toBeTrue();
    expect($user->hasRole('USER'))->toBeTrue();

    // 2. Verify Wallet created with schema defaults
    $wallet = Wallet::where('user_id', $user->id)->first();
    expect($wallet)->not->toBeNull();
    expect((float) $wallet->balance)->toEqual(50.00);
    expect($wallet->currency)->toEqual('BDT');

    // 3. Verify Cap limits created with schema defaults
    $cap = Cap::where('user_id', $user->id)->first();
    expect($cap)->not->toBeNull();
    expect((float) $cap->daily_cap)->toEqual(10000.00);
    expect((float) $cap->monthly_cap)->toEqual(50000.00);

    // 4. Verify no AgentInfo record created for USER
    expect(AgentInfo::where('user_id', $user->id)->exists())->toBeFalse();
});

test('valid agent registration creates agent_info with PENDING status and returns 201 with scoped agent token', function () {
    $payload = [
        'name' => 'Agent Smith',
        'phone_number' => '01812345678',
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => 'AGENT',
    ];

    $response = $this->postJson('/api/v1/auth/register', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'User registered successfully')
        ->assertJsonPath('data.user.name', 'Agent Smith')
        ->assertJsonPath('data.user.phone_number', '01812345678')
        ->assertJsonPath('data.user.roles', ['AGENT'])
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.abilities', ['agent']);

    $agent = User::where('phone_number', '01812345678')->first();
    expect($agent)->not->toBeNull();
    expect($agent->hasRole('AGENT'))->toBeTrue();

    // Verify AgentInfo record with PENDING status
    $agentInfo = AgentInfo::where('user_id', $agent->id)->first();
    expect($agentInfo)->not->toBeNull();
    expect($agentInfo->status)->toEqual('PENDING');
});

test('registration fails with 422 when pin confirmation does not match', function () {
    $payload = [
        'name' => 'John Doe',
        'phone_number' => '01712345678',
        'pin' => '12345',
        'pin_confirmation' => '54321',
        'role' => 'USER',
    ];

    $response = $this->postJson('/api/v1/auth/register', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['pin']);

    expect(User::where('phone_number', '01712345678')->exists())->toBeFalse();
});

test('registration fails with 422 when phone number is already registered', function () {
    $existingUser = User::factory()->create();

    $payload = [
        'name' => 'Duplicate User',
        'phone_number' => $existingUser->phone_number,
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => 'USER',
    ];

    $response = $this->postJson('/api/v1/auth/register', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['phone_number']);
});

test('registration fails with 422 when phone number has invalid operator prefix', function () {
    $payload = [
        'name' => 'Invalid Phone User',
        'phone_number' => '01212345678', // 012 is not a valid BD prefix
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => 'USER',
    ];

    $response = $this->postJson('/api/v1/auth/register', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['phone_number']);
});

test('registration fails with 422 when pin length is less than 5 digits', function () {
    $payload = [
        'name' => 'Short Pin User',
        'phone_number' => '01712345678',
        'pin' => '1234', // Only 4 digits
        'pin_confirmation' => '1234',
        'role' => 'USER',
    ];

    $response = $this->postJson('/api/v1/auth/register', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['pin']);
});

test('registration fails with 422 when attempting to register an elevated role', function () {
    $payload = [
        'name' => 'Hacker Admin',
        'phone_number' => '01712345678',
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => 'ADMIN', // Public registration must reject ADMIN
    ];

    $response = $this->postJson('/api/v1/auth/register', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['role']);
});
