<?php

use App\Models\AgentInfo;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seedRolesAndPermissions();
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

test('omitting commission_rate uses dynamic agent_commission_rate setting and freezes historical rate', function () {
    $admin = createActorForAgentApproval('ADMIN');
    $agent1 = createAgentWithStatus('PENDING');

    SystemSetting::updateOrCreate(['key' => 'agent_commission_rate'], ['value' => 0.03]);
    Cache::flush();

    Sanctum::actingAs($admin, ['admin']);
    $this->patchJson("/api/v1/users/{$agent1->id}/approve-agent", [])->assertOk();

    $agent1Info = AgentInfo::where('user_id', $agent1->id)->firstOrFail();
    expect((float) $agent1Info->commission_rate)->toBe(0.03);

    // Update global setting to 0.05
    SystemSetting::updateOrCreate(['key' => 'agent_commission_rate'], ['value' => 0.05]);
    Cache::flush();

    $agent2 = createAgentWithStatus('PENDING');
    $this->patchJson("/api/v1/users/{$agent2->id}/approve-agent", [])->assertOk();

    $agent1Info->refresh();
    $agent2Info = AgentInfo::where('user_id', $agent2->id)->firstOrFail();

    expect((float) $agent1Info->commission_rate)->toBe(0.03)
        ->and((float) $agent2Info->commission_rate)->toBe(0.05);
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

test('approving an already APPROVED agent returns 409 conflict and preserves approved_at and approved_by', function () {
    $admin = createActorForAgentApproval('ADMIN');
    $originalApprover = createActorForAgentApproval('ADMIN');
    $approvedAt = now()->subDays(2)->startOfSecond();

    $agent = createActorForAgentApproval('AGENT');
    $agent->agentInfo()->create([
        'status' => 'APPROVED',
        'commission_rate' => 0.0150,
        'approved_by' => $originalApprover->id,
        'approved_at' => $approvedAt,
    ]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$agent->id}/approve-agent", [
        'commission_rate' => 0.03,
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Agent is not pending approval.');

    $agentInfo = AgentInfo::where('user_id', $agent->id)->firstOrFail();
    expect($agentInfo->status)->toBe('APPROVED')
        ->and($agentInfo->approved_by)->toBe($originalApprover->id)
        ->and($agentInfo->approved_at->timestamp)->toBe($approvedAt->timestamp)
        ->and((float) $agentInfo->commission_rate)->toBe(0.0150);
});

test('list users endpoint with role=AGENT includes agent_info and reflects APPROVED status', function () {
    $admin = createActorForAgentApproval('ADMIN');
    $approvedAgent = createAgentWithStatus('APPROVED');
    $pendingAgent = createAgentWithStatus('PENDING');

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->getJson('/api/v1/users/all-users?role=AGENT');

    $response->assertOk()
        ->assertJsonPath('success', true);

    $items = collect($response->json('data.items'));
    expect($items)->not->toBeEmpty();

    foreach ($items as $item) {
        expect($item)->toHaveKey('agent_info')
            ->and($item['agent_info'])->not->toBeNull()
            ->and($item['agent_info'])->toHaveKey('status');
    }

    $foundApproved = $items->firstWhere('id', $approvedAgent->id);
    expect($foundApproved['agent_info']['status'])->toBe('APPROVED');

    $foundPending = $items->firstWhere('id', $pendingAgent->id);
    expect($foundPending['agent_info']['status'])->toBe('PENDING');
});

test('approve-then-list round trip updates agent status to APPROVED with commission rate in list endpoint', function () {
    $admin = createActorForAgentApproval('ADMIN');
    $agent = createAgentWithStatus('PENDING');

    Sanctum::actingAs($admin, ['admin']);

    // 1. Initial list shows agent is PENDING
    $initialList = $this->getJson('/api/v1/users/all-users?role=AGENT');
    $initialList->assertOk();
    $initialAgentItem = collect($initialList->json('data.items'))->firstWhere('id', $agent->id);
    expect($initialAgentItem['agent_info']['status'])->toBe('PENDING');

    // 2. Approve agent with 2.5% commission rate
    $approveRes = $this->patchJson("/api/v1/users/{$agent->id}/approve-agent", [
        'commission_rate' => 0.025,
    ]);
    $approveRes->assertOk();

    // 3. List endpoint reflects APPROVED status and commission rate
    $afterList = $this->getJson('/api/v1/users/all-users?role=AGENT');
    $afterList->assertOk();
    $afterAgentItem = collect($afterList->json('data.items'))->firstWhere('id', $agent->id);

    expect($afterAgentItem['agent_info']['status'])->toBe('APPROVED')
        ->and((float) $afterAgentItem['agent_info']['commission_rate'])->toBe(0.025);
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

test('ADMIN cannot approve their own agent profile', function () {
    $admin = createActorForAgentApproval('ADMIN');
    $agentRole = Role::where('name', 'AGENT')->firstOrFail();
    $admin->roles()->attach($agentRole->id, ['assigned_at' => now()]);
    $admin->agentInfo()->create([
        'status' => 'PENDING',
        'commission_rate' => 0.0100,
    ]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->patchJson("/api/v1/users/{$admin->id}/approve-agent", []);

    $response->assertStatus(403);
});
