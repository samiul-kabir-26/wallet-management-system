<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Reports\Services\LedgerExportService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    // 10:00 in Dhaka (UTC+6)
    $this->travelTo(Carbon::parse('2026-10-18 04:00:00', 'UTC'));
});

function reportStaff(string $role = 'ADMIN'): User
{
    $staff = User::factory()->create();
    $staff->roles()->attach(Role::where('name', $role)->firstOrFail()->id, ['assigned_at' => now()]);

    return $staff;
}

function reportCustomer(string $name = 'Rahim Uddin'): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->roles()->attach(Role::where('name', 'USER')->firstOrFail()->id, ['assigned_at' => now()]);

    return $user;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function reportTx(string $type, string $amount, array $attributes = []): Transaction
{
    $transaction = Transaction::create([
        'initiated_by' => $attributes['initiated_by'] ?? User::factory()->create()->id,
        'type' => $type,
        'amount' => $amount,
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => (string) Str::uuid(),
        ...array_diff_key($attributes, ['created_at' => true]),
    ]);

    if (isset($attributes['created_at'])) {
        $transaction->forceFill(['created_at' => $attributes['created_at']])->save();
    }

    return $transaction;
}

test('the overview nets reversals out of fees, commission and bonuses', function () {
    reportTx('CASH_OUT', '1000.00', ['system_fee_amount' => '50.00', 'agent_commission_amount' => '10.00']);
    reportTx('CASH_OUT', '2000.00', ['system_fee_amount' => '100.00', 'agent_commission_amount' => '20.00']);
    reportTx('REVERSAL', '1000.00', ['system_fee_amount' => '50.00', 'agent_commission_amount' => '10.00', 'meta' => ['reverses' => ['id' => 1, 'type' => 'CASH_OUT']]]);
    reportTx('SIGNUP_BONUS', '50.00');
    reportTx('SIGNUP_BONUS', '50.00');
    reportTx('SIGNUP_BONUS', '50.00', ['meta' => ['backfilled' => true]]);
    reportTx('REVERSAL', '50.00', ['meta' => ['reverses' => ['id' => 9, 'type' => 'SIGNUP_BONUS']]]);
    reportTx('TRANSFER', '300.00', ['status' => 'FAILED']);
    Sanctum::actingAs(reportStaff(), ['admin']);

    $this->getJson('/api/v1/reports/overview?days=7')
        ->assertOk()
        ->assertJsonPath('data.range.days', 7)
        ->assertJsonPath('data.range.to', '2026-10-18')
        ->assertJsonPath('data.range.from', '2026-10-12')
        ->assertJsonPath('data.totals.fees_earned', 100)
        ->assertJsonPath('data.totals.commission_paid', 20)
        ->assertJsonPath('data.totals.signup_bonus_paid', 50)
        ->assertJsonPath('data.totals.by_type.CASH_OUT.count', 2)
        ->assertJsonPath('data.totals.by_type.CASH_OUT.amount', 3000)
        ->assertJsonPath('data.totals.volume', 3150)
        ->assertJsonCount(7, 'data.daily');
});

test('transactions are grouped by business day, not UTC day', function () {
    // 2026-10-17 19:30 UTC is 2026-10-18 01:30 in Dhaka
    reportTx('TRANSFER', '400.00', ['created_at' => Carbon::parse('2026-10-17 19:30:00', 'UTC')]);
    // 2026-10-17 17:30 UTC is still 2026-10-17 23:30 in Dhaka
    reportTx('TRANSFER', '100.00', ['created_at' => Carbon::parse('2026-10-17 17:30:00', 'UTC')]);
    Sanctum::actingAs(reportStaff(), ['admin']);

    $daily = collect($this->getJson('/api/v1/reports/overview?days=2')->assertOk()->json('data.daily'))->keyBy('date');

    expect($daily['2026-10-18']['volume'])->toEqual(400)
        ->and($daily['2026-10-17']['volume'])->toEqual(100);
});

test('signups count new customers and agents only, and queues show waiting work', function () {
    reportCustomer();
    reportCustomer();
    reportStaff(); // staff account: not a signup
    $agent = User::factory()->create();
    $agent->roles()->attach(Role::where('name', 'AGENT')->firstOrFail()->id, ['assigned_at' => now()]);
    $wallet = Wallet::factory()->agent()->for($agent)->balance(700.00)->create();
    reportTx('AGENT_WITHDRAWAL', '300.00', ['status' => 'PENDING', 'agent_id' => $agent->id, 'sender_wallet_id' => $wallet->id]);
    reportTx('TOP_UP', '500.00', ['status' => 'PENDING', 'external_reference' => 'TOPUP-X']);
    Sanctum::actingAs(reportStaff(), ['admin']);

    $this->getJson('/api/v1/reports/overview')
        ->assertOk()
        ->assertJsonPath('data.totals.signups', 3)
        ->assertJsonPath('data.queues.pending_withdrawals.count', 1)
        ->assertJsonPath('data.queues.pending_withdrawals.amount', 300)
        ->assertJsonPath('data.queues.unfinished_top_ups.count', 1)
        ->assertJsonPath('data.e_money.agent_wallets', 700);
});

test('the overview needs transactions.view_any and an admin token', function () {
    $auditor = User::factory()->create();
    $auditor->roles()->attach(Role::create(['name' => 'AUDITOR', 'panel' => 'admin', 'is_system' => false])->id, ['assigned_at' => now()]);
    Sanctum::actingAs($auditor, ['admin']);
    $this->getJson('/api/v1/reports/overview')->assertForbidden();

    Sanctum::actingAs(reportCustomer(), ['user']);
    $this->getJson('/api/v1/reports/overview')->assertForbidden();

    Sanctum::actingAs(reportStaff(), ['admin']);
    $this->getJson('/api/v1/reports/overview?days=365')->assertUnprocessable();
});

test('the ledger export streams a CSV with the same filters as the ledger, and is audited', function () {
    $alice = reportCustomer('Alice Rahman');
    $bob = reportCustomer('Bob Karim');
    reportTx('TRANSFER', '250.00', ['sender_id' => $alice->id, 'recipient_id' => $bob->id, 'description' => 'Rent share']);
    reportTx('TOP_UP', '999.00', ['recipient_id' => $alice->id]);
    $staff = reportStaff('SUPER_ADMIN');
    Sanctum::actingAs($staff, ['admin']);

    $response = $this->get('/api/v1/reports/ledger.csv?type=TRANSFER')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('X-Export-Rows', '1');

    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and(str_getcsv(substr($lines[0], 3), escape: ''))->toBe(LedgerExportService::COLUMNS)
        ->and($lines)->toHaveCount(2)
        ->and($lines[1])->toContain('TRANSFER')->toContain('Alice Rahman')->toContain('Bob Karim')->toContain('Rent share')
        ->and($csv)->not->toContain('999.00');

    $audit = AuditLog::where('action', 'TRANSACTIONS_EXPORTED')->sole();
    expect($audit->actor_id)->toBe($staff->id)
        ->and($audit->metadata['rows'])->toBe(1)
        ->and($audit->metadata['filters'])->toBe(['type' => 'TRANSFER']);
});

test('cells that look like spreadsheet formulas are neutralised', function () {
    reportTx('TRANSFER', '10.00', ['description' => '=HYPERLINK("http://evil","click")']);
    Sanctum::actingAs(reportStaff('SUPER_ADMIN'), ['admin']);

    $csv = $this->get('/api/v1/reports/ledger.csv')->assertOk()->streamedContent();

    expect($csv)->toContain("'=HYPERLINK")
        ->and($csv)->not->toContain(',=HYPERLINK');
});

test('ADMIN cannot export by default, and huge exports are refused', function () {
    reportTx('TRANSFER', '10.00');
    reportTx('TRANSFER', '20.00');

    Sanctum::actingAs(reportStaff('ADMIN'), ['admin']);
    $this->getJson('/api/v1/reports/ledger.csv')->assertForbidden();

    config(['reports.export_max_rows' => 1]);
    Sanctum::actingAs(reportStaff('SUPER_ADMIN'), ['admin']);
    $this->getJson('/api/v1/reports/ledger.csv')
        ->assertUnprocessable()
        ->assertJsonPath('errors.from_date.0', 'This export has 2 rows; narrow the filters to at most 1.');
});
