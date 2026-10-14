<?php

use App\Models\Cap;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->seed(SystemSettingsSeeder::class);
    config(['transactions.business_timezone' => 'Asia/Dhaka']);
});

function createCapRolloverUser(float $balance = 100000.00): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'USER')->firstOrFail()->id, ['assigned_at' => now()]);
    Wallet::factory()->for($user)->balance($balance)->create();

    return $user;
}

function travelToDhaka(string $dateTime): void
{
    test()->travelTo(CarbonImmutable::parse($dateTime, 'Asia/Dhaka'));
}

function transferForCapRollover(User $sender, User $recipient, string $amount, string $key): TestResponse
{
    Sanctum::actingAs($sender, ['user']);

    return test()->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number,
        'amount' => $amount,
        'pin' => '12345',
        'idempotency_key' => $key,
    ]);
}

test('caps endpoint reports used and remaining amounts after a transfer', function () {
    travelToDhaka('2026-10-14 10:00');
    $sender = createCapRolloverUser();
    $recipient = createCapRolloverUser();
    Cap::factory()->for($sender)->usage('0.00', '0.00')->create();

    transferForCapRollover($sender, $recipient, '1500.00', 'caps-endpoint-1')->assertCreated();

    $this->getJson('/api/v1/transactions/caps')
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'data' => [
                'caps' => [
                    'daily_limit' => 10000,
                    'daily_used' => 1500,
                    'daily_remaining' => 8500,
                    'monthly_limit' => 50000,
                    'monthly_used' => 1500,
                    'monthly_remaining' => 48500,
                ],
            ],
        ]);
});

test("yesterday's usage does not count toward today's daily cap but still counts toward the month", function () {
    travelToDhaka('2026-10-14 10:00');
    $sender = createCapRolloverUser();
    $recipient = createCapRolloverUser();
    $cap = Cap::factory()->for($sender)->usage('9500.00', '9500.00')->create(['last_reset_date' => '2026-10-13']);

    // Would breach the 10,000 daily cap if yesterday's 9,500 still counted
    transferForCapRollover($sender, $recipient, '1000.00', 'rollover-day-1')->assertCreated();

    $cap->refresh();
    expect((float) $cap->daily_used)->toBe(1000.00)
        ->and((float) $cap->monthly_used)->toBe(10500.00)
        ->and($cap->last_reset_date->toDateString())->toBe('2026-10-14');
});

test('the first transaction of a new month resets monthly usage', function () {
    travelToDhaka('2026-11-01 09:00');
    $sender = createCapRolloverUser();
    $recipient = createCapRolloverUser();
    $cap = Cap::factory()->for($sender)->usage('500.00', '49900.00')->create(['last_reset_date' => '2026-10-31']);

    transferForCapRollover($sender, $recipient, '1000.00', 'rollover-month-1')->assertCreated();

    $cap->refresh();
    expect((float) $cap->daily_used)->toBe(1000.00)
        ->and((float) $cap->monthly_used)->toBe(1000.00);
});

test('the daily cap is still enforced within the same business day', function () {
    travelToDhaka('2026-10-14 10:00');
    $sender = createCapRolloverUser();
    $recipient = createCapRolloverUser();
    Cap::factory()->for($sender)->usage('9500.00', '9500.00')->create(['last_reset_date' => '2026-10-14']);

    transferForCapRollover($sender, $recipient, '1000.00', 'same-day-1')->assertStatus(422);
});

test('the day rolls over at midnight Dhaka time, not midnight UTC', function () {
    $sender = createCapRolloverUser();
    Cap::factory()->for($sender)->usage('4000.00', '4000.00')->create(['last_reset_date' => '2026-10-14']);
    Sanctum::actingAs($sender, ['user']);

    // 23:59 Dhaka on the 14th: still the same business day
    travelToDhaka('2026-10-14 23:59');
    $this->getJson('/api/v1/transactions/caps')->assertJsonPath('data.caps.daily_used', 4000);

    // 00:01 Dhaka on the 15th is still the 14th in UTC, but it is a new business day
    travelToDhaka('2026-10-15 00:01');
    expect(now('UTC')->toDateString())->toBe('2026-10-14');
    $this->getJson('/api/v1/transactions/caps')
        ->assertJsonPath('data.caps.daily_used', 0)
        ->assertJsonPath('data.caps.monthly_used', 4000);
});

test('caps endpoint creates a default cap row for accounts that have none', function () {
    $user = createCapRolloverUser();
    Sanctum::actingAs($user, ['user']);

    $this->getJson('/api/v1/transactions/caps')
        ->assertOk()
        ->assertJsonPath('data.caps.daily_limit', 10000)
        ->assertJsonPath('data.caps.daily_remaining', 10000);

    expect(Cap::where('user_id', $user->id)->exists())->toBeTrue();
});

test('caps endpoint is only available to user tokens', function () {
    $user = createCapRolloverUser();

    Sanctum::actingAs($user, ['agent']);
    $this->getJson('/api/v1/transactions/caps')->assertForbidden();

    Sanctum::actingAs($user, ['admin']);
    $this->getJson('/api/v1/transactions/caps')->assertForbidden();
});
