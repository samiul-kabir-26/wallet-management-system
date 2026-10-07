<?php

use App\Models\OtpToken;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('user model and api resources never expose password or pin', function () {
    $role = Role::where('name', 'USER')->firstOrFail();
    $user = User::factory()->create([
        'phone_number' => '01711223344',
        'password' => Hash::make('Secret123456!'),
        'pin' => Hash::make('12345'),
    ]);
    $user->roles()->attach($role->id, ['assigned_at' => now()]);
    $user->wallet()->create();
    $user->cap()->create();

    // 1. Eloquent serialization
    $userArray = $user->toArray();
    expect(array_key_exists('password', $userArray))->toBeFalse()
        ->and(array_key_exists('pin', $userArray))->toBeFalse();

    // 2. User profile endpoint
    Sanctum::actingAs($user, ['user']);
    $response = $this->getJson("/api/v1/users/{$user->id}");

    $response->assertOk()
        ->assertJsonMissing(['password' => $user->password])
        ->assertJsonMissing(['pin' => $user->pin]);

    // 3. Login endpoint response
    $loginResponse = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => '01711223344',
        'pin' => '12345',
    ]);

    $loginResponse->assertOk()
        ->assertJsonMissing(['password'])
        ->assertJsonMissing(['pin']);
});

test('otp token hides otp_code upon serialization', function () {
    $user = User::factory()->create();
    $otp = OtpToken::create([
        'user_id' => $user->id,
        'otp_code' => '987654',
        'purpose' => 'LOGIN',
        'expires_at' => now()->addMinutes(5),
    ]);

    $otpArray = $otp->toArray();
    expect(array_key_exists('otp_code', $otpArray))->toBeFalse();
});

test('unauthenticated API requests return standardized 401 JSON envelope', function () {
    $response = $this->getJson('/api/v1/wallets/me');

    $response->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'Unauthenticated.',
            'errors' => [],
        ]);
});

test('unauthorized API requests return standardized 403 JSON envelope', function () {
    $user = User::factory()->create();
    $role = Role::where('name', 'USER')->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    Sanctum::actingAs($user, ['user']);

    $response = $this->getJson('/api/v1/transactions/admin/all');

    $response->assertStatus(403)
        ->assertJsonPath('success', false);
});

test('requesting non-existent API endpoints or models returns standardized 404 JSON envelope', function () {
    $response = $this->getJson('/api/v1/completely-non-existent-endpoint');

    $response->assertStatus(404)
        ->assertExactJson([
            'success' => false,
            'message' => 'Resource not found.',
            'errors' => [],
        ]);
});

test('failed login attempts log security warning event with caller details', function () {
    Log::spy();

    $user = User::factory()->create(['phone_number' => '01799887766', 'pin' => Hash::make('12345')]);
    $role = Role::where('name', 'USER')->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => '01799887766',
        'pin' => '00000',
    ])->assertStatus(401);

    Log::shouldHaveReceived('warning')
        ->with('Failed login attempt', Mockery::on(function (array $context) {
            return ($context['phone_number'] ?? null) === '01799887766';
        }))
        ->atLeast()->once();
});

test('authorization denials log security warning event', function () {
    Log::spy();

    $user = User::factory()->create();
    $role = Role::where('name', 'USER')->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    $otherUser = User::factory()->create();
    $otherUser->roles()->attach($role->id, ['assigned_at' => now()]);

    Sanctum::actingAs($user, ['user']);

    // Attempt to view another user's profile which fails TransactionPolicy / User Policy
    $this->getJson("/api/v1/users/{$otherUser->id}")->assertStatus(403);

    Log::shouldHaveReceived('warning')
        ->with('Authorization denied', Mockery::on(function (array $context) use ($user) {
            return ($context['user_id'] ?? null) === $user->id;
        }))
        ->atLeast()->once();
});

test('transaction creation logs financial audit info event', function () {
    Log::spy();

    $user = User::factory()->create();
    $role = Role::where('name', 'USER')->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);
    $user->wallet()->create(['balance' => 100.00]);
    $user->cap()->create();

    Sanctum::actingAs($user, ['user']);

    $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 50.00,
        'idempotency_key' => 'log-audit-key-1',
    ])->assertStatus(201);

    Log::shouldHaveReceived('info')
        ->with('Transaction created', Mockery::on(function (array $context) use ($user) {
            return ($context['user_id'] ?? null) === $user->id
                && ($context['type'] ?? null) === 'TOP_UP';
        }))
        ->atLeast()->once();
});

test('unhandled 500 errors in production environment suppress stack traces and return generic error envelope', function () {
    Route::get('/api/v1/test-server-error', function () {
        throw new RuntimeException('Database connection string leaked: secret_pwd');
    });

    // Simulate production environment
    app()->detectEnvironment(fn () => 'production');

    $response = $this->getJson('/api/v1/test-server-error');

    $response->assertStatus(500)
        ->assertExactJson([
            'success' => false,
            'message' => 'An error occurred.',
            'errors' => [],
        ])
        ->assertJsonMissing(['Database connection string leaked']);
});
