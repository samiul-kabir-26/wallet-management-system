<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Modules\Transactions\Services\SignupBonusService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Cache::flush();
});

function createSignupBonusActor(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

function selfRegister(object $test, string $phoneNumber, string $role = 'USER'): User
{
    $test->postJson('/api/v1/auth/register', [
        'name' => 'New Customer',
        'phone_number' => $phoneNumber,
        'otp_code' => $test->registrationOtpFor($phoneNumber),
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => $role,
    ])->assertCreated();

    return User::where('phone_number', $phoneNumber)->firstOrFail();
}

function storeSignupBonusSetting(string $key, int|float $value): void
{
    SystemSetting::where('key', $key)->update(['value' => json_encode($value)]);
    Cache::flush();
}

function walletLedgerTotal(Wallet $wallet): string
{
    return number_format((float) Transaction::where('recipient_wallet_id', $wallet->id)->sum('amount'), 2, '.', '');
}

test('self-registered user receives the default bonus as a SIGNUP_BONUS transaction that matches the balance', function () {
    $user = selfRegister($this, '01712345678');
    $wallet = $user->userWallet;

    $bonus = Transaction::where('recipient_wallet_id', $wallet->id)->sole();

    expect($bonus->type)->toBe('SIGNUP_BONUS')
        ->and($bonus->amount)->toBe('50.00')
        ->and($bonus->status)->toBe('COMPLETED')
        ->and($bonus->sender_id)->toBeNull()
        ->and($bonus->recipient_id)->toBe($user->id)
        ->and($bonus->recipient_wallet_balance_after)->toBe('50.00')
        ->and($bonus->idempotency_key)->toBe("signup-bonus:{$wallet->id}")
        ->and($bonus->meta['source_label'])->toBe('bKash')
        ->and($wallet->balance)->toBe('50.00')
        ->and(walletLedgerTotal($wallet))->toBe($wallet->balance);
});

test('staff-registered agent receives the bonus on the agent wallet', function () {
    $admin = createSignupBonusActor('ADMIN');
    Sanctum::actingAs($admin, ['admin']);

    $this->postJson('/api/v1/users/register', [
        'name' => 'New Agent',
        'phone_number' => '01798765432',
        'pin' => '54321',
        'pin_confirmation' => '54321',
        'role' => 'AGENT',
    ])->assertCreated();

    $agentWallet = User::where('phone_number', '01798765432')->firstOrFail()->agentWallet;

    expect($agentWallet->balance)->toBe('50.00')
        ->and(Transaction::where('recipient_wallet_id', $agentWallet->id)->where('type', 'SIGNUP_BONUS')->count())->toBe(1);
});

test('registration credits the amount configured in system settings', function () {
    storeSignupBonusSetting(SignupBonusService::SETTING_AMOUNT, 125.5);

    $wallet = selfRegister($this, '01712345678')->userWallet;

    expect($wallet->balance)->toBe('125.50')
        ->and(walletLedgerTotal($wallet))->toBe('125.50');
});

test('disabled signup bonus opens the wallet at zero with no ledger entry', function () {
    storeSignupBonusSetting(SignupBonusService::SETTING_ENABLED, 0);

    $wallet = selfRegister($this, '01712345678')->userWallet;

    expect($wallet->balance)->toBe('0.00')
        ->and(Transaction::where('recipient_wallet_id', $wallet->id)->exists())->toBeFalse();
});

test('a zero bonus amount credits nothing', function () {
    storeSignupBonusSetting(SignupBonusService::SETTING_AMOUNT, 0);

    $wallet = selfRegister($this, '01712345678')->userWallet;

    expect($wallet->balance)->toBe('0.00')
        ->and(Transaction::where('recipient_wallet_id', $wallet->id)->exists())->toBeFalse();
});

test('crediting the same wallet twice returns the original transaction without paying again', function () {
    $wallet = selfRegister($this, '01712345678')->userWallet;
    $original = Transaction::where('recipient_wallet_id', $wallet->id)->sole();

    $again = app(SignupBonusService::class)->credit($wallet);

    expect($again->id)->toBe($original->id)
        ->and($wallet->refresh()->balance)->toBe('50.00')
        ->and(Transaction::where('recipient_wallet_id', $wallet->id)->count())->toBe(1);
});

test('the bonus does not count toward daily or monthly caps', function () {
    $cap = selfRegister($this, '01712345678')->cap;

    expect($cap->daily_used)->toBe('0.00')
        ->and($cap->monthly_used)->toBe('0.00');
});

test('the bonus appears in the new user own transaction history', function () {
    $user = selfRegister($this, '01712345678');
    Sanctum::actingAs($user, ['user']);

    $this->getJson('/api/v1/transactions/history?type=SIGNUP_BONUS')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.type', 'SIGNUP_BONUS')
        ->assertJsonPath('data.items.0.recipient_wallet_balance_after', 50);
});

test('assigning the USER role to existing staff opens a wallet without a bonus', function () {
    $superAdmin = createSignupBonusActor('SUPER_ADMIN');
    $staff = createSignupBonusActor('ADMIN');
    Sanctum::actingAs($superAdmin, ['admin']);

    $this->postJson("/api/v1/users/{$staff->id}/assign-role", ['role' => 'USER'])->assertOk();

    $wallet = $staff->refresh()->userWallet;

    expect($wallet->balance)->toBe('0.00')
        ->and(Transaction::where('recipient_wallet_id', $wallet->id)->exists())->toBeFalse();
});

test('settings report the configured default signup bonus when no override is stored', function () {
    SystemSetting::whereIn('key', [SignupBonusService::SETTING_ENABLED, SignupBonusService::SETTING_AMOUNT])->delete();
    Cache::flush();
    $admin = createSignupBonusActor('ADMIN');
    Sanctum::actingAs($admin, ['admin']);

    $this->getJson('/api/v1/system-settings')
        ->assertOk()
        ->assertJsonPath('data.settings.signup_bonus_enabled', true)
        ->assertJsonPath('data.settings.signup_bonus_amount', 50);
});

test('admin can disable the bonus and change its amount, and the change is audited', function () {
    $admin = createSignupBonusActor('ADMIN');
    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson('/api/v1/system-settings', [
        'signup_bonus_enabled' => false,
        'signup_bonus_amount' => 75.25,
    ])
        ->assertOk()
        ->assertJsonPath('data.settings.signup_bonus_enabled', false)
        ->assertJsonPath('data.settings.signup_bonus_amount', 75.25);

    $audit = AuditLog::where('action', 'SETTINGS_UPDATED')->latest('id')->firstOrFail();

    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->metadata['changes'])->toHaveKeys([SignupBonusService::SETTING_ENABLED, SignupBonusService::SETTING_AMOUNT]);
});

test('signup bonus amount is validated', function (mixed $amount) {
    $admin = createSignupBonusActor('ADMIN');
    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson('/api/v1/system-settings', ['signup_bonus_amount' => $amount])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('signup_bonus_amount');
})->with([
    'negative' => -1,
    'more than two decimals' => 10.555,
    'above the configured maximum' => 1000.01,
    'not a number' => 'fifty',
]);

test('a customer token cannot change the signup bonus', function () {
    $user = createSignupBonusActor('USER');
    Sanctum::actingAs($user, ['user']);

    $this->patchJson('/api/v1/system-settings', ['signup_bonus_amount' => 500])->assertForbidden();
});
