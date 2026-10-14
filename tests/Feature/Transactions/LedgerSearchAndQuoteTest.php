<?php

use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\SystemSettingsSeeder;
use Laravel\Sanctum\Sanctum;
use Modules\SystemSettings\Services\SystemSettingService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->seed(SystemSettingsSeeder::class);
});

function createLedgerUser(string $name, string $phone, string $roleName = 'USER'): User
{
    $user = User::factory()->create(['name' => $name, 'phone_number' => $phone]);
    $user->roles()->attach(Role::where('name', $roleName)->firstOrFail()->id, ['assigned_at' => now()]);

    return $user;
}

function createLedgerTransfer(User $sender, User $recipient, string $amount = '100.00'): Transaction
{
    return Transaction::create([
        'user_id' => $sender->id,
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'initiated_by' => $sender->id,
        'type' => 'TRANSFER',
        'amount' => $amount,
        'system_fee_amount' => '0.00',
        'system_fee_rate' => '0.0000',
        'agent_commission_amount' => '0.00',
        'agent_commission_rate' => '0.0000',
        'currency' => 'BDT',
        'status' => 'COMPLETED',
        'idempotency_key' => 'ledger-'.uniqid(),
    ]);
}

test('admin searches the ledger by party name, phone and transaction id', function () {
    $rahim = createLedgerUser('Rahim Uddin', '01711000001');
    $karim = createLedgerUser('Karim Mia', '01711000002');
    $other = createLedgerUser('Someone Else', '01711000003');

    $first = createLedgerTransfer($rahim, $karim);
    createLedgerTransfer($other, $other);

    Sanctum::actingAs(createLedgerUser('Admin', '01711000009', 'ADMIN'), ['admin']);

    $ids = fn (string $query) => collect($this->getJson('/api/v1/transactions/admin/all?'.$query)->assertOk()->json('data.items'))->pluck('id')->all();

    expect($ids('search=Rahim'))->toBe([$first->id])
        ->and($ids('search=01711000002'))->toBe([$first->id])
        ->and($ids('search=%23'.$first->id))->toContain($first->id)
        ->and($ids('party_id='.$karim->id))->toBe([$first->id]);

    $this->getJson('/api/v1/transactions/admin/all?search=Rahim')
        ->assertJsonPath('data.items.0.parties.sender.name', 'Rahim Uddin')
        ->assertJsonPath('data.items.0.parties.recipient.phone_number', '01711000002');
});

test('ledger search treats LIKE wildcards literally', function () {
    $rahim = createLedgerUser('Rahim Uddin', '01711000001');
    createLedgerTransfer($rahim, $rahim);

    Sanctum::actingAs(createLedgerUser('Admin', '01711000009', 'ADMIN'), ['admin']);

    $this->getJson('/api/v1/transactions/admin/all?search=%25')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 0);
});

test('admin wallet list includes the owner and can be searched by name or phone', function () {
    $rahim = createLedgerUser('Rahim Uddin', '01711000001');
    $karim = createLedgerUser('Karim Mia', '01711000002');
    Wallet::factory()->for($rahim)->create();
    Wallet::factory()->for($karim)->create();

    Sanctum::actingAs(createLedgerUser('Admin', '01711000009', 'ADMIN'), ['admin']);

    $this->getJson('/api/v1/wallets/admin/all?search=01711000001')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.items.0.owner.name', 'Rahim Uddin')
        ->assertJsonPath('data.items.0.owner.phone_number', '01711000001');
});

test('a user previews the cash-out fee and total before confirming', function () {
    $customer = createLedgerUser('Rahim Uddin', '01711000001');
    Sanctum::actingAs($customer, ['user']);

    $rate = (float) app(SystemSettingService::class)->get('system_fee_rate');
    $expectedFee = round(1000 * $rate, 2);

    $this->getJson('/api/v1/transactions/quote?type=CASH_OUT&amount=1000')
        ->assertOk()
        ->assertJsonPath('data.quote.amount', 1000)
        ->assertJsonPath('data.quote.fee_amount', fn ($fee) => (float) $fee === $expectedFee)
        ->assertJsonPath('data.quote.total', fn ($total) => (float) $total === 1000 + $expectedFee);

    $this->getJson('/api/v1/transactions/quote?type=TRANSFER&amount=250.50')
        ->assertOk()
        ->assertJsonPath('data.quote.fee_amount', 0)
        ->assertJsonPath('data.quote.total', 250.5);
});

test('quote validates its input and is only for user tokens', function () {
    $customer = createLedgerUser('Rahim Uddin', '01711000001');

    Sanctum::actingAs($customer, ['user']);
    $this->getJson('/api/v1/transactions/quote?type=TOP_UP&amount=10')->assertJsonValidationErrors('type');
    $this->getJson('/api/v1/transactions/quote?type=CASH_OUT&amount=0')->assertJsonValidationErrors('amount');

    Sanctum::actingAs($customer, ['agent']);
    $this->getJson('/api/v1/transactions/quote?type=CASH_OUT&amount=10')->assertForbidden();
});
