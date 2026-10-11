<?php

use App\Models\AgentInfo;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SystemSettingsSeeder::class);
});

test('a person holding both wallets: cash-in moves money only between the user USER wallet and agent AGENT wallet', function () {
    // Agent Alice
    $agent = User::factory()->create();
    $agentRole = Role::where('name', 'AGENT')->firstOrFail();
    $agent->roles()->attach($agentRole->id, ['assigned_at' => now()]);
    AgentInfo::create([
        'user_id' => $agent->id,
        'commission_rate' => 0.0100,
        'status' => 'APPROVED',
    ]);
    $agentWallet = Wallet::factory()->agent()->for($agent)->balance(1000.00)->create();

    // User Bob who also holds an AGENT role & AGENT wallet
    $user = User::factory()->create();
    $userRole = Role::where('name', 'USER')->firstOrFail();
    $user->roles()->attach($userRole->id, ['assigned_at' => now()]);
    $user->roles()->attach($agentRole->id, ['assigned_at' => now()]);
    AgentInfo::create([
        'user_id' => $user->id,
        'commission_rate' => 0.0100,
        'status' => 'APPROVED',
    ]);

    $userUserWallet = Wallet::factory()->for($user)->balance(100.00)->create();
    $userAgentWallet = Wallet::factory()->agent()->for($user)->balance(500.00)->create();

    Sanctum::actingAs($user, ['user']);

    $response = $this->postJson('/api/v1/transactions/cash-in', [
        'agent_phone' => $agent->phone_number,
        'amount' => 200.00,
        'idempotency_key' => 'multi-wallet-cashin-01',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.transaction.type', 'CASH_IN');

    $userUserWallet->refresh();
    $userAgentWallet->refresh();
    $agentWallet->refresh();

    // User's USER wallet credited: 100 + 200 = 300
    expect((float) $userUserWallet->balance)->toBe(300.00)
        // User's AGENT wallet must remain untouched: 500
        ->and((float) $userAgentWallet->balance)->toBe(500.00)
        // Agent's AGENT wallet debited: 1000 - 200 = 800
        ->and((float) $agentWallet->balance)->toBe(800.00);
});

test('transfer to an agent-only recipient without a user wallet is rejected', function () {
    $sender = User::factory()->create();
    $userRole = Role::where('name', 'USER')->firstOrFail();
    $sender->roles()->attach($userRole->id, ['assigned_at' => now()]);
    Wallet::factory()->for($sender)->balance(500.00)->create();

    // Recipient has only AGENT role and only AGENT wallet
    $recipient = User::factory()->create();
    $agentRole = Role::where('name', 'AGENT')->firstOrFail();
    $recipient->roles()->attach($agentRole->id, ['assigned_at' => now()]);
    AgentInfo::create([
        'user_id' => $recipient->id,
        'commission_rate' => 0.0100,
        'status' => 'APPROVED',
    ]);
    Wallet::factory()->agent()->for($recipient)->balance(500.00)->create();

    Sanctum::actingAs($sender, ['user']);

    $response = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_id' => $recipient->id,
        'amount' => 100.00,
        'idempotency_key' => 'transfer-agent-only-recipient',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('blocking one wallet leaves the other wallet usable for the same user', function () {
    $user = User::factory()->create();
    $userRole = Role::where('name', 'USER')->firstOrFail();
    $agentRole = Role::where('name', 'AGENT')->firstOrFail();
    $user->roles()->attach($userRole->id, ['assigned_at' => now()]);
    $user->roles()->attach($agentRole->id, ['assigned_at' => now()]);

    AgentInfo::create([
        'user_id' => $user->id,
        'commission_rate' => 0.0100,
        'status' => 'APPROVED',
    ]);

    // Block USER wallet, keep AGENT wallet active
    $userWallet = Wallet::factory()->for($user)->balance(500.00)->blocked()->create();
    $agentWallet = Wallet::factory()->agent()->for($user)->balance(1000.00)->create();

    // 1. As user, top-up fails because USER wallet is blocked
    Sanctum::actingAs($user, ['user']);
    $topUpResponse = $this->postJson('/api/v1/transactions/top-up', [
        'amount' => 100.00,
        'idempotency_key' => 'blocked-user-wallet-topup',
    ]);
    $topUpResponse->assertStatus(403);

    // 2. As agent, withdrawal succeeds because AGENT wallet is NOT blocked
    Sanctum::actingAs($user, ['agent']);
    $withdrawResponse = $this->postJson('/api/v1/transactions/agent/withdrawal', [
        'amount' => 300.00,
        'idempotency_key' => 'active-agent-wallet-withdraw',
    ]);
    $withdrawResponse->assertStatus(201)
        ->assertJsonPath('success', true);

    $agentWallet->refresh();
    expect((float) $agentWallet->balance)->toBe(700.00);
});
