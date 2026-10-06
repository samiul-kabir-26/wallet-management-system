<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('admin with pending password change is blocked from protected admin routes with 403', function () {
    $admin = User::factory()->pendingPasswordChange()->create([
        'password' => Hash::make('temp_password'),
    ]);
    $admin->roles()->attach(Role::where('name', 'SUPER_ADMIN')->first()->id, ['assigned_at' => now()]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/auth/pin-reset/initiate', [
        'phone_number' => '01712345678',
        'email' => 'target@example.com',
    ]);

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'You must change your password before proceeding.',
            'errors' => [],
        ]);
});

test('admin with pending password change is allowed to access change-password route', function () {
    $admin = User::factory()->pendingPasswordChange()->create([
        'password' => Hash::make('temporary_password_123'),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    Sanctum::actingAs($admin, ['password-change']);

    $response = $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'temporary_password_123',
        'new_password' => 'NewSecurePassword123!',
        'new_password_confirmation' => 'NewSecurePassword123!',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Password changed successfully.');

    expect($admin->fresh()->password_changed_at)->not->toBeNull();
});

test('admin who has changed password can access protected admin routes', function () {
    $admin = User::factory()->create([
        'password' => Hash::make('current_password'),
        'password_changed_at' => now(),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/auth/set-pin/request-otp');

    $response->assertOk()
        ->assertJsonPath('success', true);
});
