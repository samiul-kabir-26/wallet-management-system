<?php

use App\Models\Cap;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Transactions\Exceptions\DailyCapsExceededException;
use Modules\Transactions\Exceptions\MonthlyCapsExceededException;
use Modules\Transactions\Services\CapService;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->user = User::factory()->create();
    $this->service = new CapService;
});

test('assertWithinCaps succeeds when amount is strictly within daily and monthly caps', function () {
    $cap = Cap::factory()->for($this->user)->usage('2000.00', '5000.00')->create();

    // Requesting 3000 should pass (projected daily: 5000 <= 10000, monthly: 8000 <= 50000)
    $this->service->assertWithinCaps($cap, '3000.00');
    expect(true)->toBeTrue();
});

test('assertWithinCaps succeeds when amount reaches exactly the cap limit', function () {
    $cap = Cap::factory()->for($this->user)->usage('7000.00', '10000.00')->create();

    // Requesting exactly remaining 3000 should pass
    $this->service->assertWithinCaps($cap, '3000.00');
    expect(true)->toBeTrue();
});

test('assertWithinCaps throws DailyCapsExceededException when daily cap would be breached', function () {
    $cap = Cap::factory()->for($this->user)->usage('9500.00', '10000.00')->create();

    expect(fn () => $this->service->assertWithinCaps($cap, '500.01'))
        ->toThrow(DailyCapsExceededException::class);
});

test('assertWithinCaps throws MonthlyCapsExceededException when monthly cap would be breached', function () {
    $cap = Cap::factory()->for($this->user)->usage('1000.00', '49500.00')->create();

    expect(fn () => $this->service->assertWithinCaps($cap, '600.00'))
        ->toThrow(MonthlyCapsExceededException::class);
});

test('increment updates both daily and monthly used amounts atomically', function () {
    $cap = Cap::factory()->for($this->user)->usage('1000.50', '2500.25')->create();

    $this->service->increment($cap, '500.25');

    $cap->refresh();
    expect((string) $cap->daily_used)->toBe('1500.75')
        ->and((string) $cap->monthly_used)->toBe('3000.50');
});
