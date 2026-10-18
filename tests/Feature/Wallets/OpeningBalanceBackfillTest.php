<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Transactions\Services\SignupBonusService;
use Modules\Wallets\Services\LedgerIntegrityService;
use Modules\Wallets\Services\OpeningBalanceBackfillService;

/**
 * A wallet as the old code left it: $legacyBalance from the column default plus $topUps recorded in the ledger.
 */
function legacyWallet(float $topUps = 0.00, float $legacyBalance = 50.00, string $kind = Wallet::KIND_USER): Wallet
{
    $wallet = Wallet::factory()->for(User::factory())->state(['kind' => $kind])->balance($legacyBalance + $topUps)->create();

    if ($topUps > 0) {
        Transaction::create([
            'user_id' => $wallet->user_id,
            'recipient_id' => $wallet->user_id,
            'initiated_by' => $wallet->user_id,
            'type' => 'TOP_UP',
            'amount' => number_format($topUps, 2, '.', ''),
            'system_fee_amount' => '0.00',
            'system_fee_rate' => '0.0000',
            'agent_commission_amount' => '0.00',
            'agent_commission_rate' => '0.0000',
            'currency' => 'BDT',
            'status' => 'COMPLETED',
            'idempotency_key' => (string) Str::uuid(),
            'recipient_wallet_id' => $wallet->id,
        ]);
    }

    return $wallet;
}

test('the dry run lists legacy wallets and writes nothing', function () {
    legacyWallet(200.00);
    legacyWallet(kind: Wallet::KIND_AGENT);

    $this->artisan('wallets:backfill-opening-balances')
        ->expectsOutputToContain('Dry run: 2 wallet(s)')
        ->assertExitCode(0);

    expect(Transaction::where('type', 'SIGNUP_BONUS')->count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

test('applying writes one backfilled row per legacy wallet without changing balances', function () {
    $wallet = legacyWallet(200.00);

    $this->artisan('wallets:backfill-opening-balances', ['--apply' => true])
        ->expectsOutputToContain('Wrote 1 opening-balance row(s).')
        ->assertExitCode(0);

    $row = Transaction::where('type', 'SIGNUP_BONUS')->sole();
    expect($row->amount)->toBe('50.00')
        ->and($row->status)->toBe('COMPLETED')
        ->and($row->recipient_wallet_id)->toBe($wallet->id)
        ->and($row->recipient_id)->toBe($wallet->user_id)
        ->and($row->recipient_wallet_balance_after)->toBe('250.00')
        ->and($row->idempotency_key)->toBe(SignupBonusService::idempotencyKey($wallet))
        ->and($row->meta['backfilled'])->toBeTrue()
        ->and($row->meta['reason'])->toBe('LEGACY_OPENING_BALANCE')
        ->and($row->meta['effective_at'])->toBe($wallet->created_at->toIso8601String())
        ->and($wallet->refresh()->balance)->toBe('250.00');

    $audit = AuditLog::where('action', 'OPENING_BALANCE_BACKFILLED')->sole();
    expect($audit->auditable_id)->toBe($wallet->id)
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->metadata['ledger_balance_before'])->toBe('200.00');
});

test('after the backfill the ledger check finds no legacy gaps', function () {
    legacyWallet(120.00);
    legacyWallet(kind: Wallet::KIND_AGENT);

    expect(app(LedgerIntegrityService::class)->verify()['wallet_mismatches'])->toHaveCount(2);

    $this->artisan('wallets:backfill-opening-balances', ['--apply' => true])->assertExitCode(0);

    $report = app(LedgerIntegrityService::class)->verify();
    expect($report['wallet_mismatches'])->toBe([])
        ->and($report['healthy'])->toBeTrue();
});

test('running it again writes nothing', function () {
    legacyWallet(10.00);

    $this->artisan('wallets:backfill-opening-balances', ['--apply' => true])->assertExitCode(0);
    $this->artisan('wallets:backfill-opening-balances', ['--apply' => true])
        ->expectsOutputToContain('No wallets need an opening-balance row.')
        ->assertExitCode(0);

    expect(Transaction::where('type', 'SIGNUP_BONUS')->count())->toBe(1);
});

test('wallets that do not carry exactly the legacy gap are left alone', function () {
    $consistent = legacyWallet(100.00, legacyBalance: 0.00);
    $otherGap = legacyWallet(100.00, legacyBalance: 12.34);
    $service = app(OpeningBalanceBackfillService::class);

    expect($service->backfill($consistent->id))->toBeNull()
        ->and($service->backfill($otherGap->id))->toBeNull()
        ->and(Transaction::where('type', 'SIGNUP_BONUS')->exists())->toBeFalse();
});

test('a wallet that already has a signup bonus row is never given a second one', function () {
    $wallet = legacyWallet();
    $service = app(OpeningBalanceBackfillService::class);

    expect($service->backfill($wallet->id))->not->toBeNull();

    // Even if its balance later drifts by exactly the legacy amount again
    $wallet->forceFill(['balance' => '100.00'])->save();

    expect($service->backfill($wallet->id))->toBeNull()
        ->and(Transaction::where('type', 'SIGNUP_BONUS')->where('recipient_wallet_id', $wallet->id)->count())->toBe(1);
});

test('the backfilled row shows up in the owner history as a signup bonus', function () {
    $wallet = legacyWallet();
    $owner = $wallet->user;
    $this->seedRolesAndPermissions();
    $owner->roles()->attach(Role::where('name', 'USER')->firstOrFail()->id, ['assigned_at' => now()]);
    app(OpeningBalanceBackfillService::class)->backfill($wallet->id);
    Sanctum::actingAs($owner, ['user']);

    $this->getJson('/api/v1/transactions/history?type=SIGNUP_BONUS')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.meta.backfilled', true);
});

test('production asks for confirmation before writing', function () {
    legacyWallet();
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('wallets:backfill-opening-balances', ['--apply' => true])
        ->expectsConfirmation('Write 1 opening-balance row(s) to the production ledger?', 'no')
        ->assertExitCode(1);

    expect(Transaction::where('type', 'SIGNUP_BONUS')->exists())->toBeFalse();
});
