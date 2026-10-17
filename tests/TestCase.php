<?php

namespace Tests;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Modules\Authentication\Services\OtpService;
use Modules\Authentication\Services\RegistrationService;

abstract class TestCase extends BaseTestCase
{
    /**
     * Explicit helper to seed permissions, roles, and default role-permission attachments.
     */
    public function seedRolesAndPermissions(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * Issue a signup code for a phone number, as POST /auth/register/request-otp would, and return it.
     */
    public function registrationOtpFor(string $phoneNumber): string
    {
        return app(OtpService::class)->createForTarget(RegistrationService::OTP_PURPOSE, $phoneNumber)->otp_code;
    }
}
