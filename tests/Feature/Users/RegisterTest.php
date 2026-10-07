<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/**
 * Helper to create an actor with an attached role.
 */
function createActorWithRole(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

test('ADMIN can register a USER', function () {
    $admin = createActorWithRole('ADMIN');
    Sanctum::actingAs($admin, ['admin']);

    $payload = [
        'name' => 'Valid User',
        'phone_number' => '01712345678',
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => 'USER',
        'address' => '123 Main St, Dhaka',
    ];

    $response = $this->postJson('/api/v1/users/register', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'User registered successfully')
        ->assertJsonPath('data.user.name', 'Valid User')
        ->assertJsonPath('data.user.phone_number', '01712345678')
        ->assertJsonPath('data.user.roles', ['USER']);

    $registeredUser = User::where('phone_number', '01712345678')->firstOrFail();
    expect($registeredUser->wallet)->not->toBeNull()
        ->and((float) $registeredUser->wallet->balance)->toBe(50.00)
        ->and($registeredUser->cap)->not->toBeNull()
        ->and((float) $registeredUser->cap->daily_cap)->toBe(10000.00)
        ->and($registeredUser->hasRole('USER'))->toBeTrue()
        ->and($registeredUser->roles->first()->pivot->assigned_by)->toBe($admin->id);
});

test('ADMIN can register an AGENT with PENDING agent_info status', function () {
    $admin = createActorWithRole('ADMIN');
    Sanctum::actingAs($admin, ['admin']);

    $payload = [
        'name' => 'Valid Agent',
        'phone_number' => '01798765432',
        'pin' => '54321',
        'pin_confirmation' => '54321',
        'role' => 'AGENT',
    ];

    $response = $this->postJson('/api/v1/users/register', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.roles', ['AGENT']);

    $agent = User::where('phone_number', '01798765432')->firstOrFail();
    expect($agent->agentInfo)->not->toBeNull()
        ->and($agent->agentInfo->status)->toBe('PENDING')
        ->and($agent->hasRole('AGENT'))->toBeTrue()
        ->and($agent->roles->first()->pivot->assigned_by)->toBe($admin->id);
});

test('ADMIN cannot register an ADMIN', function () {
    $admin = createActorWithRole('ADMIN');
    Sanctum::actingAs($admin, ['admin']);

    $payload = [
        'name' => 'Attempted Admin',
        'email' => 'attempted.admin@example.com',
        'password' => 'SecurePass123!',
        'role' => 'ADMIN',
    ];

    $response = $this->postJson('/api/v1/users/register', $payload);

    $response->assertStatus(403);
    expect(User::where('email', 'attempted.admin@example.com')->exists())->toBeFalse();
});

test('SUPER_ADMIN can register an ADMIN with email and password', function () {
    $superAdmin = createActorWithRole('SUPER_ADMIN');
    Sanctum::actingAs($superAdmin, ['admin']);

    $payload = [
        'name' => 'Legit Admin',
        'email' => 'legit.admin@example.com',
        'password' => 'SecurePass123!',
        'role' => 'ADMIN',
    ];

    $response = $this->postJson('/api/v1/users/register', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', 'legit.admin@example.com')
        ->assertJsonPath('data.user.roles', ['ADMIN']);

    $newAdmin = User::where('email', 'legit.admin@example.com')->firstOrFail();
    expect($newAdmin->phone_number)->toBeNull()
        ->and($newAdmin->pin)->toBeNull()
        ->and($newAdmin->password)->not->toBeNull()
        ->and($newAdmin->hasRole('ADMIN'))->toBeTrue()
        ->and($newAdmin->roles->first()->pivot->assigned_by)->toBe($superAdmin->id);
});

test('registration fails with 422 when email is already taken', function () {
    $superAdmin = createActorWithRole('SUPER_ADMIN');
    User::factory()->create(['email' => 'taken@example.com']);

    Sanctum::actingAs($superAdmin, ['admin']);

    $response = $this->postJson('/api/v1/users/register', [
        'name' => 'Duplicate Email',
        'email' => 'taken@example.com',
        'password' => 'SecurePass123!',
        'role' => 'ADMIN',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('registration fails with 422 when phone number is already taken', function () {
    $admin = createActorWithRole('ADMIN');
    User::factory()->create(['phone_number' => '01711223344']);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/users/register', [
        'name' => 'Duplicate Phone',
        'phone_number' => '01711223344',
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => 'USER',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['phone_number']);
});

test('registration fails with 422 when USER role provides email and password instead of phone and PIN', function () {
    $admin = createActorWithRole('ADMIN');
    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/users/register', [
        'name' => 'Wrong Track User',
        'email' => 'wrong@example.com',
        'password' => 'SecurePass123!',
        'role' => 'USER',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password', 'phone_number', 'pin']);
});

test('unauthenticated caller cannot access registration endpoint', function () {
    $response = $this->postJson('/api/v1/users/register', [
        'name' => 'Unauthenticated Attempt',
        'phone_number' => '01712345678',
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => 'USER',
    ]);

    $response->assertStatus(401);
});

test('authenticated user lacking admin role cannot access registration endpoint', function () {
    $user = createActorWithRole('USER');
    Sanctum::actingAs($user, ['admin']);

    $response = $this->postJson('/api/v1/users/register', [
        'name' => 'Unauthorized Attempt',
        'phone_number' => '01712345678',
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => 'USER',
    ]);

    $response->assertStatus(403);
});

test('PIN-issued token with user ability is rejected even if caller genuinely holds ADMIN role', function () {
    // Deliberate fixture: caller holds BOTH ADMIN and USER roles in DB
    $dualRoleUser = User::factory()->create([
        'email' => 'dual.role@example.com',
        'phone_number' => '01711223344',
        'password' => Hash::make('AdminPass123!'),
        'pin' => Hash::make('12345'),
        'password_changed_at' => now(),
    ]);

    $adminRole = Role::where('name', 'ADMIN')->firstOrFail();
    $userRole = Role::where('name', 'USER')->firstOrFail();
    $dualRoleUser->roles()->attach([
        $adminRole->id => ['assigned_at' => now()],
        $userRole->id => ['assigned_at' => now()],
    ]);

    // Token carries only 'user' ability (as issued by PIN login)
    Sanctum::actingAs($dualRoleUser, ['user']);

    $response = $this->postJson('/api/v1/users/register', [
        'name' => 'Target User',
        'phone_number' => '01712345678',
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => 'USER',
    ]);

    // Rejected by ability:admin middleware
    $response->assertStatus(403);
});
