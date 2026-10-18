<?php

use App\Models\AgentInfo;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Cap;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Approvals\Services\ApprovalService;
use Modules\Transactions\Services\TransactionService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->seed(SystemSettingsSeeder::class);
    Cache::flush();
    Notification::fake();
    config(['approvals.enabled' => true]);
});

function mcStaff(string $role = 'SUPER_ADMIN'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', $role)->firstOrFail()->id, ['assigned_at' => now()]);

    return $user;
}

function mcAgent(): User
{
    $agent = User::factory()->create(['name' => 'Karim Store']);
    $agent->roles()->attach(Role::where('name', 'AGENT')->firstOrFail()->id, ['assigned_at' => now()]);
    AgentInfo::create(['user_id' => $agent->id, 'commission_rate' => '0.0100', 'status' => 'APPROVED']);
    Wallet::factory()->agent()->for($agent)->create();

    return $agent;
}

function mcCustomer(float $balance = 0.00): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'USER')->firstOrFail()->id, ['assigned_at' => now()]);
    Wallet::factory()->for($user)->create();
    Cap::factory()->for($user)->usage(0.00, 0.00)->create();
    if ($balance > 0) {
        app(TransactionService::class)->topUp($user, $balance, 'mc-fund-'.$user->id);
    }

    return $user;
}

function mcAs(User $user): void
{
    Sanctum::actingAs($user, ['admin']);
}

function mcRequestFloat(object $test, User $maker, User $agent, string $reference = 'BANK-SLIP-1', float $amount = 5000.00)
{
    mcAs($maker);

    return $test->postJson('/api/v1/transactions/admin/float-issue', [
        'agent_id' => $agent->id,
        'amount' => $amount,
        'external_reference' => $reference,
        'idempotency_key' => 'mc-'.$reference,
    ]);
}

test('a float issue is stored for approval and only applied when a second staff member approves it', function () {
    $maker = mcStaff();
    $checker = mcStaff();
    $agent = mcAgent();

    $approvalId = mcRequestFloat($this, $maker, $agent)
        ->assertStatus(202)
        ->assertJsonPath('data.approval_request.action', 'FLOAT_ISSUE')
        ->assertJsonPath('data.approval_request.status', 'PENDING')
        ->assertJsonPath('data.approval_request.can_review', false)
        ->assertJsonPath('data.approval_request.can_cancel', true)
        ->json('data.approval_request.id');

    expect(Transaction::where('type', 'FLOAT_ISSUE')->exists())->toBeFalse()
        ->and($agent->agentWallet->balance)->toBe('0.00');

    mcAs($checker);
    $response = $this->postJson("/api/v1/approvals/{$approvalId}/approve", ['note' => 'Bank slip checked'])
        ->assertOk()
        ->assertJsonPath('data.approval_request.status', 'APPROVED')
        ->assertJsonPath('data.approval_request.reviewed_by.id', $checker->id);

    $float = Transaction::findOrFail($response->json('data.approval_request.result.transaction_id'));
    expect($float->type)->toBe('FLOAT_ISSUE')
        ->and($float->initiated_by)->toBe($maker->id)
        ->and($float->external_reference)->toBe('BANK-SLIP-1')
        ->and($agent->agentWallet->refresh()->balance)->toBe('5000.00');

    expect(AuditLog::whereIn('action', ['APPROVAL_REQUESTED', 'APPROVAL_APPROVED'])->pluck('actor_id', 'action')->all())
        ->toMatchArray(['APPROVAL_REQUESTED' => $maker->id, 'APPROVAL_APPROVED' => $checker->id]);
});

test('the maker cannot approve their own request', function () {
    $maker = mcStaff();
    $approvalId = mcRequestFloat($this, $maker, mcAgent())->json('data.approval_request.id');

    $this->postJson("/api/v1/approvals/{$approvalId}/approve")
        ->assertForbidden()
        ->assertJsonPath('message', 'A different staff member must approve this request.');

    expect(ApprovalRequest::findOrFail($approvalId)->status)->toBe('PENDING');
});

test('a checker needs the same permission as the action, and cannot see requests they could not review', function () {
    $approvalId = mcRequestFloat($this, mcStaff(), mcAgent())->json('data.approval_request.id');
    $admin = mcStaff('ADMIN'); // ADMIN does not hold agents.issue_float by default
    mcAs($admin);

    $this->getJson('/api/v1/approvals')->assertOk()->assertJsonCount(0, 'data.items');
    $this->postJson("/api/v1/approvals/{$approvalId}/approve")->assertNotFound();

    Role::where('name', 'ADMIN')->firstOrFail()->permissions()->attach(
        Permission::where('name', 'agents.issue_float')->firstOrFail()->id,
        ['granted_by' => null],
    );
    mcAs($admin->fresh());

    $this->postJson("/api/v1/approvals/{$approvalId}/approve")->assertOk();
});

test('a rejected request needs a note, applies nothing and cannot be approved later', function () {
    $agent = mcAgent();
    $approvalId = mcRequestFloat($this, mcStaff(), $agent)->json('data.approval_request.id');
    mcAs(mcStaff());

    $this->postJson("/api/v1/approvals/{$approvalId}/reject")->assertUnprocessable()->assertJsonValidationErrors('note');
    $this->postJson("/api/v1/approvals/{$approvalId}/reject", ['note' => 'Slip does not match the bank statement'])
        ->assertOk()
        ->assertJsonPath('data.approval_request.status', 'REJECTED');

    $this->postJson("/api/v1/approvals/{$approvalId}/approve")
        ->assertStatus(409)
        ->assertJsonPath('message', 'This request is no longer awaiting approval.');

    expect(Transaction::where('type', 'FLOAT_ISSUE')->exists())->toBeFalse()
        ->and($agent->agentWallet->balance)->toBe('0.00');
});

test('only the maker can cancel a pending request', function () {
    $maker = mcStaff();
    $approvalId = mcRequestFloat($this, $maker, mcAgent())->json('data.approval_request.id');

    mcAs(mcStaff());
    $this->postJson("/api/v1/approvals/{$approvalId}/cancel")->assertForbidden();

    mcAs($maker);
    $this->postJson("/api/v1/approvals/{$approvalId}/cancel")
        ->assertOk()
        ->assertJsonPath('data.approval_request.status', 'CANCELLED');
});

test('the same bank reference cannot wait for approval twice', function () {
    $maker = mcStaff();
    $agent = mcAgent();
    mcRequestFloat($this, $maker, $agent, 'BANK-SLIP-9')->assertStatus(202);

    mcRequestFloat($this, mcStaff(), $agent, 'BANK-SLIP-9', 100.00)
        ->assertStatus(409)
        ->assertJsonPath('message', 'A float issue with reference BANK-SLIP-9 is already awaiting approval.');
});

test('an action that fails when approved leaves the request pending', function () {
    $agent = mcAgent();
    $approvalId = mcRequestFloat($this, mcStaff(), $agent)->json('data.approval_request.id');
    AgentInfo::where('user_id', $agent->id)->update(['status' => 'SUSPENDED']);
    mcAs(mcStaff());

    $this->postJson("/api/v1/approvals/{$approvalId}/approve")->assertStatus(409);

    expect(ApprovalRequest::findOrFail($approvalId)->status)->toBe('PENDING')
        ->and(Transaction::where('type', 'FLOAT_ISSUE')->exists())->toBeFalse();
});

test('settings changes keep only the values that change and apply on approval', function () {
    $maker = mcStaff('ADMIN');
    $checker = mcStaff('ADMIN');
    mcAs($maker);

    $response = $this->patchJson('/api/v1/system-settings', [
        'system_fee_rate' => 0.05, // unchanged (seeded default)
        'agent_commission_rate' => 0.02,
    ])->assertStatus(202);

    $changes = $response->json('data.approval_request.payload.changes');
    expect(array_keys($changes))->toBe(['agent_commission_rate'])
        ->and(SystemSetting::where('key', 'agent_commission_rate')->value('value'))->not->toEqual(0.02);

    mcAs($checker);
    $this->postJson('/api/v1/approvals/'.$response->json('data.approval_request.id').'/approve')->assertOk();

    expect((float) SystemSetting::where('key', 'agent_commission_rate')->value('value'))->toBe(0.02);
    $settingsAudit = AuditLog::where('action', 'SETTINGS_UPDATED')->sole();
    expect($settingsAudit->actor_id)->toBe($maker->id);
});

test('submitting settings that are already in force creates no request', function () {
    mcAs(mcStaff('ADMIN'));

    $this->patchJson('/api/v1/system-settings', ['system_fee_rate' => 0.05])
        ->assertOk()
        ->assertJsonPath('message', 'Nothing to change: the submitted values are already in force.');

    expect(ApprovalRequest::count())->toBe(0);
});

test('a reversal waits for approval, cannot be requested twice, and is applied by the checker', function () {
    $sender = mcCustomer(1000.00);
    $recipient = mcCustomer();
    Sanctum::actingAs($sender, ['user']);
    $transferId = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number, 'amount' => 400.00, 'pin' => '12345', 'idempotency_key' => 'mc-transfer',
    ])->assertCreated()->json('data.transaction.id');

    $maker = mcStaff();
    mcAs($maker);
    $approvalId = $this->postJson("/api/v1/transactions/admin/{$transferId}/reverse", ['reason' => 'Sent to the wrong number by mistake'])
        ->assertStatus(202)
        ->json('data.approval_request.id');
    $this->postJson("/api/v1/transactions/admin/{$transferId}/reverse", ['reason' => 'Sent to the wrong number by mistake'])
        ->assertStatus(409);

    expect(Transaction::where('type', 'REVERSAL')->exists())->toBeFalse();

    mcAs(mcStaff());
    $this->postJson("/api/v1/approvals/{$approvalId}/approve")->assertOk();

    $reversal = Transaction::where('type', 'REVERSAL')->sole();
    expect($reversal->reversed_transaction_id)->toBe($transferId)
        ->and($reversal->initiated_by)->toBe($maker->id)
        ->and($sender->userWallet->refresh()->balance)->toBe('1000.00');
});

test('a checker who took part in the transaction cannot approve its reversal', function () {
    $sender = mcCustomer(1000.00);
    $sender->roles()->attach(Role::where('name', 'SUPER_ADMIN')->firstOrFail()->id, ['assigned_at' => now()]);
    $recipient = mcCustomer();
    Sanctum::actingAs($sender, ['user']);
    $transferId = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number, 'amount' => 100.00, 'pin' => '12345', 'idempotency_key' => 'mc-transfer-2',
    ])->json('data.transaction.id');

    mcAs(mcStaff());
    $approvalId = $this->postJson("/api/v1/transactions/admin/{$transferId}/reverse", ['reason' => 'Customer dispute, confirmed by phone'])
        ->json('data.approval_request.id');

    mcAs($sender);
    $this->postJson("/api/v1/approvals/{$approvalId}/approve")
        ->assertForbidden()
        ->assertJsonPath('message', 'You cannot approve the reversal of a transaction you took part in.');
});

test('a commission-rate change waits for approval and the agent cannot approve their own', function () {
    $agent = mcAgent();
    $agent->roles()->attach(Role::where('name', 'SUPER_ADMIN')->firstOrFail()->id, ['assigned_at' => now()]);
    mcAs(mcStaff('ADMIN'));

    $approvalId = $this->patchJson("/api/v1/users/{$agent->id}/commission-rate", ['commission_rate' => 0.025])
        ->assertStatus(202)
        ->assertJsonPath('data.approval_request.payload.previous_rate', '0.0100')
        ->json('data.approval_request.id');

    mcAs($agent);
    $this->postJson("/api/v1/approvals/{$approvalId}/approve")->assertForbidden();

    mcAs(mcStaff('ADMIN'));
    $this->postJson("/api/v1/approvals/{$approvalId}/approve")->assertOk();

    expect(AgentInfo::where('user_id', $agent->id)->value('commission_rate'))->toBe('0.0250');
});

test('the list shows reviewable requests and the caller\'s own, with review flags', function () {
    $maker = mcStaff('ADMIN');
    mcAs($maker);
    $this->patchJson('/api/v1/system-settings', ['agent_commission_rate' => 0.03])->assertStatus(202);
    mcRequestFloat($this, mcStaff(), mcAgent())->assertStatus(202); // ADMIN cannot review float

    mcAs(mcStaff('ADMIN'));
    $this->getJson('/api/v1/approvals?status=PENDING')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.action', 'SETTINGS_UPDATE')
        ->assertJsonPath('data.items.0.can_review', true)
        ->assertJsonPath('meta.required', true);

    $this->getJson('/api/v1/approvals?reviewable=1')->assertOk()->assertJsonCount(1, 'data.items');

    mcAs($maker);
    $this->getJson('/api/v1/approvals?reviewable=1')->assertOk()->assertJsonCount(0, 'data.items');
    $this->getJson('/api/v1/approvals?mine=1')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.can_review', false)
        ->assertJsonPath('data.items.0.can_cancel', true);
});

test('customer tokens cannot use the approvals endpoints', function () {
    Sanctum::actingAs(mcCustomer(), ['user']);

    $this->getJson('/api/v1/approvals')->assertForbidden();
});

test('with maker-checker off the action applies immediately', function () {
    config(['approvals.enabled' => false]);

    mcRequestFloat($this, mcStaff(), mcAgent())->assertCreated();

    expect(ApprovalRequest::count())->toBe(0);
});

test('production always requires approval', function () {
    config(['approvals.enabled' => false]);
    app()->detectEnvironment(fn () => 'production');

    expect(app(ApprovalService::class)->isRequired())->toBeTrue();
});
