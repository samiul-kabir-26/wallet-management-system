<?php

use App\Models\AgentInfo;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\FloatRequest;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Transactions\Notifications\FloatRequestReviewedNotification;
use Modules\Wallets\Services\LedgerIntegrityService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Storage::fake('local');
    Notification::fake();
});

function frAgent(string $status = 'APPROVED'): User
{
    $agent = User::factory()->create(['name' => 'Karim Store']);
    $agent->roles()->attach(Role::where('name', 'AGENT')->firstOrFail()->id, ['assigned_at' => now()]);
    AgentInfo::create(['user_id' => $agent->id, 'commission_rate' => '0.0100', 'status' => $status]);
    Wallet::factory()->agent()->for($agent)->create();

    return $agent;
}

function frStaff(string $role = 'SUPER_ADMIN'): User
{
    $staff = User::factory()->create();
    $staff->roles()->attach(Role::where('name', $role)->firstOrFail()->id, ['assigned_at' => now()]);

    return $staff;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function frSubmit(object $test, User $agent, array $overrides = [])
{
    Sanctum::actingAs($agent, ['agent']);

    return $test->post('/api/v1/transactions/agent/float-requests', [
        'amount' => '5000.00',
        'bank_reference' => 'DBBL-20261018-0042',
        'deposited_at' => now()->toDateString(),
        'slip' => UploadedFile::fake()->image('slip.jpg', 800, 600),
        'note' => 'Deposited at Motijheel branch',
        ...$overrides,
    ], ['Accept' => 'application/json']);
}

function frStaffAs(User $staff): void
{
    Sanctum::actingAs($staff, ['admin']);
}

test('an approved agent submits a float request with a deposit slip kept on the private disk', function () {
    $agent = frAgent();

    $id = frSubmit($this, $agent)
        ->assertCreated()
        ->assertJsonPath('data.float_request.status', 'PENDING')
        ->assertJsonPath('data.float_request.amount', 5000)
        ->assertJsonPath('data.float_request.slip.name', 'slip.jpg')
        ->assertJsonMissingPath('data.float_request.slip_path')
        ->json('data.float_request.id');

    $floatRequest = FloatRequest::findOrFail($id);
    expect($floatRequest->slip_path)->toStartWith("float-slips/{$agent->id}/");
    Storage::disk('local')->assertExists($floatRequest->slip_path);
    expect(AuditLog::where('action', 'FLOAT_REQUEST_SUBMITTED')->sole()->actor_id)->toBe($agent->id)
        ->and($agent->agentWallet->balance)->toBe('0.00');
});

test('float request input is validated', function (array $overrides, string $field) {
    frSubmit($this, frAgent(), $overrides)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing slip' => [['slip' => null], 'slip'],
    'slip that is not an image or PDF' => [['slip' => UploadedFile::fake()->create('slip.txt', 10, 'text/plain')], 'slip'],
    'slip over 5 MB' => [['slip' => UploadedFile::fake()->create('slip.pdf', 6000, 'application/pdf')], 'slip'],
    'deposit older than 30 days' => [['deposited_at' => now()->subDays(31)->toDateString()], 'deposited_at'],
    'deposit in the future' => [['deposited_at' => now()->addDay()->toDateString()], 'deposited_at'],
    'amount below the float minimum' => [['amount' => '10.00'], 'amount'],
    'reference with odd characters' => [['bank_reference' => 'REF<script>'], 'bank_reference'],
]);

test('a PDF slip is accepted', function () {
    frSubmit($this, frAgent(), ['slip' => UploadedFile::fake()->create('slip.pdf', 200, 'application/pdf')])->assertCreated();
});

test('a bank reference that was already credited or is already waiting cannot be submitted again', function () {
    $agent = frAgent();
    frSubmit($this, $agent)->assertCreated();

    frSubmit($this, frAgent(), ['amount' => '100.00'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.bank_reference.0', 'A float request with this bank reference is already waiting for review.');

    Transaction::create([
        'user_id' => $agent->id, 'initiated_by' => $agent->id, 'type' => 'FLOAT_ISSUE', 'amount' => '1.00',
        'system_fee_amount' => '0.00', 'system_fee_rate' => '0.0000', 'agent_commission_amount' => '0.00', 'agent_commission_rate' => '0.0000',
        'currency' => 'BDT', 'status' => 'COMPLETED', 'idempotency_key' => 'x', 'external_reference' => 'USED-REF-1',
    ]);
    frSubmit($this, $agent, ['bank_reference' => 'USED-REF-1'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.bank_reference.0', 'This bank reference has already been credited.');
});

test('pending agents and customer tokens cannot submit float requests', function () {
    frSubmit($this, frAgent('PENDING'))->assertStatus(409);

    $customer = User::factory()->create();
    $customer->roles()->attach(Role::where('name', 'USER')->firstOrFail()->id, ['assigned_at' => now()]);
    Sanctum::actingAs($customer, ['user']);
    $this->postJson('/api/v1/transactions/agent/float-requests', [])->assertForbidden();
});

test('approving issues the float with the bank reference and tells the agent', function () {
    $agent = frAgent();
    $id = frSubmit($this, $agent)->json('data.float_request.id');
    $staff = frStaff();
    frStaffAs($staff);

    $this->postJson("/api/v1/transactions/admin/float-requests/{$id}/approve", ['note' => 'Matches bank statement'])
        ->assertOk()
        ->assertJsonPath('data.float_request.status', 'APPROVED')
        ->assertJsonPath('data.float_request.reviewed_by.id', $staff->id);

    $floatRequest = FloatRequest::findOrFail($id);
    $float = Transaction::findOrFail($floatRequest->transaction_id);
    expect($float->type)->toBe('FLOAT_ISSUE')
        ->and($float->external_reference)->toBe('DBBL-20261018-0042')
        ->and($float->initiated_by)->toBe($staff->id)
        ->and($agent->agentWallet->refresh()->balance)->toBe('5000.00')
        ->and(app(LedgerIntegrityService::class)->verify()['healthy'])->toBeTrue();

    Notification::assertSentTo($agent, FloatRequestReviewedNotification::class, fn ($n) => str_contains($n->toSms($agent), 'was approved'));

    $this->postJson("/api/v1/transactions/admin/float-requests/{$id}/approve")
        ->assertStatus(409)
        ->assertJsonPath('message', 'This float request has already been reviewed.');
});

test('rejecting needs a reason, credits nothing and tells the agent why', function () {
    $agent = frAgent();
    $id = frSubmit($this, $agent)->json('data.float_request.id');
    frStaffAs(frStaff());

    $this->postJson("/api/v1/transactions/admin/float-requests/{$id}/reject")->assertUnprocessable();
    $this->postJson("/api/v1/transactions/admin/float-requests/{$id}/reject", ['reason' => 'No deposit with this reference on the statement'])
        ->assertOk()
        ->assertJsonPath('data.float_request.status', 'REJECTED');

    expect(Transaction::where('type', 'FLOAT_ISSUE')->exists())->toBeFalse();
    Notification::assertSentTo($agent, FloatRequestReviewedNotification::class,
        fn ($n) => str_contains($n->toSms($agent), 'was rejected: No deposit with this reference on the statement'));
});

test('staff need agents.issue_float to see or review float requests', function () {
    $id = frSubmit($this, frAgent())->json('data.float_request.id');
    frStaffAs(frStaff('ADMIN'));

    $this->getJson('/api/v1/transactions/admin/float-requests')->assertForbidden();
    $this->get("/api/v1/transactions/admin/float-requests/{$id}/slip", ['Accept' => 'application/json'])->assertForbidden();
    $this->postJson("/api/v1/transactions/admin/float-requests/{$id}/approve")->assertForbidden();
});

test('staff list pending requests oldest first', function () {
    $first = frSubmit($this, frAgent(), ['bank_reference' => 'REF-A-0001'])->json('data.float_request.id');
    frSubmit($this, frAgent(), ['bank_reference' => 'REF-B-0002'])->assertCreated();
    frStaffAs(frStaff());

    $this->getJson('/api/v1/transactions/admin/float-requests?status=PENDING')
        ->assertOk()
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.0.id', $first)
        ->assertJsonPath('data.items.0.agent.name', 'Karim Store');
});

test('the slip can be viewed by staff and by the agent who uploaded it, but not by other agents', function () {
    $agent = frAgent();
    $id = frSubmit($this, $agent)->json('data.float_request.id');

    frStaffAs(frStaff());
    $this->get("/api/v1/transactions/admin/float-requests/{$id}/slip")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    Sanctum::actingAs($agent, ['agent']);
    $this->get("/api/v1/transactions/agent/float-requests/{$id}/slip")->assertOk();

    Sanctum::actingAs(frAgent(), ['agent']);
    $this->getJson("/api/v1/transactions/agent/float-requests/{$id}/slip")->assertNotFound();
});

test('an agent can cancel their own pending request but not someone else\'s', function () {
    $agent = frAgent();
    $id = frSubmit($this, $agent)->json('data.float_request.id');

    Sanctum::actingAs(frAgent(), ['agent']);
    $this->postJson("/api/v1/transactions/agent/float-requests/{$id}/cancel")->assertForbidden();

    Sanctum::actingAs($agent, ['agent']);
    $this->postJson("/api/v1/transactions/agent/float-requests/{$id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.float_request.status', 'CANCELLED');

    $this->getJson('/api/v1/transactions/agent/float-requests')->assertOk()->assertJsonCount(1, 'data.items');
});

test('staff who are also the agent cannot review their own request', function () {
    $agent = frAgent();
    $agent->roles()->attach(Role::where('name', 'SUPER_ADMIN')->firstOrFail()->id, ['assigned_at' => now()]);
    $id = frSubmit($this, $agent)->json('data.float_request.id');
    frStaffAs($agent);

    $this->postJson("/api/v1/transactions/admin/float-requests/{$id}/approve")->assertForbidden();
});

test('with maker-checker on, approval is forwarded and completed by a second staff member', function () {
    config(['approvals.enabled' => true]);
    $agent = frAgent();
    $id = frSubmit($this, $agent)->json('data.float_request.id');
    $maker = frStaff();
    frStaffAs($maker);

    $approvalId = $this->postJson("/api/v1/transactions/admin/float-requests/{$id}/approve", ['note' => 'Slip matches'])
        ->assertStatus(202)
        ->assertJsonPath('data.approval_request.payload.float_request_id', $id)
        ->json('data.approval_request.id');

    $this->getJson('/api/v1/transactions/admin/float-requests?status=PENDING')
        ->assertJsonPath('data.items.0.awaiting_second_approval', true);
    $this->postJson("/api/v1/transactions/admin/float-requests/{$id}/reject", ['reason' => 'Changed my mind'])->assertStatus(409);
    Sanctum::actingAs($agent, ['agent']);
    $this->postJson("/api/v1/transactions/agent/float-requests/{$id}/cancel")->assertStatus(409);
    expect($agent->agentWallet->balance)->toBe('0.00');

    frStaffAs(frStaff());
    $this->postJson("/api/v1/approvals/{$approvalId}/approve")->assertOk();

    $floatRequest = FloatRequest::findOrFail($id);
    expect($floatRequest->status)->toBe('APPROVED')
        ->and($floatRequest->reviewed_by)->toBe($maker->id)
        ->and($floatRequest->review_note)->toBe('Slip matches')
        ->and($agent->agentWallet->refresh()->balance)->toBe('5000.00');
    Notification::assertSentTo($agent, FloatRequestReviewedNotification::class);
});

test('when the second approver rejects, the float request goes back to the review queue', function () {
    config(['approvals.enabled' => true]);
    $id = frSubmit($this, frAgent())->json('data.float_request.id');
    frStaffAs(frStaff());
    $approvalId = $this->postJson("/api/v1/transactions/admin/float-requests/{$id}/approve")->json('data.approval_request.id');

    frStaffAs(frStaff());
    $this->postJson("/api/v1/approvals/{$approvalId}/reject", ['note' => 'Bank statement not checked yet'])->assertOk();

    expect(FloatRequest::findOrFail($id)->approval_request_id)->toBeNull()
        ->and(ApprovalRequest::findOrFail($approvalId)->status)->toBe('REJECTED');

    $this->postJson("/api/v1/transactions/admin/float-requests/{$id}/reject", ['reason' => 'No deposit found on the statement'])
        ->assertOk();
});
