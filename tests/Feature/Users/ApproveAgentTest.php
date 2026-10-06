<?php

use App\Models\AgentInfo;
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
function createActorForAgentApproval(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

/**
 * Helper to create an agent with a specific agent_info status.
 */
function createAgentWithStatus(string $status = 'PENDING'): User
{
    $agent = createActorForAgentApproval('AGENT');
    $agent->agentInfo()->create([
        'status' => $status,
        'commission_rate' => 0.0100,
    ]);

    return $agent;
}

test('ADMIN can approve a PENDING agent with custom commission rate', function () {
    $admin = createActorForAgentApproval('ADMIN');
    $agent = createAgentWithStatus('PENDING');

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$agent->id}/approve-agent", [
        'commission_rate' => 0.025,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Agent approved successfully')
        ->assertJsonPath('data.agent.user_id', $agent->id)
        ->assertJsonPath('data.agent.status', 'APPROVED')
        ->assertJsonPath('data.agent.commission_rate', 0.025)
        ->assertJsonPath('data.agent.approved_by', $admin->id);

    $agentInfo = AgentInfo::where('user_id', $agent->id)->firstOrFail();
    expect($agentInfo->status)->toBe('APPROVED')
        ->and((float) $agentInfo->commission_rate)->toBe(0.0250)
        ->and($agentInfo->approved_by)->toBe($admin->id)
        ->and($agentInfo->approved_at)->not->toBeNull();
});

test('omitting commission_rate defaults to 0.01', function () {
    $admin = createActorForAgentApproval('ADMIN');
    $agent = createAgentWithStatus('PENDING');

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$agent->id}/approve-agent", []);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.agent.commission_rate', 0.01);

    $agentInfo = AgentInfo::where('user_id', $agent->id)->firstOrFail();
    expect((float) $agentInfo->commission_rate)->toBe(0.0100);
});

test('commission_rate greater than 1 fails validation with 422', function () {
    $admin = createActorForAgentApproval('ADMIN');
    $agent = createAgentWithStatus('PENDING');

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$agent->id}/approve-agent", [
        'commission_rate' => 1.5,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['commission_rate']);
});

test('approving an already APPROVED agent returns 409 conflict', function () {
    $admin = createActorForAgentApproval('ADMIN');
    $agent = createAgentWithStatus('APPROVED');

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$agent->id}/approve-agent", []);

    $response->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Agent is not pending approval.');
});

test('USER or AGENT role cannot approve an agent', function () {
    $user = createActorForAgentApproval('USER');
    $agent = createAgentWithStatus('PENDING');

    Sanctum::actingAs($user, ['user']);

    $response = $this->patchJson("/api/v1/users/{$agent->id}/approve-agent", []);

    $response->assertStatus(403);
});

test('unauthenticated caller cannot access approve agent route', function () {
    $agent = createAgentWithStatus('PENDING');

    $response = $this->patchJson("/api/v1/users/{$agent->id}/approve-agent", []);

    $response->assertStatus(401);
});

test('approving a user that has no agent_info record returns 404', function () {
    $admin = createActorForAgentApproval('ADMIN');
    $plainUser = createActorForAgentApproval('USER');

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$plainUser->id}/approve-agent", []);

    $response->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Target user does not have an agent profile.');
});
