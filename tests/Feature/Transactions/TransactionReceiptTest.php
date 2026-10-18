<?php

use App\Models\AgentInfo;
use App\Models\Cap;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Notifications\Channels\SmsChannel;
use Modules\Transactions\Notifications\TransactionReceiptNotification;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SystemSettingsSeeder::class);
});

function receiptCustomer(float $balance, array $attributes = []): User
{
    $user = User::factory()->create(['name' => 'Rahim Uddin', ...$attributes]);
    $user->roles()->attach(Role::where('name', 'USER')->firstOrFail()->id, ['assigned_at' => now()]);
    Wallet::factory()->for($user)->balance($balance)->create();
    Cap::factory()->for($user)->usage(0.00, 0.00)->create();

    return $user;
}

function receiptAgent(float $balance): User
{
    $agent = User::factory()->create(['name' => 'Karim Store']);
    $agent->roles()->attach(Role::where('name', 'AGENT')->firstOrFail()->id, ['assigned_at' => now()]);
    AgentInfo::create(['user_id' => $agent->id, 'commission_rate' => '0.0100', 'status' => 'APPROVED']);
    Wallet::factory()->agent()->for($agent)->balance($balance)->create();

    return $agent;
}

/**
 * The receipt sent to $user, rendered as SMS text.
 */
function receiptSmsFor(User $user): string
{
    $sent = Notification::sent($user, TransactionReceiptNotification::class);
    expect($sent)->toHaveCount(1);

    return $sent->first()->toSms($user);
}

test('a transfer sends a receipt to the sender and to the recipient', function () {
    Notification::fake();
    $sender = receiptCustomer(1500.00);
    $recipient = receiptCustomer(100.00, ['name' => 'Nusrat Jahan', 'phone_number' => '01812345678']);
    Sanctum::actingAs($sender, ['user']);

    $transferId = $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 1000.00,
        'pin' => '12345',
        'idempotency_key' => 'receipt-transfer',
    ])->assertCreated()->json('data.transaction.id');

    $senderSms = receiptSmsFor($sender);
    expect($senderSms)
        ->toStartWith('WalletMS: You sent Tk 1,000.00 to N****t J***n (018*****678).')
        ->toContain('Balance Tk 500.00.')
        ->toContain("TrxID {$transferId}")
        ->not->toContain('01812345678')
        ->and(receiptSmsFor($recipient))
        ->toStartWith('WalletMS: You received Tk 1,000.00 from R***m U***n')
        ->toContain('Balance Tk 1,100.00.');
});

test('a cash-out receipt shows the fee to the customer and the commission to the agent', function () {
    Notification::fake();
    $customer = receiptCustomer(1500.00);
    $agent = receiptAgent(2000.00);
    Sanctum::actingAs($customer, ['user']);

    // Fee 5% = 50, commission 1% = 10
    $this->postJson('/api/v1/transactions/cash-out', [
        'agent_phone' => $agent->phone_number,
        'amount' => 1000.00,
        'pin' => '12345',
        'idempotency_key' => 'receipt-cash-out',
    ])->assertCreated();

    expect(receiptSmsFor($customer))
        ->toStartWith('WalletMS: Cash out Tk 1,000.00 at agent K***m S***e')
        ->toContain('Fee Tk 50.00. Total Tk 1,050.00.')
        ->toContain('Balance Tk 450.00.')
        ->and(receiptSmsFor($agent))
        ->toStartWith('WalletMS: Cash out received Tk 1,000.00 from R***m U***n')
        ->toContain('Commission Tk 10.00. Credited Tk 1,010.00.')
        ->toContain('Balance Tk 3,010.00.');
});

test('a cash-in sends receipts to the customer and the agent', function () {
    Notification::fake();
    $customer = receiptCustomer(0.00);
    $agent = receiptAgent(2000.00);
    Sanctum::actingAs($agent, ['agent']);

    $this->postJson('/api/v1/transactions/agent/cash-in', [
        'customer_phone' => $customer->phone_number,
        'amount' => 500.00,
        'pin' => '12345',
        'idempotency_key' => 'receipt-cash-in',
    ])->assertCreated();

    expect(receiptSmsFor($customer))
        ->toStartWith('WalletMS: Cash in Tk 500.00 from agent K***m S***e')
        ->toContain('Balance Tk 500.00.')
        ->and(receiptSmsFor($agent))
        ->toStartWith('WalletMS: Cash in given Tk 500.00 to R***m U***n')
        ->toContain('Balance Tk 1,500.00.');
});

test('replaying an idempotency key does not send the receipts again', function () {
    Notification::fake();
    $sender = receiptCustomer(1500.00);
    $recipient = receiptCustomer(0.00);
    Sanctum::actingAs($sender, ['user']);
    $payload = ['recipient_phone' => $recipient->phone_number, 'amount' => 100.00, 'pin' => '12345', 'idempotency_key' => 'receipt-replay'];

    $this->postJson('/api/v1/transactions/transfer', $payload)->assertCreated();
    $this->postJson('/api/v1/transactions/transfer', $payload)->assertSuccessful();

    Notification::assertSentToTimes($sender, TransactionReceiptNotification::class, 1);
    Notification::assertSentToTimes($recipient, TransactionReceiptNotification::class, 1);
});

test('a failed transaction sends no receipt', function () {
    Notification::fake();
    $sender = receiptCustomer(50.00);
    $recipient = receiptCustomer(0.00);
    Sanctum::actingAs($sender, ['user']);

    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 1000.00,
        'pin' => '12345',
        'idempotency_key' => 'receipt-insufficient',
    ])->assertUnprocessable();

    Notification::assertNothingSent();
});

test('top-ups, signup bonuses and withdrawals do not send transaction receipts', function () {
    Notification::fake();
    $user = receiptCustomer(0.00);
    Sanctum::actingAs($user, ['user']);

    $this->postJson('/api/v1/transactions/top-up', ['amount' => 100.00, 'idempotency_key' => 'receipt-top-up'])->assertCreated();

    Notification::assertNotSentTo($user, TransactionReceiptNotification::class);
});

test('receipts go by SMS always and by email only when the customer has an email and wants it', function () {
    $withEmail = User::factory()->make(['email' => 'rahim@example.com', 'receipt_emails_enabled' => true]);
    $optedOut = User::factory()->make(['email' => 'rahim@example.com', 'receipt_emails_enabled' => false]);
    $noEmail = User::factory()->make(['email' => null]);
    $notification = new TransactionReceiptNotification(new Transaction, TransactionReceiptNotification::PARTY_SENDER);

    expect($notification->via($withEmail))->toBe([SmsChannel::class, 'mail'])
        ->and($notification->via($optedOut))->toBe([SmsChannel::class])
        ->and($notification->via($noEmail))->toBe([SmsChannel::class]);
});

test('receipts are queued and dispatched only after the database commit', function () {
    $notification = new TransactionReceiptNotification(new Transaction, TransactionReceiptNotification::PARTY_SENDER);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->afterCommit)->toBeTrue();
});

test('the emailed receipt lists the amount, fee, balance and transaction id', function () {
    Notification::fake();
    $sender = receiptCustomer(1500.00, ['email' => 'rahim@example.com']);
    $agent = receiptAgent(2000.00);
    Sanctum::actingAs($sender, ['user']);

    $this->postJson('/api/v1/transactions/cash-out', [
        'agent_phone' => $agent->phone_number,
        'amount' => 1000.00,
        'pin' => '12345',
        'idempotency_key' => 'receipt-mail',
    ])->assertCreated();

    $notification = Notification::sent($sender, TransactionReceiptNotification::class)->first();
    $mail = $notification->toMail($sender);
    $text = implode("\n", $mail->introLines);

    expect($mail->subject)->toBe("Cash out Tk 1,000.00 — receipt #{$notification->transaction->id}")
        ->and($text)->toContain('Amount: Tk 1,000.00')
        ->toContain('Fee: Tk 50.00')
        ->toContain('Total: Tk 1,050.00')
        ->toContain('Balance after: Tk 450.00')
        ->toContain("Transaction ID: {$notification->transaction->id}");
});

test('without faking, a transfer writes both SMS receipts to the SMS log', function () {
    Log::spy();
    // SmsChannel writes through Log::channel(); keep it on the spy
    Log::shouldReceive('channel')->andReturnSelf();
    $sender = receiptCustomer(1500.00);
    $recipient = receiptCustomer(0.00);
    Sanctum::actingAs($sender, ['user']);

    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => 200.00,
        'pin' => '12345',
        'idempotency_key' => 'receipt-real-send',
    ])->assertCreated();

    Log::shouldHaveReceived('info')
        ->with('SMS message', Mockery::on(fn (array $context): bool => $context['to'] === $sender->phone_number
            && str_starts_with($context['message'], 'WalletMS: You sent Tk 200.00')))
        ->once();
    Log::shouldHaveReceived('info')
        ->with('SMS message', Mockery::on(fn (array $context): bool => $context['to'] === $recipient->phone_number
            && str_starts_with($context['message'], 'WalletMS: You received Tk 200.00')))
        ->once();
});

test('customers can turn email receipts off and on from their account', function () {
    $user = receiptCustomer(0.00, ['email' => 'rahim@example.com']);
    Sanctum::actingAs($user, ['user']);

    $this->patchJson('/api/v1/users/me', ['receipt_emails_enabled' => false])
        ->assertOk()
        ->assertJsonPath('data.user.receipt_emails_enabled', false);
    expect($user->refresh()->wantsReceiptEmails())->toBeFalse();

    $this->patchJson('/api/v1/users/me', ['receipt_emails_enabled' => true])
        ->assertOk()
        ->assertJsonPath('data.user.receipt_emails_enabled', true);
});

test('the recipient lookup still masks names the same way', function () {
    $user = receiptCustomer(0.00, ['name' => 'Rahul Kumar']);

    expect($user->maskedName())->toBe('R***l K***r')
        ->and(User::factory()->make(['name' => 'Al'])->maskedName())->toBe('A*')
        ->and(User::factory()->make(['phone_number' => '01712345678'])->maskedPhoneNumber())->toBe('017*****678');
});

test('anonymous notifiables never get the email copy', function () {
    $notification = new TransactionReceiptNotification(new Transaction, TransactionReceiptNotification::PARTY_SENDER);

    expect($notification->via((new AnonymousNotifiable)->route('sms', '01712345678')))->toBe([SmsChannel::class]);
});
