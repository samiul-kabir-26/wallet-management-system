<?php

use Modules\Authentication\Services\OtpService;
use Tests\TestCase;

uses(TestCase::class);

test('generateOtp always returns a 6-character numeric string with leading zeros preserved', function () {
    $service = new OtpService;

    for ($i = 0; $i < 100; $i++) {
        $code = $service->generateOtp();

        expect(strlen($code))->toBe(6);
        expect(ctype_digit($code))->toBeTrue();
        expect($code)->toMatch('/^\d{6}$/');
    }
});
