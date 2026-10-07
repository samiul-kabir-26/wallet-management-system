<?php

use App\Models\AgentInfo;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Transactions\Services\CommissionCalculator;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Cache::flush();
});

test('commission calculator computes commission using agent commission_rate', function () {
    $agent = User::factory()->create();
    $agentInfo = AgentInfo::create([
        'user_id' => $agent->id,
        'commission_rate' => 0.0100,
        'status' => 'APPROVED',
    ]);

    $calculator = app(CommissionCalculator::class);
    $result = $calculator->calculate('1000.00', $agentInfo);

    expect($result)->toBe([
        'rate' => '0.0100',
        'amount' => '10.00',
    ]);
});

test('commission calculator respects custom commission rate', function () {
    $agent = User::factory()->create();
    $agentInfo = AgentInfo::create([
        'user_id' => $agent->id,
        'commission_rate' => 0.0175,
        'status' => 'APPROVED',
    ]);

    $calculator = app(CommissionCalculator::class);
    $result = $calculator->calculate('1000.00', $agentInfo);

    expect($result)->toBe([
        'rate' => '0.0175',
        'amount' => '17.50',
    ]);
});

test('commission calculator falls back to agent_commission_rate setting when agent commission_rate is null', function () {
    SystemSetting::create([
        'key' => 'agent_commission_rate',
        'value' => 0.025,
    ]);

    $agentInfo = new AgentInfo([
        'commission_rate' => null,
        'status' => 'APPROVED',
    ]);

    $calculator = app(CommissionCalculator::class);
    $result = $calculator->calculate('1000.00', $agentInfo);

    expect($result)->toBe([
        'rate' => '0.0250',
        'amount' => '25.00',
    ]);
});

test('commission calculator caps commission to max commission when specified', function () {
    $agent = User::factory()->create();
    $agentInfo = AgentInfo::create([
        'user_id' => $agent->id,
        'commission_rate' => 0.0500,
        'status' => 'APPROVED',
    ]);

    $calculator = app(CommissionCalculator::class);
    // 5% of 1000 = 50.00, but fee cap is 30.00
    $result = $calculator->calculate('1000.00', $agentInfo, '30.00');

    expect($result)->toBe([
        'rate' => '0.0500',
        'amount' => '30.00',
    ]);
});

test('commission calculator does not cap if calculated commission is below max', function () {
    $agent = User::factory()->create();
    $agentInfo = AgentInfo::create([
        'user_id' => $agent->id,
        'commission_rate' => 0.0100,
        'status' => 'APPROVED',
    ]);

    $calculator = app(CommissionCalculator::class);
    // 1% of 1000 = 10.00, fee cap is 50.00
    $result = $calculator->calculate('1000.00', $agentInfo, '50.00');

    expect($result)->toBe([
        'rate' => '0.0100',
        'amount' => '10.00',
    ]);
});
