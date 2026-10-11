<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('migration backfill links all transaction types to participating wallet IDs according to the slot specification', function () {
    $user = User::factory()->create();
    $recipient = User::factory()->create();
    $agent = User::factory()->create();

    $userRole = Role::where('name', 'USER')->firstOrFail();
    $agentRole = Role::where('name', 'AGENT')->firstOrFail();

    $user->roles()->attach($userRole->id, ['assigned_at' => now()]);
    $recipient->roles()->attach($userRole->id, ['assigned_at' => now()]);
    $agent->roles()->attach($agentRole->id, ['assigned_at' => now()]);

    $userWallet = Wallet::factory()->for($user)->create(['kind' => 'USER']);
    $recipientWallet = Wallet::factory()->for($recipient)->create(['kind' => 'USER']);
    $agentWallet = Wallet::factory()->for($agent)->create(['kind' => 'AGENT']);

    $now = now();

    // 1. TOP_UP
    $topUpId = DB::table('transactions')->insertGetId([
        'user_id' => $user->id,
        'recipient_id' => $user->id,
        'initiated_by' => $user->id,
        'type' => 'TOP_UP',
        'amount' => 100.00,
        'system_fee_amount' => 0.00,
        'currency' => 'BDT',
        'idempotency_key' => 'mig-topup-'.uniqid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // 2. TRANSFER
    $transferId = DB::table('transactions')->insertGetId([
        'user_id' => $user->id,
        'sender_id' => $user->id,
        'recipient_id' => $recipient->id,
        'initiated_by' => $user->id,
        'type' => 'TRANSFER',
        'amount' => 50.00,
        'system_fee_amount' => 0.00,
        'currency' => 'BDT',
        'idempotency_key' => 'mig-transfer-'.uniqid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // 3. CASH_IN
    $cashInId = DB::table('transactions')->insertGetId([
        'user_id' => $user->id,
        'recipient_id' => $user->id,
        'agent_id' => $agent->id,
        'initiated_by' => $user->id,
        'type' => 'CASH_IN',
        'amount' => 200.00,
        'system_fee_amount' => 0.00,
        'currency' => 'BDT',
        'idempotency_key' => 'mig-cashin-'.uniqid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // 4. CASH_OUT
    $cashOutId = DB::table('transactions')->insertGetId([
        'user_id' => $user->id,
        'sender_id' => $user->id,
        'agent_id' => $agent->id,
        'initiated_by' => $user->id,
        'type' => 'CASH_OUT',
        'amount' => 300.00,
        'system_fee_amount' => 15.00,
        'currency' => 'BDT',
        'idempotency_key' => 'mig-cashout-'.uniqid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // 5. AGENT_WITHDRAWAL
    $withdrawalId = DB::table('transactions')->insertGetId([
        'user_id' => $agent->id,
        'sender_id' => $agent->id,
        'agent_id' => $agent->id,
        'initiated_by' => $agent->id,
        'type' => 'AGENT_WITHDRAWAL',
        'amount' => 400.00,
        'system_fee_amount' => 0.00,
        'currency' => 'BDT',
        'idempotency_key' => 'mig-withdrawal-'.uniqid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // 6. COMMISSION_PAYOUT
    $commissionId = DB::table('transactions')->insertGetId([
        'recipient_id' => $agent->id,
        'agent_id' => $agent->id,
        'initiated_by' => $user->id,
        'type' => 'COMMISSION_PAYOUT',
        'amount' => 10.00,
        'system_fee_amount' => 0.00,
        'currency' => 'BDT',
        'idempotency_key' => 'mig-commission-'.uniqid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Re-run the migration up logic
    $migration = require database_path('migrations/2026_10_11_095420_add_wallet_ids_to_transactions_table.php');
    $migration->performBackfill();

    // Verify TOP_UP: recipient_wallet_id is user USER wallet, others null
    $topUp = DB::table('transactions')->where('id', $topUpId)->first();
    expect($topUp->sender_wallet_id)->toBeNull()
        ->and($topUp->recipient_wallet_id)->toBe($userWallet->id)
        ->and($topUp->agent_wallet_id)->toBeNull();

    // Verify TRANSFER: sender_wallet_id is sender USER wallet, recipient_wallet_id is recipient USER wallet
    $transfer = DB::table('transactions')->where('id', $transferId)->first();
    expect($transfer->sender_wallet_id)->toBe($userWallet->id)
        ->and($transfer->recipient_wallet_id)->toBe($recipientWallet->id)
        ->and($transfer->agent_wallet_id)->toBeNull();

    // Verify CASH_IN: recipient_wallet_id is user USER wallet, agent_wallet_id is agent AGENT wallet
    $cashIn = DB::table('transactions')->where('id', $cashInId)->first();
    expect($cashIn->sender_wallet_id)->toBeNull()
        ->and($cashIn->recipient_wallet_id)->toBe($userWallet->id)
        ->and($cashIn->agent_wallet_id)->toBe($agentWallet->id);

    // Verify CASH_OUT: sender_wallet_id is user USER wallet, agent_wallet_id is agent AGENT wallet
    $cashOut = DB::table('transactions')->where('id', $cashOutId)->first();
    expect($cashOut->sender_wallet_id)->toBe($userWallet->id)
        ->and($cashOut->recipient_wallet_id)->toBeNull()
        ->and($cashOut->agent_wallet_id)->toBe($agentWallet->id);

    // Verify AGENT_WITHDRAWAL: sender_wallet_id is agent AGENT wallet, others null
    $withdrawal = DB::table('transactions')->where('id', $withdrawalId)->first();
    expect($withdrawal->sender_wallet_id)->toBe($agentWallet->id)
        ->and($withdrawal->recipient_wallet_id)->toBeNull()
        ->and($withdrawal->agent_wallet_id)->toBeNull();

    // Verify COMMISSION_PAYOUT: recipient_wallet_id and agent_wallet_id are agent AGENT wallet
    $commission = DB::table('transactions')->where('id', $commissionId)->first();
    expect($commission->sender_wallet_id)->toBeNull()
        ->and($commission->recipient_wallet_id)->toBe($agentWallet->id)
        ->and($commission->agent_wallet_id)->toBe($agentWallet->id);
});

test('migration leaves unlinkable rows null and reports them in unlinked summary', function () {
    // User who holds AGENT role and ONLY has an AGENT wallet
    $dualUser = User::factory()->create();
    $agentRole = Role::where('name', 'AGENT')->firstOrFail();
    $dualUser->roles()->attach($agentRole->id, ['assigned_at' => now()]);

    $agentWallet = Wallet::factory()->for($dualUser)->create(['kind' => 'AGENT']);

    $now = now();

    // Legacy transaction: TOP_UP executed by this user, needing a USER wallet
    $legacyTxId = DB::table('transactions')->insertGetId([
        'user_id' => $dualUser->id,
        'recipient_id' => $dualUser->id,
        'initiated_by' => $dualUser->id,
        'type' => 'TOP_UP',
        'amount' => 100.00,
        'system_fee_amount' => 0.00,
        'currency' => 'BDT',
        'idempotency_key' => 'legacy-topup-'.uniqid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $migration = require database_path('migrations/2026_10_11_095420_add_wallet_ids_to_transactions_table.php');

    // Backfill leaves slot null because no USER wallet exists for this user
    $migration->performBackfill();
    expect(DB::table('transactions')->where('id', $legacyTxId)->value('recipient_wallet_id'))->toBeNull();

    // Audit summary detects the unlinked required slot
    $summary = $migration->getUnlinkedSlotsSummary();
    expect($summary['total'])->toBeGreaterThanOrEqual(1)
        ->and($summary['by_type']['TOP_UP'])->toBeGreaterThanOrEqual(1)
        ->and($migration->countUnlinkedRequiredSlots())->toBeGreaterThanOrEqual(1);
});
