<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function createActorForLookup(string $roleName = 'USER'): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

test('authenticated user can look up recipient with masked name', function () {
    $caller = createActorForLookup('USER');
    $recipient = User::factory()->create([
        'name' => 'Rahul Kumar',
        'phone_number' => '01712345678',
    ]);

    Sanctum::actingAs($caller, ['user']);

    $response = $this->getJson('/api/v1/users/lookup?phone_number=01712345678');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'R***l K***r')
        ->assertJsonPath('data.phone_number', '01712345678');
});

test('masks various name formats appropriately', function (string $rawName, string $expectedMasked) {
    $caller = createActorForLookup('USER');
    $recipient = User::factory()->create([
        'name' => $rawName,
        'phone_number' => '01812345678',
    ]);

    Sanctum::actingAs($caller, ['user']);

    $response = $this->getJson('/api/v1/users/lookup?phone_number=01812345678');

    $response->assertOk()
        ->assertJsonPath('data.name', $expectedMasked);
})->with([
    ['John Doe', 'J**n D*e'],
    ['Md Ali', 'M* A*i'],
    ['Samiul Islam', 'S****l I***m'],
    ['A B', '* *'],
]);

test('lookup returns 404 when user is not found or deleted', function () {
    $caller = createActorForLookup('USER');
    Sanctum::actingAs($caller, ['user']);

    // Non-existent phone
    $this->getJson('/api/v1/users/lookup?phone_number=01799999999')
        ->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'User not found.');

    // Soft-deleted user
    User::factory()->create([
        'phone_number' => '01788888888',
        'is_deleted' => true,
    ]);

    $this->getJson('/api/v1/users/lookup?phone_number=01788888888')
        ->assertStatus(404)
        ->assertJsonPath('success', false);
});

test('lookup validates phone number format', function () {
    $caller = createActorForLookup('USER');
    Sanctum::actingAs($caller, ['user']);

    // Missing phone number
    $this->getJson('/api/v1/users/lookup')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phone_number']);

    // Invalid format
    $this->getJson('/api/v1/users/lookup?phone_number=12345')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phone_number']);

    $this->getJson('/api/v1/users/lookup?phone_number=01234567890')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phone_number']);
});

test('unauthenticated lookup request returns 401', function () {
    $this->getJson('/api/v1/users/lookup?phone_number=01712345678')
        ->assertStatus(401);
});

test('lookup reports can_receive based on role, wallet status, and counterparty', function () {
    $caller = createActorForLookup('USER');
    Wallet::factory()->for($caller)->create();

    // 1. Eligible recipient with active USER wallet
    $eligibleRecipient = createActorForLookup('USER');
    Wallet::factory()->for($eligibleRecipient)->create();

    Sanctum::actingAs($caller, ['user']);
    $res1 = $this->getJson("/api/v1/users/lookup?phone_number={$eligibleRecipient->phone_number}")->assertOk();
    expect($res1->json('data.can_receive'))->toBeTrue();

    // 2. Recipient with blocked USER wallet
    $blockedRecipient = createActorForLookup('USER');
    Wallet::factory()->for($blockedRecipient)->blocked()->create();
    $res2 = $this->getJson("/api/v1/users/lookup?phone_number={$blockedRecipient->phone_number}")->assertOk();
    expect($res2->json('data.can_receive'))->toBeFalse();

    // 3. Recipient without USER role (AGENT only with agent wallet)
    $agentRecipient = createActorForLookup('AGENT');
    Wallet::factory()->agent()->for($agentRecipient)->create();
    $res3 = $this->getJson("/api/v1/users/lookup?phone_number={$agentRecipient->phone_number}")->assertOk();
    expect($res3->json('data.can_receive'))->toBeFalse();

    // 4. Looking up caller themselves
    $res4 = $this->getJson("/api/v1/users/lookup?phone_number={$caller->phone_number}")->assertOk();
    expect($res4->json('data.can_receive'))->toBeFalse();
});
