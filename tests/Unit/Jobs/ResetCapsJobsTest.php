<?php

use App\Models\Cap;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Transactions\Jobs\ResetDailyCaps;
use Modules\Transactions\Jobs\ResetMonthlyCaps;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('ResetDailyCaps zeroes daily_used across all cap records and leaves monthly_used untouched', function () {
    $users = User::factory()->count(3)->create();

    foreach ($users as $index => $user) {
        $amount = (string) (($index + 1) * 100);
        Cap::factory()->for($user)->usage($amount, '2500.00')->create();
    }

    expect(Cap::where('daily_used', '>', 0)->count())->toBe(3)
        ->and(Cap::where('monthly_used', '2500.00')->count())->toBe(3);

    (new ResetDailyCaps)->handle();

    expect(Cap::where('daily_used', 0)->count())->toBe(3)
        ->and(Cap::where('monthly_used', '2500.00')->count())->toBe(3);

    foreach (Cap::all() as $cap) {
        expect((float) $cap->daily_used)->toBe(0.00)
            ->and((float) $cap->monthly_used)->toBe(2500.00);
    }
});

test('ResetMonthlyCaps zeroes monthly_used across all cap records and leaves daily_used untouched', function () {
    $users = User::factory()->count(3)->create();

    foreach ($users as $index => $user) {
        $amount = (string) (($index + 1) * 500);
        Cap::factory()->for($user)->usage('350.00', $amount)->create();
    }

    expect(Cap::where('monthly_used', '>', 0)->count())->toBe(3)
        ->and(Cap::where('daily_used', '350.00')->count())->toBe(3);

    (new ResetMonthlyCaps)->handle();

    expect(Cap::where('monthly_used', 0)->count())->toBe(3)
        ->and(Cap::where('daily_used', '350.00')->count())->toBe(3);

    foreach (Cap::all() as $cap) {
        expect((float) $cap->monthly_used)->toBe(0.00)
            ->and((float) $cap->daily_used)->toBe(350.00);
    }
});

test('both jobs run sequentially to reset all cap usage', function () {
    $user = User::factory()->create();
    $cap = Cap::factory()->for($user)->usage('800.00', '4200.00')->create();

    ResetDailyCaps::dispatchSync();
    ResetMonthlyCaps::dispatchSync();

    $cap->refresh();

    expect((float) $cap->daily_used)->toBe(0.00)
        ->and((float) $cap->monthly_used)->toBe(0.00);
});
