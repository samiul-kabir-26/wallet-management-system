<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('complete case B lifecycle: grant access -> accept invite -> change password -> execute admin action', function () {
    // 1. Setup: SUPER_ADMIN and a verified target user
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach(Role::where('name', 'SUPER_ADMIN')->first()->id, ['assigned_at' => now()]);

    $target = User::factory()->create([
        'email' => null,
        'is_verified' => true,
        'password' => null,
        'password_changed_at' => null,
    ]);

    // 2. Intercept email delivery log to capture the real signed URL and temporary password
    $capturedInvite = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$capturedInvite) {
        if (isset($event->context['url'], $event->context['temp_password'])) {
            $capturedInvite = $event->context;
        }
    });

    // 3. Step 1: SUPER_ADMIN grants admin access via API
    Sanctum::actingAs($superAdmin, ['admin']);

    $grantResponse = $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'newadmin@domain.com',
    ]);
    $grantResponse->assertOk();

    expect($capturedInvite)->toHaveKeys(['url', 'temp_password']);
    $signedUrl = $capturedInvite['url'];
    $tempPassword = $capturedInvite['temp_password'];

    // Clear actingAs / guard cache before testing bearer tokens
    Auth::forgetGuards();

    // 4. Step 2: Target user hits signed accept-invite URL
    $acceptResponse = $this->postJson($signedUrl, [
        'password' => $tempPassword,
    ]);

    $acceptResponse->assertOk();
    $passwordChangeToken = $acceptResponse->json('data.token');
    expect($acceptResponse->json('data.abilities'))->toEqual(['password-change']);

    // 5. Verify the intermediate token CANNOT execute admin actions
    Auth::forgetGuards();
    $this->withToken($passwordChangeToken)
        ->postJson('/api/v1/auth/set-pin/request-otp')
        ->assertStatus(403);

    // 6. Step 3: Target user changes password using the password-change token
    Auth::forgetGuards();
    $newPassword = 'my-new-secure-password-2026';
    $changeResponse = $this->withToken($passwordChangeToken)->postJson('/api/v1/auth/change-password', [
        'current_password' => $tempPassword,
        'new_password' => $newPassword,
        'new_password_confirmation' => $newPassword,
    ]);

    $changeResponse->assertOk();
    $finalAdminToken = $changeResponse->json('data.token');
    expect($changeResponse->json('data.abilities'))->toEqual(['admin']);

    // 7. Verify the old password-change token is now revoked and rejected
    Auth::forgetGuards();
    $this->withToken($passwordChangeToken)
        ->postJson('/api/v1/auth/change-password', [
            'current_password' => $newPassword,
            'new_password' => 'yet-another-password',
            'new_password_confirmation' => 'yet-another-password',
        ])
        ->assertStatus(401);

    // 8. Verify the final admin token is fully functional for ability:admin routes
    Auth::forgetGuards();
    $this->withToken($finalAdminToken)
        ->postJson('/api/v1/auth/set-pin/request-otp')
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'OTP sent to your email. Please check your inbox.',
        ]);

    // 9. Verify database integrity
    $target->refresh();
    expect($target->email)->toBe('newadmin@domain.com');
    expect(Hash::check($newPassword, $target->password))->toBeTrue();
    expect($target->password_changed_at)->not->toBeNull();
    expect($target->hasRole('ADMIN'))->toBeTrue();
});
