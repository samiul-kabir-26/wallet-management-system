<?php

use App\Models\AgentInfo;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function createReviewer(string $roleName = 'ADMIN'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', $roleName)->firstOrFail()->id, ['assigned_at' => now()]);

    return $user;
}

function createAgentWithPendingWithdrawal(float $balance = 1000.00, float $amount = 400.00): array
{
    $agent = User::factory()->create();
    $agent->roles()->attach(Role::where('name', 'AGENT')->firstOrFail()->id, ['assigned_at' => now()]);
    AgentInfo::create(['user_id' => $agent->id, 'commission_rate' => 0.0100, 'status' => 'APPROVED']);
    Wallet::factory()->agent()->for($agent)->balance($balance)->create();

    Sanctum::actingAs($agent, ['agent']);
    test()->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => $amount,
        'pin' => '12345',
        'idempotency_key' => 'withdrawal-'.$agent->id,
    ])->assertCreated()->assertJsonPath('data.transaction.status', 'PENDING');

    return [$agent, Transaction::where('agent_id', $agent->id)->where('type', 'AGENT_WITHDRAWAL')->firstOrFail()];
}

function agentWalletBalance(User $agent): float
{
    return (float) Wallet::where('user_id', $agent->id)->where('kind', Wallet::KIND_AGENT)->value('balance');
}

test('a withdrawal request debits the agent wallet immediately so the funds cannot be spent twice', function () {
    [$agent, $withdrawal] = createAgentWithPendingWithdrawal(balance: 1000.00, amount: 400.00);

    expect(agentWalletBalance($agent))->toBe(600.00)
        ->and($withdrawal->status)->toBe('PENDING');
});

test('admin approves a pending withdrawal with a bank reference', function () {
    [$agent, $withdrawal] = createAgentWithPendingWithdrawal();
    $admin = createReviewer();

    Sanctum::actingAs($admin, ['admin']);
    $this->patchJson("/api/v1/transactions/admin/withdrawals/{$withdrawal->id}/approve", [
        'external_reference' => 'BANK-TRX-7781',
        'note' => 'Sent via BEFTN',
    ])->assertOk()
        ->assertJsonPath('data.transaction.status', 'COMPLETED')
        ->assertJsonPath('data.transaction.external_reference', 'BANK-TRX-7781');

    $withdrawal->refresh();
    expect($withdrawal->meta['review']['decision'])->toBe('APPROVED')
        ->and($withdrawal->meta['review']['reviewed_by'])->toBe($admin->id)
        ->and(agentWalletBalance($agent))->toBe(600.00)
        ->and(AuditLog::where('action', 'WITHDRAWAL_APPROVED')->where('auditable_id', $agent->id)->exists())->toBeTrue();
});

test('admin rejects a pending withdrawal and the held funds are returned', function () {
    [$agent, $withdrawal] = createAgentWithPendingWithdrawal(balance: 1000.00, amount: 400.00);
    $admin = createReviewer();

    Sanctum::actingAs($admin, ['admin']);
    $this->patchJson("/api/v1/transactions/admin/withdrawals/{$withdrawal->id}/reject", [
        'reason' => 'Bank account name does not match',
    ])->assertOk()->assertJsonPath('data.transaction.status', 'REJECTED');

    $withdrawal->refresh();
    expect(agentWalletBalance($agent))->toBe(1000.00)
        ->and($withdrawal->meta['review']['reason'])->toBe('Bank account name does not match')
        ->and($withdrawal->meta['review']['refund_balance_after'])->toBe('1000.00')
        ->and(AuditLog::where('action', 'WITHDRAWAL_REJECTED')->exists())->toBeTrue();
});

test('rejection refunds even when the agent wallet was blocked after the request', function () {
    [$agent, $withdrawal] = createAgentWithPendingWithdrawal(balance: 1000.00, amount: 400.00);
    Wallet::where('user_id', $agent->id)->update(['is_blocked' => true]);

    Sanctum::actingAs(createReviewer(), ['admin']);
    $this->patchJson("/api/v1/transactions/admin/withdrawals/{$withdrawal->id}/reject", [
        'reason' => 'Account under review',
    ])->assertOk();

    expect(agentWalletBalance($agent))->toBe(1000.00);
});

test('a reviewed withdrawal cannot be reviewed again', function () {
    [$agent, $withdrawal] = createAgentWithPendingWithdrawal(balance: 1000.00, amount: 400.00);

    Sanctum::actingAs(createReviewer(), ['admin']);
    $this->patchJson("/api/v1/transactions/admin/withdrawals/{$withdrawal->id}/reject", ['reason' => 'Duplicate request'])->assertOk();

    $this->patchJson("/api/v1/transactions/admin/withdrawals/{$withdrawal->id}/reject", ['reason' => 'Duplicate request'])->assertStatus(409);
    $this->patchJson("/api/v1/transactions/admin/withdrawals/{$withdrawal->id}/approve", ['external_reference' => 'BANK-1'])->assertStatus(409);

    // Refunded exactly once
    expect(agentWalletBalance($agent))->toBe(1000.00);
});

test('only agent withdrawals can be reviewed', function () {
    [, $withdrawal] = createAgentWithPendingWithdrawal();
    $withdrawal->forceFill(['type' => 'TOP_UP'])->save();

    Sanctum::actingAs(createReviewer(), ['admin']);
    $this->patchJson("/api/v1/transactions/admin/withdrawals/{$withdrawal->id}/approve", ['external_reference' => 'BANK-2'])->assertStatus(409);
});

test('approval requires a unique bank reference and rejection requires a reason', function () {
    [, $first] = createAgentWithPendingWithdrawal();
    [, $second] = createAgentWithPendingWithdrawal();

    Sanctum::actingAs(createReviewer(), ['admin']);
    $this->patchJson("/api/v1/transactions/admin/withdrawals/{$first->id}/approve", ['external_reference' => 'USED-REF'])->assertOk();

    $this->patchJson("/api/v1/transactions/admin/withdrawals/{$second->id}/approve", [])->assertJsonValidationErrors('external_reference');
    $this->patchJson("/api/v1/transactions/admin/withdrawals/{$second->id}/approve", ['external_reference' => 'USED-REF'])
        ->assertJsonValidationErrors('external_reference');
    $this->patchJson("/api/v1/transactions/admin/withdrawals/{$second->id}/reject", [])->assertJsonValidationErrors('reason');
});

test('reviewing requires agents.approve_withdrawal and an admin token', function () {
    [$agent, $withdrawal] = createAgentWithPendingWithdrawal();
    $custom = Role::create(['name' => 'AUDITOR', 'panel' => 'admin', 'is_system' => false]);
    $auditor = User::factory()->create();
    $auditor->roles()->attach($custom->id, ['assigned_at' => now()]);

    Sanctum::actingAs($auditor, ['admin']);
    $this->patchJson("/api/v1/transactions/admin/withdrawals/{$withdrawal->id}/reject", ['reason' => 'Not allowed'])->assertForbidden();

    Sanctum::actingAs($agent, ['agent']);
    $this->patchJson("/api/v1/transactions/admin/withdrawals/{$withdrawal->id}/reject", ['reason' => 'Not allowed'])->assertForbidden();

    expect($withdrawal->refresh()->status)->toBe('PENDING');
});

test('a staff member who is also the agent cannot approve their own withdrawal', function () {
    [$agent, $withdrawal] = createAgentWithPendingWithdrawal();
    $agent->roles()->attach(Role::where('name', 'SUPER_ADMIN')->firstOrFail()->id, ['assigned_at' => now()]);

    Sanctum::actingAs($agent, ['admin']);
    $this->patchJson("/api/v1/transactions/admin/withdrawals/{$withdrawal->id}/approve", ['external_reference' => 'SELF-1'])->assertForbidden();
});

test('pending withdrawals can be listed for review', function () {
    createAgentWithPendingWithdrawal();

    Sanctum::actingAs(createReviewer(), ['admin']);
    $this->getJson('/api/v1/transactions/admin/all?type=AGENT_WITHDRAWAL&status=PENDING')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 1);
});
