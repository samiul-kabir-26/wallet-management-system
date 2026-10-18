<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Notifications\AdminLoginAlertNotification;
use Modules\Authentication\Notifications\OtpCodeNotification;
use Modules\Authentication\Services\TotpService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
});

function securityAdmin(): User
{
    $admin = User::factory()->create([
        'email' => 'staff'.uniqid().'@wallet.test',
        'password' => Hash::make('secret_admin_pass'),
        'password_changed_at' => now(),
    ]);
    $admin->roles()->attach(Role::where('name', 'ADMIN')->firstOrFail()->id, ['assigned_at' => now()]);

    return $admin;
}

/**
 * Run both admin login steps; returns the verify-otp response.
 */
function adminSignIn(object $test, User $admin, ?string $code = null, string $userAgent = 'Firefox/130')
{
    $test->withHeader('User-Agent', $userAgent);
    $test->postJson('/api/v1/auth/admin/login', ['identifier' => $admin->email, 'password' => 'secret_admin_pass'])->assertOk();

    $code ??= Notification::sent($admin, OtpCodeNotification::class)->last()->code;

    return $test->postJson('/api/v1/auth/admin/verify-otp', ['email' => $admin->email, 'otp_code' => $code]);
}

function currentTotp(User $user, int $offsetSteps = 0): string
{
    $totp = app(TotpService::class);

    return $totp->codeAt($totp->base32Decode((string) $user->fresh()->totp_secret), intdiv(time(), 30) + $offsetSteps);
}

test('TOTP codes match the RFC 6238 test vector', function () {
    // RFC 6238 Appendix B, SHA-1 secret "12345678901234567890" at T=59 -> 94287082 (6 digits: 287082)
    $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    $totp = app(TotpService::class);

    expect($totp->match($secret, '287082', null, 59))->toBe(1)
        ->and($totp->match($secret, '287082', 1, 59))->toBeNull()
        ->and($totp->match($secret, '123456', null, 59))->toBeNull()
        ->and($totp->match($secret, 'abcdef', null, 59))->toBeNull();
});

test('staff set up an authenticator app and it must prove a code before it is switched on', function () {
    $admin = securityAdmin();
    Sanctum::actingAs($admin, ['admin']);

    $setup = $this->postJson('/api/v1/auth/totp/setup')->assertOk();
    expect($setup->json('data.otpauth_uri'))->toStartWith('otpauth://totp/')
        ->and($admin->fresh()->usesTotp())->toBeFalse()
        ->and($admin->fresh()->getRawOriginal('totp_secret'))->not->toBe($setup->json('data.secret'));

    $this->postJson('/api/v1/auth/totp/confirm', ['code' => '000000'])->assertUnauthorized();
    $this->postJson('/api/v1/auth/totp/confirm', ['code' => currentTotp($admin)])->assertOk();

    expect($admin->fresh()->usesTotp())->toBeTrue()
        ->and(AuditLog::where('action', 'TOTP_ENABLED')->exists())->toBeTrue();
    $this->postJson('/api/v1/auth/totp/setup')->assertStatus(409);
});

test('with an authenticator app, sign-in skips the email and accepts each app code once', function () {
    $admin = securityAdmin();
    Sanctum::actingAs($admin, ['admin']);
    $this->postJson('/api/v1/auth/totp/setup');
    $this->postJson('/api/v1/auth/totp/confirm', ['code' => currentTotp($admin)])->assertOk();
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/v1/auth/admin/login', ['identifier' => $admin->email, 'password' => 'secret_admin_pass'])
        ->assertOk()
        ->assertJsonPath('data.second_factor', 'totp');
    Notification::assertNotSentTo($admin, OtpCodeNotification::class);

    $code = currentTotp($admin, 1);
    $this->postJson('/api/v1/auth/admin/verify-otp', ['email' => $admin->email, 'otp_code' => $code])
        ->assertOk()
        ->assertJsonStructure(['data' => ['token']]);

    // The same code cannot be used again, even right after another password step
    $this->postJson('/api/v1/auth/admin/login', ['identifier' => $admin->email, 'password' => 'secret_admin_pass'])->assertOk();
    $this->postJson('/api/v1/auth/admin/verify-otp', ['email' => $admin->email, 'otp_code' => $code])->assertUnauthorized();
});

test('an authenticator code alone, without the password step, does not sign in', function () {
    $admin = securityAdmin();
    $admin->forceFill(['totp_secret' => app(TotpService::class)->generateSecret(), 'totp_confirmed_at' => now()])->save();

    $this->postJson('/api/v1/auth/admin/verify-otp', ['email' => $admin->email, 'otp_code' => currentTotp($admin)])
        ->assertUnauthorized();
});

test('turning the authenticator app off needs the password and a current code', function () {
    $admin = securityAdmin();
    Sanctum::actingAs($admin, ['admin']);
    $this->postJson('/api/v1/auth/totp/setup');
    $this->postJson('/api/v1/auth/totp/confirm', ['code' => currentTotp($admin)]);

    $this->postJson('/api/v1/auth/totp/disable', ['password' => 'wrong', 'code' => currentTotp($admin, 1)])->assertUnauthorized();
    $this->postJson('/api/v1/auth/totp/disable', ['password' => 'secret_admin_pass', 'code' => currentTotp($admin, 1)])->assertOk();

    expect($admin->fresh()->usesTotp())->toBeFalse();
});

test('sessions record where they came from and can be signed out one by one', function () {
    $admin = securityAdmin();
    $first = adminSignIn($this, $admin, userAgent: 'Firefox/130')->json('data.token');
    $second = adminSignIn($this, $admin, userAgent: 'Chrome/128')->json('data.token');

    $sessions = $this->withHeader('Authorization', "Bearer {$second}")->getJson('/api/v1/auth/sessions')
        ->assertOk()
        ->assertJsonCount(2, 'data.sessions')
        ->json('data.sessions');
    $current = collect($sessions)->firstWhere('current', true);
    $other = collect($sessions)->firstWhere('current', false);
    expect($current['user_agent'])->toBe('Chrome/128')
        ->and($other['user_agent'])->toBe('Firefox/130')
        ->and($current['panel'])->toBe('admin');

    $this->withHeader('Authorization', "Bearer {$second}")->deleteJson("/api/v1/auth/sessions/{$other['id']}")->assertOk();
    expect(PersonalAccessToken::findToken($first))->toBeNull()
        ->and(AuditLog::where('action', 'SESSION_REVOKED')->exists())->toBeTrue();

    $someoneElse = securityAdmin()->createToken('x', ['admin'])->accessToken;
    $this->withHeader('Authorization', "Bearer {$second}")->deleteJson("/api/v1/auth/sessions/{$someoneElse->id}")->assertNotFound();
});

test('staff get an email when they sign in from a new IP and browser, but not on the first sign-in or a known device', function () {
    // Three full sign-ins in a row would trip the login rate limit, which is not what this test is about
    $this->withoutMiddleware(ThrottleRequests::class);
    $admin = securityAdmin();

    adminSignIn($this, $admin, userAgent: 'Firefox/130')->assertOk();
    Notification::assertNotSentTo($admin, AdminLoginAlertNotification::class);

    adminSignIn($this, $admin, userAgent: 'Firefox/130')->assertOk();
    Notification::assertNotSentTo($admin, AdminLoginAlertNotification::class);

    adminSignIn($this, $admin, userAgent: 'Unknown-Browser/1.0')->assertOk();
    Notification::assertSentTo($admin, AdminLoginAlertNotification::class,
        fn ($n) => $n->userAgent === 'Unknown-Browser/1.0' && str_contains(implode(' ', $n->toMail($admin)->introLines), 'IP address'));
});
