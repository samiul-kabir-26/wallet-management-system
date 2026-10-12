<?php

use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Policies\TransactionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->policy = new TransactionPolicy;
});

use App\Models\Wallet;

function createUserWithRole(string $roleName, ?array $abilities = null): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    if ($roleName === 'AGENT') {
        Wallet::factory()->agent()->create(['user_id' => $user->id]);
    } elseif ($roleName === 'USER') {
        Wallet::factory()->create(['user_id' => $user->id]);
    }

    $abilities ??= match ($roleName) {
        'ADMIN', 'SUPER_ADMIN' => ['admin'],
        'AGENT' => ['agent'],
        default => ['user'],
    };

    Sanctum::actingAs($user, $abilities);

    return $user;
}

test('viewAny is allowed for admin and super admin, denied for regular user and agent', function () {
    $admin = createUserWithRole('ADMIN');
    $superAdmin = createUserWithRole('SUPER_ADMIN');
    $user = createUserWithRole('USER');
    $agent = createUserWithRole('AGENT');

    expect($this->policy->viewAny($admin))->toBeTrue()
        ->and($this->policy->viewAny($superAdmin))->toBeTrue()
        ->and($this->policy->viewAny($user))->toBeFalse()
        ->and($this->policy->viewAny($agent))->toBeFalse();
});

test('admin role without admin token ability is denied view and viewAny', function () {
    $adminWithoutAdminAbility = createUserWithRole('ADMIN', ['user']);
    $transaction = new Transaction([
        'id' => 99999,
        'user_id' => 999,
        'sender_id' => 998,
        'recipient_id' => 997,
        'agent_id' => 996,
        'initiated_by' => 995,
    ]);

    expect($this->policy->viewAny($adminWithoutAdminAbility))->toBeFalse()
        ->and($this->policy->view($adminWithoutAdminAbility, $transaction))->toBeFalse();
});

test('view is allowed for admin regardless of transaction participants', function () {
    $admin = createUserWithRole('ADMIN');
    $transaction = new Transaction([
        'user_id' => 999,
        'sender_id' => 998,
        'recipient_id' => 997,
        'agent_id' => 996,
        'initiated_by' => 995,
    ]);

    expect($this->policy->view($admin, $transaction))->toBeTrue();
});

test('view is allowed for users participating via wallet slots', function () {
    $sender = createUserWithRole('USER');
    $recipient = createUserWithRole('USER');
    $agent = createUserWithRole('AGENT');
    $outsider = createUserWithRole('USER');

    $senderWallet = $sender->wallets()->first();
    $recipientWallet = $recipient->wallets()->first();
    $agentWallet = $agent->wallets()->first();

    $transaction = Transaction::create([
        'user_id' => $sender->id,
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'agent_id' => $agent->id,
        'initiated_by' => $sender->id,
        'sender_wallet_id' => $senderWallet->id,
        'recipient_wallet_id' => $recipientWallet->id,
        'agent_wallet_id' => $agentWallet->id,
        'type' => 'TRANSFER',
        'amount' => '100.00',
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'policy-test-tx',
    ]);

    expect($this->policy->view($sender, $transaction))->toBeTrue()
        ->and($this->policy->view($recipient, $transaction))->toBeTrue()
        ->and($this->policy->view($agent, $transaction))->toBeTrue()
        ->and($this->policy->view($outsider, $transaction))->toBeFalse();
});

test('user operations (topUp, cashIn, cashOut, transfer) are only permitted for USER role', function () {
    $user = createUserWithRole('USER');
    $agent = createUserWithRole('AGENT');
    $admin = createUserWithRole('ADMIN');

    foreach (['topUp', 'cashIn', 'cashOut', 'transfer'] as $ability) {
        expect($this->policy->$ability($user))->toBeTrue("Expected {$ability} to be true for USER")
            ->and($this->policy->$ability($agent))->toBeFalse("Expected {$ability} to be false for AGENT")
            ->and($this->policy->$ability($admin))->toBeFalse("Expected {$ability} to be false for ADMIN");
    }
});

test('agentWithdrawal is only permitted for AGENT role', function () {
    $agent = createUserWithRole('AGENT');
    $user = createUserWithRole('USER');
    $admin = createUserWithRole('ADMIN');

    expect($this->policy->agentWithdrawal($agent))->toBeTrue()
        ->and($this->policy->agentWithdrawal($user))->toBeFalse()
        ->and($this->policy->agentWithdrawal($admin))->toBeFalse();
});
