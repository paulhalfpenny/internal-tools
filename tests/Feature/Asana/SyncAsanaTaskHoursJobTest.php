<?php

use App\Enums\Role;
use App\Jobs\Asana\SyncAsanaTaskHoursJob;
use App\Models\AsanaProject;
use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Asana\AsanaService;
use App\Services\Asana\AsanaTaskHoursAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.asana.client_id' => 'c',
        'services.asana.client_secret' => 's',
        'services.asana.redirect' => 'http://localhost/cb',
        'services.asana.custom_field_name' => 'Hours tracked (Internal Tools)',
    ]);
});

function asanaTestLinkedProject(?string $customFieldGid = 'F1', string $boardGid = 'P1', string $workspaceGid = 'WS1'): Project
{
    $project = Project::factory()->create();
    AsanaProject::firstOrCreate(
        ['gid' => $boardGid],
        ['workspace_gid' => $workspaceGid, 'name' => 'Asana '.$boardGid, 'is_archived' => false],
    );
    $project->asanaProjects()->attach($boardGid, ['asana_custom_field_gid' => $customFieldGid]);

    return $project;
}

function asanaTestEnsureCachedTask(string $gid, string $boardGid = 'P1'): void
{
    AsanaTask::firstOrCreate(
        ['gid' => $gid],
        ['asana_project_gid' => $boardGid, 'name' => 'Task '.$gid, 'is_completed' => false],
    );
}

function asanaTestConnectedAdmin(): User
{
    return User::factory()->create([
        'role' => Role::Admin,
        'asana_access_token' => 'tok',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'admin-gid',
        'asana_workspace_gid' => 'WS1',
    ]);
}

function asanaTestEntry(Project $p, Task $t, User $u, string $gid, float $hours): TimeEntry
{
    return TimeEntry::create([
        'user_id' => $u->id,
        'project_id' => $p->id,
        'task_id' => $t->id,
        'spent_on' => '2026-05-05',
        'hours' => $hours,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 100,
        'billable_amount' => 100 * $hours,
        'asana_task_gid' => $gid,
    ]);
}

test('pushes summed hours to asana custom field', function () {
    $project = asanaTestLinkedProject();
    $admin = asanaTestConnectedAdmin();
    $task = Task::factory()->create();
    $regular = User::factory()->create();

    Http::preventStrayRequests();
    Http::fake([
        'app.asana.com/api/1.0/tasks/T1' => Http::response(['data' => []]),
    ]);

    asanaTestEnsureCachedTask('T1');
    asanaTestEntry($project, $task, $admin, 'T1', 1.5);
    asanaTestEntry($project, $task, $regular, 'T1', 2.5);

    // Observer would dispatch — we run the job directly to assert behaviour
    (new SyncAsanaTaskHoursJob('T1', $project->id))->handle(
        app(AsanaService::class),
        app(AsanaTaskHoursAggregator::class),
    );

    Http::assertSent(function ($r) {
        return $r->method() === 'PUT'
            && str_contains($r->url(), '/tasks/T1')
            && $r['data']['custom_fields']['F1'] === 4.0;
    });

    expect(TimeEntry::query()->whereNotNull('asana_synced_at')->count())->toBe(2);
});

test('marks entries with error when task is 404 in asana', function () {
    $project = asanaTestLinkedProject();
    asanaTestConnectedAdmin();
    $task = Task::factory()->create();
    $regular = User::factory()->create();

    Http::fake([
        'app.asana.com/api/1.0/tasks/Tgone' => Http::response(['errors' => [['message' => 'not found']]], 404),
    ]);

    asanaTestEnsureCachedTask('Tgone');
    $entry = asanaTestEntry($project, $task, $regular, 'Tgone', 1.0);

    (new SyncAsanaTaskHoursJob('Tgone', $project->id))->handle(
        app(AsanaService::class),
        app(AsanaTaskHoursAggregator::class),
    );

    expect($entry->fresh()->asana_sync_error)->toContain('not found');
});

test('skips silently when no connected actor available', function () {
    $project = asanaTestLinkedProject();
    $task = Task::factory()->create();
    $regular = User::factory()->create();

    Http::preventStrayRequests();
    asanaTestEnsureCachedTask('T1');
    asanaTestEntry($project, $task, $regular, 'T1', 1.0);

    (new SyncAsanaTaskHoursJob('T1', $project->id))->handle(
        app(AsanaService::class),
        app(AsanaTaskHoursAggregator::class),
    );

    expect(true)->toBeTrue(); // no exception, no requests
});

test('ensures custom field on first run when missing on project', function () {
    $project = asanaTestLinkedProject(customFieldGid: null);
    asanaTestConnectedAdmin();
    $task = Task::factory()->create();
    $u = User::factory()->create();

    Http::fake([
        'app.asana.com/api/1.0/projects/P1/custom_field_settings*' => Http::response(['data' => []]),
        'app.asana.com/api/1.0/workspaces/WS1/custom_fields*' => Http::response(['data' => []]),
        'app.asana.com/api/1.0/custom_fields' => Http::response(['data' => ['gid' => 'NEW']]),
        'app.asana.com/api/1.0/projects/P1/addCustomFieldSetting' => Http::response(['data' => ['gid' => 'S1']]),
        'app.asana.com/api/1.0/tasks/T1' => Http::response(['data' => []]),
    ]);

    asanaTestEnsureCachedTask('T1');
    asanaTestEntry($project, $task, $u, 'T1', 0.5);

    (new SyncAsanaTaskHoursJob('T1', $project->id))->handle(
        app(AsanaService::class),
        app(AsanaTaskHoursAggregator::class),
    );

    $pivot = DB::table('project_asana_links')
        ->where('project_id', $project->id)
        ->where('asana_project_gid', 'P1')
        ->first();
    expect($pivot->asana_custom_field_gid)->toBe('NEW');
});

test('routes hours to the correct board when project links to multiple Asana boards', function () {
    $project = asanaTestLinkedProject(customFieldGid: 'F1', boardGid: 'P1', workspaceGid: 'WS1');
    // Link a second board with its own custom field id
    AsanaProject::create(['gid' => 'P2', 'workspace_gid' => 'WS1', 'name' => 'Asana P2', 'is_archived' => false]);
    $project->asanaProjects()->attach('P2', ['asana_custom_field_gid' => 'F2']);
    asanaTestConnectedAdmin();

    $task = Task::factory()->create();
    $regular = User::factory()->create();

    // Two asana tasks, each living on a different board
    asanaTestEnsureCachedTask('A1', 'P1');
    asanaTestEnsureCachedTask('B1', 'P2');

    Http::preventStrayRequests();
    Http::fake([
        'app.asana.com/api/1.0/tasks/A1' => Http::response(['data' => []]),
        'app.asana.com/api/1.0/tasks/B1' => Http::response(['data' => []]),
    ]);

    asanaTestEntry($project, $task, $regular, 'A1', 1.0);
    asanaTestEntry($project, $task, $regular, 'B1', 2.0);

    (new SyncAsanaTaskHoursJob('A1', $project->id))->handle(
        app(AsanaService::class),
        app(AsanaTaskHoursAggregator::class),
    );
    (new SyncAsanaTaskHoursJob('B1', $project->id))->handle(
        app(AsanaService::class),
        app(AsanaTaskHoursAggregator::class),
    );

    Http::assertSent(fn ($r) => str_contains($r->url(), '/tasks/A1') && $r['data']['custom_fields']['F1'] === 1.0);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/tasks/B1') && $r['data']['custom_fields']['F2'] === 2.0);
});

test('soft-fails when the asana task belongs to a board that is no longer linked', function () {
    $project = asanaTestLinkedProject(boardGid: 'P1');
    asanaTestConnectedAdmin();
    $task = Task::factory()->create();
    $regular = User::factory()->create();

    // Task lives on a board that this project is NOT linked to.
    AsanaProject::create(['gid' => 'P-orphan', 'workspace_gid' => 'WS1', 'name' => 'Orphan', 'is_archived' => false]);
    asanaTestEnsureCachedTask('Tx', 'P-orphan');

    $entry = asanaTestEntry($project, $task, $regular, 'Tx', 1.0);

    Http::preventStrayRequests();

    (new SyncAsanaTaskHoursJob('Tx', $project->id))->handle(
        app(AsanaService::class),
        app(AsanaTaskHoursAggregator::class),
    );

    expect($entry->fresh()->asana_sync_error)->toContain('no longer linked');
});
