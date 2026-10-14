<?php

use App\Models\Cap;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Transactions\Jobs\ResetStaleCaps;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['transactions.business_timezone' => 'Asia/Dhaka']);
});

test('caps anchored to an earlier day in the same month reset daily usage only', function () {
    $this->travelTo(now('Asia/Dhaka')->setDate(2026, 10, 14)->setTime(0, 10));

    $cap = Cap::factory()->for(User::factory())->usage('800.00', '4200.00')->create(['last_reset_date' => '2026-10-13']);

    ResetStaleCaps::dispatchSync();

    $cap->refresh();
    expect((float) $cap->daily_used)->toBe(0.00)
        ->and((float) $cap->monthly_used)->toBe(4200.00)
        ->and($cap->last_reset_date->toDateString())->toBe('2026-10-14');
});

test('caps anchored to an earlier month reset both counters', function () {
    $this->travelTo(now('Asia/Dhaka')->setDate(2026, 11, 1)->setTime(0, 10));

    $cap = Cap::factory()->for(User::factory())->usage('800.00', '4200.00')->create(['last_reset_date' => '2026-10-31']);

    ResetStaleCaps::dispatchSync();

    $cap->refresh();
    expect((float) $cap->daily_used)->toBe(0.00)
        ->and((float) $cap->monthly_used)->toBe(0.00);
});

test('caps already anchored to today are left untouched, so a late run cannot wipe current usage', function () {
    $this->travelTo(now('Asia/Dhaka')->setDate(2026, 10, 14)->setTime(9, 0));

    $cap = Cap::factory()->for(User::factory())->usage('800.00', '4200.00')->create(['last_reset_date' => '2026-10-14']);

    ResetStaleCaps::dispatchSync();

    $cap->refresh();
    expect((float) $cap->daily_used)->toBe(800.00)
        ->and((float) $cap->monthly_used)->toBe(4200.00);
});

test('caps that were never anchored are reset', function () {
    $cap = Cap::factory()->for(User::factory())->usage('300.00', '300.00')->create(['last_reset_date' => null]);

    ResetStaleCaps::dispatchSync();

    $cap->refresh();
    expect((float) $cap->daily_used)->toBe(0.00)
        ->and((float) $cap->monthly_used)->toBe(0.00)
        ->and($cap->last_reset_date)->not->toBeNull();
});
