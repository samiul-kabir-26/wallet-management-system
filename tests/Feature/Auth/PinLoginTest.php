<?php

use App\Models\AgentInfo;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('non-existent phone number returns 401 with generic error message', function () {
    $response = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => '01712345678',
        'pin' => '12345',
    ]);

    $response->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Invalid phone number or PIN.',
            'errors' => [],
        ]);
});

test('correct phone but wrong PIN returns identical 401 response body for anti-enumeration', function () {
    // 1. Capture non-existent response
    $nonExistentResponse = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => '01712345678',
        'pin' => '12345',
    ]);

    // 2. Create existing user with USER role
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'USER')->first()->id, ['assigned_at' => now()]);

    // 3. Submit wrong PIN
    $wrongPinResponse = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => $user->phone_number,
        'pin' => '99999',
    ]);

    $wrongPinResponse->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Invalid phone number or PIN.',
            'errors' => [],
        ]);

    // Assert exact parity between the two branches
    expect($wrongPinResponse->json())->toEqual($nonExistentResponse->json());
});

test('inactive user returns 403 account inactive response', function () {
    $user = User::factory()->create([
        'is_active' => 'INACTIVE',
    ]);
    $user->roles()->attach(Role::where('name', 'USER')->first()->id, ['assigned_at' => now()]);

    $response = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => $user->phone_number,
        'pin' => '12345',
    ]);

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'Your account is inactive.',
            'errors' => [],
        ]);
});

test('user without USER role is rejected on user login route with 403', function () {
    // User only has AGENT role
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'AGENT')->first()->id, ['assigned_at' => now()]);

    $response = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => $user->phone_number,
        'pin' => '12345',
    ]);

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'You do not have permission to access this portal.',
            'errors' => [],
        ]);
});

test('agent without approved agent info is rejected on agent login route with 403', function () {
    $agent = User::factory()->create();
    $agent->roles()->attach(Role::where('name', 'AGENT')->first()->id, ['assigned_at' => now()]);

    // Scenario A: No agent_info record at all
    $responseNoInfo = $this->postJson('/api/v1/auth/agent/login', [
        'phone_number' => $agent->phone_number,
        'pin' => '12345',
    ]);

    $responseNoInfo->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'You do not have permission to access this portal.',
            'errors' => [],
        ]);

    // Scenario B: agent_info exists but status is PENDING
    AgentInfo::forceCreate([
        'user_id' => $agent->id,
        'status' => 'PENDING',
    ]);

    $responsePending = $this->postJson('/api/v1/auth/agent/login', [
        'phone_number' => $agent->phone_number,
        'pin' => '12345',
    ]);

    $responsePending->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'You do not have permission to access this portal.',
            'errors' => [],
        ]);
});

test('valid user credentials return 200 with scoped user token', function () {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'USER')->first()->id, ['assigned_at' => now()]);

    $response = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => $user->phone_number,
        'pin' => '12345',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Login successful')
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.name', $user->name)
        ->assertJsonPath('data.user.phone_number', $user->phone_number)
        ->assertJsonPath('data.user.roles', ['USER'])
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.abilities', ['user']);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();
});

test('valid approved agent credentials return 200 with scoped agent token', function () {
    $agent = User::factory()->create();
    $agent->roles()->attach(Role::where('name', 'AGENT')->first()->id, ['assigned_at' => now()]);

    AgentInfo::forceCreate([
        'user_id' => $agent->id,
        'status' => 'APPROVED',
    ]);

    $response = $this->postJson('/api/v1/auth/agent/login', [
        'phone_number' => $agent->phone_number,
        'pin' => '12345',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Login successful')
        ->assertJsonPath('data.user.id', $agent->id)
        ->assertJsonPath('data.user.name', $agent->name)
        ->assertJsonPath('data.user.phone_number', $agent->phone_number)
        ->assertJsonPath('data.user.roles', ['AGENT'])
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.abilities', ['agent']);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();
});
