<?php

use App\Models\AgentInfo;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\SystemSettingsSeeder;
use Laravel\Sanctum\Sanctum;
use Modules\Roles\Enums\Permission as PermissionEnum;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->seed(SystemSettingsSeeder::class);
});

function createActorForFloatIssue(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

function createAgentForFloatIssue(float $balance = 0.00, string $status = 'APPROVED'): User
{
    $agent = createActorForFloatIssue('AGENT');

    AgentInfo::create([
        'user_id' => $agent->id,
        'commission_rate' => 0.0100,
        'status' => $status,
        'approved_at' => now(),
    ]);

    Wallet::factory()->agent()->for($agent)->balance($balance)->create();

    return $agent;
}

test('admin with agents.issue_float permission can issue float to an approved agent by agent_id', function () {
    $admin = createActorForFloatIssue('ADMIN');
    $agent = createAgentForFloatIssue(balance: 1000.00);
    $agentWallet = Wallet::where('user_id', $agent->id)->where('kind', Wallet::KIND_AGENT)->firstOrFail();

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $agent->id,
        'amount' => '5000.00',
        'external_reference' => 'BANK-SLIP-1001',
        'idempotency_key' => 'idem-float-1',
        'description' => 'Cheque deposit branch #42',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Float issued successfully.')
        ->assertJsonPath('data.transaction.type', 'FLOAT_ISSUE')
        ->assertJsonPath('data.transaction.amount', 5000)
        ->assertJsonPath('data.transaction.external_reference', 'BANK-SLIP-1001')
        ->assertJsonPath('data.transaction.recipient_wallet_balance_after', 6000);

    // Verify agent wallet updated
    $agentWallet->refresh();
    expect((float) $agentWallet->balance)->toBe(6000.00);

    // Verify Transaction record
    $tx = Transaction::where('idempotency_key', 'idem-float-1')->firstOrFail();
    expect($tx->type)->toBe('FLOAT_ISSUE')
        ->and((float) $tx->amount)->toBe(5000.00)
        ->and($tx->initiated_by)->toBe($admin->id)
        ->and($tx->user_id)->toBe($agent->id)
        ->and($tx->agent_id)->toBe($agent->id)
        ->and($tx->external_reference)->toBe('BANK-SLIP-1001')
        ->and($tx->agent_wallet_id)->toBe($agentWallet->id)
        ->and($tx->recipient_wallet_id)->toBe($agentWallet->id)
        ->and($tx->status)->toBe('COMPLETED');

    // Verify AuditLog
    $audit = AuditLog::where('action', 'FLOAT_ISSUED')
        ->where('auditable_id', $agent->id)
        ->firstOrFail();

    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->auditable_type)->toBe($agent->getMorphClass())
        ->and($audit->metadata['external_reference'])->toBe('BANK-SLIP-1001')
        ->and($audit->metadata['amount'])->toBe('5000.00')
        ->and($audit->metadata['balance_before'])->toBe('1000.00')
        ->and($audit->metadata['balance_after'])->toBe('6000.00');
});

test('admin can issue float by specifying agent_phone', function () {
    $admin = createActorForFloatIssue('ADMIN');
    $agent = createAgentForFloatIssue(balance: 500.00);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_phone' => $agent->phone_number,
        'amount' => '1500.00',
        'external_reference' => 'BANK-SLIP-PHONE-1',
        'idempotency_key' => 'idem-float-phone-1',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.transaction.amount', 1500);

    $agentWallet = Wallet::where('user_id', $agent->id)->where('kind', Wallet::KIND_AGENT)->firstOrFail();
    expect((float) $agentWallet->balance)->toBe(2000.00);
});

test('admin without agents.issue_float permission is rejected with 403', function () {
    $admin = createActorForFloatIssue('ADMIN');
    $agent = createAgentForFloatIssue();

    // Detach AgentsIssueFloat permission from admin role
    $permission = Permission::where('name', PermissionEnum::AgentsIssueFloat->value)->firstOrFail();
    $adminRole = Role::where('name', 'ADMIN')->firstOrFail();
    $adminRole->permissions()->detach($permission->id);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $agent->id,
        'amount' => '500.00',
        'external_reference' => 'BANK-SLIP-DENIED',
        'idempotency_key' => 'idem-denied',
    ]);

    $response->assertForbidden();
});

test('non-admin user or agent is rejected with 403', function () {
    $user = createActorForFloatIssue('USER');
    $agent = createAgentForFloatIssue();

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $agent->id,
        'amount' => '500.00',
        'external_reference' => 'BANK-SLIP-DENIED-USER',
        'idempotency_key' => 'idem-denied-user',
    ]);

    $response->assertForbidden();
});

test('admin cannot issue float to themselves', function () {
    $admin = createActorForFloatIssue('ADMIN');
    // Also attach agent role and agent info to admin
    $agentRole = Role::where('name', 'AGENT')->firstOrFail();
    $admin->roles()->attach($agentRole->id, ['assigned_at' => now()]);
    AgentInfo::create([
        'user_id' => $admin->id,
        'commission_rate' => 0.0100,
        'status' => 'APPROVED',
        'approved_at' => now(),
    ]);
    Wallet::factory()->agent()->for($admin)->balance(0.00)->create();

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $admin->id,
        'amount' => '1000.00',
        'external_reference' => 'BANK-SLIP-SELF',
        'idempotency_key' => 'idem-self',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Cannot issue float to yourself.');
});

test('cannot issue float to unapproved or suspended agent', function () {
    $admin = createActorForFloatIssue('ADMIN');
    $pendingAgent = createAgentForFloatIssue(status: 'PENDING');

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $pendingAgent->id,
        'amount' => '1000.00',
        'external_reference' => 'BANK-SLIP-PENDING',
        'idempotency_key' => 'idem-pending',
    ]);

    $response->assertStatus(409);

    $suspendedAgent = createAgentForFloatIssue(status: 'SUSPENDED');

    $response2 = $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $suspendedAgent->id,
        'amount' => '1000.00',
        'external_reference' => 'BANK-SLIP-SUSPENDED',
        'idempotency_key' => 'idem-suspended',
    ]);

    $response2->assertStatus(409);
});

test('cannot issue float to a deleted agent account', function () {
    $admin = createActorForFloatIssue('ADMIN');
    $agent = createAgentForFloatIssue();
    $agent->is_deleted = true;
    $agent->save();

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $agent->id,
        'amount' => '1000.00',
        'external_reference' => 'BANK-SLIP-DELETED',
        'idempotency_key' => 'idem-deleted',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['agent_id']);
});

test('external reference must be unique across all transactions', function () {
    $admin = createActorForFloatIssue('ADMIN');
    $agent = createAgentForFloatIssue();

    Sanctum::actingAs($admin, ['admin']);

    $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $agent->id,
        'amount' => '1000.00',
        'external_reference' => 'DUPLICATE-SLIP-1',
        'idempotency_key' => 'idem-dup-1',
    ])->assertCreated();

    $secondResponse = $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $agent->id,
        'amount' => '1000.00',
        'external_reference' => 'DUPLICATE-SLIP-1',
        'idempotency_key' => 'idem-dup-2',
    ]);

    $secondResponse->assertStatus(422)
        ->assertJsonValidationErrors(['external_reference']);
});

test('amount validation bounds and exact decimal format', function () {
    $admin = createActorForFloatIssue('ADMIN');
    $agent = createAgentForFloatIssue();

    Sanctum::actingAs($admin, ['admin']);

    // Below min (< 100.00)
    $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $agent->id,
        'amount' => '50.00',
        'external_reference' => 'SLIP-BELOW-MIN',
        'idempotency_key' => 'idem-min',
    ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

    // Above max (> 500000.00)
    $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $agent->id,
        'amount' => '600000.00',
        'external_reference' => 'SLIP-ABOVE-MAX',
        'idempotency_key' => 'idem-max',
    ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

    // 3 decimal places
    $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $agent->id,
        'amount' => '500.123',
        'external_reference' => 'SLIP-DECIMAL',
        'idempotency_key' => 'idem-dec',
    ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
});

test('idempotent replay returns existing transaction without double crediting', function () {
    $admin = createActorForFloatIssue('ADMIN');
    $agent = createAgentForFloatIssue(balance: 100.00);

    Sanctum::actingAs($admin, ['admin']);

    $firstResponse = $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $agent->id,
        'amount' => '1000.00',
        'external_reference' => 'SLIP-IDEM-SAME',
        'idempotency_key' => 'same-key-1',
    ])->assertCreated();

    $firstTxId = $firstResponse->json('data.transaction.id');

    // Replay with exact same payload and key
    $secondResponse = $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $agent->id,
        'amount' => '1000.00',
        'external_reference' => 'SLIP-IDEM-SAME',
        'idempotency_key' => 'same-key-1',
    ])->assertCreated();

    expect($secondResponse->json('data.transaction.id'))->toBe($firstTxId);

    // Balance should only be 1100.00, NOT 2100.00
    $agentWallet = Wallet::where('user_id', $agent->id)->where('kind', Wallet::KIND_AGENT)->firstOrFail();
    expect((float) $agentWallet->balance)->toBe(1100.00);

    // Replay with different amount raises conflict
    $conflictResponse = $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $agent->id,
        'amount' => '2000.00',
        'external_reference' => 'SLIP-IDEM-SAME',
        'idempotency_key' => 'same-key-1',
    ]);

    $conflictResponse->assertStatus(409);
});

test('cannot issue float if agent wallet is blocked', function () {
    $admin = createActorForFloatIssue('ADMIN');
    $agent = createAgentForFloatIssue();
    $agentWallet = Wallet::where('user_id', $agent->id)->where('kind', Wallet::KIND_AGENT)->firstOrFail();
    $agentWallet->update(['is_blocked' => true]);

    Sanctum::actingAs($admin, ['admin']);

    $response = $this->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $agent->id,
        'amount' => '1000.00',
        'external_reference' => 'SLIP-BLOCKED',
        'idempotency_key' => 'idem-blocked',
    ]);

    $response->assertStatus(403);
});
