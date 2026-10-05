 <?php

    use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('unauthenticated caller cannot access change-password', function () {
    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'some-password',
        'new_password' => 'new-password-123',
        'new_password_confirmation' => 'new-password-123',
    ])->assertStatus(401);
});

test('caller with user or agent ability token is rejected with 403 on change-password', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user, ['user']);

    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'some-password',
        'new_password' => 'new-password-123',
        'new_password_confirmation' => 'new-password-123',
    ])->assertStatus(403);
});

test('change-password fails with 401 when current password is wrong', function () {
    $user = User::factory()->create([
        'password' => Hash::make('real-current-password'),
    ]);

    Sanctum::actingAs($user, ['password-change']);

    $response = $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'wrong-current-password',
        'new_password' => 'brand-new-password',
        'new_password_confirmation' => 'brand-new-password',
    ]);

    $response->assertStatus(401)
        ->assertExactJson([
            'success' => false,
            'message' => 'The current password provided is incorrect.',
            'errors' => [],
        ]);
});

test('change-password fails validation when new password matches current password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('my-password-123'),
    ]);

    Sanctum::actingAs($user, ['password-change']);

    $response = $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'my-password-123',
        'new_password' => 'my-password-123',
        'new_password_confirmation' => 'my-password-123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['new_password']);
});

test('change-password fails validation when confirmation does not match', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-password-123'),
    ]);

    Sanctum::actingAs($user, ['password-change']);

    $response = $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'old-password-123',
        'new_password' => 'new-password-123',
        'new_password_confirmation' => 'mismatched-confirmation',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['new_password']);
});

test('happy path with password-change token updates password, issues admin token, and revokes old token', function () {
    $user = User::factory()->create([
        'password' => Hash::make('temp-password-123'),
        'password_changed_at' => null,
    ]);
    $user->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    // Create a real PersonalAccessToken with password-change ability
    $plainTextToken = $user->createToken('accept-invite', ['password-change'])->plainTextToken;

    $response = $this->withToken($plainTextToken)->postJson('/api/v1/auth/change-password', [
        'current_password' => 'temp-password-123',
        'new_password' => 'new-secure-password-123',
        'new_password_confirmation' => 'new-secure-password-123',
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Password changed successfully.',
            'data' => [
                'abilities' => ['admin'],
            ],
        ]);

    $user->refresh();

    // 1. Password hash updated and password_changed_at set
    expect(Hash::check('new-secure-password-123', $user->password))->toBeTrue();
    expect($user->password_changed_at)->not->toBeNull();

    // 2. The old password-change token must be revoked and no longer work
    Auth::forgetGuards();

    $this->withToken($plainTextToken)
        ->postJson('/api/v1/auth/change-password', [
            'current_password' => 'new-secure-password-123',
            'new_password' => 'another-password-123',
            'new_password_confirmation' => 'another-password-123',
        ])
        ->assertStatus(401);

    // 3. The new token returned from change-password works for admin endpoints
    Auth::forgetGuards();

    $newAdminToken = $response->json('data.token');
    $this->withToken($newAdminToken)
        ->postJson('/api/v1/auth/set-pin/request-otp')
        ->assertOk();
});
