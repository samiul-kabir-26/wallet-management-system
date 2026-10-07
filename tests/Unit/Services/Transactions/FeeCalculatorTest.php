<?php

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Transactions\Services\FeeCalculator;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('fee calculator uses default 5 percent fee rate when setting is not present', function () {
    $calculator = new FeeCalculator;
    $result = $calculator->calculate('1000.00');

    expect($result)->toBe([
        'rate' => '0.0500',
        'amount' => '50.00',
    ]);
});

test('fee calculator uses rate from system_fee_rate setting', function () {
    SystemSetting::create([
        'key' => 'system_fee_rate',
        'value' => 0.025,
    ]);

    $calculator = new FeeCalculator;
    $result = $calculator->calculate('2000.00');

    expect($result)->toBe([
        'rate' => '0.0250',
        'amount' => '50.00',
    ]);
});

test('fee calculator correctly handles zero fee rate', function () {
    SystemSetting::create([
        'key' => 'system_fee_rate',
        'value' => 0.0,
    ]);

    $calculator = new FeeCalculator;
    $result = $calculator->calculate('1000.00');

    expect($result)->toBe([
        'rate' => '0.0000',
        'amount' => '0.00',
    ]);
});

test('fee calculator handles small decimal amounts accurately', function () {
    SystemSetting::create([
        'key' => 'system_fee_rate',
        'value' => 0.05,
    ]);

    $calculator = new FeeCalculator;
    $result = $calculator->calculate('10.50');

    expect($result)->toBe([
        'rate' => '0.0500',
        'amount' => '0.53',
    ]);
});
