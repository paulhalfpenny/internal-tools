<?php

use App\Enums\Role;
use App\Jobs\Asana\SyncAsanaTaskHoursJob;
use App\Models\AsanaProject;
use App\Models\AsanaSyncLog;
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
use Illuminate\Support\Facades\Queue;

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

test('prefers the designated sync actor over admins', function () {
    $project = asanaTestLinkedProject();
    asanaTestConnectedAdmin(); // token "tok"
    $bot = User::factory()->create([
        'role' => Role::User,
        'asana_access_token' => 'bottok',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'bot-gid',
        'asana_workspace_gid' => 'WS1',
    ]);
    User::designateAsanaSyncActor($bot);

    $task = Task::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['app.asana.com/api/1.0/tasks/T1' => Http::response(['data' => []])]);

    asanaTestEnsureCachedTask('T1');
    asanaTestEntry($project, $task, $bot, 'T1', 1.0);

    (new SyncAsanaTaskHoursJob('T1', $project->id))->handle(
        app(AsanaService::class),
        app(AsanaTaskHoursAggregator::class),
    );

    Http::assertSent(fn ($r) => str_contains($r->url(), '/tasks/T1')
        && $r->hasHeader('Authorization', 'Bearer bottok'));

    expect(AsanaSyncLog::query()->where('event', 'asana.sync_hours.actor_fallback')->exists())
        ->toBeFalse();
});

test('falls back to an admin and warns when the designated actor has no token', function () {
    $project = asanaTestLinkedProject();
    asanaTestConnectedAdmin(); // token "tok"
    $bot = User::factory()->create([
        'role' => Role::User,
        'asana_access_token' => null,
        'asana_workspace_gid' => 'WS1',
    ]);
    User::designateAsanaSyncActor($bot);

    $task = Task::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['app.asana.com/api/1.0/tasks/T1' => Http::response(['data' => []])]);

    asanaTestEnsureCachedTask('T1');
    asanaTestEntry($project, $task, $bot, 'T1', 1.0);

    (new SyncAsanaTaskHoursJob('T1', $project->id))->handle(
        app(AsanaService::class),
        app(AsanaTaskHoursAggregator::class),
    );

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok'));

    $log = AsanaSyncLog::query()->where('event', 'asana.sync_hours.actor_fallback')->first();
    expect($log)->not->toBeNull();
    expect($log->context['reason'])->toBe('actor_no_token');
});

test('falls back and warns when the designated actor is in another workspace', function () {
    $project = asanaTestLinkedProject();
    asanaTestConnectedAdmin(); // token "tok", workspace WS1
    $bot = User::factory()->create([
        'role' => Role::User,
        'asana_access_token' => 'bottok',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'bot-gid',
        'asana_workspace_gid' => 'WS-OTHER',
    ]);
    User::designateAsanaSyncActor($bot);

    $task = Task::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['app.asana.com/api/1.0/tasks/T1' => Http::response(['data' => []])]);

    asanaTestEnsureCachedTask('T1');
    asanaTestEntry($project, $task, $bot, 'T1', 1.0);

    (new SyncAsanaTaskHoursJob('T1', $project->id))->handle(
        app(AsanaService::class),
        app(AsanaTaskHoursAggregator::class),
    );

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok'));

    $log = AsanaSyncLog::query()->where('event', 'asana.sync_hours.actor_fallback')->first();
    expect($log?->context['reason'])->toBe('actor_workspace_mismatch');
});

test('does not warn on fallback when no sync actor is designated', function () {
    $project = asanaTestLinkedProject();
    $admin = asanaTestConnectedAdmin(); // token "tok"
    $task = Task::factory()->create();

    Http::preventStrayRequests();
    Http::fake(['app.asana.com/api/1.0/tasks/T1' => Http::response(['data' => []])]);

    asanaTestEnsureCachedTask('T1');
    asanaTestEntry($project, $task, $admin, 'T1', 1.0);

    (new SyncAsanaTaskHoursJob('T1', $project->id))->handle(
        app(AsanaService::class),
        app(AsanaTaskHoursAggregator::class),
    );

    expect(AsanaSyncLog::query()->where('event', 'asana.sync_hours.actor_fallback')->exists())
        ->toBeFalse();
});

test('handles permission denied while setting hours without retrying', function () {
    Queue::fake();
    $project = asanaTestLinkedProject();
    $actor = asanaTestConnectedAdmin();
    User::designateAsanaSyncActor($actor);
    $task = Task::factory()->create();

    asanaTestEnsureCachedTask('T403');

    $requests = 0;
    Http::fake(function ($request) use (&$requests) {
        $requests++;

        return Http::response([
            'errors' => [['message' => 'Only project admins can edit this field.']],
        ], 403);
    });
    $entry = asanaTestEntry($project, $task, $actor, 'T403', 1.0);

    $thrown = null;
    try {
        (new SyncAsanaTaskHoursJob('T403', $project->id))->handle(
            app(AsanaService::class),
            app(AsanaTaskHoursAggregator::class),
        );
    } catch (Throwable $e) {
        $thrown = $e;
    }

    $log = AsanaSyncLog::query()->where('event', 'asana.sync_hours.permission_denied')->first();

    expect($thrown)->toBeNull()
        ->and($requests)->toBe(1)
        ->and($entry->fresh()->asana_sync_error)
        ->toContain('Grant it project-admin and custom-field edit access')
        ->and($log)->not->toBeNull()
        ->and($log->context['stage'])->toBe('set_hours')
        ->and($log->context['asana_task_gid'])->toBe('T403')
        ->and($log->context['asana_task_name'])->toBe('Task T403')
        ->and($log->context['board_gid'])->toBe('P1')
        ->and($log->context['board_name'])->toBe('Asana P1')
        ->and($log->context['custom_field_gid'])->toBe('F1')
        ->and($log->context['project_id'])->toBe($project->id)
        ->and($log->context['actor_user_id'])->toBe($actor->id)
        ->and($log->context['actor_asana_user_gid'])->toBe('admin-gid');
});

test('handles permission denied while ensuring field without retrying', function () {
    Queue::fake();
    $project = asanaTestLinkedProject(customFieldGid: null);
    $actor = asanaTestConnectedAdmin();
    User::designateAsanaSyncActor($actor);
    $task = Task::factory()->create();

    asanaTestEnsureCachedTask('T403');

    $requests = 0;
    Http::fake(function ($request) use (&$requests) {
        $requests++;

        return Http::response([
            'errors' => [['message' => 'Only project admins can manage custom fields.']],
        ], 403);
    });
    $entry = asanaTestEntry($project, $task, $actor, 'T403', 1.0);

    $thrown = null;
    try {
        (new SyncAsanaTaskHoursJob('T403', $project->id))->handle(
            app(AsanaService::class),
            app(AsanaTaskHoursAggregator::class),
        );
    } catch (Throwable $e) {
        $thrown = $e;
    }

    $log = AsanaSyncLog::query()->where('event', 'asana.sync_hours.permission_denied')->first();

    expect($thrown)->toBeNull()
        ->and($requests)->toBe(1)
        ->and($entry->fresh()->asana_sync_error)
        ->toContain('Grant it project-admin and custom-field edit access')
        ->and($log)->not->toBeNull()
        ->and($log->context['stage'])->toBe('ensure_field')
        ->and($log->context['board_gid'])->toBe('P1')
        ->and($log->context['custom_field_gid'])->toBeNull()
        ->and($log->context['actor_user_id'])->toBe($actor->id);
});

test('releases rate limited jobs without an immediate HTTP retry', function () {
    Queue::fake();
    $project = asanaTestLinkedProject();
    $actor = asanaTestConnectedAdmin();
    $task = Task::factory()->create();

    asanaTestEnsureCachedTask('T429');

    $requests = 0;
    Http::fake(function () use (&$requests) {
        $requests++;

        return Http::response(
            ['errors' => [['message' => 'Rate limited']]],
            429,
            ['Retry-After' => '45'],
        );
    });
    asanaTestEntry($project, $task, $actor, 'T429', 1.0);

    $job = (new SyncAsanaTaskHoursJob('T429', $project->id))->withFakeQueueInteractions();
    $job->handle(app(AsanaService::class), app(AsanaTaskHoursAggregator::class));

    $job->assertReleased(45);
    expect($requests)->toBe(1);
});
