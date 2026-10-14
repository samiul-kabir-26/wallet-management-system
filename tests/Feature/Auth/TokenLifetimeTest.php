<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function createPinUserForTokenLifetime(string $roleName = 'USER'): User
{
    $user = User::factory()->create(['pin' => Hash::make('12345')]);
    $user->roles()->attach(Role::where('name', $roleName)->first()->id, ['assigned_at' => now()]);

    return $user;
}

function findAccessToken(string $plainTextToken): ?PersonalAccessToken
{
    return PersonalAccessToken::findToken($plainTextToken);
}

test('user login issues a token that expires after the configured user lifetime', function () {
    config(['auth_settings.tokens.lifetime_minutes.user' => 720]);
    $this->freezeSecond();

    $user = createPinUserForTokenLifetime();

    $token = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => $user->phone_number,
        'pin' => '12345',
    ])->assertOk()->json('data.token');

    expect(findAccessToken($token)->expires_at->equalTo(now()->addMinutes(720)))->toBeTrue();
});

test('password-change token gets the short configured lifetime', function () {
    config(['auth_settings.tokens.lifetime_minutes.password-change' => 30]);
    $this->freezeSecond();

    $user = User::factory()->create(['password' => Hash::make('temp-password-123'), 'password_changed_at' => null]);
    $user->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    $token = $this->postJson(URL::temporarySignedRoute(
        'auth.accept-invite',
        now()->addHour(),
        ['user' => $user->id],
    ), ['password' => 'temp-password-123'])->assertOk()->json('data.token');

    expect(findAccessToken($token)->expires_at->equalTo(now()->addMinutes(30)))->toBeTrue();
});

test('a token past its absolute expiry is rejected', function () {
    config(['auth_settings.tokens.lifetime_minutes.user' => 60, 'auth_settings.tokens.idle_timeout_minutes' => 0]);

    $user = createPinUserForTokenLifetime();
    $token = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => $user->phone_number,
        'pin' => '12345',
    ])->json('data.token');

    $this->travel(61)->minutes();
    Auth::forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

test('a token idle longer than the timeout is rejected, while regular use keeps it alive', function () {
    config(['auth_settings.tokens.idle_timeout_minutes' => 15]);

    $user = createPinUserForTokenLifetime();
    $token = $user->createToken('user-login', ['user'], now()->addHours(12))->plainTextToken;

    // Used every 10 minutes: never idle for 15
    foreach (range(1, 3) as $step) {
        $this->travel(10)->minutes();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    // Then left alone for 16 minutes
    $this->travel(16)->minutes();
    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

test('refreshing rotates the token without extending its absolute expiry', function () {
    $this->freezeSecond();

    $user = createPinUserForTokenLifetime();
    $originalExpiry = now()->addMinutes(90);
    $oldToken = $user->createToken('user-login', ['user'], $originalExpiry)->plainTextToken;

    // Within the idle window, so the old token is still valid
    $this->travel(10)->minutes();

    $newToken = $this->withToken($oldToken)->postJson('/api/v1/auth/refresh-token')->assertOk()->json('data.token');

    $rotated = findAccessToken($newToken);
    expect($rotated->expires_at->equalTo($originalExpiry))->toBeTrue()
        ->and($rotated->name)->toBe('user-login')
        ->and($rotated->abilities)->toBe(['user'])
        ->and(findAccessToken($oldToken))->toBeNull();
});

test('changing the password revokes every other session the user had', function () {
    $user = User::factory()->create([
        'password' => Hash::make('current-password-123'),
        'password_changed_at' => now(),
    ]);
    $user->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    $otherSession = $user->createToken('admin-login', ['admin'])->plainTextToken;
    $currentSession = $user->createToken('admin-login', ['admin'])->plainTextToken;

    $newToken = $this->withToken($currentSession)->postJson('/api/v1/auth/change-password', [
        'current_password' => 'current-password-123',
        'new_password' => 'brand-new-password-456',
        'new_password_confirmation' => 'brand-new-password-456',
    ])->assertOk()->json('data.token');

    expect($user->tokens()->count())->toBe(1)
        ->and(findAccessToken($otherSession))->toBeNull()
        ->and(findAccessToken($newToken))->not->toBeNull();
});

test('logout-all revokes every token the user holds', function () {
    $user = createPinUserForTokenLifetime();
    $first = $user->createToken('user-login', ['user'])->plainTextToken;
    $user->createToken('agent-login', ['agent']);

    $this->withToken($first)->postJson('/api/v1/auth/logout-all')
        ->assertOk()
        ->assertJsonPath('message', 'Logged out from all sessions.');

    expect($user->tokens()->count())->toBe(0);

    Auth::forgetGuards();
    $this->withToken($first)->getJson('/api/v1/auth/me')->assertUnauthorized();
});
