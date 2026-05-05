<?php

use App\Jobs\Asana\PullAsanaProjectsJob;
use App\Jobs\Asana\PullAsanaTasksJob;
use App\Models\AsanaProject;
use App\Models\AsanaTask;
use App\Models\User;
use App\Services\Asana\AsanaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function asanaTestConnectedUser(): User
{
    return User::factory()->create([
        'asana_access_token' => 'tok',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'me',
        'asana_workspace_gid' => 'WS1',
    ]);
}

test('pulling projects upserts and removes stale rows', function () {
    $user = asanaTestConnectedUser();
    AsanaProject::create(['gid' => 'p-old', 'workspace_gid' => 'WS1', 'name' => 'Old', 'is_archived' => false]);

    Http::fake([
        'app.asana.com/api/1.0/projects*' => Http::response([
            'data' => [
                ['gid' => 'p1', 'name' => 'Active', 'archived' => false],
                ['gid' => 'p2', 'name' => 'Other', 'archived' => false],
            ],
            'next_page' => null,
        ]),
    ]);

    (new PullAsanaProjectsJob('WS1', $user->id))->handle(app(AsanaService::class));

    expect(AsanaProject::find('p-old'))->toBeNull();
    expect(AsanaProject::find('p1')->name)->toBe('Active');
    expect(AsanaProject::count())->toBe(2);
});

test('pulling tasks upserts and removes stale rows', function () {
    $user = asanaTestConnectedUser();
    AsanaTask::create(['gid' => 't-old', 'asana_project_gid' => 'P1', 'name' => 'Old', 'is_completed' => false]);

    Http::fake([
        'app.asana.com/api/1.0/projects/P1/tasks*' => Http::response([
            'data' => [
                ['gid' => 't1', 'name' => 'New', 'completed' => false, 'parent' => null],
            ],
            'next_page' => null,
        ]),
    ]);

    (new PullAsanaTasksJob('P1', $user->id))->handle(app(AsanaService::class));

    expect(AsanaTask::find('t-old'))->toBeNull();
    expect(AsanaTask::find('t1')->name)->toBe('New');
});

test('jobs no-op for users not connected', function () {
    $user = User::factory()->create(); // not connected

    Http::preventStrayRequests();

    (new PullAsanaProjectsJob('WS1', $user->id))->handle(app(AsanaService::class));
    (new PullAsanaTasksJob('P1', $user->id))->handle(app(AsanaService::class));

    expect(true)->toBeTrue();
});
