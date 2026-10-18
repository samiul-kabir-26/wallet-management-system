<?php

use App\Models\AuditLog;
use App\Models\Cap;
use App\Models\KycSubmission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Transactions\Services\TransactionService;
use Modules\Users\Notifications\KycReviewedNotification;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->seed(SystemSettingsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

function kycCustomer(string $tier = 'BASIC', float $balance = 0.00, array $attributes = []): User
{
    $user = User::factory()->create(['kyc_tier' => $tier, ...$attributes]);
    $user->roles()->attach(Role::where('name', 'USER')->firstOrFail()->id, ['assigned_at' => now()]);
    Wallet::factory()->for($user)->create();
    $user->cap()->create();
    if ($balance > 0) {
        app(TransactionService::class)->topUp($user, $balance, 'kyc-fund-'.$user->id);
    }

    return $user->refresh();
}

function kycStaff(string $role = 'ADMIN'): User
{
    $staff = User::factory()->create();
    $staff->roles()->attach(Role::where('name', $role)->firstOrFail()->id, ['assigned_at' => now()]);

    return $staff;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function kycSubmit(object $test, User $user, array $overrides = [])
{
    Sanctum::actingAs($user, ['user']);

    return $test->post('/api/v1/users/me/kyc', [
        'document_type' => 'NID',
        'document_number' => '1990123456789',
        'full_name' => 'Rahim Uddin',
        'date_of_birth' => '1990-05-01',
        'front' => UploadedFile::fake()->image('front.jpg', 1000, 640),
        'back' => UploadedFile::fake()->image('back.jpg', 1000, 640),
        ...$overrides,
    ], ['Accept' => 'application/json']);
}

function kycLimits(User $user): array
{
    $cap = Cap::where('user_id', $user->id)->firstOrFail();

    return [(float) $cap->daily_cap, (float) $cap->monthly_cap];
}

test('cap rows take the owner tier limits, and accounts created before tiers stay STANDARD', function () {
    expect(kycLimits(kycCustomer('BASIC')))->toBe([5000.0, 20000.0])
        ->and(kycLimits(kycCustomer('PREMIUM')))->toBe([25000.0, 100000.0]);

    $legacy = User::factory()->create();
    $legacy->cap()->create();
    expect($legacy->refresh()->kyc_tier)->toBe('STANDARD')
        ->and(kycLimits($legacy))->toBe([10000.0, 50000.0]);
});

test('a BASIC customer cannot transfer more than the BASIC daily limit', function () {
    $sender = kycCustomer('BASIC', 8000.00);
    $recipient = kycCustomer('BASIC');
    Sanctum::actingAs($sender, ['user']);

    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number, 'amount' => 6000.00, 'pin' => '12345', 'idempotency_key' => 'kyc-over-limit',
    ])->assertUnprocessable();

    $this->postJson('/api/v1/transactions/transfer', [
        'recipient_phone' => $recipient->phone_number, 'amount' => 5000.00, 'pin' => '12345', 'idempotency_key' => 'kyc-at-limit',
    ])->assertCreated();
});

test('a customer submits identity documents; images stay private and the number is encrypted', function () {
    $user = kycCustomer();

    $id = kycSubmit($this, $user)
        ->assertCreated()
        ->assertJsonPath('data.submission.status', 'PENDING')
        ->assertJsonPath('data.submission.document_number', '*********6789')
        ->assertJsonMissingPath('data.submission.front_path')
        ->json('data.submission.id');

    $submission = KycSubmission::findOrFail($id);
    Storage::disk('local')->assertExists([$submission->front_path, $submission->back_path]);
    expect($submission->front_path)->toStartWith("kyc/{$user->id}/")
        ->and($submission->document_number)->toBe('1990123456789')
        ->and(DB::table('kyc_submissions')->value('document_number'))->not->toContain('1990123456789')
        ->and(AuditLog::where('action', 'KYC_SUBMITTED')->sole()->actor_id)->toBe($user->id);
});

test('identity submissions are validated', function (array $overrides, string $field) {
    kycSubmit($this, kycCustomer(), $overrides)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'under 18' => [['date_of_birth' => now()->subYears(17)->toDateString()], 'date_of_birth'],
    'unknown document type' => [['document_type' => 'LIBRARY_CARD'], 'document_type'],
    'odd characters in the number' => [['document_number' => '123-456<>'], 'document_number'],
    'PDF instead of a photo' => [['front' => UploadedFile::fake()->create('front.pdf', 100, 'application/pdf')], 'front'],
    'missing back' => [['back' => null], 'back'],
    'photo over 5 MB' => [['back' => UploadedFile::fake()->image('back.jpg')->size(6000)], 'back'],
]);

test('only one submission can wait at a time, and only customers can submit', function () {
    $user = kycCustomer();
    kycSubmit($this, $user)->assertCreated();
    kycSubmit($this, $user)->assertStatus(409)->assertJsonPath('message', 'Your documents are already waiting for review.');

    $agent = User::factory()->create();
    $agent->roles()->attach(Role::where('name', 'AGENT')->firstOrFail()->id, ['assigned_at' => now()]);
    Sanctum::actingAs($agent, ['agent']);
    $this->postJson('/api/v1/users/me/kyc', [])->assertForbidden();
});

test('approving verifies the customer, raises BASIC to STANDARD and keeps usage', function () {
    $user = kycCustomer('BASIC');
    Cap::where('user_id', $user->id)->update(['daily_used' => '1200.00']);
    $id = kycSubmit($this, $user)->json('data.submission.id');
    $staff = kycStaff();
    Sanctum::actingAs($staff, ['admin']);

    $this->getJson('/api/v1/users/kyc-submissions?status=PENDING')
        ->assertOk()
        ->assertJsonPath('data.items.0.document_number', '1990123456789')
        ->assertJsonPath('data.items.0.user.kyc_tier', 'BASIC');

    $this->postJson("/api/v1/users/kyc-submissions/{$id}/approve", ['note' => 'Photo matches'])
        ->assertOk()
        ->assertJsonPath('data.submission.status', 'APPROVED');

    $user->refresh();
    $cap = Cap::where('user_id', $user->id)->firstOrFail();
    expect($user->kyc_tier)->toBe('STANDARD')
        ->and($user->kyc_verified_at)->not->toBeNull()
        ->and([(float) $cap->daily_cap, (float) $cap->monthly_cap, (float) $cap->daily_used])->toBe([10000.0, 50000.0, 1200.0])
        ->and(AuditLog::where('action', 'KYC_APPROVED')->sole()->actor_id)->toBe($staff->id);

    Notification::assertSentTo($user, KycReviewedNotification::class,
        fn ($n) => $n->toSms($user) === 'WalletMS: Your identity is verified. Your account is now Standard. Daily limit: Tk 10,000.00.');

    $this->postJson("/api/v1/users/kyc-submissions/{$id}/approve")->assertStatus(409);
});

test('approval never lowers a higher tier or custom limits', function () {
    $user = kycCustomer('STANDARD');
    Cap::where('user_id', $user->id)->update(['daily_cap' => '40000.00', 'monthly_cap' => '90000.00', 'is_custom' => true]);
    $id = kycSubmit($this, $user)->json('data.submission.id');
    Sanctum::actingAs(kycStaff(), ['admin']);

    $this->postJson("/api/v1/users/kyc-submissions/{$id}/approve")->assertOk();

    expect($user->refresh()->kyc_tier)->toBe('STANDARD')
        ->and(kycLimits($user))->toBe([40000.0, 90000.0]);
});

test('rejecting tells the customer why, and they can submit again', function () {
    $user = kycCustomer();
    $id = kycSubmit($this, $user)->json('data.submission.id');
    Sanctum::actingAs(kycStaff(), ['admin']);

    $this->postJson("/api/v1/users/kyc-submissions/{$id}/reject")->assertUnprocessable();
    $this->postJson("/api/v1/users/kyc-submissions/{$id}/reject", ['reason' => 'The photo of the back is blurred.'])
        ->assertOk()
        ->assertJsonPath('data.submission.status', 'REJECTED');

    expect($user->refresh()->kyc_tier)->toBe('BASIC');
    Notification::assertSentTo($user, KycReviewedNotification::class,
        fn ($n) => str_contains($n->toSms($user), 'The photo of the back is blurred.'));

    kycSubmit($this, $user)->assertCreated();
});

test('staff need users.manage_kyc, and cannot review their own documents', function () {
    $id = kycSubmit($this, kycCustomer())->json('data.submission.id');

    $auditorRole = Role::create(['name' => 'AUDITOR', 'panel' => 'admin', 'is_system' => false]);
    $auditor = User::factory()->create();
    $auditor->roles()->attach($auditorRole->id, ['assigned_at' => now()]);
    Sanctum::actingAs($auditor, ['admin']);
    $this->getJson('/api/v1/users/kyc-submissions')->assertForbidden();
    $this->postJson("/api/v1/users/kyc-submissions/{$id}/approve")->assertForbidden();

    $staffCustomer = kycCustomer();
    $staffCustomer->roles()->attach(Role::where('name', 'ADMIN')->firstOrFail()->id, ['assigned_at' => now()]);
    $ownId = kycSubmit($this, $staffCustomer)->json('data.submission.id');
    Sanctum::actingAs($staffCustomer, ['admin']);
    $this->postJson("/api/v1/users/kyc-submissions/{$ownId}/approve")->assertForbidden();
});

test('document images are served to KYC staff only', function () {
    $user = kycCustomer();
    $id = kycSubmit($this, $user)->json('data.submission.id');

    Sanctum::actingAs(kycStaff(), ['admin']);
    $this->get("/api/v1/users/kyc-submissions/{$id}/documents/front")
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private');

    Sanctum::actingAs($user, ['user']);
    $this->getJson("/api/v1/users/kyc-submissions/{$id}/documents/front")->assertForbidden();
});

test('premium and standard tiers need verified documents; changing tier resets limits and is audited', function () {
    $user = kycCustomer('BASIC');
    $staff = kycStaff();
    Sanctum::actingAs($staff, ['admin']);

    $this->patchJson("/api/v1/users/{$user->id}/kyc-tier", ['tier' => 'PREMIUM', 'reason' => 'High-volume trader'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.tier.0', 'The Premium tier needs verified identity documents first.');

    $user->forceFill(['kyc_verified_at' => now()])->save();
    $this->patchJson("/api/v1/users/{$user->id}/kyc-tier", ['tier' => 'PREMIUM', 'reason' => 'High-volume trader'])
        ->assertOk()
        ->assertJsonPath('data.user.kyc.tier', 'PREMIUM');
    expect(kycLimits($user))->toBe([25000.0, 100000.0]);

    $this->patchJson("/api/v1/users/{$user->id}/kyc-tier", ['tier' => 'BASIC', 'reason' => 'Suspicious activity review'])->assertOk();
    expect(kycLimits($user))->toBe([5000.0, 20000.0]);

    $audit = AuditLog::where('action', 'KYC_TIER_CHANGED')->latest('id')->firstOrFail();
    expect($audit->metadata['from'])->toBe('PREMIUM')
        ->and($audit->metadata['to'])->toBe('BASIC')
        ->and($audit->actor_id)->toBe($staff->id);
});

test('staff can set custom limits within bounds, and a tier change replaces them', function () {
    $user = kycCustomer('STANDARD', attributes: ['kyc_verified_at' => now()]);
    Sanctum::actingAs(kycStaff(), ['admin']);

    $this->patchJson("/api/v1/users/{$user->id}/caps", ['daily_cap' => 60000, 'monthly_cap' => 50000, 'reason' => 'Business account'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('daily_cap');
    $this->patchJson("/api/v1/users/{$user->id}/caps", ['daily_cap' => 200000, 'monthly_cap' => 300000, 'reason' => 'Business account'])
        ->assertUnprocessable();

    $this->patchJson("/api/v1/users/{$user->id}/caps", ['daily_cap' => 30000, 'monthly_cap' => 120000, 'reason' => 'Business account'])
        ->assertOk();
    expect(kycLimits($user))->toBe([30000.0, 120000.0])
        ->and(Cap::where('user_id', $user->id)->value('is_custom'))->toBeTruthy()
        ->and(AuditLog::where('action', 'CAPS_CUSTOMIZED')->exists())->toBeTrue();

    $this->patchJson("/api/v1/users/{$user->id}/kyc-tier", ['tier' => 'STANDARD', 'reason' => 'Back to normal limits'])->assertOk();
    expect(kycLimits($user))->toBe([10000.0, 50000.0])
        ->and(Cap::where('user_id', $user->id)->value('is_custom'))->toBeFalsy();
});

test('staff cannot change their own tier or limits', function () {
    $staff = kycCustomer('BASIC');
    $staff->roles()->attach(Role::where('name', 'ADMIN')->firstOrFail()->id, ['assigned_at' => now()]);
    Sanctum::actingAs($staff, ['admin']);

    $this->patchJson("/api/v1/users/{$staff->id}/caps", ['daily_cap' => 9000, 'monthly_cap' => 9000, 'reason' => 'Just because'])
        ->assertForbidden();
});

test('a customer sees their tier, limits, the tier ladder and their latest submission', function () {
    $user = kycCustomer('BASIC');
    kycSubmit($this, $user)->assertCreated();

    $this->getJson('/api/v1/users/me/kyc')
        ->assertOk()
        ->assertJsonPath('data.tier', 'BASIC')
        ->assertJsonPath('data.label', 'Basic')
        ->assertJsonPath('data.caps.daily_limit', 5000)
        ->assertJsonPath('data.tiers.1.tier', 'STANDARD')
        ->assertJsonPath('data.latest_submission.status', 'PENDING')
        ->assertJsonPath('data.latest_submission.document_number', '*********6789');
});
