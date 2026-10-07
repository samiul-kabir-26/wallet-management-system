<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Modules\SystemSettings\Services\SystemSettingService;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SystemSettingsSeeder::class);
    Cache::flush();
});

function createActorForGetSettings(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

test('any authenticated user (user, agent, admin) can view system settings', function () {
    $user = createActorForGetSettings('USER');
    $agent = createActorForGetSettings('AGENT');
    $admin = createActorForGetSettings('ADMIN');

    // USER can view
    Sanctum::actingAs($user, ['user']);
    $response = $this->getJson('/api/v1/system-settings');
    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.settings.system_fee_rate', 0.05)
        ->assertJsonPath('data.settings.agent_commission_rate', 0.01);

    // AGENT can view
    Sanctum::actingAs($agent, ['agent']);
    $this->getJson('/api/v1/system-settings')
        ->assertStatus(200)
        ->assertJsonPath('data.settings.system_fee_rate', 0.05);

    // ADMIN can view
    Sanctum::actingAs($admin, ['admin']);
    $this->getJson('/api/v1/system-settings')
        ->assertStatus(200)
        ->assertJsonPath('data.settings.system_fee_rate', 0.05);
});

test('unauthenticated request to view settings returns 401', function () {
    $this->getJson('/api/v1/system-settings')
        ->assertStatus(401);
});

test('token without valid user, agent, or admin ability cannot view settings', function () {
    $user = createActorForGetSettings('USER');

    Sanctum::actingAs($user, ['restricted-ability']);
    $this->getJson('/api/v1/system-settings')
        ->assertStatus(403);
});

test('viewing settings populates cache', function () {
    $user = createActorForGetSettings('USER');

    expect(Cache::has(SystemSettingService::CACHE_KEY))->toBeFalse();

    Sanctum::actingAs($user, ['user']);
    $this->getJson('/api/v1/system-settings')->assertStatus(200);

    expect(Cache::has(SystemSettingService::CACHE_KEY))->toBeTrue()
        ->and(Cache::get(SystemSettingService::CACHE_KEY))->toHaveKey('system_fee_rate')
        ->and(Cache::get(SystemSettingService::CACHE_KEY)['system_fee_rate'])->toBe(0.05);
});
