<?php

use App\Models\AgentInfo;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Transactions\Services\TransactionService;
use Modules\Wallets\Services\LedgerIntegrityService;

function ledgerWallet(float $balance = 0.00, string $kind = Wallet::KIND_USER): Wallet
{
    return Wallet::factory()->for(User::factory())->state(['kind' => $kind])->balance($balance)->create();
}

/**
 * Insert a ledger row the way the transaction services write it, so the recomputation rules can be
 * tested type by type.
 *
 * @param  array<string, mixed>  $attributes
 */
function ledgerRow(array $attributes): Transaction
{
    $initiator = $attributes['initiated_by'] ?? User::factory()->create()->id;

    return Transaction::create([
        'initiated_by' => $initiator,
        'amount' => '0.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => (string) Str::uuid(),
        ...$attributes,
    ]);
}

function ledgerReport(?array $walletIds = null): array
{
    return app(LedgerIntegrityService::class)->verify($walletIds);
}

test('balances built only from ledger rows are consistent for every transaction type', function () {
    $customer = ledgerWallet(0.00 + 50 + 1000 - 300 - 210 + 400);
    $friend = ledgerWallet(300.00);
    $agent = ledgerWallet(5000 - 400 + 200 + 4 - 1000 - 500, Wallet::KIND_AGENT);

    ledgerRow(['type' => 'SIGNUP_BONUS', 'amount' => '50.00', 'recipient_wallet_id' => $customer->id]);
    ledgerRow(['type' => 'TOP_UP', 'amount' => '1000.00', 'recipient_wallet_id' => $customer->id]);
    ledgerRow(['type' => 'TRANSFER', 'amount' => '300.00', 'sender_wallet_id' => $customer->id, 'recipient_wallet_id' => $friend->id]);
    ledgerRow(['type' => 'FLOAT_ISSUE', 'amount' => '5000.00', 'recipient_wallet_id' => $agent->id, 'agent_wallet_id' => $agent->id]);
    ledgerRow(['type' => 'CASH_IN', 'amount' => '400.00', 'recipient_wallet_id' => $customer->id, 'agent_wallet_id' => $agent->id]);
    ledgerRow([
        'type' => 'CASH_OUT', 'amount' => '200.00', 'system_fee_amount' => '10.00', 'agent_commission_amount' => '4.00',
        'sender_wallet_id' => $customer->id, 'agent_wallet_id' => $agent->id,
    ]);
    // Memo row: the commission was already credited by the CASH_OUT row
    ledgerRow(['type' => 'COMMISSION_PAYOUT', 'amount' => '4.00', 'recipient_wallet_id' => $agent->id, 'agent_wallet_id' => $agent->id]);
    ledgerRow(['type' => 'AGENT_WITHDRAWAL', 'status' => 'COMPLETED', 'amount' => '1000.00', 'sender_wallet_id' => $agent->id]);
    ledgerRow(['type' => 'AGENT_WITHDRAWAL', 'status' => 'PENDING', 'amount' => '500.00', 'sender_wallet_id' => $agent->id]);
    ledgerRow(['type' => 'AGENT_WITHDRAWAL', 'status' => 'REJECTED', 'amount' => '700.00', 'sender_wallet_id' => $agent->id]);
    ledgerRow(['type' => 'TOP_UP', 'status' => 'PENDING', 'amount' => '999.00', 'recipient_wallet_id' => $customer->id, 'external_reference' => 'TOPUP-A']);
    ledgerRow(['type' => 'TOP_UP', 'status' => 'FAILED', 'amount' => '888.00', 'recipient_wallet_id' => $customer->id, 'external_reference' => 'TOPUP-B']);

    $report = ledgerReport();

    expect($report['healthy'])->toBeTrue()
        ->and($report['wallets_checked'])->toBe(3)
        ->and($report['wallet_mismatches'])->toBe([]);
});

test('a balance changed outside the ledger is reported as unexplained', function () {
    $wallet = ledgerWallet(1000.00);
    ledgerRow(['type' => 'TOP_UP', 'amount' => '1000.00', 'recipient_wallet_id' => $wallet->id]);
    $wallet->forceFill(['balance' => '1010.00'])->save();

    $report = ledgerReport();

    expect($report['healthy'])->toBeFalse()
        ->and($report['wallet_mismatches'])->toBe([[
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'kind' => 'USER',
            'stored_balance' => '1010.00',
            'ledger_balance' => '1000.00',
            'difference' => '10.00',
            'classification' => LedgerIntegrityService::UNEXPLAINED,
        ]]);
});

test('the old 50.00 opening balance with no ledger row is a known legacy gap, not a failure', function () {
    $legacy = ledgerWallet(250.00);
    ledgerRow(['type' => 'TOP_UP', 'amount' => '200.00', 'recipient_wallet_id' => $legacy->id]);

    $report = ledgerReport();

    expect($report['healthy'])->toBeTrue()
        ->and($report['wallet_mismatches'][0]['classification'])->toBe(LedgerIntegrityService::LEGACY_OPENING_BALANCE);
});

test('a 50.00 gap on a wallet that already received the signup bonus is unexplained', function () {
    $wallet = ledgerWallet(100.00);
    ledgerRow(['type' => 'SIGNUP_BONUS', 'amount' => '50.00', 'recipient_wallet_id' => $wallet->id]);

    $report = ledgerReport();

    expect($report['healthy'])->toBeFalse()
        ->and($report['wallet_mismatches'][0]['classification'])->toBe(LedgerIntegrityService::UNEXPLAINED);
});

test('an agent commission total that disagrees with the cash-outs is reported', function () {
    $agentWallet = ledgerWallet(0.00 + 104.00, Wallet::KIND_AGENT);
    AgentInfo::create(['user_id' => $agentWallet->user_id, 'commission_rate' => '0.0200', 'status' => 'APPROVED', 'total_commission' => '9.00']);
    ledgerRow([
        'type' => 'CASH_OUT', 'amount' => '100.00', 'agent_commission_amount' => '4.00',
        'agent_id' => $agentWallet->user_id, 'agent_wallet_id' => $agentWallet->id,
    ]);

    $report = ledgerReport();

    expect($report['healthy'])->toBeFalse()
        ->and($report['agents_checked'])->toBe(1)
        ->and($report['commission_mismatches'])->toBe([[
            'user_id' => $agentWallet->user_id,
            'stored_total' => '9.00',
            'ledger_total' => '4.00',
            'difference' => '5.00',
        ]]);
});

test('the check can be limited to specific wallets', function () {
    $clean = ledgerWallet(0.00);
    ledgerWallet(10.00);

    expect(ledgerReport([$clean->id]))->toMatchArray(['wallets_checked' => 1, 'healthy' => true]);
});

test('the command exits 0 on a consistent ledger and logs a warning for legacy wallets', function () {
    Log::spy();
    ledgerWallet(50.00);

    $this->artisan('wallets:verify-ledger')
        ->expectsOutputToContain('Ledger is consistent.')
        ->assertExitCode(0);

    Log::shouldHaveReceived('warning')->once();
    Log::shouldNotHaveReceived('critical');
});

test('the command exits 1 and logs a critical alert on a mismatch', function () {
    Log::spy();
    ledgerWallet(12.34);

    $this->artisan('wallets:verify-ledger')
        ->expectsOutputToContain('Ledger mismatch detected.')
        ->assertExitCode(1);

    Log::shouldHaveReceived('critical')->once();
});

test('the command can print the report as JSON', function () {
    $wallet = ledgerWallet(0.00);

    $this->artisan('wallets:verify-ledger', ['--wallet' => [$wallet->id], '--json' => true])
        ->expectsOutputToContain('"healthy": true')
        ->assertExitCode(0);
});

test('real top-ups through the transaction service keep the ledger consistent', function () {
    $user = User::factory()->create();
    Wallet::factory()->for($user)->create();
    $service = app(TransactionService::class);

    $service->topUp($user, 120.50, 'ledger-top-up-1');
    $service->topUp($user, 79.50, 'ledger-top-up-2');
    $service->createPendingTopUp($user, 500.00, 'ledger-pending', 'TOPUP-LEDGER-1', 'sslcommerz');

    expect($user->userWallet->balance)->toBe('200.00')
        ->and(ledgerReport()['healthy'])->toBeTrue();
});

test('the check runs daily', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'wallets:verify-ledger'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 2 * * *');
});
