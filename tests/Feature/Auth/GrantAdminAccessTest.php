<?php

use App\Models\AuditLog;
use App\Models\OtpToken;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Modules\Roles\Enums\RolePanel;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RolePermissionSeeder::class);
});

test('non-super-admin caller cannot grant admin access without permission', function () {
    $admin = User::factory()->create();
    $admin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    $target = User::factory()->withoutPassword()->create(['is_verified' => true]);

    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'newadmin@example.com',
        'role' => 'ADMIN',
    ])->assertStatus(403);
});

test('unauthenticated caller cannot access grant-admin-access route', function () {
    $target = User::factory()->withoutPassword()->create(['is_verified' => true]);

    $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'newadmin@example.com',
        'role' => 'ADMIN',
    ])->assertStatus(401);
});

test('caller with wrong token ability is rejected with 403 on grant-admin-access', function () {
    $user = User::factory()->create();
    $target = User::factory()->withoutPassword()->create(['is_verified' => true]);

    Sanctum::actingAs($user, ['user']);

    $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'newadmin@example.com',
        'role' => 'ADMIN',
    ])->assertStatus(403);
});

test('grant-admin-access fails with 422 when target user is not phone-verified', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach(Role::where('name', 'SUPER_ADMIN')->first()->id, ['assigned_at' => now()]);

    $target = User::factory()->withoutPassword()->unverified()->create();

    Sanctum::actingAs($superAdmin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'newadmin@example.com',
        'role' => 'ADMIN',
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
    $target = User::factory()->withoutPassword()->create(['is_verified' => true]);

    Sanctum::actingAs($superAdmin, ['admin']);

    // Duplicate email
    $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'existing@example.com',
        'role' => 'ADMIN',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    // Malformed email
    $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'not-an-email',
        'role' => 'ADMIN',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('grant-admin-access fails validation when role is missing, non-existent, or not admin-panel', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach(Role::where('name', 'SUPER_ADMIN')->first()->id, ['assigned_at' => now()]);
    $target = User::factory()->withoutPassword()->create(['is_verified' => true]);

    Sanctum::actingAs($superAdmin, ['admin']);

    // Missing role
    $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'staff1@example.com',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['role']);

    // Non-existent role
    $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'staff2@example.com',
        'role' => 'NON_EXISTENT_ROLE',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['role']);

    // Customer role (USER panel != admin)
    $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'staff3@example.com',
        'role' => 'USER',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['role']);
});

test('super admin can grant admin access and records audit log', function () {
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
        'role' => 'ADMIN',
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

    // Check AuditLog
    $audit = AuditLog::where('action', 'user.admin_access_granted')
        ->where('auditable_id', $target->id)
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->actor_id)->toBe($superAdmin->id);
    expect($audit->metadata['role'])->toBe('ADMIN');
    expect($audit->metadata['email'])->toBe('newadmin@example.com');
    expect($audit->metadata['method'])->toBe('invite');
});

test('non-super admin holding grant_admin_access cannot escalate by assigning ADMIN role', function () {
    // Custom staff role with users.grant_admin_access
    $managerRole = Role::create([
        'name' => 'INVITE_MANAGER',
        'description' => 'Can grant invites',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);
    $grantPerm = Permission::where('name', 'users.grant_admin_access')->firstOrFail();
    $managerRole->permissions()->attach($grantPerm->id);

    $manager = User::factory()->create();
    $manager->roles()->attach($managerRole->id, ['assigned_at' => now()]);

    $target = User::factory()->withoutPassword()->create(['is_verified' => true]);

    Sanctum::actingAs($manager, ['admin']);

    // Attempting to grant ADMIN role must fail anti-escalation (R5)
    $response = $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'escalate@example.com',
        'role' => 'ADMIN',
    ]);

    $response->assertStatus(403);
    expect($target->fresh()->hasRole('ADMIN'))->toBeFalse();
});

test('non-super admin cannot call grant-admin-access even if role has users.grant_admin_access attached', function () {
    $grantPerm = Permission::where('name', 'users.grant_admin_access')->firstOrFail();
    $viewPerm = Permission::where('name', 'users.view')->firstOrFail();

    $managerRole = Role::create([
        'name' => 'SUPERVISOR',
        'description' => 'Cannot grant invites',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);
    $managerRole->permissions()->attach([$grantPerm->id, $viewPerm->id]);

    $manager = User::factory()->create();
    $manager->roles()->attach($managerRole->id, ['assigned_at' => now()]);

    $target = User::factory()->withoutPassword()->create(['is_verified' => true]);

    Sanctum::actingAs($manager, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'subordinate@example.com',
        'role' => 'ADMIN',
    ]);

    $response->assertStatus(403);
    expect($target->fresh()->hasRole('ADMIN'))->toBeFalse();
});

test('super admin with pending password change is blocked from granting admin access with 403', function () {
    $superAdmin = User::factory()->pendingPasswordChange()->create();
    $superAdmin->roles()->attach(Role::where('name', 'SUPER_ADMIN')->first()->id, ['assigned_at' => now()]);

    $target = User::factory()->withoutPassword()->create(['is_verified' => true]);

    Sanctum::actingAs($superAdmin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$target->id}/grant-admin-access", [
        'email' => 'newadmin@example.com',
        'role' => 'ADMIN',
    ]);

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'You must change your password before proceeding.',
            'errors' => [],
        ]);
});

test('grant targeting an existing ADMIN or SUPER_ADMIN fails with 409 and does not alter credentials', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach(Role::where('name', 'SUPER_ADMIN')->first()->id, ['assigned_at' => now()]);

    $existingAdmin = User::factory()->create([
        'email' => 'original_admin@example.com',
        'password' => Hash::make('SecretAdminPass123!'),
    ]);
    $existingAdmin->roles()->attach(Role::where('name', 'ADMIN')->first()->id, ['assigned_at' => now()]);

    Sanctum::actingAs($superAdmin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$existingAdmin->id}/grant-admin-access", [
        'email' => 'attacker_takeover@example.com',
        'role' => 'ADMIN',
    ]);

    $response->assertStatus(409)
        ->assertJson([
            'success' => false,
            'message' => 'This user already has staff access; use role assignment instead.',
        ]);

    $existingAdmin->refresh();
    expect($existingAdmin->email)->toBe('original_admin@example.com')
        ->and(Hash::check('SecretAdminPass123!', $existingAdmin->password))->toBeTrue();
});

test('grant targeting a user who already has a password but no staff role fails with 409', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach(Role::where('name', 'SUPER_ADMIN')->first()->id, ['assigned_at' => now()]);

    // Customer with existing password (e.g. former staff member whose staff role was unassigned)
    $targetWithPassword = User::factory()->create([
        'email' => 'former_staff@example.com',
        'password' => Hash::make('FormerPass123!'),
        'is_verified' => true,
    ]);

    Sanctum::actingAs($superAdmin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$targetWithPassword->id}/grant-admin-access", [
        'email' => 'new_staff@example.com',
        'role' => 'ADMIN',
    ]);

    $response->assertStatus(409)
        ->assertJson([
            'success' => false,
            'message' => 'This user already has staff access; use role assignment instead.',
        ]);

    $targetWithPassword->refresh();
    expect($targetWithPassword->email)->toBe('former_staff@example.com')
        ->and(Hash::check('FormerPass123!', $targetWithPassword->password))->toBeTrue()
        ->and($targetWithPassword->hasRole('ADMIN'))->toBeFalse();
});

test('grant where caller is target fails with 403', function () {
    $superAdmin = User::factory()->withoutPassword()->create(['is_verified' => true]);
    $superAdmin->roles()->attach(Role::where('name', 'SUPER_ADMIN')->first()->id, ['assigned_at' => now()]);

    Sanctum::actingAs($superAdmin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$superAdmin->id}/grant-admin-access", [
        'email' => 'self_grant@example.com',
        'role' => 'ADMIN',
    ]);

    $response->assertStatus(403);
});

test('attack chain blocked: admin grant on verified customer cannot overwrite customer PIN or hijack wallet', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach(Role::where('name', 'SUPER_ADMIN')->first()->id, ['assigned_at' => now()]);

    // Verified customer with established PIN and active wallet
    $customerOriginalPin = '78901';
    $customer = User::factory()->withoutPassword()->create([
        'phone_number' => '01799887766',
        'pin' => Hash::make($customerOriginalPin),
        'is_verified' => true,
    ]);
    $customer->roles()->attach(Role::where('name', 'USER')->first()->id, ['assigned_at' => now()]);

    // 1. Attacker (SUPER_ADMIN or staff) grants admin access pointing to attacker-controlled email
    Sanctum::actingAs($superAdmin, ['admin']);

    $capturedInvite = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$capturedInvite) {
        if (isset($event->context['url'], $event->context['temp_password'])) {
            $capturedInvite = $event->context;
        }
    });

    $attackerEmail = 'attacker@malicious.local';
    $this->patchJson("/api/v1/users/{$customer->id}/grant-admin-access", [
        'email' => $attackerEmail,
        'role' => 'ADMIN',
    ])->assertOk();

    expect($capturedInvite)->not->toBeEmpty();
    $tempPassword = $capturedInvite['temp_password'];
    $inviteUrl = $capturedInvite['url'];

    // 2. Attacker accepts invite via signed link
    Auth::forgetGuards();
    $acceptResponse = $this->postJson($inviteUrl, [
        'password' => $tempPassword,
    ])->assertOk();
    $changeToken = $acceptResponse->json('data.token');

    // 3. Attacker changes password to establish admin session
    Auth::forgetGuards();
    $newAdminPassword = 'AttackerNewPassword123!';
    $changeResponse = $this->withToken($changeToken)->postJson('/api/v1/auth/change-password', [
        'current_password' => $tempPassword,
        'new_password' => $newAdminPassword,
        'new_password_confirmation' => $newAdminPassword,
    ])->assertOk();
    $attackerAdminToken = $changeResponse->json('data.token');

    // 4. Attacker attempts to use Case A to overwrite the customer's PIN
    // Step 4a: requestOtp MUST be rejected with HTTP 409
    Auth::forgetGuards();
    $this->withToken($attackerAdminToken)
        ->postJson('/api/v1/auth/set-pin/request-otp')
        ->assertStatus(409)
        ->assertJson([
            'success' => false,
            'message' => 'A PIN is already set on this account. Use staff-assisted PIN recovery to reset your PIN.',
        ]);

    // Step 4b: direct set-pin MUST also be rejected with HTTP 409 even if an OTP token exists
    $forgedOtp = OtpToken::create([
        'user_id' => $customer->id,
        'otp_code' => '998877',
        'purpose' => 'SET_PIN',
        'expires_at' => now()->addMinutes(5),
    ]);

    $this->withToken($attackerAdminToken)
        ->postJson('/api/v1/auth/set-pin', [
            'otp_code' => '998877',
            'pin' => '99999',
            'pin_confirmation' => '99999',
        ])
        ->assertStatus(409)
        ->assertJson([
            'success' => false,
            'message' => 'A PIN is already set on this account. Use staff-assisted PIN recovery to reset your PIN.',
        ]);

    // 5. Customer wallet credential integrity: customer still logs in with their original PIN!
    Auth::forgetGuards();
    $customerLogin = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => $customer->phone_number,
        'pin' => $customerOriginalPin,
    ]);
    $customerLogin->assertOk()
        ->assertJsonPath('data.user.phone_number', $customer->phone_number);

    // Attacker cannot log in as customer with new PIN
    $attackerCustomerLogin = $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => $customer->phone_number,
        'pin' => '99999',
    ]);
    $attackerCustomerLogin->assertStatus(401);
});
