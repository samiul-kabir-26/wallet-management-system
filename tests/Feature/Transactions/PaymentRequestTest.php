<?php

use App\Models\Cap;
use App\Models\PaymentRequest;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Transactions\Notifications\PaymentRequestNotification;
use Modules\Transactions\Services\PaymentRequestService;
use Modules\Transactions\Services\TransactionService;
use Modules\Wallets\Services\LedgerIntegrityService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->seed(SystemSettingsSeeder::class);
    Notification::fake();
});

function prCustomer(float $balance = 0.00, string $name = 'Rahim Uddin'): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->roles()->attach(Role::where('name', 'USER')->firstOrFail()->id, ['assigned_at' => now()]);
    Wallet::factory()->for($user)->create();
    Cap::factory()->for($user)->usage(0.00, 0.00)->create();
    if ($balance > 0) {
        app(TransactionService::class)->topUp($user, $balance, 'pr-fund-'.$user->id);
    }

    return $user;
}

function prAsk(object $test, User $requester, User $payer, float $amount = 500.00, ?string $note = 'Dinner')
{
    Sanctum::actingAs($requester, ['user']);

    return $test->postJson('/api/v1/transactions/requests', [
        'payer_phone' => $payer->phone_number,
        'amount' => $amount,
        'note' => $note,
    ]);
}

function prPay(object $test, User $payer, int $id, string $pin = '12345')
{
    Sanctum::actingAs($payer, ['user']);

    return $test->postJson("/api/v1/transactions/requests/{$id}/pay", ['pin' => $pin, 'idempotency_key' => 'pay-'.$id.'-'.uniqid()]);
}

test('a customer asks another for money and the payer gets an SMS', function () {
    $requester = prCustomer(name: 'Rahim Uddin');
    $payer = prCustomer(1000.00, 'Nusrat Jahan');

    $id = prAsk($this, $requester, $payer)
        ->assertCreated()
        ->assertJsonPath('data.payment_request.status', 'PENDING')
        ->assertJsonPath('data.payment_request.direction', 'outgoing')
        ->assertJsonPath('data.payment_request.payer.name', 'N****t J***n')
        ->json('data.payment_request.id');

    Notification::assertSentTo($payer, PaymentRequestNotification::class,
        fn ($n) => $n->toSms($payer) === "WalletMS: R***m U***n requests Tk 500.00 (Dinner). Open WalletMS to pay or decline request #{$id}.");
});

test('paying a request is a normal PIN-verified transfer linked to the request', function () {
    $requester = prCustomer();
    $payer = prCustomer(1000.00);
    $id = prAsk($this, $requester, $payer)->json('data.payment_request.id');

    prPay($this, $payer, $id)
        ->assertOk()
        ->assertJsonPath('data.payment_request.status', 'PAID')
        ->assertJsonPath('data.payment_request.direction', 'incoming');

    $request = PaymentRequest::findOrFail($id);
    $transfer = Transaction::findOrFail($request->transaction_id);
    expect($transfer->type)->toBe('TRANSFER')
        ->and($transfer->sender_id)->toBe($payer->id)
        ->and($transfer->recipient_id)->toBe($requester->id)
        ->and($transfer->meta['payment_request_id'])->toBe($id)
        ->and($transfer->description)->toBe("Payment request #{$id}: Dinner")
        ->and($payer->userWallet->refresh()->balance)->toBe('500.00')
        ->and($requester->userWallet->refresh()->balance)->toBe('500.00')
        ->and((float) Cap::where('user_id', $payer->id)->value('daily_used'))->toBe(500.0)
        ->and(app(LedgerIntegrityService::class)->verify()['healthy'])->toBeTrue();

    Notification::assertSentTo($requester, PaymentRequestNotification::class, fn ($n) => $n->event === PaymentRequestNotification::EVENT_PAID);

    prPay($this, $payer, $id)->assertStatus(409)->assertJsonPath('message', 'This payment request is no longer open.');
});

test('a failed payment leaves the request open', function () {
    $requester = prCustomer();
    $payer = prCustomer(100.00);
    $id = prAsk($this, $requester, $payer)->json('data.payment_request.id');

    prPay($this, $payer, $id)->assertUnprocessable(); // not enough balance
    prPay($this, $payer, $id, '99999')->assertUnprocessable(); // wrong PIN

    expect(PaymentRequest::findOrFail($id)->status)->toBe('PENDING')
        ->and(Transaction::where('type', 'TRANSFER')->exists())->toBeFalse();
});

test('only the payer can pay or decline, only the requester can cancel, and strangers get 404', function () {
    $requester = prCustomer();
    $payer = prCustomer(1000.00);
    $id = prAsk($this, $requester, $payer)->json('data.payment_request.id');

    prPay($this, $requester, $id)->assertForbidden();

    Sanctum::actingAs($payer, ['user']);
    $this->postJson("/api/v1/transactions/requests/{$id}/cancel")->assertForbidden();

    Sanctum::actingAs(prCustomer(), ['user']);
    $this->postJson("/api/v1/transactions/requests/{$id}/decline")->assertNotFound();

    Sanctum::actingAs($payer, ['user']);
    $this->postJson("/api/v1/transactions/requests/{$id}/decline")
        ->assertOk()
        ->assertJsonPath('data.payment_request.status', 'DECLINED');
    Notification::assertSentTo($requester, PaymentRequestNotification::class, fn ($n) => $n->event === PaymentRequestNotification::EVENT_DECLINED);
});

test('the requester can cancel an open request', function () {
    $requester = prCustomer();
    $id = prAsk($this, $requester, prCustomer())->json('data.payment_request.id');

    $this->postJson("/api/v1/transactions/requests/{$id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.payment_request.status', 'CANCELLED');
});

test('expired requests cannot be paid and are closed by the sweep', function () {
    $requester = prCustomer();
    $payer = prCustomer(1000.00);
    $id = prAsk($this, $requester, $payer)->json('data.payment_request.id');
    $this->travel(8)->days();

    prPay($this, $payer, $id)->assertStatus(409)->assertJsonPath('message', 'This payment request has expired.');
    $this->getJson('/api/v1/transactions/requests?direction=incoming')->assertJsonPath('data.items.0.status', 'EXPIRED');

    expect(app(PaymentRequestService::class)->expireOverdue())->toBe(1)
        ->and(PaymentRequest::findOrFail($id)->status)->toBe('EXPIRED');
});

test('requests are validated', function () {
    $requester = prCustomer();
    $payer = prCustomer();

    prAsk($this, $requester, $requester)->assertUnprocessable()->assertJsonPath('errors.payer_phone.0', 'You cannot request money from yourself.');
    prAsk($this, $requester, $payer, 5.00)->assertUnprocessable()->assertJsonValidationErrors('amount');
    prAsk($this, $requester, $payer, 99999.00)->assertUnprocessable()->assertJsonValidationErrors('amount');

    Sanctum::actingAs($requester, ['user']);
    $this->postJson('/api/v1/transactions/requests', ['payer_phone' => '01799999999', 'amount' => 100])->assertNotFound();

    $agent = User::factory()->create();
    $agent->roles()->attach(Role::where('name', 'AGENT')->firstOrFail()->id, ['assigned_at' => now()]);
    prAsk($this, $requester, $agent)->assertUnprocessable()->assertJsonPath('errors.payer_phone.0', 'This number does not belong to a customer account.');
});

test('one customer cannot flood another with open requests', function () {
    $requester = prCustomer();
    $payer = prCustomer();

    foreach (range(1, 3) as $i) {
        prAsk($this, $requester, $payer, 100.00)->assertCreated();
    }

    prAsk($this, $requester, $payer, 100.00)
        ->assertUnprocessable()
        ->assertJsonPath('errors.payer_phone.0', 'You already have the maximum number of open requests to this customer.');
});

test('the list separates incoming and outgoing requests', function () {
    $alice = prCustomer();
    $bob = prCustomer();
    prAsk($this, $alice, $bob)->assertCreated();
    prAsk($this, $bob, $alice, 200.00)->assertCreated();

    Sanctum::actingAs($alice, ['user']);
    $this->getJson('/api/v1/transactions/requests?direction=incoming')->assertOk()->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.amount', 200);
    $this->getJson('/api/v1/transactions/requests?direction=outgoing&status=PENDING')->assertOk()->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.amount', 500);
    $this->getJson('/api/v1/transactions/requests')->assertOk()->assertJsonCount(2, 'data.items');
});

test('agent tokens cannot request money', function () {
    $agent = User::factory()->create();
    $agent->roles()->attach(Role::where('name', 'AGENT')->firstOrFail()->id, ['assigned_at' => now()]);
    Sanctum::actingAs($agent, ['agent']);

    $this->getJson('/api/v1/transactions/requests')->assertForbidden();
});
