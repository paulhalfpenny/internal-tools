<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seedJob(?string $reservedAt): void
{
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => $reservedAt === null ? null : now()->timestamp,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);
}

test('succeeds immediately when nothing is in flight', function () {
    config(['queue.default' => 'database']);

    $this->artisan('queue:await-idle', ['--timeout' => 4])
        ->expectsOutputToContain('Queue is idle.')
        ->assertSuccessful();
});

test('pending jobs do not count as in flight', function () {
    config(['queue.default' => 'database']);

    // Queued but unreserved: workers are paused during maintenance, so these
    // will not start and must not hold the deploy up.
    seedJob(null);
    seedJob(null);

    $this->artisan('queue:await-idle', ['--timeout' => 4])
        ->expectsOutputToContain('Queue is idle.')
        ->assertSuccessful();
});

test('fails when a job stays in flight past the timeout', function () {
    config(['queue.default' => 'database']);

    seedJob('now');

    $this->artisan('queue:await-idle', ['--timeout' => 2, '--poll' => 1])
        ->expectsOutputToContain('still in flight')
        ->assertFailed();
});

test('refuses to run against a non-database queue driver', function () {
    config(['queue.default' => 'redis']);

    // Must fail rather than pass silently: the deploy trusts this to prove
    // nothing is writing before it takes the backup.
    $this->artisan('queue:await-idle')
        ->expectsOutputToContain('only supports the database queue driver')
        ->assertFailed();
});

test('warns when the app is not in maintenance mode', function () {
    config(['queue.default' => 'database']);

    $this->artisan('queue:await-idle', ['--timeout' => 2])
        ->expectsOutputToContain('not in maintenance mode')
        ->assertSuccessful();
});
