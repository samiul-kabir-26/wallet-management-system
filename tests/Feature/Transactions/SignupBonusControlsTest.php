<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Transactions\Notifications\TransactionReceiptNotification;
use Modules\Transactions\Services\SignupBonusService;
use Modules\Wallets\Services\LedgerIntegrityService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Cache::flush();
    Notification::fake();
});

function controlsRegister(object $test, string $phoneNumber): User
{
    $test->postJson('/api/v1/auth/register', [
        'name' => 'New Customer',
        'phone_number' => $phoneNumber,
        'otp_code' => $test->registrationOtpFor($phoneNumber),
        'pin' => '12345',
        'pin_confirmation' => '12345',
        'role' => 'USER',
    ])->assertCreated();

    return User::where('phone_number', $phoneNumber)->firstOrFail();
}

function setDailyBudget(int|float $budget): void
{
    SystemSetting::where('key', SignupBonusService::SETTING_DAILY_BUDGET)->update(['value' => json_encode($budget)]);
    Cache::flush();
}

function controlsStaff(string $role = 'SUPER_ADMIN'): User
{
    $staff = User::factory()->create();
    $staff->roles()->attach(Role::where('name', $role)->firstOrFail()->id, ['assigned_at' => now()]);

    return $staff;
}

function bonusOf(User $user): Transaction
{
    return Transaction::where('type', 'SIGNUP_BONUS')->where('recipient_id', $user->id)->sole();
}

test('with no budget every new account gets the bonus', function () {
    setDailyBudget(0);

    foreach (['01711111111', '01722222222', '01733333333'] as $phone) {
        expect(controlsRegister($this, $phone)->userWallet->balance)->toBe('50.00');
    }
});

test('once the daily budget is used up, new accounts register without a bonus and it is audited', function () {
    setDailyBudget(100);

    $first = controlsRegister($this, '01711111111');
    $second = controlsRegister($this, '01722222222');
    $third = controlsRegister($this, '01733333333');

    expect($first->userWallet->balance)->toBe('50.00')
        ->and($second->userWallet->balance)->toBe('50.00')
        ->and($third->userWallet->balance)->toBe('0.00')
        ->and(Transaction::where('type', 'SIGNUP_BONUS')->where('recipient_id', $third->id)->exists())->toBeFalse();

    $audit = AuditLog::where('action', 'SIGNUP_BONUS_WITHHELD')->sole();
    expect($audit->auditable_id)->toBe($third->id)
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->metadata)->toMatchArray(['reason' => 'DAILY_BUDGET_EXHAUSTED', 'daily_budget' => '100.00', 'paid_today' => '100.00']);
});

test('a budget that cannot cover a whole bonus pays nothing rather than part of it', function () {
    setDailyBudget(70);

    controlsRegister($this, '01711111111');
    expect(controlsRegister($this, '01722222222')->userWallet->balance)->toBe('0.00');
});

test('bonuses from earlier days and backfilled opening balances do not use today\'s budget', function () {
    setDailyBudget(50);

    $this->travel(-1)->days();
    controlsRegister($this, '01711111111');
    $this->travelBack();

    $legacy = controlsRegister($this, '01722222222');
    bonusOf($legacy)->forceFill(['meta' => ['backfilled' => true]])->save();

    expect(app(SignupBonusService::class)->paidToday())->toBe('0.00')
        ->and(controlsRegister($this, '01733333333')->userWallet->balance)->toBe('50.00');
});

test('staff set the budget from system settings and see what was paid today', function () {
    controlsRegister($this, '01711111111');
    Sanctum::actingAs(controlsStaff('ADMIN'), ['admin']);

    $this->patchJson('/api/v1/system-settings', ['signup_bonus_daily_budget' => 2500])
        ->assertOk()
        ->assertJsonPath('data.settings.signup_bonus_daily_budget', 2500)
        ->assertJsonPath('data.settings.signup_bonus_paid_today', 50)
        ->assertJsonPath('data.settings.signup_bonus_clawback_days', 30);

    $this->patchJson('/api/v1/system-settings', ['signup_bonus_daily_budget' => -1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('signup_bonus_daily_budget');
});

test('staff can claw back a signup bonus; the money returns to the platform', function () {
    $customer = controlsRegister($this, '01711111111');
    $bonus = bonusOf($customer);
    $staff = controlsStaff();
    Sanctum::actingAs($staff, ['admin']);

    $reversalId = $this->postJson("/api/v1/transactions/admin/{$bonus->id}/reverse", ['reason' => 'Bonus farming with a batch of new SIM cards'])
        ->assertCreated()
        ->assertJsonPath('data.transaction.type', 'REVERSAL')
        ->json('data.transaction.id');

    $reversal = Transaction::findOrFail($reversalId);
    expect($reversal->sender_id)->toBe($customer->id)
        ->and($reversal->recipient_id)->toBeNull()
        ->and($reversal->recipient_wallet_id)->toBeNull()
        ->and($customer->userWallet->refresh()->balance)->toBe('0.00')
        ->and(app(LedgerIntegrityService::class)->verify()['healthy'])->toBeTrue();

    $receipt = Notification::sent($customer, TransactionReceiptNotification::class)->last();
    expect($receipt->toSms($customer))->toStartWith("WalletMS: Reversal of #{$bonus->id}: returned Tk 50.00 to bKash.");
});

test('a bonus cannot be clawed back after the clawback window', function () {
    $customer = controlsRegister($this, '01711111111');
    $bonus = bonusOf($customer);
    $this->travel(31)->days();
    Sanctum::actingAs(controlsStaff(), ['admin']);

    $this->postJson("/api/v1/transactions/admin/{$bonus->id}/reverse", ['reason' => 'Bonus farming with a batch of new SIM cards'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Signup bonuses can only be clawed back within 30 days.');
});

test('a backfilled opening balance cannot be clawed back', function () {
    $customer = controlsRegister($this, '01711111111');
    $bonus = bonusOf($customer);
    $bonus->forceFill(['meta' => [...$bonus->meta, 'backfilled' => true]])->save();
    Sanctum::actingAs(controlsStaff(), ['admin']);

    $this->postJson("/api/v1/transactions/admin/{$bonus->id}/reverse", ['reason' => 'Bonus farming with a batch of new SIM cards'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'A backfilled opening balance cannot be clawed back.');
});

test('a clawback fails when the customer has already spent the bonus', function () {
    $customer = controlsRegister($this, '01711111111');
    $bonus = bonusOf($customer);
    $customer->userWallet->forceFill(['balance' => '20.00'])->save();
    Sanctum::actingAs(controlsStaff(), ['admin']);

    $this->postJson("/api/v1/transactions/admin/{$bonus->id}/reverse", ['reason' => 'Bonus farming with a batch of new SIM cards'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The customer no longer has enough balance to return this money.');
});

test('with maker-checker on, a clawback waits for a second approver', function () {
    config(['approvals.enabled' => true]);
    $customer = controlsRegister($this, '01711111111');
    $bonus = bonusOf($customer);
    Sanctum::actingAs(controlsStaff(), ['admin']);

    $approvalId = $this->postJson("/api/v1/transactions/admin/{$bonus->id}/reverse", ['reason' => 'Bonus farming with a batch of new SIM cards'])
        ->assertStatus(202)
        ->json('data.approval_request.id');
    expect($customer->userWallet->balance)->toBe('50.00');

    Sanctum::actingAs(controlsStaff(), ['admin']);
    $this->postJson("/api/v1/approvals/{$approvalId}/approve")->assertOk();

    expect($customer->userWallet->refresh()->balance)->toBe('0.00');
});
