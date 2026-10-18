<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Payments\Services\TopUpPaymentService;

const SSLCZ_SESSION_URL = 'sandbox.sslcommerz.com/gwprocess/v4/api.php';
const SSLCZ_VALIDATION_URL = 'sandbox.sslcommerz.com/validator/api/validationserverAPI.php*';
const SSLCZ_QUERY_URL = 'sandbox.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php*';
const SSLCZ_CHECKOUT_URL = 'https://sandbox.sslcommerz.com/EasyCheckOut/testcde123';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config([
        'payments.top_up_gateway' => 'sslcommerz',
        'payments.sslcommerz.store_id' => 'testbox',
        'payments.sslcommerz.store_password' => 'qwerty',
        'payments.sslcommerz.sandbox' => true,
    ]);
});

function sslczCustomer(float $balance = 100.00, bool $blocked = false): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'USER')->firstOrFail()->id, ['assigned_at' => now()]);

    $factory = Wallet::factory()->for($user)->balance($balance);
    ($blocked ? $factory->blocked() : $factory)->create();

    return $user;
}

/**
 * @param  array<string, mixed>  $validation
 * @param  list<array<string, mixed>>  $attempts
 */
function fakeSslCommerz(array $validation = [], array $attempts = [], bool $sessionFails = false): void
{
    // Start from a fresh client so these stubs replace the ones an earlier call registered
    Http::swap(new HttpFactory(app('events')));

    Http::fake([
        SSLCZ_SESSION_URL => $sessionFails
            ? Http::response(['status' => 'FAILED', 'failedreason' => 'Store Credential Error Or Store is De-active'])
            : Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => SSLCZ_CHECKOUT_URL, 'sessionkey' => 'SESSION123']),
        SSLCZ_VALIDATION_URL => Http::response($validation ?: ['status' => 'INVALID_TRANSACTION']),
        SSLCZ_QUERY_URL => Http::response(['APIConnect' => 'DONE', 'no_of_trans_found' => count($attempts), 'element' => $attempts]),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validPayment(Transaction $topUp, array $overrides = []): array
{
    return [
        'status' => 'VALID',
        'tran_id' => $topUp->external_reference,
        'val_id' => 'VAL-'.$topUp->id,
        'amount' => (string) $topUp->amount,
        'currency' => 'BDT',
        'currency_type' => 'BDT',
        'bank_tran_id' => 'BANK-'.$topUp->id,
        'card_type' => 'VISA-Dutch Bangla',
        'card_no' => '411111XXXXXX1111',
        'risk_level' => '0',
        ...$overrides,
    ];
}

function startTopUp(object $test, User $user, float $amount = 500.00, string $key = 'sslcz-key-1'): Transaction
{
    Sanctum::actingAs($user, ['user']);

    $test->postJson('/api/v1/transactions/top-up', [
        'amount' => $amount,
        'idempotency_key' => $key,
    ])->assertCreated();

    return Transaction::where('idempotency_key', $key)->sole();
}

test('starting a top-up records it as pending, credits nothing and returns the SSLCommerz checkout url', function () {
    fakeSslCommerz();
    $user = sslczCustomer();
    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 500.00,
        'idempotency_key' => 'sslcz-start',
        'description' => 'Card top-up',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.transaction.status', 'PENDING')
        ->assertJsonPath('data.payment.gateway', 'sslcommerz')
        ->assertJsonPath('data.payment.redirect_url', SSLCZ_CHECKOUT_URL);

    $topUp = Transaction::sole();
    expect($topUp->type)->toBe('TOP_UP')
        ->and($topUp->external_reference)->toStartWith('TOPUP-')
        ->and($topUp->recipient_wallet_balance_after)->toBeNull()
        ->and((float) $user->userWallet->balance)->toBe(100.00);

    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'gwprocess/v4/api.php')
        && $request['store_id'] === 'testbox'
        && $request['store_passwd'] === 'qwerty'
        && $request['tran_id'] === $topUp->external_reference
        && $request['total_amount'] === '500.00'
        && $request['currency'] === 'BDT'
        && str_ends_with($request['success_url'], '/payments/sslcommerz/success')
        && str_ends_with($request['ipn_url'], '/api/v1/payments/sslcommerz/ipn'));
});

test('replaying the idempotency key returns the same checkout without opening a second session', function () {
    fakeSslCommerz();
    $user = sslczCustomer();
    Sanctum::actingAs($user, ['user']);
    $payload = ['amount' => 250.00, 'idempotency_key' => 'sslcz-replay'];

    $first = $this->postJson('/api/v1/transactions/top-up', $payload)->assertCreated();
    $second = $this->postJson('/api/v1/transactions/top-up', $payload)->assertCreated();

    expect($second->json('data.transaction.id'))->toBe($first->json('data.transaction.id'))
        ->and($second->json('data.payment.redirect_url'))->toBe(SSLCZ_CHECKOUT_URL)
        ->and(Transaction::count())->toBe(1);
    Http::assertSentCount(1);
});

test('a gateway that refuses the session returns 502 and marks the top-up failed', function () {
    fakeSslCommerz(sessionFails: true);
    $user = sslczCustomer();
    Sanctum::actingAs($user, ['user']);

    $this->postJson('/api/v1/transactions/top-up', ['amount' => 100.00, 'idempotency_key' => 'sslcz-502'])
        ->assertStatus(502)
        ->assertJsonPath('success', false);

    expect(Transaction::sole()->status)->toBe('FAILED')
        ->and((float) $user->userWallet->balance)->toBe(100.00);
});

test('a blocked wallet cannot start a top-up and the gateway is never contacted', function () {
    fakeSslCommerz();
    $user = sslczCustomer(blocked: true);
    Sanctum::actingAs($user, ['user']);

    $this->postJson('/api/v1/transactions/top-up', ['amount' => 100.00, 'idempotency_key' => 'sslcz-blocked'])
        ->assertForbidden();

    expect(Transaction::count())->toBe(0);
    Http::assertNothingSent();
});

test('a validated success redirect credits the wallet once and sends the customer to the result page', function () {
    $user = sslczCustomer();
    fakeSslCommerz();
    $topUp = startTopUp($this, $user);
    fakeSslCommerz(validation: validPayment($topUp));

    $this->post('/payments/sslcommerz/success', [
        'tran_id' => $topUp->external_reference,
        'val_id' => 'VAL-'.$topUp->id,
        'status' => 'VALID',
    ])->assertRedirect(route('user.top-up.result', ['transaction' => $topUp->id, 'outcome' => 'success']));

    $topUp->refresh();
    expect($topUp->status)->toBe('COMPLETED')
        ->and($topUp->recipient_wallet_balance_after)->toBe('600.00')
        ->and($topUp->meta['payment']['val_id'])->toBe('VAL-'.$topUp->id)
        ->and($topUp->meta['payment']['card_no'])->toBe('411111XXXXXX1111')
        ->and((float) $user->userWallet->balance)->toBe(600.00);

    // The IPN and a refreshed success page arrive afterwards: nothing is credited again
    $this->postJson('/api/v1/payments/sslcommerz/ipn', [
        'tran_id' => $topUp->external_reference,
        'val_id' => 'VAL-'.$topUp->id,
        'status' => 'VALID',
    ])->assertOk();
    $this->post('/payments/sslcommerz/success', [
        'tran_id' => $topUp->external_reference,
        'val_id' => 'VAL-'.$topUp->id,
        'status' => 'VALID',
    ]);

    expect((float) $user->userWallet->refresh()->balance)->toBe(600.00);
});

test('the IPN alone credits the wallet', function () {
    $user = sslczCustomer();
    fakeSslCommerz();
    $topUp = startTopUp($this, $user, 300.00);
    fakeSslCommerz(validation: validPayment($topUp));

    $this->postJson('/api/v1/payments/sslcommerz/ipn', [
        'tran_id' => $topUp->external_reference,
        'val_id' => 'VAL-'.$topUp->id,
        'status' => 'VALID',
    ])->assertOk();

    expect($topUp->refresh()->status)->toBe('COMPLETED')
        ->and((float) $user->userWallet->balance)->toBe(400.00);
});

test('a forged success post that SSLCommerz does not validate credits nothing and does not fail the top-up', function () {
    $user = sslczCustomer();
    fakeSslCommerz();
    $topUp = startTopUp($this, $user);
    fakeSslCommerz(validation: ['status' => 'INVALID_TRANSACTION']);

    $this->post('/payments/sslcommerz/success', [
        'tran_id' => $topUp->external_reference,
        'val_id' => 'FORGED',
        'status' => 'VALID',
    ])->assertRedirect();

    expect($topUp->refresh()->status)->toBe('PENDING')
        ->and((float) $user->userWallet->balance)->toBe(100.00);
});

test('a val_id that belongs to another tran_id credits nothing', function () {
    $user = sslczCustomer();
    fakeSslCommerz();
    $topUp = startTopUp($this, $user);
    fakeSslCommerz(validation: validPayment($topUp, ['tran_id' => 'TOPUP-SOMEONE-ELSE']));

    $this->postJson('/api/v1/payments/sslcommerz/ipn', [
        'tran_id' => $topUp->external_reference,
        'val_id' => 'VAL-OTHER',
        'status' => 'VALID',
    ])->assertOk();

    expect($topUp->refresh()->status)->toBe('PENDING')
        ->and((float) $user->userWallet->balance)->toBe(100.00);
});

test('a paid amount that differs from the top-up is held for staff instead of credited', function () {
    $user = sslczCustomer();
    fakeSslCommerz();
    $topUp = startTopUp($this, $user, 500.00);
    fakeSslCommerz(validation: validPayment($topUp, ['amount' => '5.00']));

    $this->postJson('/api/v1/payments/sslcommerz/ipn', [
        'tran_id' => $topUp->external_reference,
        'val_id' => 'VAL-'.$topUp->id,
        'status' => 'VALID',
    ])->assertOk();

    $topUp->refresh();
    expect($topUp->status)->toBe('PENDING')
        ->and($topUp->meta['on_hold']['reason'])->toBe('AMOUNT_MISMATCH')
        ->and((float) $user->userWallet->balance)->toBe(100.00)
        ->and(AuditLog::where('action', 'TOP_UP_ON_HOLD')->count())->toBe(1);

    // A later fail signal must not fail money that was actually paid
    fakeSslCommerz(attempts: [['status' => 'FAILED', 'tran_id' => $topUp->external_reference]]);
    $this->post('/payments/sslcommerz/fail', ['tran_id' => $topUp->external_reference, 'status' => 'FAILED']);

    expect($topUp->refresh()->status)->toBe('PENDING');
});

test('a high-risk payment is held for staff instead of credited', function () {
    $user = sslczCustomer();
    fakeSslCommerz();
    $topUp = startTopUp($this, $user);
    fakeSslCommerz(validation: validPayment($topUp, ['risk_level' => '1']));

    $this->postJson('/api/v1/payments/sslcommerz/ipn', [
        'tran_id' => $topUp->external_reference,
        'val_id' => 'VAL-'.$topUp->id,
        'status' => 'VALID',
    ])->assertOk();

    expect($topUp->refresh()->meta['on_hold']['reason'])->toBe('HIGH_RISK')
        ->and((float) $user->userWallet->balance)->toBe(100.00);
});

test('a wallet blocked while the customer was paying holds the payment instead of crediting it', function () {
    $user = sslczCustomer();
    fakeSslCommerz();
    $topUp = startTopUp($this, $user);
    $user->userWallet->update(['is_blocked' => true]);
    fakeSslCommerz(validation: validPayment($topUp));

    $this->postJson('/api/v1/payments/sslcommerz/ipn', [
        'tran_id' => $topUp->external_reference,
        'val_id' => 'VAL-'.$topUp->id,
        'status' => 'VALID',
    ])->assertOk();

    expect($topUp->refresh()->status)->toBe('PENDING')
        ->and($topUp->meta['on_hold']['reason'])->toBe('WALLET_BLOCKED')
        ->and((float) $user->userWallet->refresh()->balance)->toBe(100.00);
});

test('cancelling before paying marks the top-up failed', function (?string $gatewayStatus) {
    $user = sslczCustomer();
    fakeSslCommerz();
    $topUp = startTopUp($this, $user);
    fakeSslCommerz(attempts: $gatewayStatus ? [['status' => $gatewayStatus, 'tran_id' => $topUp->external_reference]] : []);

    $this->post('/payments/sslcommerz/cancel', ['tran_id' => $topUp->external_reference, 'status' => 'CANCELLED'])
        ->assertRedirect(route('user.top-up.result', ['transaction' => $topUp->id, 'outcome' => 'cancel']));

    $topUp->refresh();
    expect($topUp->status)->toBe('FAILED')
        ->and($topUp->meta['failure']['reason'])->toBe('NOT_PAID')
        ->and((float) $user->userWallet->balance)->toBe(100.00);
})->with([
    'no attempt recorded' => null,
    'cancelled at the gateway' => 'CANCELLED',
    'tran_id unknown to the gateway' => 'INVALID',
]);

test('a fail signal leaves the top-up pending while SSLCommerz still has an open attempt', function () {
    $user = sslczCustomer();
    fakeSslCommerz();
    $topUp = startTopUp($this, $user);
    fakeSslCommerz(attempts: [['status' => 'PROCESSING', 'tran_id' => $topUp->external_reference]]);

    $this->post('/payments/sslcommerz/fail', ['tran_id' => $topUp->external_reference, 'status' => 'FAILED']);

    expect($topUp->refresh()->status)->toBe('PENDING');
});

test('a payment confirmed after an earlier failure is still credited', function () {
    $user = sslczCustomer();
    fakeSslCommerz();
    $topUp = startTopUp($this, $user);
    fakeSslCommerz(attempts: [['status' => 'FAILED', 'tran_id' => $topUp->external_reference]]);
    $this->post('/payments/sslcommerz/fail', ['tran_id' => $topUp->external_reference, 'status' => 'FAILED']);
    expect($topUp->refresh()->status)->toBe('FAILED');

    fakeSslCommerz(validation: validPayment($topUp));
    $this->postJson('/api/v1/payments/sslcommerz/ipn', [
        'tran_id' => $topUp->external_reference,
        'val_id' => 'VAL-'.$topUp->id,
        'status' => 'VALID',
    ])->assertOk();

    expect($topUp->refresh()->status)->toBe('COMPLETED')
        ->and((float) $user->userWallet->balance)->toBe(600.00);
});

test('the owner can refresh a pending top-up, which looks the payment up by tran_id', function () {
    $user = sslczCustomer();
    fakeSslCommerz();
    $topUp = startTopUp($this, $user);
    fakeSslCommerz(
        validation: validPayment($topUp),
        attempts: [['status' => 'VALID', 'tran_id' => $topUp->external_reference, 'val_id' => 'VAL-'.$topUp->id]],
    );

    $this->postJson("/api/v1/transactions/top-up/{$topUp->id}/refresh")
        ->assertOk()
        ->assertJsonPath('data.transaction.status', 'COMPLETED');

    expect((float) $user->userWallet->balance)->toBe(600.00);
});

test('another customer cannot refresh someone else\'s top-up', function () {
    $owner = sslczCustomer();
    fakeSslCommerz();
    $topUp = startTopUp($this, $owner);

    Sanctum::actingAs(sslczCustomer(), ['user']);

    $this->postJson("/api/v1/transactions/top-up/{$topUp->id}/refresh")->assertNotFound();
});

test('an unknown tran_id sends the browser back to the wallet page and changes nothing', function () {
    fakeSslCommerz();

    $this->post('/payments/sslcommerz/success', ['tran_id' => 'TOPUP-UNKNOWN', 'val_id' => 'X', 'status' => 'VALID'])
        ->assertRedirect('/user/wallet');

    Http::assertNothingSent();
});

test('production always uses SSLCommerz even when the simulated gateway is configured', function () {
    config(['payments.top_up_gateway' => 'simulated']);
    $service = app(TopUpPaymentService::class);

    expect($service->gateway())->toBe(TopUpPaymentService::GATEWAY_SIMULATED);

    app()->detectEnvironment(fn () => 'production');

    expect($service->gateway())->toBe(TopUpPaymentService::GATEWAY_SSLCOMMERZ);
});
