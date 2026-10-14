<?php

use App\Models\OtpToken;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Modules\Roles\Enums\RolePanel;

beforeEach(function (): void {
    $this->seed([
        PermissionSeeder::class,
        RoleSeeder::class,
        RolePermissionSeeder::class,
    ]);
});

test('custom auditor role end-to-end authentication, scoped authorization, and immediate revocation', function (): void {
    // 1. Create custom Auditor role with only transactions.view_any
    $auditorRole = Role::create([
        'name' => 'AUDITOR',
        'description' => 'Audit transactions only',
        'panel' => RolePanel::Admin,
        'is_system' => false,
    ]);
    $txViewPerm = Permission::where('name', 'transactions.view_any')->firstOrFail();
    $auditorRole->permissions()->attach($txViewPerm->id);

    // 2. Assign Auditor role to a user with email and password
    $auditor = User::factory()->create([
        'email' => 'auditor@wallet.local',
        'password' => Hash::make('SecretPass123!'),
        'password_changed_at' => now(),
    ]);
    $auditor->roles()->attach($auditorRole->id, ['assigned_at' => now()]);

    // 3. User can log in on the admin route (P2 fixed!)
    $loginRes = $this->postJson('/api/v1/auth/admin/login', [
        'identifier' => 'auditor@wallet.local',
        'password' => 'SecretPass123!',
    ]);
    $loginRes->assertStatus(200);

    $otp = OtpToken::where('user_id', $auditor->id)->where('purpose', 'LOGIN')->firstOrFail();

    $verifyRes = $this->postJson('/api/v1/auth/admin/verify-otp', [
        'email' => 'auditor@wallet.local',
        'otp_code' => $otp->otp_code,
    ]);
    $verifyRes->assertStatus(200)
        ->assertJsonPath('data.user.roles', ['AUDITOR'])
        ->assertJsonPath('data.user.permissions', ['transactions.view_any']);

    $token = $verifyRes->json('data.token');

    // 4. Create sample transaction to view
    $customer = User::factory()->create();
    $wallet = Wallet::factory()->create(['user_id' => $customer->id]);
    $tx = Transaction::create([
        'user_id' => $customer->id,
        'initiated_by' => $customer->id,
        'type' => 'TOP_UP',
        'amount' => 500.00,
        'system_fee_amount' => 0.00,
        'system_fee_rate' => 0.0000,
        'agent_commission_amount' => 0.00,
        'agent_commission_rate' => 0.0000,
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'auditor-test-tx-'.uniqid(),
        'recipient_wallet_id' => $wallet->id,
    ]);

    // 5. Authenticate auditor using the admin token
    $headers = ['Authorization' => "Bearer {$token}"];

    // Auditor CAN view transactions list via admin/all
    $txListRes = $this->withHeaders($headers)->getJson('/api/v1/transactions/admin/all');
    $txListRes->assertStatus(200);

    // Auditor sees staff-only fields in TransactionResource
    $txDetailRes = $this->withHeaders($headers)->getJson("/api/v1/transactions/{$tx->id}");
    $txDetailRes->assertStatus(200);
    expect($txDetailRes->json('data.transaction'))->toHaveKey('recipient_wallet_id');

    // Auditor CANNOT block wallets, change settings, or view roles
    $this->withHeaders($headers)->patchJson("/api/v1/wallets/{$wallet->id}/block", [
        'reason' => 'Unauthorized block attempt',
    ])->assertStatus(403);

    $this->withHeaders($headers)->patchJson('/api/v1/system-settings', [
        'key' => 'agent_commission_rate',
        'value' => '0.0200',
    ])->assertStatus(403);

    $this->withHeaders($headers)->getJson('/api/v1/roles')->assertStatus(403);

    // 6. Remove transactions.view_any from the Auditor role
    $auditorRole->permissions()->detach($txViewPerm->id);
    Auth::forgetGuards();

    // Next request to admin/all is immediately 403 (no stale cache, live authorization per D9)
    $afterRevokeRes = $this->withHeaders($headers)->getJson('/api/v1/transactions/admin/all');
    $afterRevokeRes->assertStatus(403);
});
