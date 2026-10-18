<?php

use App\Models\AgentInfo;
use App\Models\AuditLog;
use App\Models\Cap;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Transactions\Notifications\TransactionReceiptNotification;
use Modules\Transactions\Services\TransactionService;
use Modules\Wallets\Services\LedgerIntegrityService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
});

function reversalActor(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->roles()->attach(Role::where('name', $role)->firstOrFail()->id, ['assigned_at' => now()]);

    return $user;
}

function reversalCustomer(float $balance): User
{
    $user = reversalActor('USER');
    Wallet::factory()->for($user)->create();
    Cap::factory()->for($user)->usage(0.00, 0.00)->create();
    // Fund through the ledger so the integrity check stays meaningful
    if ($balance > 0) {
        app(TransactionService::class)->topUp($user, $balance, 'fund-'.$user->id);
    }

    return $user;
}

function reversalAgent(float $float): User
{
    $agent = reversalActor('AGENT');
    AgentInfo::create(['user_id' => $agent->id, 'commission_rate' => '0.0100', 'status' => 'APPROVED']);
    $wallet = Wallet::factory()->agent()->for($agent)->create();
    Transaction::create([
        'user_id' => $agent->id, 'recipient_id' => $agent->id, 'agent_id' => $agent->id, 'initiated_by' => $agent->id,
        'type' => 'FLOAT_ISSUE', 'amount' => number_format($float, 2, '.', ''),
        'system_fee_amount' => '0.00', 'system_fee_rate' => '0.0000', 'agent_commission_amount' => '0.00', 'agent_commission_rate' => '0.0000',
        'currency' => 'BDT', 'status' => 'COMPLETED', 'idempotency_key' => 'float-'.$agent->id,
        'recipient_wallet_id' => $wallet->id, 'agent_wallet_id' => $wallet->id,
    ]);
    $wallet->forceFill(['balance' => number_format($float, 2, '.', '')])->save();

    return $agent;
}

function transferBetween(object $test, User $sender, User $recipient, float $amount): Transaction
{
    Sanctum::actingAs($sender, ['user']);
    $id = $test->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number, 'amount' => $amount, 'pin' => '12345', 'idempotency_key' => 'rev-transfer-'.uniqid(),
    ])->assertCreated()->json('data.transaction.id');

    return Transaction::findOrFail($id);
}

function reverseAs(object $test, User $staff, Transaction $transaction, string $reason = 'Sent to the wrong number by mistake')
{
    Sanctum::actingAs($staff, ['admin']);

    return $test->postJson("/api/v1/transactions/admin/{$transaction->id}/reverse", ['reason' => $reason]);
}

function balanceOf(User $user, string $kind = Wallet::KIND_USER): string
{
    return Wallet::where('user_id', $user->id)->where('kind', $kind)->value('balance');
}

test('a reversed transfer moves the money back with a new REVERSAL row and leaves the original untouched', function () {
    $sender = reversalCustomer(1500.00);
    $recipient = reversalCustomer(100.00);
    $original = transferBetween($this, $sender, $recipient, 1000.00);
    $staff = reversalActor('SUPER_ADMIN');

    $response = reverseAs($this, $staff, $original)
        ->assertCreated()
        ->assertJsonPath('data.transaction.type', 'REVERSAL')
        ->assertJsonPath('data.transaction.reversed_transaction_id', $original->id);

    expect(balanceOf($sender))->toBe('1500.00')
        ->and(balanceOf($recipient))->toBe('100.00');

    $reversal = Transaction::findOrFail($response->json('data.transaction.id'));
    expect($reversal->sender_id)->toBe($recipient->id)
        ->and($reversal->recipient_id)->toBe($sender->id)
        ->and($reversal->initiated_by)->toBe($staff->id)
        ->and($reversal->amount)->toBe('1000.00')
        ->and($reversal->meta['reason'])->toBe('Sent to the wrong number by mistake')
        ->and($original->fresh()->only(['status', 'amount', 'updated_at']))->toEqual($original->only(['status', 'amount', 'updated_at']));

    $audit = AuditLog::where('action', 'TRANSACTION_REVERSED')->sole();
    expect($audit->actor_id)->toBe($staff->id)
        ->and($audit->auditable_id)->toBe($original->id)
        ->and($audit->metadata['reversal_id'])->toBe($reversal->id);

    expect(app(LedgerIntegrityService::class)->verify()['healthy'])->toBeTrue();
});

test('both parties get a receipt for the reversal', function () {
    $sender = reversalCustomer(1500.00);
    $recipient = reversalCustomer(0.00);
    $original = transferBetween($this, $sender, $recipient, 400.00);

    reverseAs($this, reversalActor('SUPER_ADMIN'), $original)->assertCreated();

    $toRecipient = Notification::sent($recipient, TransactionReceiptNotification::class)->last();
    $toSender = Notification::sent($sender, TransactionReceiptNotification::class)->last();

    expect($toRecipient->toSms($recipient))->toStartWith("WalletMS: Reversal of #{$original->id}: returned Tk 400.00 to")
        ->and($toSender->toSms($sender))->toStartWith("WalletMS: Reversal of #{$original->id}: you got back Tk 400.00 from")
        ->and($toSender->toSms($sender))->toContain('Balance Tk 1,500.00.');
});

test('a reversed cash-out returns amount and fee to the customer and claws back amount and commission from the agent', function () {
    $customer = reversalCustomer(1500.00);
    $agent = reversalAgent(2000.00);
    Sanctum::actingAs($customer, ['user']);
    // Fee 5% = 50, commission 1% = 10
    $id = $this->postJson('/api/v1/transactions/cash-out', [
        'agent_phone' => $agent->phone_number, 'amount' => 1000.00, 'pin' => '12345', 'idempotency_key' => 'rev-cash-out',
    ])->assertCreated()->json('data.transaction.id');
    expect(balanceOf($customer))->toBe('450.00')
        ->and(balanceOf($agent, Wallet::KIND_AGENT))->toBe('3010.00');

    reverseAs($this, reversalActor('SUPER_ADMIN'), Transaction::findOrFail($id))->assertCreated();

    expect(balanceOf($customer))->toBe('1500.00')
        ->and(balanceOf($agent, Wallet::KIND_AGENT))->toBe('2000.00')
        ->and(AgentInfo::where('user_id', $agent->id)->value('total_commission'))->toBe('0.00');

    $report = app(LedgerIntegrityService::class)->verify();
    expect($report['wallet_mismatches'])->toBe([])
        ->and($report['commission_mismatches'])->toBe([]);
});

test('a reversed cash-in returns the float to the agent', function () {
    $customer = reversalCustomer(0.00);
    $agent = reversalAgent(2000.00);
    Sanctum::actingAs($agent, ['agent']);
    $id = $this->postJson('/api/v1/transactions/agent/cash-in', [
        'customer_phone' => $customer->phone_number, 'amount' => 500.00, 'pin' => '12345', 'idempotency_key' => 'rev-cash-in',
    ])->assertCreated()->json('data.transaction.id');

    reverseAs($this, reversalActor('SUPER_ADMIN'), Transaction::findOrFail($id))->assertCreated();

    expect(balanceOf($customer))->toBe('0.00')
        ->and(balanceOf($agent, Wallet::KIND_AGENT))->toBe('2000.00')
        ->and(app(LedgerIntegrityService::class)->verify()['healthy'])->toBeTrue();
});

test('a transaction can only be reversed once', function () {
    $sender = reversalCustomer(1500.00);
    $recipient = reversalCustomer(1000.00);
    $original = transferBetween($this, $sender, $recipient, 300.00);

    reverseAs($this, reversalActor('SUPER_ADMIN'), $original)->assertCreated();
    reverseAs($this, reversalActor('SUPER_ADMIN'), $original)
        ->assertStatus(409)
        ->assertJsonPath('message', 'This transaction has already been reversed.');

    expect(Transaction::where('type', 'REVERSAL')->count())->toBe(1)
        ->and(balanceOf($sender))->toBe('1500.00');
});

test('the reversal fails and changes nothing when the recipient has already spent the money', function () {
    $sender = reversalCustomer(1500.00);
    $recipient = reversalCustomer(0.00);
    $third = reversalCustomer(0.00);
    $original = transferBetween($this, $sender, $recipient, 1000.00);
    transferBetween($this, $recipient, $third, 900.00);

    reverseAs($this, reversalActor('SUPER_ADMIN'), $original)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The recipient no longer has enough balance to return this money.');

    expect(balanceOf($recipient))->toBe('100.00')
        ->and(balanceOf($sender))->toBe('500.00')
        ->and(Transaction::where('type', 'REVERSAL')->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'TRANSACTION_REVERSED')->exists())->toBeFalse();
});

test('a blocked wallet does not stop a reversal', function () {
    $sender = reversalCustomer(1500.00);
    $recipient = reversalCustomer(0.00);
    $original = transferBetween($this, $sender, $recipient, 500.00);
    Wallet::where('user_id', $recipient->id)->update(['is_blocked' => true]);

    reverseAs($this, reversalActor('SUPER_ADMIN'), $original)->assertCreated();

    expect(balanceOf($recipient))->toBe('0.00')
        ->and(balanceOf($sender))->toBe('1500.00');
});

test('only completed transfers, cash-ins and cash-outs can be reversed', function () {
    $user = reversalCustomer(500.00);
    $topUp = Transaction::where('type', 'TOP_UP')->where('user_id', $user->id)->firstOrFail();

    reverseAs($this, reversalActor('SUPER_ADMIN'), $topUp)
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only completed transfers, cash-ins and cash-outs can be reversed.');
});

test('a reversal itself cannot be reversed', function () {
    $sender = reversalCustomer(1500.00);
    $recipient = reversalCustomer(0.00);
    $original = transferBetween($this, $sender, $recipient, 200.00);
    $reversalId = reverseAs($this, reversalActor('SUPER_ADMIN'), $original)->json('data.transaction.id');

    reverseAs($this, reversalActor('SUPER_ADMIN'), Transaction::findOrFail($reversalId))->assertStatus(409);
});

test('ADMIN cannot reverse by default; a role granted transactions.reverse can', function () {
    $sender = reversalCustomer(1500.00);
    $recipient = reversalCustomer(0.00);
    $original = transferBetween($this, $sender, $recipient, 200.00);
    $admin = reversalActor('ADMIN');

    reverseAs($this, $admin, $original)->assertForbidden();

    Role::where('name', 'ADMIN')->firstOrFail()->permissions()->attach(
        Permission::where('name', 'transactions.reverse')->firstOrFail()->id,
        ['granted_by' => null],
    );

    reverseAs($this, $admin->fresh(), $original)->assertCreated();
});

test('staff cannot reverse a transaction they took part in', function () {
    $staffCustomer = reversalCustomer(1500.00);
    $staffCustomer->roles()->attach(Role::where('name', 'SUPER_ADMIN')->firstOrFail()->id, ['assigned_at' => now()]);
    $recipient = reversalCustomer(0.00);
    $original = transferBetween($this, $staffCustomer, $recipient, 300.00);

    reverseAs($this, $staffCustomer, $original)->assertForbidden();
});

test('a reason of at least 10 characters is required', function () {
    $sender = reversalCustomer(1500.00);
    $original = transferBetween($this, $sender, reversalCustomer(0.00), 100.00);

    reverseAs($this, reversalActor('SUPER_ADMIN'), $original, 'oops')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
});

test('customer tokens cannot reverse', function () {
    $sender = reversalCustomer(1500.00);
    $original = transferBetween($this, $sender, reversalCustomer(0.00), 100.00);
    Sanctum::actingAs($sender, ['user']);

    $this->postJson("/api/v1/transactions/admin/{$original->id}/reverse", ['reason' => 'I changed my mind about this'])->assertForbidden();
});

test('the admin ledger shows which transaction reversed which', function () {
    $sender = reversalCustomer(1500.00);
    $original = transferBetween($this, $sender, reversalCustomer(0.00), 100.00);
    $staff = reversalActor('SUPER_ADMIN');
    $reversalId = reverseAs($this, $staff, $original)->json('data.transaction.id');

    $items = collect($this->getJson('/api/v1/transactions/admin/all?per_page=100')->assertOk()->json('data.items'))->keyBy('id');

    expect($items[$original->id]['reversed_by_transaction_id'])->toBe($reversalId)
        ->and($items[$reversalId]['reversed_transaction_id'])->toBe($original->id);

    $this->getJson('/api/v1/transactions/admin/all?type=REVERSAL')->assertOk()->assertJsonCount(1, 'data.items');
});

test('the reversal appears in the customer history', function () {
    $sender = reversalCustomer(1500.00);
    $original = transferBetween($this, $sender, reversalCustomer(0.00), 100.00);
    reverseAs($this, reversalActor('SUPER_ADMIN'), $original)->assertCreated();
    Sanctum::actingAs($sender, ['user']);

    $this->getJson('/api/v1/transactions/history?type=REVERSAL')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.recipient_wallet_balance_after', 1500);
});
