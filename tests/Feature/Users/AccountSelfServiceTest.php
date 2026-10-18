<?php

use App\Models\AuditLog;
use App\Models\OtpToken;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Notifications\PhoneVerificationCodeNotification;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function createAccountTestUser(string $roleName, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->roles()->attach(Role::where('name', $roleName)->firstOrFail()->id, ['assigned_at' => now()]);

    return $user;
}

test('each panel can read and edit its own profile, but not email or phone', function (string $roleName, string $ability) {
    $user = createAccountTestUser($roleName);
    Sanctum::actingAs($user, [$ability]);

    $this->getJson('/api/v1/users/me')
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.avatar_url', null);

    $this->patchJson('/api/v1/users/me', ['name' => 'Renamed Person'])
        ->assertOk()
        ->assertJsonPath('data.user.name', 'Renamed Person');

    $this->patchJson('/api/v1/users/me', ['email' => 'new@wallet.test', 'phone_number' => '01700000000'])
        ->assertJsonValidationErrors(['email', 'phone_number']);

    expect($user->refresh()->email)->not->toBe('new@wallet.test');
})->with([
    'admin' => ['ADMIN', 'admin'],
    'agent' => ['AGENT', 'agent'],
    'customer' => ['USER', 'user'],
]);

test('an admin with a temporary password is told to change it before using their profile', function () {
    $admin = createAccountTestUser('ADMIN');
    $admin->forceFill(['password_changed_at' => null])->save();
    Sanctum::actingAs($admin, ['admin']);

    $this->getJson('/api/v1/users/me')
        ->assertForbidden()
        ->assertJsonPath('meta.code', 'PASSWORD_CHANGE_REQUIRED');

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.must_change_password', true);
});

test('changing a temporary password unlocks the admin panel and is audited', function () {
    $admin = createAccountTestUser('ADMIN');
    $admin->forceFill(['password_changed_at' => null])->save();
    $token = $admin->createToken('admin-login', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/v1/auth/change-password', [
        'current_password' => 'password',
        'new_password' => 'a-new-password',
        'new_password_confirmation' => 'a-new-password',
    ])->assertOk()->assertJsonPath('data.user.must_change_password', false);

    $this->app['auth']->forgetGuards();
    $this->withToken($response->json('data.token'))->getJson('/api/v1/users/me')->assertOk();

    $audit = AuditLog::where('action', 'PASSWORD_CHANGED')->sole();
    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->metadata['replaced_temporary_password'])->toBeTrue();
});

test('a user uploads, replaces and removes their avatar', function () {
    Storage::fake('public');
    $user = createAccountTestUser('USER');
    Sanctum::actingAs($user, ['user']);

    $first = $this->post('/api/v1/users/me/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 200, 200)], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('data.user.avatar_url');
    $firstPath = $user->refresh()->image;

    expect($first)->toContain('/storage/avatars/');
    Storage::disk('public')->assertExists($firstPath);

    $this->post('/api/v1/users/me/avatar', ['avatar' => UploadedFile::fake()->image('new.jpg', 300, 300)], ['Accept' => 'application/json'])
        ->assertOk();
    Storage::disk('public')->assertMissing($firstPath);

    $secondPath = $user->refresh()->image;
    $this->deleteJson('/api/v1/users/me/avatar')->assertOk()->assertJsonPath('data.user.avatar_url', null);
    Storage::disk('public')->assertMissing($secondPath);
    expect($user->refresh()->image)->toBeNull();
});

test('avatar uploads reject non-images, SVG and oversized files', function () {
    Storage::fake('public');
    Sanctum::actingAs(createAccountTestUser('USER'), ['user']);

    $upload = fn (UploadedFile $file) => $this->post('/api/v1/users/me/avatar', ['avatar' => $file], ['Accept' => 'application/json']);

    $upload(UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))->assertJsonValidationErrors('avatar');
    $upload(UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml'))->assertJsonValidationErrors('avatar');
    $upload(UploadedFile::fake()->image('huge.png', 500, 500)->size(3000))->assertJsonValidationErrors('avatar');
    $upload(UploadedFile::fake()->image('tiny.png', 10, 10))->assertJsonValidationErrors('avatar');

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('a customer verifies their phone with an SMS code', function () {
    Notification::fake();
    $customer = createAccountTestUser('USER', ['is_verified' => false]);
    Sanctum::actingAs($customer, ['user']);

    $this->postJson('/api/v1/users/me/phone-verification')->assertOk();

    $code = null;
    Notification::assertSentTo($customer, PhoneVerificationCodeNotification::class, function ($notification) use (&$code, $customer) {
        $code = $notification->code;

        return str_contains($notification->toSms($customer), $code);
    });

    $this->postJson('/api/v1/users/me/phone-verification/confirm', ['otp_code' => $code === '000000' ? '111111' : '000000'])
        ->assertStatus(401);
    expect((bool) $customer->refresh()->is_verified)->toBeFalse();

    $this->postJson('/api/v1/users/me/phone-verification/confirm', ['otp_code' => $code])
        ->assertOk()
        ->assertJsonPath('data.user.is_verified', true);

    $audit = AuditLog::where('action', 'PHONE_VERIFIED')->sole();
    expect($audit->actor_id)->toBe($customer->id)
        ->and($audit->metadata['method'])->toBe('OTP');

    // The account is now verified, so neither a reused code nor a new request is accepted
    Sanctum::actingAs($customer->refresh(), ['user']);
    $this->postJson('/api/v1/users/me/phone-verification/confirm', ['otp_code' => $code])->assertStatus(409);
    $this->postJson('/api/v1/users/me/phone-verification')->assertStatus(409);
});

test('phone verification codes are rate limited and need a phone number', function () {
    Notification::fake();
    $agent = createAccountTestUser('AGENT', ['is_verified' => false]);
    Sanctum::actingAs($agent, ['agent']);

    foreach (range(1, 3) as $attempt) {
        $this->postJson('/api/v1/users/me/phone-verification')->assertOk();
    }
    $this->postJson('/api/v1/users/me/phone-verification')->assertStatus(429);

    // Each request invalidates the previous code
    expect(OtpToken::where('user_id', $agent->id)->whereNull('used_at')->count())->toBe(1);

    $staff = createAccountTestUser('ADMIN', ['phone_number' => null, 'is_verified' => false]);
    Sanctum::actingAs($staff, ['admin']);
    $this->postJson('/api/v1/users/me/phone-verification')->assertStatus(422);
});

test('account endpoints require a signed-in panel token', function () {
    $this->getJson('/api/v1/users/me')->assertUnauthorized();

    $invitee = createAccountTestUser('ADMIN');
    Sanctum::actingAs($invitee, ['password-change']);
    $this->getJson('/api/v1/users/me')->assertForbidden();
});

test('staff add a phone number by SMS code and can then sign in to the admin panel with it', function () {
    Notification::fake();
    $staff = createAccountTestUser('ADMIN', ['phone_number' => null, 'is_verified' => false]);
    Sanctum::actingAs($staff, ['admin']);

    $this->postJson('/api/v1/users/me/phone', ['phone_number' => '01822000111'])->assertOk();

    $code = null;
    Notification::assertSentOnDemand(PhoneVerificationCodeNotification::class, function ($notification, $channels, $notifiable) use (&$code) {
        $code = $notification->code;

        return $notifiable->routeNotificationFor('sms') === '01822000111';
    });
    expect($staff->refresh()->phone_number)->toBeNull();

    $this->postJson('/api/v1/users/me/phone/confirm', ['otp_code' => $code])
        ->assertOk()
        ->assertJsonPath('data.user.phone_number', '01822000111')
        ->assertJsonPath('data.user.is_verified', true);

    expect(AuditLog::where('action', 'PHONE_ADDED')->where('auditable_id', $staff->id)->exists())->toBeTrue();

    // Admin login by phone: password step, then the OTP step uses the email the server returned
    $this->app['auth']->forgetGuards();
    $email = $this->postJson('/api/v1/auth/admin/login', ['identifier' => '01822000111', 'password' => 'password'])
        ->assertOk()
        ->json('data.email');
    expect($email)->toBe($staff->email);
});

test('adding a phone is refused for a taken number or an account that already has one', function () {
    Notification::fake();
    createAccountTestUser('USER', ['phone_number' => '01822000222']);
    $staff = createAccountTestUser('ADMIN', ['phone_number' => null]);
    Sanctum::actingAs($staff, ['admin']);

    $this->postJson('/api/v1/users/me/phone', ['phone_number' => '01822000222'])->assertJsonValidationErrors('phone_number');
    $this->postJson('/api/v1/users/me/phone', ['phone_number' => '12345'])->assertJsonValidationErrors('phone_number');

    $customer = createAccountTestUser('USER');
    Sanctum::actingAs($customer, ['user']);
    $this->postJson('/api/v1/users/me/phone', ['phone_number' => '01822000333'])->assertStatus(409);
});

test('a number registered by someone else after the code was sent is not attached', function () {
    Notification::fake();
    $staff = createAccountTestUser('ADMIN', ['phone_number' => null]);
    Sanctum::actingAs($staff, ['admin']);

    $this->postJson('/api/v1/users/me/phone', ['phone_number' => '01822000444'])->assertOk();
    $code = OtpToken::where('user_id', $staff->id)->where('purpose', 'ADD_PHONE')->sole()->otp_code;

    createAccountTestUser('USER', ['phone_number' => '01822000444']);

    $this->postJson('/api/v1/users/me/phone/confirm', ['otp_code' => $code])->assertJsonValidationErrors('phone_number');
    expect($staff->refresh()->phone_number)->toBeNull();
});

test('codes to a number are limited even across accounts', function () {
    Notification::fake();

    foreach (range(1, 3) as $attempt) {
        Sanctum::actingAs(createAccountTestUser('ADMIN', ['phone_number' => null]), ['admin']);
        $this->postJson('/api/v1/users/me/phone', ['phone_number' => '01822000555'])->assertOk();
    }

    Sanctum::actingAs(createAccountTestUser('ADMIN', ['phone_number' => null]), ['admin']);
    $this->postJson('/api/v1/users/me/phone', ['phone_number' => '01822000555'])->assertStatus(429);
});
