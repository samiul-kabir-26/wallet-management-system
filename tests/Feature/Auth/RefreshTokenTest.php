 <?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('unauthenticated caller cannot access refresh-token route', function () {
    $this->postJson('/api/v1/auth/refresh-token')
        ->assertStatus(401);
});

test('valid token can be refreshed, revoking the old token and issuing a new one', function () {
    $user = User::factory()->create();
    $oldToken = $user->createToken('user-login', ['user'])->plainTextToken;

    $response = $this->withToken($oldToken)
        ->postJson('/api/v1/auth/refresh-token');

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Token refreshed',
            'data' => [
                'abilities' => ['user'],
            ],
        ]);

    $newToken = $response->json('data.token');
    expect($newToken)->not->toBeNull();
    expect($newToken)->not->toEqual($oldToken);

    // Old token must now be revoked
    Auth::forgetGuards();
    $this->withToken($oldToken)
        ->postJson('/api/v1/auth/refresh-token')
        ->assertStatus(401);

    // New token works
    Auth::forgetGuards();
    $this->withToken($newToken)
        ->postJson('/api/v1/auth/refresh-token')
        ->assertOk();
});

test('refreshing preserves exact token abilities and prevents privilege escalation', function () {
    // User holds ADMIN role in database
    $dualRoleUser = User::factory()->create();
    $dualRoleUser->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);
    $dualRoleUser->roles()->attach(Role::where('name', 'USER')->first()->id, ['assigned_at' => now()]);

    // But this active session token was issued with 'user' ability
    $userSessionToken = $dualRoleUser->createToken('user-login', ['user'])->plainTextToken;

    $response = $this->withToken($userSessionToken)
        ->postJson('/api/v1/auth/refresh-token');

    $response->assertOk();

    // Abilities must remain strictly ['user'], never escalating to ['admin']
    $abilities = $response->json('data.abilities');
    expect($abilities)->toEqual(['user']);
    expect($abilities)->not->toContain('admin');

    // Confirm against database record as well
    $latestToken = $dualRoleUser->tokens()->latest('id')->first();
    expect($latestToken->abilities)->toEqual(['user']);
});
