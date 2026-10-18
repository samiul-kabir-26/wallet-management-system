<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Payments\Jobs\ReconcilePendingTopUps;
use Modules\Payments\Services\TopUpReconciler;
use Modules\Transactions\Services\TransactionService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    config([
        'payments.top_up_gateway' => 'sslcommerz',
        'payments.sslcommerz.sandbox' => true,
        'payments.reconciliation.check_after_minutes' => 10,
        'payments.reconciliation.expire_after_hours' => 24,
    ]);
});

function reconCustomer(float $balance = 100.00): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'USER')->firstOrFail()->id, ['assigned_at' => now()]);
    Wallet::factory()->for($user)->balance($balance)->create();

    return $user;
}

function reconStaff(string $role = 'ADMIN'): User
{
    $staff = User::factory()->create();
    $staff->roles()->attach(Role::where('name', $role)->firstOrFail()->id, ['assigned_at' => now()]);

    return $staff;
}

/**
 * A gateway top-up created $minutesAgo minutes ago, still awaiting payment.
 *
 * @param  array<string, mixed>  $meta
 */
function reconTopUp(User $user, int $minutesAgo, float $amount = 500.00, array $meta = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    $topUp = app(TransactionService::class)->createPendingTopUp(
        user: $user,
        amount: $amount,
        idempotencyKey: "recon-{$sequence}",
        gatewayReference: "TOPUP-RECON-{$sequence}",
        gateway: 'sslcommerz',
    );

    $topUp->forceFill([
        'created_at' => now()->subMinutes($minutesAgo),
        'meta' => [...$topUp->meta, ...$meta],
    ])->save();

    return $topUp;
}

/**
 * Fake the SSLCommerz lookup and validation APIs. Each top-up maps to the attempts the lookup returns;
 * a paid attempt is also accepted by the validation API.
 *
 * @param  array<string, list<array<string, mixed>>>  $attemptsByTranId
 */
function fakeReconGateway(array $attemptsByTranId = [], bool $unreachable = false): void
{
    Http::swap(new HttpFactory(app('events')));

    if ($unreachable) {
        Http::fake(['sandbox.sslcommerz.com/*' => Http::response('Service Unavailable', 503)]);

        return;
    }

    Http::fake(function ($request) use ($attemptsByTranId) {
        if (str_contains($request->url(), 'merchantTransIDvalidationAPI.php')) {
            $attempts = $attemptsByTranId[$request['tran_id']] ?? [['status' => 'INVALID']];

            return Http::response(['APIConnect' => 'DONE', 'element' => $attempts]);
        }

        if (str_contains($request->url(), 'validationserverAPI.php')) {
            foreach ($attemptsByTranId as $attempts) {
                foreach ($attempts as $attempt) {
                    if (($attempt['val_id'] ?? null) === $request['val_id']) {
                        return Http::response([...$attempt, 'currency' => 'BDT', 'currency_type' => 'BDT', 'risk_level' => '0']);
                    }
                }
            }

            return Http::response(['status' => 'INVALID_TRANSACTION']);
        }

        return Http::response([], 404);
    });
}

/**
 * @return array<string, mixed>
 */
function paidAttempt(Transaction $topUp, ?string $amount = null): array
{
    return [
        'status' => 'VALID',
        'tran_id' => $topUp->external_reference,
        'val_id' => 'VAL-'.$topUp->external_reference,
        'amount' => $amount ?? (string) $topUp->amount,
    ];
}

test('a stale top-up that was actually paid is credited by the sweep', function () {
    $user = reconCustomer();
    $topUp = reconTopUp($user, minutesAgo: 30);
    fakeReconGateway([$topUp->external_reference => [paidAttempt($topUp)]]);

    $summary = app(TopUpReconciler::class)->run();

    expect($summary)->toMatchArray(['checked' => 1, 'completed' => 1, 'errors' => 0])
        ->and($topUp->refresh()->status)->toBe('COMPLETED')
        ->and((float) $user->userWallet->balance)->toBe(600.00);

    // A second sweep finds nothing open and credits nothing again
    expect(app(TopUpReconciler::class)->run()['checked'])->toBe(0)
        ->and((float) $user->userWallet->refresh()->balance)->toBe(600.00);
});

test('top-ups younger than the check delay are left to their callbacks', function () {
    reconTopUp(reconCustomer(), minutesAgo: 2);
    fakeReconGateway();

    expect(app(TopUpReconciler::class)->run()['checked'])->toBe(0);
    Http::assertNothingSent();
});

test('a top-up the customer cancelled at the gateway is marked failed', function () {
    $topUp = reconTopUp(reconCustomer(), minutesAgo: 30);
    fakeReconGateway([$topUp->external_reference => [['status' => 'CANCELLED', 'tran_id' => $topUp->external_reference]]]);

    app(TopUpReconciler::class)->run();

    expect($topUp->refresh()->status)->toBe('FAILED')
        ->and($topUp->meta['failure']['reason'])->toBe('NOT_PAID');
});

test('a checkout still open at the gateway stays pending until it expires', function (int $minutesAgo, string $expectedStatus) {
    $topUp = reconTopUp(reconCustomer(), $minutesAgo);
    fakeReconGateway([$topUp->external_reference => [['status' => 'PROCESSING', 'tran_id' => $topUp->external_reference]]]);

    app(TopUpReconciler::class)->run();

    expect($topUp->refresh()->status)->toBe($expectedStatus);
    if ($expectedStatus === 'FAILED') {
        expect($topUp->meta['failure']['reason'])->toBe('EXPIRED');
    }
})->with([
    'within the expiry window' => [60 * 23, 'PENDING'],
    'past the expiry window' => [60 * 25, 'FAILED'],
]);

test('an expired top-up is still credited if the payment is confirmed later', function () {
    $user = reconCustomer();
    $topUp = reconTopUp($user, minutesAgo: 60 * 25);
    fakeReconGateway([$topUp->external_reference => [['status' => 'PROCESSING', 'tran_id' => $topUp->external_reference]]]);
    app(TopUpReconciler::class)->run();
    expect($topUp->refresh()->status)->toBe('FAILED');

    fakeReconGateway([$topUp->external_reference => [paidAttempt($topUp)]]);
    $this->postJson('/api/v1/payments/sslcommerz/ipn', [
        'tran_id' => $topUp->external_reference,
        'val_id' => 'VAL-'.$topUp->external_reference,
        'status' => 'VALID',
    ])->assertOk();

    expect($topUp->refresh()->status)->toBe('COMPLETED')
        ->and((float) $user->userWallet->balance)->toBe(600.00);
});

test('nothing is expired while the gateway cannot be reached', function () {
    $topUp = reconTopUp(reconCustomer(), minutesAgo: 60 * 48);
    fakeReconGateway(unreachable: true);

    $summary = app(TopUpReconciler::class)->run();

    expect($summary)->toMatchArray(['checked' => 1, 'errors' => 1])
        ->and($topUp->refresh()->status)->toBe('PENDING');
});

test('holds that need a staff decision are not touched by the sweep', function () {
    reconTopUp(reconCustomer(), minutesAgo: 60 * 48, meta: ['on_hold' => ['reason' => 'AMOUNT_MISMATCH']]);
    fakeReconGateway();

    expect(app(TopUpReconciler::class)->run()['checked'])->toBe(0);
    Http::assertNothingSent();
});

test('a payment held because the wallet was blocked is credited once the wallet is unblocked', function () {
    $user = reconCustomer();
    $topUp = reconTopUp($user, minutesAgo: 30);
    $user->userWallet->update(['is_blocked' => true]);
    fakeReconGateway([$topUp->external_reference => [paidAttempt($topUp)]]);

    expect(app(TopUpReconciler::class)->run()['held'])->toBe(1)
        ->and($topUp->refresh()->meta['on_hold']['reason'])->toBe('WALLET_BLOCKED');

    $user->userWallet->update(['is_blocked' => false]);
    expect(app(TopUpReconciler::class)->run()['completed'])->toBe(1)
        ->and($topUp->refresh()->status)->toBe('COMPLETED')
        ->and($topUp->meta)->not->toHaveKey('on_hold')
        ->and((float) $user->userWallet->refresh()->balance)->toBe(600.00);
});

test('the artisan command runs the sweep and reports the outcome', function () {
    $topUp = reconTopUp(reconCustomer(), minutesAgo: 30);
    fakeReconGateway([$topUp->external_reference => [paidAttempt($topUp)]]);

    $this->artisan('payments:reconcile-top-ups')->assertExitCode(0);

    expect($topUp->refresh()->status)->toBe('COMPLETED');
});

test('the artisan command fails when the gateway could not be reached', function () {
    reconTopUp(reconCustomer(), minutesAgo: 30);
    fakeReconGateway(unreachable: true);

    $this->artisan('payments:reconcile-top-ups')->assertExitCode(1);
});

test('the sweep is scheduled every ten minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->description, ReconcilePendingTopUps::class));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/10 * * * *');
});

test('staff can list unfinished top-ups and filter holds', function () {
    $user = reconCustomer();
    $waiting = reconTopUp($user, minutesAgo: 5);
    $held = reconTopUp($user, minutesAgo: 5, meta: ['on_hold' => ['reason' => 'HIGH_RISK']]);
    $done = reconTopUp($user, minutesAgo: 5);
    $done->forceFill(['status' => 'COMPLETED'])->save();
    Sanctum::actingAs(reconStaff(), ['admin']);

    $this->getJson('/api/v1/payments/admin/top-ups')
        ->assertOk()
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.0.id', $waiting->id);

    $this->getJson('/api/v1/payments/admin/top-ups?state=on_hold')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.id', $held->id)
        ->assertJsonPath('data.items.0.meta.on_hold.reason', 'HIGH_RISK');

    $this->getJson('/api/v1/payments/admin/top-ups?state=awaiting_payment')
        ->assertOk()
        ->assertJsonCount(1, 'data.items');
});

test('staff can reconcile one top-up on demand and the check is audited', function () {
    $user = reconCustomer();
    $topUp = reconTopUp($user, minutesAgo: 1);
    $staff = reconStaff();
    fakeReconGateway([$topUp->external_reference => [paidAttempt($topUp)]]);
    Sanctum::actingAs($staff, ['admin']);

    $this->postJson("/api/v1/payments/admin/top-ups/{$topUp->id}/reconcile")
        ->assertOk()
        ->assertJsonPath('data.transaction.status', 'COMPLETED');

    $audit = AuditLog::where('action', 'TOP_UP_RECONCILED')->sole();
    expect($audit->actor_id)->toBe($staff->id)
        ->and($audit->metadata['status_before'])->toBe('PENDING')
        ->and($audit->metadata['status_after'])->toBe('COMPLETED')
        ->and((float) $user->userWallet->balance)->toBe(600.00);
});

test('reconciling something that is not a gateway top-up returns 404', function () {
    $simulated = app(TransactionService::class)->topUp(reconCustomer(), 100.00, 'simulated-top-up');
    Sanctum::actingAs(reconStaff(), ['admin']);

    $this->postJson("/api/v1/payments/admin/top-ups/{$simulated->id}/reconcile")->assertNotFound();
});

test('customer tokens cannot use the staff top-up endpoints', function () {
    $user = reconCustomer();
    $topUp = reconTopUp($user, minutesAgo: 30);
    Sanctum::actingAs($user, ['user']);

    $this->getJson('/api/v1/payments/admin/top-ups')->assertForbidden();
    $this->postJson("/api/v1/payments/admin/top-ups/{$topUp->id}/reconcile")->assertForbidden();
});
