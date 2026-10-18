<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function createActorForCommission(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

function createAgentForCommission(string $status = 'APPROVED', float $rate = 0.0100): User
{
    $agent = createActorForCommission('AGENT');
    $agent->agentInfo()->create([
        'status' => $status,
        'commission_rate' => $rate,
        'approved_at' => $status === 'PENDING' ? null : now(),
    ]);

    return $agent;
}

test('ADMIN can change an approved agent commission rate and the change is audited', function () {
    $admin = createActorForCommission('ADMIN');
    $agent = createAgentForCommission();
    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson("/api/v1/users/{$agent->id}/commission-rate", ['commission_rate' => 0.0125])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.agent.commission_rate', 0.0125);

    expect($agent->agentInfo()->value('commission_rate'))->toBe('0.0125');

    $audit = AuditLog::where('action', 'AGENT_COMMISSION_UPDATED')->sole();
    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->auditable_id)->toBe($agent->id)
        ->and($audit->metadata)->toEqual(['previous_rate' => '0.0100', 'new_rate' => '0.0125']);
});

test('a suspended agent commission rate can be changed', function () {
    $admin = createActorForCommission('ADMIN');
    $agent = createAgentForCommission('SUSPENDED');
    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson("/api/v1/users/{$agent->id}/commission-rate", ['commission_rate' => 0.02])->assertOk();

    expect($agent->agentInfo()->value('commission_rate'))->toBe('0.0200');
});

test('a pending agent commission rate cannot be changed before approval', function () {
    $admin = createActorForCommission('ADMIN');
    $agent = createAgentForCommission('PENDING');
    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson("/api/v1/users/{$agent->id}/commission-rate", ['commission_rate' => 0.02])->assertStatus(409);

    expect(AuditLog::where('action', 'AGENT_COMMISSION_UPDATED')->exists())->toBeFalse();
});

test('setting the same rate writes no audit entry', function () {
    $admin = createActorForCommission('ADMIN');
    $agent = createAgentForCommission(rate: 0.0150);
    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson("/api/v1/users/{$agent->id}/commission-rate", ['commission_rate' => 0.015])->assertOk();

    expect(AuditLog::where('action', 'AGENT_COMMISSION_UPDATED')->exists())->toBeFalse();
});

test('a non-agent account returns 404', function () {
    $admin = createActorForCommission('ADMIN');
    $user = createActorForCommission('USER');
    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson("/api/v1/users/{$user->id}/commission-rate", ['commission_rate' => 0.02])
        ->assertNotFound();
});

test('commission rate is validated', function (mixed $rate) {
    $admin = createActorForCommission('ADMIN');
    $agent = createAgentForCommission();
    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson("/api/v1/users/{$agent->id}/commission-rate", ['commission_rate' => $rate])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('commission_rate');
})->with([
    'missing' => null,
    'negative' => -0.01,
    'above one' => 1.01,
    'too many decimals' => 0.01255,
    'not a number' => 'one percent',
]);

test('staff without agents.update_commission are forbidden', function () {
    $staff = User::factory()->create();
    $role = Role::create(['name' => 'AUDITOR', 'panel' => 'admin', 'is_system' => false]);
    $staff->roles()->attach($role->id, ['assigned_at' => now()]);
    $agent = createAgentForCommission();
    Sanctum::actingAs($staff, ['admin']);

    $this->patchJson("/api/v1/users/{$agent->id}/commission-rate", ['commission_rate' => 0.02])->assertForbidden();
});

test('an agent PIN token cannot change commission even when the account also holds ADMIN', function () {
    $agent = createAgentForCommission();
    $agent->roles()->attach(Role::where('name', 'ADMIN')->firstOrFail()->id, ['assigned_at' => now()]);
    $otherAgent = createAgentForCommission();
    Sanctum::actingAs($agent, ['agent']);

    $this->patchJson("/api/v1/users/{$otherAgent->id}/commission-rate", ['commission_rate' => 0.5])->assertForbidden();
});

test('staff cannot change their own commission rate', function () {
    $admin = createAgentForCommission();
    $admin->roles()->attach(Role::where('name', 'ADMIN')->firstOrFail()->id, ['assigned_at' => now()]);
    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson("/api/v1/users/{$admin->id}/commission-rate", ['commission_rate' => 0.5])->assertForbidden();

    expect($admin->agentInfo()->value('commission_rate'))->toBe('0.0100');
});
