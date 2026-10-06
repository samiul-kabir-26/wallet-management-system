<?php

use App\Models\AgentInfo;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/**
 * Helper to create an actor with an attached role.
 */
function createActorForAgentSuspension(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

/**
 * Helper to create an agent with a specific agent_info status.
 */
function createAgentForSuspension(string $status = 'APPROVED'): User
{
    $agent = createActorForAgentSuspension('AGENT');
    $agent->agentInfo()->create([
        'status' => $status,
        'commission_rate' => 0.0100,
        'approved_at' => now(),
    ]);

    return $agent;
}

test('ADMIN can suspend an APPROVED agent and sets suspended_at and suspended_by', function () {
    $admin = createActorForAgentSuspension('ADMIN');
    $agent = createAgentForSuspension('APPROVED');

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$agent->id}/suspend-agent", [
        'reason' => 'Suspicious transaction pattern detected',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Agent suspended successfully')
        ->assertJsonPath('data.agent.user_id', $agent->id)
        ->assertJsonPath('data.agent.status', 'SUSPENDED')
        ->assertJsonPath('data.agent.suspended_by', $admin->id);

    $agentInfo = AgentInfo::where('user_id', $agent->id)->firstOrFail();
    expect($agentInfo->status)->toBe('SUSPENDED')
        ->and($agentInfo->suspended_by)->toBe($admin->id)
        ->and($agentInfo->suspended_at)->not->toBeNull();
});

test('suspending an agent creates an immutable AuditLog record with action, reason, and actor', function () {
    $admin = createActorForAgentSuspension('ADMIN');
    $agent = createAgentForSuspension('APPROVED');

    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson("/api/v1/users/{$agent->id}/suspend-agent", [
        'reason' => 'Repeated policy violations',
    ])->assertOk();

    $auditLog = AuditLog::where('action', 'AGENT_SUSPENDED')
        ->where('auditable_id', $agent->id)
        ->firstOrFail();

    expect($auditLog->actor_id)->toBe($admin->id)
        ->and($auditLog->auditable_type)->toBe($agent->getMorphClass())
        ->and($auditLog->metadata['reason'])->toBe('Repeated policy violations')
        ->and($auditLog->metadata['previous_status'])->toBe('APPROVED');
});

test('suspending without a reason fails with 422', function () {
    $admin = createActorForAgentSuspension('ADMIN');
    $agent = createAgentForSuspension('APPROVED');

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$agent->id}/suspend-agent", []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

test('suspending a PENDING agent returns 409 conflict', function () {
    $admin = createActorForAgentSuspension('ADMIN');
    $agent = createAgentForSuspension('PENDING');

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$agent->id}/suspend-agent", [
        'reason' => 'Invalid attempt to suspend non-approved agent',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Agent is not currently approved.');
});

test('suspending an already SUSPENDED agent returns 409 conflict', function () {
    $admin = createActorForAgentSuspension('ADMIN');
    $agent = createAgentForSuspension('SUSPENDED');

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$agent->id}/suspend-agent", [
        'reason' => 'Re-suspension attempt',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Agent is not currently approved.');
});

test('USER or AGENT role cannot suspend an agent', function () {
    $user = createActorForAgentSuspension('USER');
    $agent = createAgentForSuspension('APPROVED');

    Sanctum::actingAs($user, ['user']);

    $response = $this->patchJson("/api/v1/users/{$agent->id}/suspend-agent", [
        'reason' => 'Unauthorized attempt',
    ]);

    $response->assertStatus(403);
});

test('unauthenticated caller cannot access suspend agent route', function () {
    $agent = createAgentForSuspension('APPROVED');

    $response = $this->patchJson("/api/v1/users/{$agent->id}/suspend-agent", [
        'reason' => 'Unauthenticated attempt',
    ]);

    $response->assertStatus(401);
});

test('suspending a user that has no agent_info record returns 404', function () {
    $admin = createActorForAgentSuspension('ADMIN');
    $plainUser = createActorForAgentSuspension('USER');

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$plainUser->id}/suspend-agent", [
        'reason' => 'Non-agent target',
    ]);

    $response->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Target user does not have an agent profile.');
});
