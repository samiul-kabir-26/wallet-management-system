<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/*
    |--------------------------------------------------------------------------
    | Step 1: POST /api/v1/auth/pin-reset/initiate
    |--------------------------------------------------------------------------
    */

test('caller with user or agent ability token is rejected with 403 on initiate-pin-reset', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user, ['user']);

    $this->postJson('/api/v1/auth/pin-reset/initiate', [
        'phone_number' => '01712345678',
        'email' => 'recovery@example.com',
    ])->assertStatus(403);
});

test('caller with admin ability but lacking staff role is rejected with 403 by service', function () {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'USER')->first()->id, ['assigned_at' => now()]);

    // Has admin token ability, but lacks SUPER_ADMIN, ADMIN, or MODERATOR role
    Sanctum::actingAs($user, ['admin']);

    $target = User::factory()->create(['pin' => Hash::make('12345')]);

    $this->postJson('/api/v1/auth/pin-reset/initiate', [
        'phone_number' => $target->phone_number,
        'email' => 'recovery@example.com',
    ])->assertStatus(403);
});

test('initiate-pin-reset returns 404 when target phone number is not found', function () {
    $moderator = User::factory()->create();
    $moderator->roles()->attach(Role::where('name', 'MODERATOR')->first()->id, ['assigned_at' => now()]);

    Sanctum::actingAs($moderator, ['admin']);

    $response = $this->postJson('/api/v1/auth/pin-reset/initiate', [
        'phone_number' => '01799999999',
        'email' => 'recovery@example.com',
    ]);

    $response->assertStatus(404)
        ->assertExactJson([
            'success' => false,
            'message' => 'User not found with the provided phone number.',
            'errors' => [],
        ]);
});

test('initiate-pin-reset returns 422 when target user has no PIN configured', function () {
    $moderator = User::factory()->create();
    $moderator->roles()->attach(Role::where('name', 'MODERATOR')->first()->id, ['assigned_at' => now()]);

    $target = User::factory()->create(['pin' => null]);

    Sanctum::actingAs($moderator, ['admin']);

    $response = $this->postJson('/api/v1/auth/pin-reset/initiate', [
        'phone_number' => $target->phone_number,
        'email' => 'recovery@example.com',
    ]);

    $response->assertStatus(422)
        ->assertExactJson([
            'success' => false,
            'message' => 'Target account does not have a PIN configured to reset.',
            'errors' => [],
        ]);
});

test('initiate-pin-reset returns 403 when target account is inactive', function () {
    $moderator = User::factory()->create();
    $moderator->roles()->attach(Role::where('name', 'MODERATOR')->first()->id, ['assigned_at' => now()]);

    $target = User::factory()->create([
        'pin' => Hash::make('12345'),
        'is_active' => 'INACTIVE',
    ]);

    Sanctum::actingAs($moderator, ['admin']);

    $response = $this->postJson('/api/v1/auth/pin-reset/initiate', [
        'phone_number' => $target->phone_number,
        'email' => 'recovery@example.com',
    ]);

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'Your account is inactive.',
            'errors' => [],
        ]);
});

test('initiate-pin-reset fails validation when recovery email is already taken', function () {
    $moderator = User::factory()->create();
    $moderator->roles()->attach(Role::where('name', 'MODERATOR')->first()->id, ['assigned_at' => now()]);

    User::factory()->create(['email' => 'taken@example.com']);
    $target = User::factory()->create(['pin' => Hash::make('12345')]);

    Sanctum::actingAs($moderator, ['admin']);

    $this->postJson('/api/v1/auth/pin-reset/initiate', [
        'phone_number' => $target->phone_number,
        'email' => 'taken@example.com',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('moderator can initiate pin reset, attaching email and recording audit log', function () {
    $moderator = User::factory()->create();
    $moderator->roles()->attach(Role::where('name', 'MODERATOR')->first()->id, ['assigned_at' => now()]);

    $target = User::factory()->create([
        'email' => null,
        'pin' => Hash::make('12345'),
    ]);

    Sanctum::actingAs($moderator, ['admin']);

    $response = $this->postJson('/api/v1/auth/pin-reset/initiate', [
        'phone_number' => $target->phone_number,
        'email' => 'recovery@example.com',
    ]);

    $response->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'PIN reset link generated and sent.',
        ]);

    $target->refresh();
    expect($target->email)->toBe('recovery@example.com');

    $audit = AuditLog::where('action', 'PIN_RESET_INITIATED')
        ->where('actor_id', $moderator->id)
        ->where('auditable_id', $target->id)
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->auditable_type)->toBe($target->getMorphClass());
    expect($audit->metadata['attached_email'])->toBe('recovery@example.com');
    expect($audit->metadata['phone_number'])->toBe($target->phone_number);
    expect($audit->created_at)->not->toBeNull();
});

/*
    |--------------------------------------------------------------------------
    | Step 2: POST /api/v1/auth/reset-pin/{user}
    |--------------------------------------------------------------------------
    */

test('reset-pin succeeds with valid signed URL and updates user PIN', function () {
    $user = User::factory()->create([
        'pin' => Hash::make('11111'),
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'auth.reset-pin',
        now()->addMinutes(15),
        ['user' => $user->id],
    );

    $response = $this->postJson($signedUrl, [
        'pin' => '99999',
        'pin_confirmation' => '99999',
    ]);

    $response->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'PIN reset successfully. Please log in with your new PIN.',
        ]);

    $user->refresh();
    expect(Hash::check('99999', $user->pin))->toBeTrue();
});

test('reset-pin fails with 403 on expired or tampered signature', function () {
    $user = User::factory()->create([
        'pin' => Hash::make('11111'),
    ]);

    // Tampered signature
    $validUrl = URL::temporarySignedRoute(
        'auth.reset-pin',
        now()->addMinutes(15),
        ['user' => $user->id],
    );
    $tamperedUrl = $validUrl.'tampered';

    $this->postJson($tamperedUrl, [
        'pin' => '99999',
        'pin_confirmation' => '99999',
    ])->assertStatus(403);

    // Expired signature
    $expiredUrl = URL::temporarySignedRoute(
        'auth.reset-pin',
        now()->subMinute(),
        ['user' => $user->id],
    );

    $this->postJson($expiredUrl, [
        'pin' => '99999',
        'pin_confirmation' => '99999',
    ])->assertStatus(403);
});

test('reset-pin fails with 403 when target account is inactive at click time', function () {
    $user = User::factory()->create([
        'pin' => Hash::make('11111'),
        'is_active' => 'INACTIVE',
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'auth.reset-pin',
        now()->addMinutes(15),
        ['user' => $user->id],
    );

    $response = $this->postJson($signedUrl, [
        'pin' => '99999',
        'pin_confirmation' => '99999',
    ]);

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'Your account is inactive.',
            'errors' => [],
        ]);
});

test('reset-pin fails with 422 if account PIN was cleared before consuming the link', function () {
    $user = User::factory()->create([
        'pin' => null,
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'auth.reset-pin',
        now()->addMinutes(15),
        ['user' => $user->id],
    );

    $response = $this->postJson($signedUrl, [
        'pin' => '99999',
        'pin_confirmation' => '99999',
    ]);

    $response->assertStatus(422)
        ->assertExactJson([
            'success' => false,
            'message' => 'Target account does not have a PIN configured to reset.',
            'errors' => [],
        ]);
});

test('reset-pin fails validation when pin confirmation mismatches or format is invalid', function () {
    $user = User::factory()->create([
        'pin' => Hash::make('11111'),
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'auth.reset-pin',
        now()->addMinutes(15),
        ['user' => $user->id],
    );

    // Confirmation mismatch
    $this->postJson($signedUrl, [
        'pin' => '99999',
        'pin_confirmation' => '88888',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['pin']);

    // Too short (< 5 digits)
    $this->postJson($signedUrl, [
        'pin' => '123',
        'pin_confirmation' => '123',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['pin']);
});
