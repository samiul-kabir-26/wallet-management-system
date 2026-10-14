<?php

use App\Models\Cap;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Exceptions\PinLockedException;
use Modules\Authentication\Services\PinVerifier;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SystemSettingsSeeder::class);
});

function createSecurityTestUser(float $balance = 1000.00): User
{
    $user = User::factory()->create([
        'pin' => Hash::make('12345'),
    ]);
    $role = Role::where('name', 'USER')->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    Wallet::factory()->for($user)->balance($balance)->create();
    Cap::factory()->for($user)->usage(0.00, 0.00)->create();

    RateLimiter::clear(PinVerifier::getThrottleKey($user->id));

    return $user;
}

test('pin verifier increments counter before running slow hash check and short-circuits when locked', function () {
    $user = createSecurityTestUser();
    $verifier = app(PinVerifier::class);
    $key = PinVerifier::getThrottleKey($user->id);

    // 1. Manually set rate limiter counter to max attempts (5)
    for ($i = 0; $i < 5; $i++) {
        RateLimiter::hit($key);
    }
    expect(RateLimiter::attempts($key))->toBe(5);

    // 2. Spy on Hash to verify Hash::check is never invoked once locked
    Hash::spy();

    // 3. Invoking verify with even the correct PIN must throw PinLockedException immediately
    expect(fn () => $verifier->verify($user, '12345'))
        ->toThrow(PinLockedException::class);

    // 4. Assert Hash::check was never called because the attempt counter was evaluated first
    Hash::shouldNotHaveReceived('check');
});

test('failed login PIN counts toward transaction lockout across shared counter', function () {
    $user = createSecurityTestUser(1000.00);
    $recipient = createSecurityTestUser(500.00);

    // 1. Submit 3 failed PIN attempts on login route
    for ($i = 1; $i <= 3; $i++) {
        $this->postJson('/api/v1/auth/user/login', [
            'phone_number' => $user->phone_number,
            'pin' => '99999',
        ])->assertStatus(401);
    }

    // 2. User logs in (or is actingAs) for transaction
    Sanctum::actingAs($user, ['user']);

    // 4th attempt overall (1st on transaction) -> 422 with 1 attempt left
    $fourth = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 10.00,
        'pin' => '99999',
        'idempotency_key' => 'shared-lockout-4',
    ]);
    $fourth->assertStatus(422)
        ->assertJsonPath('meta.attempts_remaining', 1);

    // 5th attempt overall (2nd on transaction) -> 429 locked out
    $fifth = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 10.00,
        'pin' => '99999',
        'idempotency_key' => 'shared-lockout-5',
    ]);
    $fifth->assertStatus(429)
        ->assertJsonPath('meta.locked', true)
        ->assertHeader('Retry-After');
});

test('failed transaction PIN counts toward login lockout across shared counter', function () {
    $user = createSecurityTestUser(1000.00);
    $recipient = createSecurityTestUser(500.00);

    Sanctum::actingAs($user, ['user']);

    // 1. Submit 3 failed PIN attempts on transfer route
    for ($i = 1; $i <= 3; $i++) {
        $this->postJson('/api/v1/transactions/transfer', [
            'recipient_phone' => $recipient->phone_number,
            'amount' => 10.00,
            'pin' => '99999',
            'idempotency_key' => "tx-fail-{$i}",
        ])->assertStatus(422);
    }

    // 2. 4th attempt on login route -> 401
    $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => $user->phone_number,
        'pin' => '99999',
    ])->assertStatus(401);

    // 3. 5th attempt on login route -> 429 locked
    $fifth = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => $user->phone_number,
        'pin' => '99999',
    ]);

    $fifth->assertStatus(429)
        ->assertJsonPath('meta.locked', true)
        ->assertHeader('Retry-After');
});

test('a correct PIN on transaction resets the shared counter', function () {
    $user = createSecurityTestUser(1000.00);
    $recipient = createSecurityTestUser(500.00);

    Sanctum::actingAs($user, ['user']);

    // 3 failed attempts
    for ($i = 1; $i <= 3; $i++) {
        $this->postJson('/api/v1/transactions/transfer', [
            'recipient_phone' => $recipient->phone_number,
            'amount' => 10.00,
            'pin' => '99999',
            'idempotency_key' => "reset-test-fail-{$i}",
        ])->assertStatus(422);
    }

    expect(RateLimiter::attempts(PinVerifier::getThrottleKey($user->id)))->toBe(3);

    // 1 successful transaction with correct PIN
    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 10.00,
        'pin' => '12345',
        'idempotency_key' => 'reset-test-success',
    ])->assertStatus(201);

    // Counter must be completely cleared
    expect(RateLimiter::attempts(PinVerifier::getThrottleKey($user->id)))->toBe(0);

    // Next wrong attempt now has 4 attempts remaining, not 1
    $nextFail = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 10.00,
        'pin' => '99999',
        'idempotency_key' => 'after-reset-fail',
    ]);
    $nextFail->assertStatus(422)
        ->assertJsonPath('meta.attempts_remaining', 4);
});

test('a correct PIN on login resets the shared counter', function () {
    $user = createSecurityTestUser(1000.00);
    $recipient = createSecurityTestUser(500.00);

    Sanctum::actingAs($user, ['user']);

    // 3 failed attempts on transfer
    for ($i = 1; $i <= 3; $i++) {
        $this->postJson('/api/v1/transactions/transfer', [
            'recipient_phone' => $recipient->phone_number,
            'amount' => 10.00,
            'pin' => '99999',
            'idempotency_key' => "login-reset-fail-{$i}",
        ])->assertStatus(422);
    }

    expect(RateLimiter::attempts(PinVerifier::getThrottleKey($user->id)))->toBe(3);

    // 1 successful login with correct PIN
    $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => $user->phone_number,
        'pin' => '12345',
    ])->assertOk();

    // Counter must be cleared
    expect(RateLimiter::attempts(PinVerifier::getThrottleKey($user->id)))->toBe(0);
});

test('lockout response shape keeps errors clean and exposes metadata in meta block with Retry-After header', function () {
    $user = createSecurityTestUser(1000.00);
    $recipient = createSecurityTestUser(500.00);

    Sanctum::actingAs($user, ['user']);

    for ($i = 1; $i <= 4; $i++) {
        $this->postJson('/api/v1/transactions/transfer', [
            'recipient_phone' => $recipient->phone_number,
            'amount' => 10.00,
            'pin' => '99999',
            'idempotency_key' => "shape-fail-{$i}",
        ])->assertStatus(422);
    }

    $lockoutRes = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 10.00,
        'pin' => '99999',
        'idempotency_key' => 'shape-fail-5',
    ]);

    $lockoutRes->assertStatus(429);
    $data = $lockoutRes->json();

    // 1. errors only contains field name to array of string messages
    expect(array_key_exists('errors', $data))->toBeTrue()
        ->and($data['errors'])->toBeArray()
        ->and(array_key_exists('pin', $data['errors']))->toBeTrue()
        ->and($data['errors']['pin'])->toBeArray()
        ->and($data['errors']['pin'][0])->toBeString();

    // 2. errors MUST NOT contain metadata keys
    expect(array_key_exists('locked', $data['errors']))->toBeFalse()
        ->and(array_key_exists('retry_after', $data['errors']))->toBeFalse()
        ->and(array_key_exists('attempts_remaining', $data['errors']))->toBeFalse();

    // 3. meta contains the structured metadata
    expect(array_key_exists('meta', $data))->toBeTrue()
        ->and($data['meta']['locked'])->toBeTrue()
        ->and($data['meta']['retry_after'])->toBeInt()->toBeGreaterThan(0);

    // 4. Header Retry-After is present and matches meta
    expect($lockoutRes->headers->get('Retry-After'))->toBe((string) $data['meta']['retry_after']);
});
