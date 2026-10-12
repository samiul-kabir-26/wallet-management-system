<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Roles\Enums\Permission as PermissionEnum;
use Modules\Roles\Enums\RolePanel;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RolePermissionSeeder::class);
});

test('a user with a role that has wallets.block can block wallets with an admin token', function (): void {
    $role = Role::create([
        'name' => 'WALLET_OPERATOR',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);

    $perm = Permission::where('name', 'wallets.block')->firstOrFail();
    $role->permissions()->attach($perm->id);

    $user = User::factory()->create();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    Sanctum::actingAs($user, ['admin']);

    expect($user->can('wallets.block'))->toBeTrue();
});

test('a user with wallets.block permission is denied when using a user token', function (): void {
    $role = Role::create([
        'name' => 'WALLET_OPERATOR',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);

    $perm = Permission::where('name', 'wallets.block')->firstOrFail();
    $role->permissions()->attach($perm->id);

    $user = User::factory()->create();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    Sanctum::actingAs($user, ['user']);

    expect($user->can('wallets.block'))->toBeFalse();
});

test('a super admin with an admin token passes every permission check', function (): void {
    $user = User::factory()->create();
    $superRole = Role::where('name', 'SUPER_ADMIN')->firstOrFail();
    $user->roles()->attach($superRole->id, ['assigned_at' => now()]);

    Sanctum::actingAs($user, ['admin']);

    foreach (PermissionEnum::cases() as $permission) {
        expect($user->can($permission->value))->toBeTrue("Expected super admin to pass {$permission->value}");
    }
});

test('a super admin with a user token is denied admin permissions', function (): void {
    $user = User::factory()->create();
    $superRole = Role::where('name', 'SUPER_ADMIN')->firstOrFail();
    $user->roles()->attach($superRole->id, ['assigned_at' => now()]);

    Sanctum::actingAs($user, ['user']);

    expect($user->can('wallets.block'))->toBeFalse()
        ->and($user->can('users.view_any'))->toBeFalse()
        ->and($user->can('roles.manage'))->toBeFalse();
});

test('a user with multiple roles receives the union of their permissions', function (): void {
    $roleA = Role::create([
        'name' => 'ROLE_A',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);
    $roleB = Role::create([
        'name' => 'ROLE_B',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);

    $permBlock = Permission::where('name', 'wallets.block')->firstOrFail();
    $permUnblock = Permission::where('name', 'wallets.unblock')->firstOrFail();

    $roleA->permissions()->attach($permBlock->id);
    $roleB->permissions()->attach($permUnblock->id);

    $user = User::factory()->create();
    $user->roles()->attach([$roleA->id, $roleB->id], ['assigned_at' => now()]);

    Sanctum::actingAs($user, ['admin']);

    expect($user->can('wallets.block'))->toBeTrue()
        ->and($user->can('wallets.unblock'))->toBeTrue()
        ->and($user->can('settings.update'))->toBeFalse();
});

test('a user with no admin-panel role is denied all permissions', function (): void {
    $user = User::factory()->create();
    $userRole = Role::where('name', 'USER')->firstOrFail();
    $user->roles()->attach($userRole->id, ['assigned_at' => now()]);

    Sanctum::actingAs($user, ['user']);

    expect($user->can('wallets.block'))->toBeFalse()
        ->and($user->can('users.view_any'))->toBeFalse();
});

test('permissions are queried once per request and memoized on User model', function (): void {
    $role = Role::create([
        'name' => 'ROLE_MEMO',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);
    $perm = Permission::where('name', 'wallets.block')->firstOrFail();
    $role->permissions()->attach($perm->id);

    $user = User::factory()->create();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    Sanctum::actingAs($user, ['admin']);

    DB::flushQueryLog();
    DB::enableQueryLog();

    // First check triggers the permission resolution query
    $firstCheck = $user->can('wallets.block');
    $queryCountAfterFirst = count(DB::getQueryLog());

    // Subsequent checks must be memoized and execute ZERO additional database queries
    $secondCheck = $user->can('wallets.block');
    $thirdCheck = $user->can('wallets.unblock');
    $queryCountAfterSubsequent = count(DB::getQueryLog());

    expect($firstCheck)->toBeTrue()
        ->and($secondCheck)->toBeTrue()
        ->and($thirdCheck)->toBeFalse()
        ->and($queryCountAfterSubsequent)->toBe($queryCountAfterFirst);
});
