<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/**
 * Helper to create an actor with an attached role.
 */
function createActorWithAttachedRole(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

test('plain USER with user-ability token can view their own profile', function () {
    $user = createActorWithAttachedRole('USER');
    $user->wallet()->create();
    $user->cap()->create();

    Sanctum::actingAs($user, ['user']);

    $response = $this->getJson("/api/v1/users/{$user->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.name', $user->name)
        ->assertJsonPath('data.user.roles', ['USER'])
        ->assertJsonPath('data.user.wallet.balance', '50.00')
        ->assertJsonPath('data.user.caps.daily_cap', '10000.00');
});

test('plain USER with user-ability token is rejected with 403 when viewing another user profile', function () {
    $user = createActorWithAttachedRole('USER');
    $otherUser = createActorWithAttachedRole('USER');

    Sanctum::actingAs($user, ['user']);

    $response = $this->getJson("/api/v1/users/{$otherUser->id}");

    $response->assertStatus(403);
});

test('ADMIN with admin-ability token can view any user profile', function () {
    $admin = createActorWithAttachedRole('ADMIN');
    $targetUser = createActorWithAttachedRole('USER');
    $targetUser->wallet()->create();

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->getJson("/api/v1/users/{$targetUser->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.id', $targetUser->id)
        ->assertJsonPath('data.user.roles', ['USER']);
});

test('AGENT with agent-ability token can view their own profile', function () {
    $agent = createActorWithAttachedRole('AGENT');
    $agent->agentInfo()->create();

    Sanctum::actingAs($agent, ['agent']);

    $response = $this->getJson("/api/v1/users/{$agent->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.id', $agent->id)
        ->assertJsonPath('data.user.agent_info.status', 'PENDING');
});

test('unauthenticated request to view user details returns 401', function () {
    $user = createActorWithAttachedRole('USER');

    $response = $this->getJson("/api/v1/users/{$user->id}");

    $response->assertStatus(401);
});

test('requesting non-existent user returns 404', function () {
    $admin = createActorWithAttachedRole('ADMIN');
    Sanctum::actingAs($admin, ['admin']);

    $response = $this->getJson('/api/v1/users/999999');

    $response->assertStatus(404);
});
