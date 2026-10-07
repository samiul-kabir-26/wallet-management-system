<?php

use App\Models\AgentInfo;
use App\Models\Cap;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Modules\Transactions\Jobs\ResetDailyCaps;
use Modules\Transactions\Jobs\ResetMonthlyCaps;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    // Ensure standard system fee (5%) and commission defaults (2%) are set
    SystemSetting::updateOrCreate(['key' => 'system_fee_rate'], ['value' => 0.05]);
    SystemSetting::updateOrCreate(['key' => 'agent_commission_rate'], ['value' => 0.02]);
    Cache::flush();
});

test('complete financial lifecycle integration flow across user, agent, admin, and background cap resets', function () {
    // -------------------------------------------------------------
    // 1. Setup Admin Actor
    // -------------------------------------------------------------
    $admin = User::factory()->create();
    $adminRole = Role::where('name', 'ADMIN')->firstOrFail();
    $admin->roles()->attach($adminRole->id, ['assigned_at' => now()]);

    // -------------------------------------------------------------
    // 2. Register User 1 via API
    // -------------------------------------------------------------
    $user1RegResponse = $this->postJson('/api/v1/auth/register', [
        'name' => 'Alice User',
        'phone_number' => '01711000001',
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => 'USER',
    ]);
    $user1RegResponse->assertStatus(201);
    $user1Id = $user1RegResponse->json('data.user.id');
    $user1 = User::findOrFail($user1Id);

    // Initial wallet balance is 50.00 BDT signup bonus
    expect((float) $user1->wallet->balance)->toBe(50.00);

    // -------------------------------------------------------------
    // 3. Register User 2 via API
    // -------------------------------------------------------------
    $user2RegResponse = $this->postJson('/api/v1/auth/register', [
        'name' => 'Bob User',
        'phone_number' => '01711000002',
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => 'USER',
    ]);
    $user2RegResponse->assertStatus(201);
    $user2Id = $user2RegResponse->json('data.user.id');
    $user2 = User::findOrFail($user2Id);
    expect((float) $user2->wallet->balance)->toBe(50.00);

    // -------------------------------------------------------------
    // 4. Register Agent via API
    // -------------------------------------------------------------
    $agentRegResponse = $this->postJson('/api/v1/auth/register', [
        'name' => 'Agent Charlie',
        'phone_number' => '01711000003',
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => 'AGENT',
    ]);
    $agentRegResponse->assertStatus(201);
    $agentId = $agentRegResponse->json('data.user.id');
    $agent = User::findOrFail($agentId);

    // Agent profile is initially PENDING with 0.00 initial balance
    $agentInfo = AgentInfo::where('user_id', $agentId)->firstOrFail();
    expect($agentInfo->status)->toBe('PENDING');

    // -------------------------------------------------------------
    // 5. Admin Approves Agent with 2.5% commission rate
    // -------------------------------------------------------------
    Sanctum::actingAs($admin, ['admin']);
    $this->patchJson("/api/v1/users/{$agentId}/approve-agent", [
        'commission_rate' => 0.025,
    ])->assertOk();

    $agentInfo->refresh();
    expect($agentInfo->status)->toBe('APPROVED')
        ->and((float) $agentInfo->commission_rate)->toBe(0.025);

    // -------------------------------------------------------------
    // 6. User 1 Tops Up 5,000 BDT
    // -------------------------------------------------------------
    Sanctum::actingAs($user1, ['user']);
    $topUpResponse = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 5000.00,
        'idempotency_key' => 'flow-topup-001',
    ]);
    $topUpResponse->assertStatus(201)
        ->assertJsonPath('data.transaction.type', 'TOP_UP')
        ->assertJsonPath('data.transaction.status', 'COMPLETED');

    $user1->wallet->refresh();
    // 50.00 (bonus) + 5000.00 = 5050.00
    expect((float) $user1->wallet->balance)->toBe(5050.00);

    // -------------------------------------------------------------
    // 7. User 1 Transfers 1,000 BDT to User 2
    // -------------------------------------------------------------
    $transferResponse = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_id' => $user2Id,
        'amount' => 1000.00,
        'idempotency_key' => 'flow-transfer-001',
    ]);
    $transferResponse->assertStatus(201)
        ->assertJsonPath('data.transaction.type', 'TRANSFER');

    $user1->wallet->refresh();
    $user2->wallet->refresh();
    $user1Cap = Cap::where('user_id', $user1Id)->firstOrFail();

    expect((float) $user1->wallet->balance)->toBe(4050.00)
        ->and((float) $user2->wallet->balance)->toBe(1050.00)
        ->and((float) $user1Cap->daily_used)->toBe(1000.00)
        ->and((float) $user1Cap->monthly_used)->toBe(1000.00);

    // -------------------------------------------------------------
    // 8. User 1 Cashes Out 2,000 BDT via Agent Charlie
    // Fee = 5% of 2000 = 100.00 BDT
    // Commission = 2.5% of 2000 = 50.00 BDT
    // Total User 1 debit = 2000 + 100 = 2100.00 BDT
    // Total Agent credit = 2000 + 50 = 2050.00 BDT
    // -------------------------------------------------------------
    $cashOutResponse = $this->postJson('/api/v1/transactions/cash-out', [
        'agent_id' => $agentId,
        'amount' => 2000.00,
        'idempotency_key' => 'flow-cashout-001',
    ]);
    $cashOutResponse->assertStatus(201)
        ->assertJsonPath('data.transaction.type', 'CASH_OUT')
        ->assertJsonPath('data.transaction.system_fee_amount', fn ($v) => (float) $v === 100.0)
        ->assertJsonPath('data.transaction.agent_commission_amount', fn ($v) => (float) $v === 50.0);

    $user1->wallet->refresh();
    $agent->wallet->refresh();
    $agentInfo->refresh();
    $user1Cap->refresh();

    // 4050 - 2100 = 1950.00
    expect((float) $user1->wallet->balance)->toBe(1950.00)
        // Agent: 50 (initial) + 2000 + 50 = 2100.00
        ->and((float) $agent->wallet->balance)->toBe(2100.00)
        ->and((float) $agentInfo->total_commission)->toBe(50.00)
        // Daily used: 1000 + 2000 = 3000.00
        ->and((float) $user1Cap->daily_used)->toBe(3000.00)
        ->and((float) $user1Cap->monthly_used)->toBe(3000.00);

    // Assert linked COMMISSION_PAYOUT transaction exists
    $commissionTx = Transaction::where('type', 'COMMISSION_PAYOUT')->first();
    expect($commissionTx)->not->toBeNull()
        ->and((float) $commissionTx->amount)->toBe(50.00)
        ->and($commissionTx->recipient_id)->toBe($agentId)
        ->and($commissionTx->agent_id)->toBe($agentId);

    // -------------------------------------------------------------
    // 9. Agent Charlie Withdraws 1,500 BDT
    // -------------------------------------------------------------
    Sanctum::actingAs($agent, ['agent']);
    $withdrawResponse = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 1500.00,
        'idempotency_key' => 'flow-withdraw-001',
    ]);
    $withdrawResponse->assertStatus(201)
        ->assertJsonPath('data.transaction.type', 'AGENT_WITHDRAWAL');

    $agent->wallet->refresh();
    // 2100 - 1500 = 600.00
    expect((float) $agent->wallet->balance)->toBe(600.00);

    // -------------------------------------------------------------
    // 10. Audit: User 1 views transaction history
    // -------------------------------------------------------------
    Sanctum::actingAs($user1, ['user']);
    $historyResponse = $this->getJson('/api/v1/transactions/history');
    $historyResponse->assertOk()
        ->assertJsonPath('success', true);
    // TOP_UP, TRANSFER, CASH_OUT, COMMISSION_PAYOUT (initiated by User 1)
    expect(count($historyResponse->json('data.transactions')))->toBe(4);

    // -------------------------------------------------------------
    // 11. Admin views all transactions across the system
    // -------------------------------------------------------------
    Sanctum::actingAs($admin, ['admin']);
    $adminAllResponse = $this->getJson('/api/v1/transactions/admin/all');
    $adminAllResponse->assertOk();
    // Total transactions: TOP_UP (1) + TRANSFER (1) + CASH_OUT (1) + COMMISSION_PAYOUT (1) + AGENT_WITHDRAWAL (1) = 5
    expect(count($adminAllResponse->json('data.transactions')))->toBe(5);

    // -------------------------------------------------------------
    // 12. Midnight Background Cap Reset
    // -------------------------------------------------------------
    expect((float) $user1Cap->daily_used)->toBe(3000.00);

    // Execute Daily Cap Reset Job
    app(ResetDailyCaps::class)->handle();

    $user1Cap->refresh();
    expect((float) $user1Cap->daily_used)->toBe(0.00)
        ->and((float) $user1Cap->monthly_used)->toBe(3000.00); // Monthly remains intact

    // Execute Monthly Cap Reset Job
    app(ResetMonthlyCaps::class)->handle();

    $user1Cap->refresh();
    expect((float) $user1Cap->monthly_used)->toBe(0.00);
});

test('transaction rolls back completely and leaves wallet balances unchanged on mid-flight failure', function () {
    $sender = User::factory()->create();
    $role = Role::where('name', 'USER')->firstOrFail();
    $sender->roles()->attach($role->id, ['assigned_at' => now()]);
    $senderWallet = Wallet::factory()->for($sender)->balance(1000.00)->create();
    $senderCap = Cap::factory()->for($sender)->usage(0.00, 0.00)->create();

    $recipient = User::factory()->create(['phone_number' => '01788776655']);
    $recipient->roles()->attach($role->id, ['assigned_at' => now()]);
    $recipientWallet = Wallet::factory()->for($recipient)->balance(200.00)->create();
    Cap::factory()->for($recipient)->usage(0.00, 0.00)->create();

    Sanctum::actingAs($sender, ['user']);

    // Attempting a transfer that exceeds sender balance (e.g. 5,000 BDT)
    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_id' => $recipient->id,
        'amount' => 5000.00,
        'idempotency_key' => 'rollback-test-key-1',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Insufficient wallet balance to complete transfer.');

    // Balances and caps must remain strictly unchanged
    $senderWallet->refresh();
    $recipientWallet->refresh();
    $senderCap->refresh();

    expect((float) $senderWallet->balance)->toBe(1000.00)
        ->and((float) $recipientWallet->balance)->toBe(200.00)
        ->and((float) $senderCap->daily_used)->toBe(0.00)
        ->and(Transaction::where('idempotency_key', 'rollback-test-key-1')->count())->toBe(0);
});

test('submitting identical idempotency key returns identical transaction and prevents double mutations', function () {
    $user = User::factory()->create();
    $role = Role::where('name', 'USER')->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);
    $userWallet = Wallet::factory()->for($user)->balance(500.00)->create();
    $userCap = Cap::factory()->for($user)->usage(0.00, 0.00)->create();

    Sanctum::actingAs($user, ['user']);

    $payload = [
        'amount' => 200.00,
        'idempotency_key' => 'idem-duplicate-check-key',
    ];

    $res1 = $this->postJson('/api/v1/transactions/top-up', $payload);
    $res2 = $this->postJson('/api/v1/transactions/top-up', $payload);

    $res1->assertStatus(201);
    $res2->assertSuccessful()
        ->assertJsonPath('data.transaction.id', $res1->json('data.transaction.id'));

    $userWallet->refresh();
    // 500 + 200 = 700 (NOT 900)
    expect((float) $userWallet->balance)->toBe(700.00)
        ->and(Transaction::where('idempotency_key', 'idem-duplicate-check-key')->count())->toBe(1);
});
