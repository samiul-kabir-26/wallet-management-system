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
function createActorWithRoleForUpdate(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

test('USER can update their own name, address, and image', function () {
    $user = createActorWithRoleForUpdate('USER');
    Sanctum::actingAs($user, ['user']);

    $response = $this->patchJson("/api/v1/users/{$user->id}", [
        'name' => 'Updated Name',
        'address' => '456 Updated St, Chittagong',
        'image' => 'profile_avatar.png',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.name', 'Updated Name')
        ->assertJsonPath('data.user.address', '456 Updated St, Chittagong');

    $user->refresh();
    expect($user->name)->toBe('Updated Name')
        ->and($user->address)->toBe('456 Updated St, Chittagong')
        ->and($user->image)->toBe('profile_avatar.png');
});

test('USER cannot update another user profile', function () {
    $user = createActorWithRoleForUpdate('USER');
    $otherUser = createActorWithRoleForUpdate('USER');

    Sanctum::actingAs($user, ['user']);

    $response = $this->patchJson("/api/v1/users/{$otherUser->id}", [
        'name' => 'Hacked Name',
    ]);

    $response->assertStatus(403);

    $otherUser->refresh();
    expect($otherUser->name)->not->toBe('Hacked Name');
});

test('ADMIN can update any user profile', function () {
    $admin = createActorWithRoleForUpdate('ADMIN');
    $targetUser = createActorWithRoleForUpdate('USER');

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$targetUser->id}", [
        'name' => 'Admin Updated Name',
        'address' => 'Admin Assigned Address',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.id', $targetUser->id)
        ->assertJsonPath('data.user.name', 'Admin Updated Name');

    $targetUser->refresh();
    expect($targetUser->name)->toBe('Admin Updated Name')
        ->and($targetUser->address)->toBe('Admin Assigned Address');
});

test('payload with prohibited fields email, phone_number, password, or role fails with 422', function () {
    $user = createActorWithRoleForUpdate('USER');
    Sanctum::actingAs($user, ['user']);

    $response = $this->patchJson("/api/v1/users/{$user->id}", [
        'email' => 'newemail@example.com',
        'phone_number' => '01799887766',
        'password' => 'NewPassword123!',
        'role' => 'ADMIN',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'phone_number', 'password', 'role']);
});

test('unauthenticated caller cannot access update route', function () {
    $user = createActorWithRoleForUpdate('USER');

    $response = $this->patchJson("/api/v1/users/{$user->id}", [
        'name' => 'Anonymous Change',
    ]);

    $response->assertStatus(401);
});

test('updating non-existent user returns 404', function () {
    $admin = createActorWithRoleForUpdate('ADMIN');
    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson('/api/v1/users/999999', [
        'name' => 'Non Existent',
    ]);

    $response->assertStatus(404);
});
