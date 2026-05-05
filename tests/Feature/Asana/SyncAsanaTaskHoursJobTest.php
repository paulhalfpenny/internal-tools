<?php

use App\Enums\Role;
use App\Jobs\Asana\SyncAsanaTaskHoursJob;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Asana\AsanaService;
use App\Services\Asana\AsanaTaskHoursAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

function asanaTestLinkedProject(?string $customFieldGid = 'F1'): Project
{
    return Project::factory()->create([
        'asana_project_gid' => 'P1',
        'asana_workspace_gid' => 'WS1',
        'asana_custom_field_gid' => $customFieldGid,
    ]);
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

    asanaTestEntry($project, $task, $u, 'T1', 0.5);

    (new SyncAsanaTaskHoursJob('T1', $project->id))->handle(
        app(AsanaService::class),
        app(AsanaTaskHoursAggregator::class),
    );

    expect($project->fresh()->asana_custom_field_gid)->toBe('NEW');
});
