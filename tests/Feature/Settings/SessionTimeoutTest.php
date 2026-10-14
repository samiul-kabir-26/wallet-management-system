<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function createSessionTimeoutActor(string $roleName): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', $roleName)->firstOrFail()->id, ['assigned_at' => now()]);

    return $user;
}

test('each panel defaults to a five minute idle timeout', function () {
    Sanctum::actingAs(createSessionTimeoutActor('ADMIN'), ['admin']);

    $this->getJson('/api/v1/system-settings/session-timeouts')
        ->assertOk()
        ->assertJsonPath('data.session_timeouts', ['admin' => 5, 'agent' => 5, 'user' => 5]);
});

test('admin changes the timeout per panel and the change is audited', function () {
    $admin = createSessionTimeoutActor('ADMIN');
    Sanctum::actingAs($admin, ['admin']);

    $this->patchJson('/api/v1/system-settings/session-timeouts', ['admin' => 15, 'user' => 3])
        ->assertOk()
        ->assertJsonPath('data.session_timeouts', ['admin' => 15, 'agent' => 5, 'user' => 3]);

    $audit = AuditLog::where('action', 'SESSION_TIMEOUTS_UPDATED')->sole();
    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->metadata['before']['admin'])->toBe(5)
        ->and($audit->metadata['after']['admin'])->toBe(15);
});

test('changing timeouts needs settings.manage_session_timeouts and valid bounds', function () {
    $auditor = createSessionTimeoutActor('USER');
    $auditor->roles()->attach(Role::create(['name' => 'AUDITOR', 'panel' => 'admin', 'is_system' => false])->id, ['assigned_at' => now()]);

    Sanctum::actingAs($auditor, ['admin']);
    $this->patchJson('/api/v1/system-settings/session-timeouts', ['user' => 10])->assertForbidden();

    Sanctum::actingAs(createSessionTimeoutActor('ADMIN'), ['admin']);
    $this->patchJson('/api/v1/system-settings/session-timeouts', ['user' => 0])->assertJsonValidationErrors('user');
    $this->patchJson('/api/v1/system-settings/session-timeouts', ['user' => 121])->assertJsonValidationErrors('user');
    $this->patchJson('/api/v1/system-settings/session-timeouts', [])->assertJsonValidationErrors('session_timeouts');
});

test('the server rejects a token idle longer than its panel timeout', function () {
    $admin = createSessionTimeoutActor('ADMIN');
    Sanctum::actingAs($admin, ['admin']);
    $this->patchJson('/api/v1/system-settings/session-timeouts', ['user' => 3, 'agent' => 30])->assertOk();
    Auth::forgetGuards();

    $customer = createSessionTimeoutActor('USER');
    $userToken = $customer->createToken('user-login', ['user'], now()->addHours(12))->plainTextToken;
    $agentToken = $customer->createToken('agent-login', ['agent'], now()->addHours(12))->plainTextToken;

    $this->travel(4)->minutes();
    Auth::forgetGuards();

    $this->withToken($userToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    Auth::forgetGuards();
    $this->withToken($agentToken)->getJson('/api/v1/auth/me')->assertOk();
});

test('login responses tell the client its panel idle timeout', function () {
    $customer = createSessionTimeoutActor('USER');

    $this->postJson('/api/v1/auth/user/login', [
        'phone_number' => $customer->phone_number,
        'pin' => '12345',
    ])->assertOk()->assertJsonPath('data.idle_timeout_minutes', 5);
});
