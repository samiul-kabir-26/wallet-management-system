<?php

use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->admin = User::factory()->create(['name' => 'Directory Admin']);
    $this->admin->roles()->attach(Role::where('name', 'ADMIN')->firstOrFail()->id, ['assigned_at' => now()]);
    Sanctum::actingAs($this->admin, ['admin']);
});

function createDirectoryUser(string $roleName, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->roles()->attach(Role::where('name', $roleName)->firstOrFail()->id, ['assigned_at' => now()]);

    return $user;
}

/**
 * @return list<int>
 */
function directoryIds(object $test, array $query): array
{
    return collect($test->getJson('/api/v1/users/all-users?'.http_build_query($query))->assertOk()->json('data.items'))
        ->pluck('id')
        ->all();
}

test('search matches name, email and phone number', function () {
    $byName = createDirectoryUser('USER', ['name' => 'Rahima Khatun']);
    $byEmail = createDirectoryUser('USER', ['email' => 'rahima.support@example.com']);
    $byPhone = createDirectoryUser('USER', ['phone_number' => '01811223344']);
    $other = createDirectoryUser('USER', ['name' => 'Unrelated Person']);

    expect(directoryIds($this, ['search' => 'rahima']))->toContain($byName->id, $byEmail->id)->not->toContain($other->id)
        ->and(directoryIds($this, ['search' => '0181122']))->toBe([$byPhone->id]);
});

test('search by #ID returns that user', function () {
    $target = createDirectoryUser('USER');

    expect(directoryIds($this, ['search' => "#{$target->id}"]))->toContain($target->id);
});

test('search never returns deleted users', function () {
    $deleted = createDirectoryUser('USER', ['name' => 'Deleted Rahim']);
    $deleted->forceFill(['is_deleted' => true])->save();
    $active = createDirectoryUser('USER', ['name' => 'Active Rahim']);

    expect(directoryIds($this, ['search' => 'Rahim']))->toBe([$active->id]);
});

test('LIKE wildcards in the search term are matched literally', function () {
    createDirectoryUser('USER', ['name' => 'Plain Name']);

    expect(directoryIds($this, ['search' => '%']))->toBe([])
        ->and(directoryIds($this, ['search' => '_']))->toBe([]);
});

test('panel filter separates staff, agents and customers', function () {
    $agent = createDirectoryUser('AGENT');
    $customer = createDirectoryUser('USER');

    $staffIds = directoryIds($this, ['panel' => 'admin']);
    expect($staffIds)->toContain($this->admin->id)->not->toContain($agent->id, $customer->id)
        ->and(directoryIds($this, ['panel' => 'agent']))->toBe([$agent->id])
        ->and(directoryIds($this, ['panel' => 'user']))->toBe([$customer->id]);
});

test('search combines with the other filters', function () {
    $verified = createDirectoryUser('USER', ['name' => 'Karim Verified', 'is_verified' => true]);
    createDirectoryUser('USER', ['name' => 'Karim Unverified', 'is_verified' => false]);
    createDirectoryUser('AGENT', ['name' => 'Karim Agent', 'is_verified' => true]);

    expect(directoryIds($this, ['search' => 'Karim', 'panel' => 'user', 'is_verified' => 1]))->toBe([$verified->id]);
});

test('invalid search and panel values are rejected', function (array $query, string $field) {
    $this->getJson('/api/v1/users/all-users?'.http_build_query($query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unknown panel' => [['panel' => 'superuser'], 'panel'],
    'search too long' => [['search' => str_repeat('a', 101)], 'search'],
]);
