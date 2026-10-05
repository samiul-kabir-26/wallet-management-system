<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Authentication\Exceptions\InvalidCredentialsException;
use Modules\Authentication\Services\PinAuthService;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('attempt throws InvalidCredentialsException for nonexistent phone number', function () {
    $service = app(PinAuthService::class);

    expect(fn () => $service->attempt('01799999999', '12345'))
        ->toThrow(InvalidCredentialsException::class, 'Invalid phone number or PIN.');
});

test('attempt throws identical InvalidCredentialsException class and message for wrong PIN', function () {
    $service = app(PinAuthService::class);

    // 1. Capture non-existent exception message
    $nonExistentMessage = null;
    try {
        $service->attempt('01799999999', '12345');
    } catch (InvalidCredentialsException $e) {
        $nonExistentMessage = $e->getMessage();
    }

    // 2. Create real user with PIN
    $user = User::factory()->create([
        'pin' => Hash::make('11111'),
    ]);

    // 3. Capture wrong-PIN exception message
    $wrongPinMessage = null;
    try {
        $service->attempt($user->phone_number, '99999');
    } catch (InvalidCredentialsException $e) {
        $wrongPinMessage = $e->getMessage();
    }

    // 4. Assert both branches throw and produce byte-identical messages for anti-enumeration
    expect($nonExistentMessage)->not->toBeNull();
    expect($wrongPinMessage)->not->toBeNull();
    expect($wrongPinMessage)->toBe('Invalid phone number or PIN.');
    expect($wrongPinMessage)->toBe($nonExistentMessage);
});
