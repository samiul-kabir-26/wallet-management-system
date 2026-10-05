<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('non-super-admin caller cannot grant admin access', function () {
    $admin = User::factory()->create();
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    $target = User::factory()->create(['is_verified' => true]);

    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'newadmin@example.com',
    ])->assertStatus(403);
});

test('unauthenticated caller cannot access grant-admin-access route', function () {
    $target = User::factory()->create(['is_verified' => true]);

    $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'newadmin@example.com',
    ])->assertStatus(401);
});

test('caller with wrong token ability is rejected with 403 on grant-admin-access', function () {
    $user = User::factory()->create();
    $target = User::factory()->create(['is_verified' => true]);

    Sanctum::actingAs($user, ['user']);

    $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'newadmin@example.com',
    ])->assertStatus(403);
});

test('grant-admin-access fails with 422 when target user is not phone-verified', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach(Role::where('name', 'SUPER_ADMIN')->first()->id, ['assigned_at' => now()]);

    $target = User::factory()->unverified()->create();

    Sanctum::actingAs($superAdmin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'newadmin@example.com',
    ]);

    $response->assertStatus(422)
        ->assertExactJson([
            'success' => false,
            'message' => 'Target user phone number must be verified before granting admin access.',
            'errors' => [],
        ]);

    expect($target->fresh()->hasRole('ADMIN'))->toBeFalse();
});

test('grant-admin-access fails validation when email is invalid or already taken', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach(Role::where('name', 'SUPER_ADMIN')->first()->id, ['assigned_at' => now()]);

    $existing = User::factory()->create(['email' => 'existing@example.com']);
    $target = User::factory()->create(['is_verified' => true]);

    Sanctum::actingAs($superAdmin, ['admin']);

    // Duplicate email
    $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'existing@example.com',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    // Malformed email
    $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'not-an-email',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('super admin can grant admin access to phone-verified user', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach(Role::where('name', 'SUPER_ADMIN')->first()->id, ['assigned_at' => now()]);

    $target = User::factory()->create([
        'email' => null,
        'is_verified' => true,
        'password' => null,
        'password_changed_at' => null,
    ]);

    Sanctum::actingAs($superAdmin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'newadmin@example.com',
    ]);

    $response->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'Admin access granted and invitation sent.',
            'data' => [
                'email' => 'newadmin@example.com',
            ],
        ]);

    $target->refresh();

    expect($target->email)->toBe('newadmin@example.com');
    expect($target->password)->not->toBeNull();
    expect($target->password_changed_at)->toBeNull();
    expect($target->hasRole('ADMIN'))->toBeTrue();

    $pivot = $target->roles()->where('name', 'ADMIN')->first()->pivot;
    expect($pivot->assigned_by)->toBe($superAdmin->id);
    expect($pivot->assigned_at)->not->toBeNull();
});
