<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seedRolesAndPermissions();
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

test('USER can update their own name and address', function () {
    $user = createActorWithRoleForUpdate('USER');
    Sanctum::actingAs($user, ['user']);

    $response = $this->patchJson("/api/v1/users/{$user->id}", [
        'name' => 'Updated Name',
        'address' => '456 Updated St, Chittagong',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.name', 'Updated Name')
        ->assertJsonPath('data.user.address', '456 Updated St, Chittagong');

    $user->refresh();
    expect($user->name)->toBe('Updated Name')
        ->and($user->address)->toBe('456 Updated St, Chittagong');
});

test('an arbitrary image string is rejected; avatars must be uploaded', function () {
    $user = createActorWithRoleForUpdate('USER');
    Sanctum::actingAs($user, ['user']);

    $this->patchJson("/api/v1/users/{$user->id}", ['image' => 'https://tracker.example/pixel.png'])
        ->assertJsonValidationErrors('image');

    expect($user->refresh()->image)->toBeNull();
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

test('staff edits to another user profile are audited with before and after values', function () {
    $admin = createActorWithRoleForUpdate('ADMIN');
    $targetUser = createActorWithRoleForUpdate('USER');
    $originalName = $targetUser->name;
    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson("/api/v1/users/{$targetUser->id}", ['name' => 'Corrected Name'])->assertOk();

    $audit = AuditLog::where('action', 'PROFILE_UPDATED')->sole();
    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->auditable_id)->toBe($targetUser->id)
        ->and($audit->metadata['changes'])->toEqual(['name' => ['before' => $originalName, 'after' => 'Corrected Name']]);
});

test('a user editing their own profile writes no staff audit entry', function () {
    $user = createActorWithRoleForUpdate('USER');
    Sanctum::actingAs($user, ['user']);

    $this->patchJson("/api/v1/users/{$user->id}", ['name' => 'My New Name'])->assertOk();

    expect(AuditLog::where('action', 'PROFILE_UPDATED')->exists())->toBeFalse();
});

test('an unchanged submission writes no audit entry', function () {
    $admin = createActorWithRoleForUpdate('ADMIN');
    $targetUser = createActorWithRoleForUpdate('USER');
    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson("/api/v1/users/{$targetUser->id}", ['name' => $targetUser->name])->assertOk();

    expect(AuditLog::where('action', 'PROFILE_UPDATED')->exists())->toBeFalse();
});

test('ADMIN cannot edit a SUPER_ADMIN profile', function () {
    $admin = createActorWithRoleForUpdate('ADMIN');
    $superAdmin = createActorWithRoleForUpdate('SUPER_ADMIN');
    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson("/api/v1/users/{$superAdmin->id}", ['name' => 'Renamed'])->assertForbidden();

    expect($superAdmin->fresh()->name)->not->toBe('Renamed');
});

test('SUPER_ADMIN can edit another SUPER_ADMIN profile', function () {
    $superAdmin = createActorWithRoleForUpdate('SUPER_ADMIN');
    $otherSuperAdmin = createActorWithRoleForUpdate('SUPER_ADMIN');
    Sanctum::actingAs($superAdmin, ['admin']);

    $this->patchJson("/api/v1/users/{$otherSuperAdmin->id}", ['name' => 'Renamed'])->assertOk();

    expect($otherSuperAdmin->fresh()->name)->toBe('Renamed');
});
