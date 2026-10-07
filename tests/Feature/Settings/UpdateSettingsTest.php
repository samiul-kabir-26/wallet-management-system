<?php

use App\Models\AgentInfo;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Modules\SystemSettings\Services\SystemSettingService;
use Modules\Transactions\Services\FeeCalculator;
use Modules\Users\Services\UserService;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SystemSettingsSeeder::class);
    Cache::flush();
});

function createActorForUpdateSettings(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

test('admin can update settings using snake_case parameter names', function () {
    $admin = createActorForUpdateSettings('ADMIN');

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson('/api/v1/system-settings', [
        'system_fee_rate' => 0.07,
        'agent_commission_rate' => 0.015,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Settings updated successfully')
        ->assertJsonPath('data.settings.system_fee_rate', 0.07)
        ->assertJsonPath('data.settings.agent_commission_rate', 0.015);

    // Verify DB update
    expect((float) SystemSetting::where('key', 'system_fee_rate')->value('value'))->toBe(0.07)
        ->and((float) SystemSetting::where('key', 'agent_commission_rate')->value('value'))->toBe(0.015);
});

test('admin can update settings using camelCase parameter names', function () {
    $admin = createActorForUpdateSettings('ADMIN');

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson('/api/v1/system-settings', [
        'transactionFee' => 0.03,
        'agentCommission' => 0.008,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.settings.system_fee_rate', 0.03)
        ->assertJsonPath('data.settings.agent_commission_rate', 0.008);

    expect((float) SystemSetting::where('key', 'system_fee_rate')->value('value'))->toBe(0.03)
        ->and((float) SystemSetting::where('key', 'agent_commission_rate')->value('value'))->toBe(0.008);
});

test('updating settings records audit trail of updating admin in updated_by column', function () {
    $admin = createActorForUpdateSettings('ADMIN');

    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson('/api/v1/system-settings', [
        'system_fee_rate' => 0.06,
    ])->assertStatus(200);

    $feeSetting = SystemSetting::where('key', 'system_fee_rate')->firstOrFail();
    expect($feeSetting->updated_by)->toBe($admin->id)
        ->and($feeSetting->updatedByUser->id)->toBe($admin->id);
});

test('updating settings invalidates cache and updates subsequent fee calculations', function () {
    $admin = createActorForUpdateSettings('ADMIN');

    // Pre-populate cache by reading
    app(SystemSettingService::class)->getSettings();
    expect(Cache::has(SystemSettingService::CACHE_KEY))->toBeTrue();

    // Verify fee calculator with initial setting (5%)
    $feeCalculator = app(FeeCalculator::class);
    $initialFee = $feeCalculator->calculate('1000.00');
    expect($initialFee['amount'])->toBe('50.00')
        ->and($initialFee['rate'])->toBe('0.0500');

    // Update fee to 8%
    Sanctum::actingAs($admin, ['admin']);
    $this->patchJson('/api/v1/system-settings', [
        'system_fee_rate' => 0.08,
    ])->assertStatus(200);

    // Fee calculator immediately reflects 8%
    $newFee = $feeCalculator->calculate('1000.00');
    expect($newFee['amount'])->toBe('80.00')
        ->and($newFee['rate'])->toBe('0.0800');
});

test('non-admin user and agent are forbidden from updating settings', function () {
    $user = createActorForUpdateSettings('USER');
    $agent = createActorForUpdateSettings('AGENT');

    Sanctum::actingAs($user, ['user']);
    $this->patchJson('/api/v1/system-settings', [
        'system_fee_rate' => 0.02,
    ])->assertStatus(403);

    Sanctum::actingAs($agent, ['agent']);
    $this->patchJson('/api/v1/system-settings', [
        'system_fee_rate' => 0.02,
    ])->assertStatus(403);
});

test('admin without admin token ability is forbidden from updating settings', function () {
    $admin = createActorForUpdateSettings('ADMIN');

    Sanctum::actingAs($admin, ['user']);
    $this->patchJson('/api/v1/system-settings', [
        'system_fee_rate' => 0.02,
    ])->assertStatus(403);
});

test('validation rejects rates below 0 or above 1', function () {
    $admin = createActorForUpdateSettings('ADMIN');

    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson('/api/v1/system-settings', [
        'system_fee_rate' => -0.05,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['system_fee_rate']);

    $this->patchJson('/api/v1/system-settings', [
        'system_fee_rate' => 1.5,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['system_fee_rate']);
});

test('validation requires at least one setting to be provided', function () {
    $admin = createActorForUpdateSettings('ADMIN');

    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson('/api/v1/system-settings', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings']);
});

test('updating agent_commission_rate updates default rate for newly approved agents without mutating existing agents', function () {
    $admin = createActorForUpdateSettings('ADMIN');

    // 1. Approve Agent 1 under initial default setting (1%)
    $agent1 = createActorForUpdateSettings('AGENT');
    AgentInfo::create([
        'user_id' => $agent1->id,
        'status' => 'PENDING',
        'commission_rate' => 0.0000,
    ]);

    Sanctum::actingAs($admin, ['admin']);
    $userService = app(UserService::class);
    $userService->approveAgent($agent1, null, $admin);

    $agent1->refresh();
    expect((float) $agent1->agentInfo->commission_rate)->toBe(0.01);

    // 2. Update agent_commission_rate setting to 2.5%
    $this->patchJson('/api/v1/system-settings', [
        'agent_commission_rate' => 0.025,
    ])->assertStatus(200);

    // 3. Approve Agent 2 under new default setting (2.5%)
    $agent2 = createActorForUpdateSettings('AGENT');
    AgentInfo::create([
        'user_id' => $agent2->id,
        'status' => 'PENDING',
        'commission_rate' => 0.0000,
    ]);

    $userService->approveAgent($agent2, null, $admin);

    $agent2->refresh();
    $agent1->refresh();

    // Agent 2 receives 2.5%, Agent 1 retains historical 1%
    expect((float) $agent2->agentInfo->commission_rate)->toBe(0.025)
        ->and((float) $agent1->agentInfo->commission_rate)->toBe(0.01);
});
