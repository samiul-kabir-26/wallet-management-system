<?php

use App\Models\AgentInfo;
use App\Models\OtpToken;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('admin login issues token with admin ability and user login issues token with user ability', function () {
    // 1. Fixture: User holding BOTH ADMIN and USER roles, with BOTH password and PIN
    $user = User::factory()->create([
        'email' => 'dual.role@example.com',
        'phone_number' => '01711223344',
        'password' => Hash::make('AdminPass123!'),
        'pin' => Hash::make('12345'),
        'password_changed_at' => now(),
    ]);

    $adminRole = Role::where('name', 'ADMIN')->first();
    $userRole = Role::where('name', 'USER')->first();
    $user->roles()->attach([
        $adminRole->id => ['assigned_at' => now()],
        $userRole->id => ['assigned_at' => now()],
    ]);

    // 2. User login with PIN
    $userLoginResponse = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => $user->phone_number,
        'pin' => '12345',
    ]);

    $userLoginResponse->assertOk()
        ->assertJsonPath('data.abilities', ['user']);

    // Clear request guard cache between multi-auth checks in the same test
    Auth::forgetGuards();

    // 3. Admin login with Password + OTP
    $this->postJson('/api/v1/auth/admin/login', [
        'identifier' => $user->email,
        'password' => 'AdminPass123!',
    ])->assertOk();

    $otp = OtpToken::where('user_id', $user->id)
        ->where('purpose', 'LOGIN')
        ->whereNull('used_at')
        ->first();

    $adminVerifyResponse = $this->postJson('/api/v1/auth/admin/verify-otp', [
        'email' => $user->email,
        'otp_code' => $otp->otp_code,
    ]);

    $adminVerifyResponse->assertOk()
        ->assertJsonPath('data.abilities', ['admin']);
});

test('PIN-issued user token is rejected by admin-only endpoint even though user holds ADMIN role', function () {
    // Deliberate fixture: user genuinely holds ADMIN and USER roles in DB
    $user = User::factory()->create([
        'email' => 'dual.role@example.com',
        'phone_number' => '01711223344',
        'password' => Hash::make('AdminPass123!'),
        'pin' => Hash::make('12345'),
        'password_changed_at' => now(),
    ]);

    $adminRole = Role::where('name', 'ADMIN')->first();
    $userRole = Role::where('name', 'USER')->first();
    $user->roles()->attach([
        $adminRole->id => ['assigned_at' => now()],
        $userRole->id => ['assigned_at' => now()],
    ]);

    // Authenticate via PIN login
    $loginResponse = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => $user->phone_number,
        'pin' => '12345',
    ]);
    $userToken = $loginResponse->json('data.token');

    // Attempt to hit admin-only route with this user token
    $response = $this->withToken($userToken)
        ->postJson('/api/v1/auth/set-pin/request-otp');

    // Rejected by ability:admin middleware
    $response->assertStatus(403);
});

test('admin-issued token is accepted by admin-only endpoint', function () {
    $user = User::factory()->create([
        'email' => 'dual.role@example.com',
        'phone_number' => '01711223344',
        'password' => Hash::make('AdminPass123!'),
        'pin' => Hash::make('12345'),
        'password_changed_at' => now(),
    ]);

    $adminRole = Role::where('name', 'ADMIN')->first();
    $userRole = Role::where('name', 'USER')->first();
    $user->roles()->attach([
        $adminRole->id => ['assigned_at' => now()],
        $userRole->id => ['assigned_at' => now()],
    ]);

    // Admin login + verify OTP
    $this->postJson('/api/v1/auth/admin/login', [
        'identifier' => $user->email,
        'password' => 'AdminPass123!',
    ])->assertOk();

    $otp = OtpToken::where('user_id', $user->id)
        ->where('purpose', 'LOGIN')
        ->whereNull('used_at')
        ->first();

    $verifyResponse = $this->postJson('/api/v1/auth/admin/verify-otp', [
        'email' => $user->email,
        'otp_code' => $otp->otp_code,
    ]);
    $adminToken = $verifyResponse->json('data.token');

    // Attempt to hit admin-only route with admin token
    $response = $this->withToken($adminToken)
        ->postJson('/api/v1/auth/set-pin/request-otp');

    $response->assertOk()
        ->assertJsonPath('success', true);
});

test('PIN login by user holding ADMIN role but lacking USER role is rejected with 403', function () {
    $adminOnlyUser = User::factory()->create([
        'phone_number' => '01711223344',
        'pin' => Hash::make('12345'),
    ]);
    $adminOnlyUser->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    $response = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => $adminOnlyUser->phone_number,
        'pin' => '12345',
    ]);

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'You do not have permission to access this portal.',
            'errors' => [],
        ]);
});

test('agent whose agent_info status is PENDING cannot log in on agent route', function () {
    $agent = User::factory()->create([
        'phone_number' => '01711223344',
        'pin' => Hash::make('12345'),
    ]);
    $agent->roles()->attach(Role::where('name', 'AGENT')->first()->id, ['assigned_at' => now()]);

    AgentInfo::forceCreate([
        'user_id' => $agent->id,
        'status' => 'PENDING',
    ]);

    $response = $this->postJson('/api/v1/auth/agent/login', [
        'phone_number' => $agent->phone_number,
        'pin' => '12345',
    ]);

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'You do not have permission to access this portal.',
            'errors' => [],
        ]);
});

test('token refresh preserves existing abilities and cannot escalate privileges', function () {
    $user = User::factory()->create([
        'phone_number' => '01711223344',
        'pin' => Hash::make('12345'),
        'password' => Hash::make('AdminPass123!'),
        'password_changed_at' => now(),
    ]);

    $adminRole = Role::where('name', 'ADMIN')->first();
    $userRole = Role::where('name', 'USER')->first();
    $user->roles()->attach([
        $adminRole->id => ['assigned_at' => now()],
        $userRole->id => ['assigned_at' => now()],
    ]);

    $loginResponse = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => $user->phone_number,
        'pin' => '12345',
    ]);
    $userToken = $loginResponse->json('data.token');

    // Refresh token
    $refreshResponse = $this->withToken($userToken)
        ->postJson('/api/v1/auth/refresh-token');

    $refreshResponse->assertOk()
        ->assertJsonPath('data.abilities', ['user']);

    $refreshedToken = $refreshResponse->json('data.token');

    // Clear request guards before subsequent request
    Auth::forgetGuards();

    // Refreshed token is still rejected by admin routes
    $this->withToken($refreshedToken)
        ->postJson('/api/v1/auth/set-pin/request-otp')
        ->assertStatus(403);
});
