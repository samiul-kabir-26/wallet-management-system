<?php

use Illuminate\Support\Facades\Schema;

test('returns a successful response', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
});

test('sessions table exists in the database', function () {
    expect(Schema::hasTable('sessions'))->toBeTrue()
        ->and(Schema::hasColumns('sessions', ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity']))->toBeTrue();
});

test('web login route renders successfully with database session driver', function () {
    config(['session.driver' => 'database']);

    $response = $this->get(route('login'));

    $response->assertOk();
});
