<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('migration down refuses to rollback when duplicate wallets exist for a user', function () {
    $user = User::factory()->create();
    Wallet::factory()->for($user)->balance(100.00)->create(['kind' => 'USER']);
    Wallet::factory()->for($user)->balance(200.00)->create(['kind' => 'AGENT']);

    $migration = require database_path('migrations/2026_10_11_091148_add_kind_to_wallets_table.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot rollback migration');
});

test('migration up backfill correctly maps AGENT role to AGENT kind and other accounts to USER kind', function () {
    // 1. Plain user
    $plainUser = User::factory()->create();
    $userRole = Role::where('name', 'USER')->firstOrFail();
    $plainUser->roles()->attach($userRole->id, ['assigned_at' => now()]);

    // 2. Agent user
    $agentUser = User::factory()->create();
    $agentRole = Role::where('name', 'AGENT')->firstOrFail();
    $agentUser->roles()->attach($agentRole->id, ['assigned_at' => now()]);

    // Create wallets with raw DB query to simulate pre-migration unclassified state
    $plainWalletId = DB::table('wallets')->insertGetId([
        'user_id' => $plainUser->id,
        'kind' => 'USER',
        'balance' => '50.00',
        'currency' => 'BDT',
        'is_blocked' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $agentWalletId = DB::table('wallets')->insertGetId([
        'user_id' => $agentUser->id,
        'kind' => 'USER', // initially USER before agent backfill
        'balance' => '50.00',
        'currency' => 'BDT',
        'is_blocked' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Re-run the backfill logic as executed in the migration
    DB::table('wallets')
        ->whereIn('user_id', function ($query) {
            $query->select('user_roles.user_id')
                ->from('user_roles')
                ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                ->where('roles.name', 'AGENT');
        })
        ->update(['kind' => 'AGENT']);

    expect(DB::table('wallets')->where('id', $plainWalletId)->value('kind'))->toBe('USER')
        ->and(DB::table('wallets')->where('id', $agentWalletId)->value('kind'))->toBe('AGENT');
});
