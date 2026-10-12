<?php

use App\Models\AgentInfo;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\SystemSettingsSeeder;
use Laravel\Sanctum\Sanctum;
use Modules\Transactions\Resources\TransactionResource;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->seed(SystemSettingsSeeder::class);
});

function createScopedUser(string ...$roles): User
{
    $user = User::factory()->create();
    foreach ($roles as $roleName) {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user->roles()->attach($role->id, ['assigned_at' => now()]);
        if ($roleName === 'USER') {
            Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000.00]);
        } elseif ($roleName === 'AGENT') {
            AgentInfo::create([
                'user_id' => $user->id,
                'commission_rate' => 0.0100,
                'status' => 'APPROVED',
            ]);
            Wallet::factory()->agent()->create(['user_id' => $user->id, 'balance' => 2000.00]);
        }
    }

    return $user;
}

test('service operations populate exact wallet slots in database', function () {
    $customer = createScopedUser('USER');
    $recipient = createScopedUser('USER');
    $agent = createScopedUser('AGENT');

    $custWallet = $customer->wallets()->where('kind', Wallet::KIND_USER)->firstOrFail();
    $recipWallet = $recipient->wallets()->where('kind', Wallet::KIND_USER)->firstOrFail();
    $agentWallet = $agent->wallets()->where('kind', Wallet::KIND_AGENT)->firstOrFail();

    // 1. TOP_UP
    Sanctum::actingAs($customer, ['user']);
    $topUpRes = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 100.00,
        'idempotency_key' => 'slot-topup-'.uniqid(),
    ])->assertStatus(201);
    $topUpTx = Transaction::findOrFail($topUpRes->json('data.transaction.id'));
    expect($topUpTx->sender_wallet_id)->toBeNull()
        ->and($topUpTx->recipient_wallet_id)->toBe($custWallet->id)
        ->and($topUpTx->agent_wallet_id)->toBeNull();

    // 2. TRANSFER
    $transferRes = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_id' => $recipient->id,
        'amount' => 50.00,
        'idempotency_key' => 'slot-transfer-'.uniqid(),
    ])->assertStatus(201);
    $transferTx = Transaction::findOrFail($transferRes->json('data.transaction.id'));
    expect($transferTx->sender_wallet_id)->toBe($custWallet->id)
        ->and($transferTx->recipient_wallet_id)->toBe($recipWallet->id)
        ->and($transferTx->agent_wallet_id)->toBeNull();

    // 3. CASH_IN
    $cashInRes = $this->postJson('/api/v1/transactions/cash-in', [
        'agent_phone' => $agent->phone_number,
        'amount' => 60.00,
        'idempotency_key' => 'slot-cashin-'.uniqid(),
    ])->assertStatus(201);
    $cashInTx = Transaction::findOrFail($cashInRes->json('data.transaction.id'));
    expect($cashInTx->sender_wallet_id)->toBeNull()
        ->and($cashInTx->recipient_wallet_id)->toBe($custWallet->id)
        ->and($cashInTx->agent_wallet_id)->toBe($agentWallet->id);

    // 4. CASH_OUT (creates CASH_OUT and COMMISSION_PAYOUT)
    $cashOutRes = $this->postJson('/api/v1/transactions/cash-out', [
        'agent_phone' => $agent->phone_number,
        'amount' => 40.00,
        'idempotency_key' => 'slot-cashout-'.uniqid(),
    ])->assertStatus(201);
    $cashOutTx = Transaction::findOrFail($cashOutRes->json('data.transaction.id'));
    expect($cashOutTx->sender_wallet_id)->toBe($custWallet->id)
        ->and($cashOutTx->recipient_wallet_id)->toBeNull()
        ->and($cashOutTx->agent_wallet_id)->toBe($agentWallet->id);

    $commTx = Transaction::where('type', 'COMMISSION_PAYOUT')->where('meta->related_transaction_id', $cashOutTx->id)->firstOrFail();
    expect($commTx->sender_wallet_id)->toBeNull()
        ->and($commTx->recipient_wallet_id)->toBe($agentWallet->id)
        ->and($commTx->agent_wallet_id)->toBe($agentWallet->id);

    // 5. AGENT_WITHDRAWAL
    Sanctum::actingAs($agent, ['agent']);
    $withdrawRes = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 10.00,
        'idempotency_key' => 'slot-withdraw-'.uniqid(),
    ])->assertStatus(201);
    $withdrawTx = Transaction::findOrFail($withdrawRes->json('data.transaction.id'));
    expect($withdrawTx->sender_wallet_id)->toBe($agentWallet->id)
        ->and($withdrawTx->recipient_wallet_id)->toBeNull()
        ->and($withdrawTx->agent_wallet_id)->toBeNull();
});

test('matrix: visibility in history and detail view across viewer types and token abilities', function () {
    $alice = createScopedUser('USER');
    $bob = createScopedUser('USER');
    $agent = createScopedUser('AGENT');
    $dual = createScopedUser('USER', 'AGENT');
    $stranger = createScopedUser('USER');
    $admin = createScopedUser('ADMIN');

    $aliceWallet = $alice->wallets()->where('kind', Wallet::KIND_USER)->firstOrFail();
    $bobWallet = $bob->wallets()->where('kind', Wallet::KIND_USER)->firstOrFail();
    $agentWallet = $agent->wallets()->where('kind', Wallet::KIND_AGENT)->firstOrFail();
    $dualUserWallet = $dual->wallets()->where('kind', Wallet::KIND_USER)->firstOrFail();
    $dualAgentWallet = $dual->wallets()->where('kind', Wallet::KIND_AGENT)->firstOrFail();

    // Tx 1: TOP_UP by Alice
    $txTopUp = Transaction::create([
        'user_id' => $alice->id,
        'initiated_by' => $alice->id,
        'sender_wallet_id' => null,
        'recipient_wallet_id' => $aliceWallet->id,
        'agent_wallet_id' => null,
        'type' => 'TOP_UP',
        'amount' => '100.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'm-tx-topup',
    ]);

    // Tx 2: TRANSFER Alice -> Bob
    $txTransfer = Transaction::create([
        'user_id' => $alice->id,
        'sender_id' => $alice->id,
        'recipient_id' => $bob->id,
        'initiated_by' => $alice->id,
        'sender_wallet_id' => $aliceWallet->id,
        'recipient_wallet_id' => $bobWallet->id,
        'agent_wallet_id' => null,
        'type' => 'TRANSFER',
        'amount' => '50.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'm-tx-transfer',
    ]);

    // Tx 3: CASH_IN Alice via Dual as Agent
    $txCashIn = Transaction::create([
        'user_id' => $alice->id,
        'agent_id' => $dual->id,
        'initiated_by' => $alice->id,
        'sender_wallet_id' => null,
        'recipient_wallet_id' => $aliceWallet->id,
        'agent_wallet_id' => $dualAgentWallet->id,
        'type' => 'CASH_IN',
        'amount' => '80.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'm-tx-cashin',
    ]);

    // Tx 4: CASH_OUT Dual (as customer) via Agent
    $txCashOut = Transaction::create([
        'user_id' => $dual->id,
        'agent_id' => $agent->id,
        'initiated_by' => $dual->id,
        'sender_wallet_id' => $dualUserWallet->id,
        'recipient_wallet_id' => null,
        'agent_wallet_id' => $agentWallet->id,
        'type' => 'CASH_OUT',
        'amount' => '70.00',
        'system_fee_amount' => '1.00',
        'system_fee_rate' => '0.0100',
        'agent_commission_amount' => '0.70',
        'agent_commission_rate' => '0.0100',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'm-tx-cashout',
    ]);

    // Tx 5: AGENT_WITHDRAWAL by Dual (as agent)
    $txAgentWithdrawal = Transaction::create([
        'user_id' => $dual->id,
        'initiated_by' => $dual->id,
        'sender_wallet_id' => $dualAgentWallet->id,
        'recipient_wallet_id' => null,
        'agent_wallet_id' => null,
        'type' => 'AGENT_WITHDRAWAL',
        'amount' => '30.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'm-tx-withdrawal',
    ]);

    // Tx 6: COMMISSION_PAYOUT to Dual (as agent)
    $txCommission = Transaction::create([
        'user_id' => $dual->id,
        'initiated_by' => $dual->id,
        'recipient_id' => $dual->id,
        'agent_id' => $dual->id,
        'sender_wallet_id' => null,
        'recipient_wallet_id' => $dualAgentWallet->id,
        'agent_wallet_id' => $dualAgentWallet->id,
        'type' => 'COMMISSION_PAYOUT',
        'amount' => '0.80',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.80',
        'agent_commission_rate' => '0.0100',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'm-tx-commission',
    ]);

    // --- 1. Alice (customer with 'user' token) ---
    Sanctum::actingAs($alice, ['user']);
    $aliceHistory = $this->getJson('/api/v1/transactions/history')->assertStatus(200);
    $aliceIds = collect($aliceHistory->json('data.transactions'))->pluck('id')->all();
    expect($aliceIds)->toEqualCanonicalizing([$txTopUp->id, $txTransfer->id, $txCashIn->id]);
    $this->getJson("/api/v1/transactions/{$txTopUp->id}")->assertStatus(200);
    $this->getJson("/api/v1/transactions/{$txTransfer->id}")->assertStatus(200);
    $this->getJson("/api/v1/transactions/{$txCashIn->id}")->assertStatus(200);
    $this->getJson("/api/v1/transactions/{$txCashOut->id}")->assertStatus(403);
    $this->getJson("/api/v1/transactions/{$txAgentWithdrawal->id}")->assertStatus(403);

    // --- 2. Bob (customer with 'user' token) ---
    Sanctum::actingAs($bob, ['user']);
    $bobHistory = $this->getJson('/api/v1/transactions/history')->assertStatus(200);
    $bobIds = collect($bobHistory->json('data.transactions'))->pluck('id')->all();
    expect($bobIds)->toEqualCanonicalizing([$txTransfer->id]);
    $this->getJson("/api/v1/transactions/{$txTransfer->id}")->assertStatus(200);
    $this->getJson("/api/v1/transactions/{$txTopUp->id}")->assertStatus(403);

    // --- 3. Agent (with 'agent' token) ---
    Sanctum::actingAs($agent, ['agent']);
    $agentHistory = $this->getJson('/api/v1/transactions/history')->assertStatus(200);
    $agentIds = collect($agentHistory->json('data.transactions'))->pluck('id')->all();
    expect($agentIds)->toEqualCanonicalizing([$txCashOut->id]);
    $this->getJson("/api/v1/transactions/{$txCashOut->id}")->assertStatus(200);
    $this->getJson("/api/v1/transactions/{$txTopUp->id}")->assertStatus(403);

    // --- 4. Dual-role user with 'user' token ---
    Sanctum::actingAs($dual, ['user']);
    $dualUserHistory = $this->getJson('/api/v1/transactions/history')->assertStatus(200);
    $dualUserIds = collect($dualUserHistory->json('data.transactions'))->pluck('id')->all();
    expect($dualUserIds)->toEqualCanonicalizing([$txCashOut->id]);
    $this->getJson("/api/v1/transactions/{$txCashOut->id}")->assertStatus(200);
    $this->getJson("/api/v1/transactions/{$txAgentWithdrawal->id}")->assertStatus(403);
    $this->getJson("/api/v1/transactions/{$txCommission->id}")->assertStatus(403);
    $this->getJson("/api/v1/transactions/{$txCashIn->id}")->assertStatus(403);

    // --- 5. Dual-role user with 'agent' token ---
    Sanctum::actingAs($dual, ['agent']);
    $dualAgentHistory = $this->getJson('/api/v1/transactions/history')->assertStatus(200);
    $dualAgentIds = collect($dualAgentHistory->json('data.transactions'))->pluck('id')->all();
    expect($dualAgentIds)->toEqualCanonicalizing([$txCashIn->id, $txAgentWithdrawal->id, $txCommission->id]);
    $this->getJson("/api/v1/transactions/{$txCashIn->id}")->assertStatus(200);
    $this->getJson("/api/v1/transactions/{$txAgentWithdrawal->id}")->assertStatus(200);
    $this->getJson("/api/v1/transactions/{$txCommission->id}")->assertStatus(200);
    $this->getJson("/api/v1/transactions/{$txCashOut->id}")->assertStatus(403);

    // --- 6. Stranger with 'user' token ---
    Sanctum::actingAs($stranger, ['user']);
    $strangerHistory = $this->getJson('/api/v1/transactions/history')->assertStatus(200);
    expect($strangerHistory->json('data.transactions'))->toBeEmpty();
    $this->getJson("/api/v1/transactions/{$txTopUp->id}")->assertStatus(403);
    $this->getJson("/api/v1/transactions/{$txTransfer->id}")->assertStatus(403);

    // --- 7. Admin with 'admin' token ---
    Sanctum::actingAs($admin, ['admin']);
    $adminHistory = $this->getJson('/api/v1/transactions/history')->assertStatus(200);
    $adminIds = collect($adminHistory->json('data.transactions'))->pluck('id')->all();
    expect($adminIds)->toEqualCanonicalizing([
        $txTopUp->id,
        $txTransfer->id,
        $txCashIn->id,
        $txCashOut->id,
        $txAgentWithdrawal->id,
        $txCommission->id,
    ]);
    $this->getJson("/api/v1/transactions/{$txTopUp->id}")->assertStatus(200);
    $this->getJson("/api/v1/transactions/{$txAgentWithdrawal->id}")->assertStatus(200);
});

test('counterparty balances are absent for non-admin viewers and present for admins', function () {
    $sender = createScopedUser('USER');
    $recipient = createScopedUser('USER');
    $admin = createScopedUser('ADMIN');

    $senderWallet = $sender->wallets()->where('kind', Wallet::KIND_USER)->firstOrFail();
    $recipientWallet = $recipient->wallets()->where('kind', Wallet::KIND_USER)->firstOrFail();

    $tx = Transaction::create([
        'user_id' => $sender->id,
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'initiated_by' => $sender->id,
        'sender_wallet_id' => $senderWallet->id,
        'recipient_wallet_id' => $recipientWallet->id,
        'agent_wallet_id' => null,
        'sender_wallet_balance_after' => '900.00',
        'recipient_wallet_balance_after' => '1100.00',
        'type' => 'TRANSFER',
        'amount' => '100.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'mask-test-tx',
    ]);

    // 1. Sender views detail
    Sanctum::actingAs($sender, ['user']);
    $senderRes = $this->getJson("/api/v1/transactions/{$tx->id}")->assertStatus(200);
    $senderData = $senderRes->json('data.transaction');
    expect(array_key_exists('sender_wallet_balance_after', $senderData))->toBeTrue()
        ->and((float) $senderData['sender_wallet_balance_after'])->toBe(900.0)
        ->and(array_key_exists('recipient_wallet_balance_after', $senderData))->toBeFalse();

    // 2. Sender views history
    $senderHistRes = $this->getJson('/api/v1/transactions/history')->assertStatus(200);
    $senderHistData = $senderHistRes->json('data.transactions.0');
    expect(array_key_exists('sender_wallet_balance_after', $senderHistData))->toBeTrue()
        ->and(array_key_exists('recipient_wallet_balance_after', $senderHistData))->toBeFalse();

    // 3. Recipient views detail
    Sanctum::actingAs($recipient, ['user']);
    $recipientRes = $this->getJson("/api/v1/transactions/{$tx->id}")->assertStatus(200);
    $recipientData = $recipientRes->json('data.transaction');
    expect(array_key_exists('recipient_wallet_balance_after', $recipientData))->toBeTrue()
        ->and((float) $recipientData['recipient_wallet_balance_after'])->toBe(1100.0)
        ->and(array_key_exists('sender_wallet_balance_after', $recipientData))->toBeFalse();

    // 4. Admin views detail
    Sanctum::actingAs($admin, ['admin']);
    $adminRes = $this->getJson("/api/v1/transactions/{$tx->id}")->assertStatus(200);
    $adminData = $adminRes->json('data.transaction');
    expect(array_key_exists('sender_wallet_balance_after', $adminData))->toBeTrue()
        ->and((float) $adminData['sender_wallet_balance_after'])->toBe(900.0)
        ->and(array_key_exists('recipient_wallet_balance_after', $adminData))->toBeTrue()
        ->and((float) $adminData['recipient_wallet_balance_after'])->toBe(1100.0);
});

test('idempotent replays return original row with unchanged wallet slots', function () {
    $user = createScopedUser('USER');
    $wallet = $user->wallets()->where('kind', Wallet::KIND_USER)->firstOrFail();

    Sanctum::actingAs($user, ['user']);
    $key = 'idemp-slot-'.uniqid();

    $res1 = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 50.00,
        'idempotency_key' => $key,
    ])->assertStatus(201);

    $res2 = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 50.00,
        'idempotency_key' => $key,
    ]);

    expect($res1->json('data.transaction.id'))->toBe($res2->json('data.transaction.id'))
        ->and(Transaction::findOrFail($res2->json('data.transaction.id'))->recipient_wallet_id)->toBe($wallet->id);
});

test('blocked wallet does not change history visibility', function () {
    $user = createScopedUser('USER');
    $wallet = $user->wallets()->where('kind', Wallet::KIND_USER)->firstOrFail();

    Transaction::create([
        'user_id' => $user->id,
        'initiated_by' => $user->id,
        'sender_wallet_id' => null,
        'recipient_wallet_id' => $wallet->id,
        'agent_wallet_id' => null,
        'type' => 'TOP_UP',
        'amount' => '100.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'block-history-tx',
    ]);

    // Block the wallet
    $wallet->update(['is_blocked' => true]);

    Sanctum::actingAs($user, ['user']);
    $response = $this->getJson('/api/v1/transactions/history')->assertStatus(200);
    expect($response->json('data.pagination.total'))->toBe(1);
});

test('viewer whose token kind has no wallet receives empty history list', function () {
    // User without a wallet
    $user = User::factory()->create();
    $role = Role::where('name', 'USER')->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    Sanctum::actingAs($user, ['user']);

    $response = $this->getJson('/api/v1/transactions/history');
    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.pagination.total', 0)
        ->assertJsonCount(0, 'data.transactions');
});

test('operation responses for cash-in and cash-out return only caller balance after', function () {
    $customer = createScopedUser('USER');
    $agent = createScopedUser('AGENT');

    // 1. Customer initiates cash-in
    Sanctum::actingAs($customer, ['user']);
    $cashInRes = $this->postJson('/api/v1/transactions/cash-in', [
        'agent_phone' => $agent->phone_number,
        'amount' => 100.00,
        'idempotency_key' => 'op-mask-cashin-'.uniqid(),
    ])->assertStatus(201);

    $cashInTx = $cashInRes->json('data.transaction');
    expect(array_key_exists('recipient_wallet_balance_after', $cashInTx))->toBeTrue()
        ->and(array_key_exists('agent_wallet_balance_after', $cashInTx))->toBeFalse()
        ->and(array_key_exists('sender_wallet_balance_after', $cashInTx))->toBeFalse();

    // Agent views the same cash-in row
    Sanctum::actingAs($agent, ['agent']);
    $agentViewCashIn = $this->getJson("/api/v1/transactions/{$cashInTx['id']}")->assertStatus(200);
    $agentCashInData = $agentViewCashIn->json('data.transaction');
    expect(array_key_exists('agent_wallet_balance_after', $agentCashInData))->toBeTrue()
        ->and(array_key_exists('recipient_wallet_balance_after', $agentCashInData))->toBeFalse()
        ->and(array_key_exists('sender_wallet_balance_after', $agentCashInData))->toBeFalse();

    // 2. Customer initiates cash-out
    Sanctum::actingAs($customer, ['user']);
    $cashOutRes = $this->postJson('/api/v1/transactions/cash-out', [
        'agent_phone' => $agent->phone_number,
        'amount' => 50.00,
        'idempotency_key' => 'op-mask-cashout-'.uniqid(),
    ])->assertStatus(201);

    $cashOutTx = $cashOutRes->json('data.transaction');
    expect(array_key_exists('sender_wallet_balance_after', $cashOutTx))->toBeTrue()
        ->and(array_key_exists('agent_wallet_balance_after', $cashOutTx))->toBeFalse()
        ->and(array_key_exists('recipient_wallet_balance_after', $cashOutTx))->toBeFalse();

    // Agent views the same cash-out row
    Sanctum::actingAs($agent, ['agent']);
    $agentViewCashOut = $this->getJson("/api/v1/transactions/{$cashOutTx['id']}")->assertStatus(200);
    $agentCashOutData = $agentViewCashOut->json('data.transaction');
    expect(array_key_exists('agent_wallet_balance_after', $agentCashOutData))->toBeTrue()
        ->and(array_key_exists('sender_wallet_balance_after', $agentCashOutData))->toBeFalse()
        ->and(array_key_exists('recipient_wallet_balance_after', $agentCashOutData))->toBeFalse();
});

test('operation responses for top-up, transfer, and withdrawal carry only viewer balance field', function () {
    $sender = createScopedUser('USER');
    $recipient = createScopedUser('USER');
    $agent = createScopedUser('AGENT');

    // Top-up response carries only recipient_wallet_balance_after
    Sanctum::actingAs($sender, ['user']);
    $topUpRes = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 50.00,
        'idempotency_key' => 'op-topup-'.uniqid(),
    ])->assertStatus(201);
    $topUpTx = $topUpRes->json('data.transaction');
    expect(array_key_exists('recipient_wallet_balance_after', $topUpTx))->toBeTrue()
        ->and(array_key_exists('sender_wallet_balance_after', $topUpTx))->toBeFalse()
        ->and(array_key_exists('agent_wallet_balance_after', $topUpTx))->toBeFalse();

    // Transfer response carries only sender_wallet_balance_after
    $transferRes = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_id' => $recipient->id,
        'amount' => 25.00,
        'idempotency_key' => 'op-transfer-'.uniqid(),
    ])->assertStatus(201);
    $transferTx = $transferRes->json('data.transaction');
    expect(array_key_exists('sender_wallet_balance_after', $transferTx))->toBeTrue()
        ->and(array_key_exists('recipient_wallet_balance_after', $transferTx))->toBeFalse()
        ->and(array_key_exists('agent_wallet_balance_after', $transferTx))->toBeFalse();

    // Agent withdrawal response carries only sender_wallet_balance_after
    Sanctum::actingAs($agent, ['agent']);
    $withRes = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 30.00,
        'idempotency_key' => 'op-withdraw-'.uniqid(),
    ])->assertStatus(201);
    $withTx = $withRes->json('data.transaction');
    expect(array_key_exists('sender_wallet_balance_after', $withTx))->toBeTrue()
        ->and(array_key_exists('recipient_wallet_balance_after', $withTx))->toBeFalse()
        ->and(array_key_exists('agent_wallet_balance_after', $withTx))->toBeFalse();
});

test('wallet id foreign keys are omitted for non-admins and present for admins', function () {
    $user = createScopedUser('USER');
    $admin = createScopedUser('ADMIN');

    Sanctum::actingAs($user, ['user']);
    $res = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 40.00,
        'idempotency_key' => 'fkeys-test-'.uniqid(),
    ])->assertStatus(201);

    $txId = $res->json('data.transaction.id');
    // Non-admin response does not have internal wallet IDs
    expect(array_key_exists('sender_wallet_id', $res->json('data.transaction')))->toBeFalse()
        ->and(array_key_exists('recipient_wallet_id', $res->json('data.transaction')))->toBeFalse()
        ->and(array_key_exists('agent_wallet_id', $res->json('data.transaction')))->toBeFalse();

    // Admin response has internal wallet IDs
    Sanctum::actingAs($admin, ['admin']);
    $adminRes = $this->getJson("/api/v1/transactions/{$txId}")->assertStatus(200);
    expect(array_key_exists('recipient_wallet_id', $adminRes->json('data.transaction')))->toBeTrue()
        ->and(array_key_exists('sender_wallet_id', $adminRes->json('data.transaction')))->toBeTrue();
});

test('staff with admin ability can inspect any transaction and audit history', function () {
    $customer = createScopedUser('USER');
    $staff = createScopedUser('ADMIN');

    Sanctum::actingAs($customer, ['user']);
    $res = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 80.00,
        'idempotency_key' => 'mod-test-'.uniqid(),
    ])->assertStatus(201);
    $txId = $res->json('data.transaction.id');

    // Staff authenticated with 'admin' ability
    Sanctum::actingAs($staff, ['admin']);
    $historyRes = $this->getJson('/api/v1/transactions/history')->assertStatus(200);
    expect($historyRes->json('data.pagination.total'))->toBeGreaterThanOrEqual(1);

    $showRes = $this->getJson("/api/v1/transactions/{$txId}")->assertStatus(200);
    $showData = $showRes->json('data.transaction');
    expect($showData['id'])->toBe($txId)
        ->and(array_key_exists('recipient_wallet_balance_after', $showData))->toBeTrue()
        ->and(array_key_exists('recipient_wallet_id', $showData))->toBeTrue();

    $this->getJson('/api/v1/transactions/admin/all')->assertStatus(200);
});

test('viewer with token ability but no wallet gets empty history, 403 on show, and no N+1 queries', function () {
    $otherUser = createScopedUser('USER');
    $otherWallet = $otherUser->wallets()->firstOrFail();

    // Create 5 transactions belonging to otherUser
    $txs = collect();
    for ($i = 1; $i <= 5; $i++) {
        $txs->push(Transaction::create([
            'user_id' => $otherUser->id,
            'initiated_by' => $otherUser->id,
            'recipient_wallet_id' => $otherWallet->id,
            'type' => 'TOP_UP',
            'amount' => '10.00',
            'system_fee_amount' => '0.00',
            'system_fee_rate' => '0.0000',
            'agent_commission_amount' => '0.00',
            'agent_commission_rate' => '0.0000',
            'currency' => 'BDT',
            'status' => 'COMPLETED',
            'idempotency_key' => "no-wallet-tx-{$i}-".uniqid(),
        ]));
    }

    // User with USER role but no wallet
    $userWithoutWallet = User::factory()->create();
    $role = Role::where('name', 'USER')->firstOrFail();
    $userWithoutWallet->roles()->attach($role->id, ['assigned_at' => now()]);

    Sanctum::actingAs($userWithoutWallet, ['user']);

    // 1. History returns empty list
    $this->getJson('/api/v1/transactions/history')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.pagination.total', 0)
        ->assertJsonCount(0, 'data.transactions');

    // 2. Show returns 403
    $this->getJson("/api/v1/transactions/{$txs->first()->id}")
        ->assertStatus(403);

    // 3. No extra per-row query when serializing collection without a viewer wallet
    $request = request();
    $request->setUserResolver(fn () => $userWithoutWallet);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $collection = TransactionResource::collection($txs)->toArray($request);

    // Only 1 query to check wallets for the user, cached for all remaining rows
    $walletQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_contains($q['query'], 'wallets')
    );
    expect($walletQueries->count())->toBeLessThanOrEqual(1);

    // Negative caching verified: viewer_wallet_id attribute is explicitly bound
    expect($request->attributes->has('viewer_wallet_id'))->toBeTrue()
        ->and($request->attributes->get('viewer_wallet_id'))->toBeNull();

    // All balance after fields are absent in the serialized rows
    foreach ($collection as $row) {
        expect(array_key_exists('sender_wallet_balance_after', $row))->toBeFalse()
            ->and(array_key_exists('recipient_wallet_balance_after', $row))->toBeFalse()
            ->and(array_key_exists('agent_wallet_balance_after', $row))->toBeFalse();
    }
});
