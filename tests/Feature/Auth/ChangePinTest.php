<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Authentication\Services\PinVerifier;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function createPinChangeUser(string $roleName = 'USER'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', $roleName)->firstOrFail()->id, ['assigned_at' => now()]);
    RateLimiter::clear(PinVerifier::getThrottleKey($user->id));

    return $user;
}

test('a customer changes their PIN, keeps this session and signs out the others', function () {
    $customer = createPinChangeUser();
    $current = $customer->createToken('user-login', ['user'])->plainTextToken;
    $other = $customer->createToken('user-login', ['user'])->plainTextToken;

    $this->withToken($current)->postJson('/api/v1/auth/change-pin', [
        'current_pin' => '12345',
        'new_pin' => '54321',
        'new_pin_confirmation' => '54321',
    ])->assertOk();

    expect(Hash::check('54321', $customer->refresh()->pin))->toBeTrue()
        ->and($customer->tokens()->count())->toBe(1)
        ->and(AuditLog::where('action', 'PIN_CHANGED')->where('actor_id', $customer->id)->exists())->toBeTrue();

    $this->app['auth']->forgetGuards();
    $this->withToken($current)->getJson('/api/v1/auth/me')->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

test('a wrong current PIN is rejected and counts toward the login lockout', function () {
    $agent = createPinChangeUser('AGENT');
    $token = $agent->createToken('agent-login', ['agent'])->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/change-pin', [
        'current_pin' => '99999',
        'new_pin' => '54321',
        'new_pin_confirmation' => '54321',
    ])->assertStatus(422)->assertJsonPath('meta.attempts_remaining', 4);

    expect(Hash::check('12345', $agent->refresh()->pin))->toBeTrue()
        ->and(RateLimiter::attempts(PinVerifier::getThrottleKey($agent->id)))->toBe(1);
});

test('the new PIN must be valid, confirmed and different', function () {
    $customer = createPinChangeUser();
    $token = $customer->createToken('user-login', ['user'])->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/change-pin', [
        'current_pin' => '12345',
        'new_pin' => '12345',
        'new_pin_confirmation' => '12345',
    ])->assertJsonValidationErrors('new_pin');

    $this->withToken($token)->postJson('/api/v1/auth/change-pin', [
        'current_pin' => '12345',
        'new_pin' => '12ab5',
        'new_pin_confirmation' => '54321',
    ])->assertJsonValidationErrors('new_pin');
});

test('an admin token cannot change a PIN', function () {
    $admin = createPinChangeUser('ADMIN');
    $token = $admin->createToken('admin-login', ['admin'])->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/change-pin', [
        'current_pin' => '12345',
        'new_pin' => '54321',
        'new_pin_confirmation' => '54321',
    ])->assertForbidden();
});
