<?php

use App\Jobs\Asana\PullAsanaProjectsJob;
use App\Jobs\Asana\PullAsanaTasksJob;
use App\Models\AsanaProject;
use App\Models\AsanaSyncLog;
use App\Models\AsanaTask;
use App\Models\Project;
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

test('pulling projects keeps stale rows that are still linked to an internal project', function () {
    $user = asanaTestConnectedUser();
    AsanaProject::create(['gid' => 'p-linked', 'workspace_gid' => 'WS1', 'name' => 'Linked', 'is_archived' => false]);
    AsanaProject::create(['gid' => 'p-orphan', 'workspace_gid' => 'WS1', 'name' => 'Orphan', 'is_archived' => false]);

    $project = Project::factory()->create();
    $project->asanaProjects()->attach('p-linked', ['asana_custom_field_gid' => null]);

    Http::fake([
        'app.asana.com/api/1.0/projects*' => Http::response([
            'data' => [
                ['gid' => 'p1', 'name' => 'Active', 'archived' => false],
            ],
            'next_page' => null,
        ]),
    ]);

    (new PullAsanaProjectsJob('WS1', $user->id))->handle(app(AsanaService::class));

    // The linked board is no longer returned by Asana but must survive the prune,
    // because project_asana_links.asana_project_gid is ON DELETE RESTRICT.
    expect(AsanaProject::find('p-linked'))->not->toBeNull();
    // An unlinked stale row is still pruned as before.
    expect(AsanaProject::find('p-orphan'))->toBeNull();
    expect(AsanaProject::find('p1')->name)->toBe('Active');
});

test('pulling projects merges visibility across fallback actors', function () {
    $firstUser = User::factory()->create([
        'asana_access_token' => 'tok-first',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'first',
        'asana_workspace_gid' => 'WS1',
    ]);
    $secondUser = User::factory()->create([
        'asana_access_token' => 'tok-second',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'second',
        'asana_workspace_gid' => 'WS1',
    ]);
    AsanaProject::create(['gid' => 'p-old', 'workspace_gid' => 'WS1', 'name' => 'Old', 'is_archived' => false]);

    Http::fake(function ($request) {
        $token = $request->header('Authorization')[0] ?? '';

        if ($token === 'Bearer tok-first') {
            return Http::response([
                'data' => [
                    ['gid' => 'p1', 'name' => 'First visible project', 'archived' => false],
                ],
                'next_page' => null,
            ]);
        }

        return Http::response([
            'data' => [
                ['gid' => 'p2', 'name' => 'Second visible project', 'archived' => false],
            ],
            'next_page' => null,
        ]);
    });

    (new PullAsanaProjectsJob('WS1', $firstUser->id, [$secondUser->id]))->handle(app(AsanaService::class));

    expect(AsanaProject::find('p1')->name)->toBe('First visible project');
    expect(AsanaProject::find('p2')->name)->toBe('Second visible project');
    expect(AsanaProject::find('p-old'))->toBeNull();
    expect(AsanaSyncLog::where('event', 'asana.pull_projects.completed')->first()?->context['user_ids'])
        ->toBe([$firstUser->id, $secondUser->id]);
});

test('pulling projects does not prune when actor visibility is partial', function () {
    $firstUser = User::factory()->create([
        'asana_access_token' => 'tok-first',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'first',
        'asana_workspace_gid' => 'WS1',
    ]);
    $deniedUser = User::factory()->create([
        'asana_access_token' => 'tok-denied',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'denied',
        'asana_workspace_gid' => 'WS1',
    ]);
    AsanaProject::create(['gid' => 'p-old', 'workspace_gid' => 'WS1', 'name' => 'Old', 'is_archived' => false]);

    Http::fake(function ($request) {
        $token = $request->header('Authorization')[0] ?? '';

        if ($token === 'Bearer tok-first') {
            return Http::response([
                'data' => [
                    ['gid' => 'p1', 'name' => 'Visible project', 'archived' => false],
                ],
                'next_page' => null,
            ]);
        }

        return Http::response([
            'errors' => [
                ['message' => 'You do not have access to this workspace.'],
            ],
        ], 403);
    });

    (new PullAsanaProjectsJob('WS1', $firstUser->id, [$deniedUser->id]))->handle(app(AsanaService::class));

    expect(AsanaProject::find('p1')->name)->toBe('Visible project');
    expect(AsanaProject::find('p-old'))->not->toBeNull();
    expect(AsanaSyncLog::where('event', 'asana.pull_projects.partial_visibility')->exists())->toBeTrue();
    expect(AsanaSyncLog::where('event', 'asana.pull_projects.failed')->exists())->toBeFalse();
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

test('pulling tasks caches searchable Asana custom field values', function () {
    $user = asanaTestConnectedUser();

    Http::fake([
        'app.asana.com/api/1.0/projects/P1/tasks*' => Http::response([
            'data' => [
                [
                    'gid' => 't1',
                    'name' => 'Build booking journey',
                    'completed' => false,
                    'parent' => null,
                    'custom_fields' => [
                        ['name' => 'Ticket ID', 'display_value' => 'JDW-12345'],
                    ],
                ],
            ],
            'next_page' => null,
        ]),
    ]);

    (new PullAsanaTasksJob('P1', $user->id))->handle(app(AsanaService::class));

    expect(AsanaTask::find('t1')->search_text)->toBe('Ticket ID JDW-12345');
});

test('pulling tasks falls back when the first actor cannot access the Asana project', function () {
    $deniedUser = User::factory()->create([
        'asana_access_token' => 'tok-denied',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'denied',
        'asana_workspace_gid' => 'WS1',
    ]);
    $allowedUser = User::factory()->create([
        'asana_access_token' => 'tok-allowed',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'allowed',
        'asana_workspace_gid' => 'WS1',
    ]);

    Http::fake(function ($request) {
        $token = $request->header('Authorization')[0] ?? '';

        if ($token === 'Bearer tok-denied') {
            return Http::response([
                'errors' => [
                    ['message' => 'You do not have access to this project.'],
                ],
            ], 403);
        }

        return Http::response([
            'data' => [
                ['gid' => 't1', 'name' => 'Visible task', 'completed' => false, 'parent' => null],
            ],
            'next_page' => null,
        ]);
    });

    $thrown = null;

    try {
        (new PullAsanaTasksJob('P1', $deniedUser->id, [$allowedUser->id]))->handle(app(AsanaService::class));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull();
    expect(AsanaTask::find('t1')->name)->toBe('Visible task');
    expect(AsanaSyncLog::where('event', 'asana.pull_tasks.failed')->exists())->toBeFalse();
    expect(AsanaSyncLog::where('event', 'asana.pull_tasks.completed')->first()?->context['user_id'])->toBe($allowedUser->id);
});

test('jobs no-op for users not connected', function () {
    $user = User::factory()->create(); // not connected

    Http::preventStrayRequests();

    (new PullAsanaProjectsJob('WS1', $user->id))->handle(app(AsanaService::class));
    (new PullAsanaTasksJob('P1', $user->id))->handle(app(AsanaService::class));

    expect(true)->toBeTrue();
});
