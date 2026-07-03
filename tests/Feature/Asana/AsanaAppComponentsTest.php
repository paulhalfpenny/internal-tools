<?php

use App\Models\AsanaProject;
use App\Models\AsanaProjectAssociation;
use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const ASANA_APP_SECRET = 'test-asana-client-secret';

beforeEach(function () {
    config(['services.asana.client_secret' => ASANA_APP_SECRET]);
});

// ─── helpers ────────────────────────────────────────────────────────────────

function signedGet(string $path, array $params): TestResponse
{
    $params['expires_at'] = $params['expires_at'] ?? now()->addMinutes(5)->toIso8601String();
    $query = http_build_query($params);

    return test()->get($path.'?'.$query, [
        'x-asana-request-signature' => hash_hmac('sha256', $query, ASANA_APP_SECRET),
    ]);
}

function signedPost(string $path, array $data): TestResponse
{
    $data['expires_at'] = $data['expires_at'] ?? now()->addMinutes(5)->toIso8601String();
    $blob = json_encode($data, JSON_THROW_ON_ERROR);
    $body = json_encode(['data' => $blob], JSON_THROW_ON_ERROR);

    return test()->call('POST', $path, [], [], [], [
        'HTTP_X_ASANA_REQUEST_SIGNATURE' => hash_hmac('sha256', $blob, ASANA_APP_SECRET),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

function asanaAppSetup(): array
{
    $user = User::factory()->create([
        'asana_user_gid' => 'AU1',
        'default_hourly_rate' => 100,
    ]);

    $project = Project::factory()->create(['name' => 'Website Build', 'asana_task_required' => false]);
    $task = Task::factory()->create(['name' => 'Development']);
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    AsanaProject::create(['gid' => 'BOARD1', 'workspace_gid' => 'WS1', 'name' => 'Client Board', 'is_archived' => false]);
    $project->asanaProjects()->attach('BOARD1', ['asana_custom_field_gid' => null]);
    AsanaTask::create(['gid' => 'AT1', 'asana_project_gid' => 'BOARD1', 'name' => 'Fix the checkout flow', 'is_completed' => false]);

    return [$user, $project, $task];
}

// ─── signature middleware ────────────────────────────────────────────────────

test('form endpoint rejects requests without a signature', function () {
    asanaAppSetup();

    $this->get('/asana-app/form?task=AT1&user=AU1&expires_at='.urlencode(now()->addMinutes(5)->toIso8601String()))
        ->assertStatus(401);
});

test('form endpoint rejects requests with a wrong signature', function () {
    asanaAppSetup();
    $query = 'task=AT1&user=AU1&expires_at='.urlencode(now()->addMinutes(5)->toIso8601String());

    $this->get('/asana-app/form?'.$query, [
        'x-asana-request-signature' => hash_hmac('sha256', $query, 'not-the-secret'),
    ])->assertStatus(401);
});

test('form endpoint rejects expired requests even with a valid signature', function () {
    asanaAppSetup();

    signedGet('/asana-app/form', [
        'task' => 'AT1',
        'user' => 'AU1',
        'expires_at' => now()->subMinute()->toIso8601String(),
    ])->assertStatus(401);
});

test('submit endpoint verifies the signature over the data blob', function () {
    asanaAppSetup();
    $blob = json_encode(['task' => 'AT1', 'user' => 'AU1', 'values' => [], 'expires_at' => now()->addMinutes(5)->toIso8601String()]);

    $this->call('POST', '/asana-app/submit', [], [], [], [
        'HTTP_X_ASANA_REQUEST_SIGNATURE' => hash_hmac('sha256', $blob, 'not-the-secret'),
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['data' => $blob]))->assertStatus(401);
});

// ─── form metadata ───────────────────────────────────────────────────────────

test('form preselects the mapped project and prefills notes with the Asana task title', function () {
    [$user, $project, $task] = asanaAppSetup();

    $response = signedGet('/asana-app/form', ['task' => 'AT1', 'user' => 'AU1'])->assertOk();
    $metadata = $response->json('metadata');
    $fields = collect($metadata['fields'])->keyBy('id');

    expect($metadata['title'])->toBe('Log time to Internal Tools')
        ->and($metadata['on_submit_callback'])->toContain('/asana-app/submit')
        ->and($fields['project']['value'])->toBe((string) $project->id)
        ->and(collect($fields['task']['options'])->pluck('label'))->toContain('Development')
        ->and($fields['notes']['value'])->toBe('Fix the checkout flow')
        ->and($fields['date']['value'])->toBe(today()->toDateString())
        ->and($fields['timer']['options'][0]['id'])->toBe('start');
});

test('form shows a connect prompt with no submit button for unlinked Asana users', function () {
    asanaAppSetup();

    $response = signedGet('/asana-app/form', ['task' => 'AT1', 'user' => 'UNKNOWN-GID'])->assertOk();
    $metadata = $response->json('metadata');

    expect($metadata['title'])->toBe('Connect Internal Tools')
        ->and($metadata)->not->toHaveKey('on_submit_callback')
        ->and($metadata['fields'][0]['name'])->toContain('/profile/asana');
});

test('form uses the remembered association when a board maps to multiple projects', function () {
    [$user, $projectA, $task] = asanaAppSetup();

    $projectB = Project::factory()->create(['name' => 'Aardvark Retainer', 'asana_task_required' => false]);
    $projectB->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $projectB->users()->attach($user->id, ['hourly_rate_override' => null]);
    $projectB->asanaProjects()->attach('BOARD1', ['asana_custom_field_gid' => null]);

    // Without an association, the alphabetically-first linked project wins.
    $first = signedGet('/asana-app/form', ['task' => 'AT1', 'user' => 'AU1'])->json('metadata.fields.0.value');
    expect($first)->toBe((string) $projectB->id);

    AsanaProjectAssociation::create([
        'user_id' => $user->id,
        'asana_project_gid' => 'BOARD1',
        'project_id' => $projectA->id,
        'task_id' => $task->id,
        'last_used_at' => now(),
    ]);

    $fields = collect(signedGet('/asana-app/form', ['task' => 'AT1', 'user' => 'AU1'])->json('metadata.fields'))->keyBy('id');
    expect($fields['project']['value'])->toBe((string) $projectA->id)
        ->and($fields['task']['value'])->toBe((string) $task->id);
});

test('on_change rebuilds the task options for the newly selected project', function () {
    [$user, $projectA] = asanaAppSetup();

    $otherTask = Task::factory()->create(['name' => 'Copywriting']);
    $projectB = Project::factory()->create(['name' => 'Second Project', 'asana_task_required' => false]);
    $projectB->tasks()->attach($otherTask->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $projectB->users()->attach($user->id, ['hourly_rate_override' => null]);

    $response = signedPost('/asana-app/form/change', [
        'task' => 'AT1',
        'user' => 'AU1',
        'values' => ['project' => (string) $projectB->id],
    ])->assertOk();

    $fields = collect($response->json('metadata.fields'))->keyBy('id');
    expect($fields['project']['value'])->toBe((string) $projectB->id)
        ->and(collect($fields['task']['options'])->pluck('label')->all())->toBe(['Copywriting']);
});

// ─── submit ──────────────────────────────────────────────────────────────────

test('submit creates a linked time entry and remembers the association', function () {
    [$user, $project, $task] = asanaAppSetup();

    $response = signedPost('/asana-app/submit', [
        'task' => 'AT1',
        'user' => 'AU1',
        'values' => [
            'project' => (string) $project->id,
            'task' => (string) $task->id,
            'hours' => '1:30',
            'date' => '2026-07-01',
            'notes' => 'Fix the checkout flow',
            'timer' => [],
        ],
    ])->assertOk();

    expect($response->json('resource_url'))->toContain('/asana-app/tasks/AT1')
        ->and($response->json('resource_name'))->toContain('Internal Tools');

    $entry = $user->timeEntries()->sole();
    expect((float) $entry->hours)->toBe(1.5)
        ->and($entry->asana_task_gid)->toBe('AT1')
        ->and($entry->spent_on->toDateString())->toBe('2026-07-01')
        ->and($entry->notes)->toBe('Fix the checkout flow')
        ->and((bool) $entry->is_running)->toBeFalse();

    $assoc = AsanaProjectAssociation::sole();
    expect($assoc->project_id)->toBe($project->id)
        ->and($assoc->task_id)->toBe($task->id)
        ->and($assoc->asana_project_gid)->toBe('BOARD1');
});

test('submit rejects unparseable hours with a form error', function () {
    [$user, $project, $task] = asanaAppSetup();

    $response = signedPost('/asana-app/submit', [
        'task' => 'AT1',
        'user' => 'AU1',
        'values' => [
            'project' => (string) $project->id,
            'task' => (string) $task->id,
            'hours' => 'banana',
            'timer' => [],
        ],
    ])->assertOk();

    expect($response->json('error'))->toContain('banana')
        ->and(TimeEntry::count())->toBe(0);
});

test('submit requires hours unless starting a timer', function () {
    [$user, $project, $task] = asanaAppSetup();

    $response = signedPost('/asana-app/submit', [
        'task' => 'AT1',
        'user' => 'AU1',
        'values' => [
            'project' => (string) $project->id,
            'task' => (string) $task->id,
            'hours' => '',
            'timer' => [],
        ],
    ])->assertOk();

    expect($response->json('error'))->toContain('hours')
        ->and(TimeEntry::count())->toBe(0);
});

test('submit rejects an unparseable date with a form error instead of a 500', function () {
    [$user, $project, $task] = asanaAppSetup();

    $response = signedPost('/asana-app/submit', [
        'task' => 'AT1',
        'user' => 'AU1',
        'values' => [
            'project' => (string) $project->id,
            'task' => (string) $task->id,
            'hours' => '1',
            'date' => 'not-a-date',
            'timer' => [],
        ],
    ])->assertOk();

    expect($response->json('error'))->toContain('date')
        ->and(TimeEntry::count())->toBe(0);
});

test('submit stores no asana gid when the chosen project is not linked to the board', function () {
    [$user, , $task] = asanaAppSetup();

    $unlinked = Project::factory()->create(['name' => 'Internal Ops', 'asana_task_required' => false]);
    $unlinked->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $unlinked->users()->attach($user->id, ['hourly_rate_override' => null]);

    signedPost('/asana-app/submit', [
        'task' => 'AT1',
        'user' => 'AU1',
        'values' => [
            'project' => (string) $unlinked->id,
            'task' => (string) $task->id,
            'hours' => '0.5',
            'timer' => [],
        ],
    ])->assertOk();

    expect($user->timeEntries()->sole()->asana_task_gid)->toBeNull();
});

test('submit rejects projects the user is not assigned to', function () {
    [$user, , $task] = asanaAppSetup();

    $foreign = Project::factory()->create(['asana_task_required' => false]);
    $foreign->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);

    $response = signedPost('/asana-app/submit', [
        'task' => 'AT1',
        'user' => 'AU1',
        'values' => [
            'project' => (string) $foreign->id,
            'task' => (string) $task->id,
            'hours' => '1',
            'timer' => [],
        ],
    ])->assertOk();

    expect($response->json('error'))->toContain('not assigned')
        ->and(TimeEntry::count())->toBe(0);
});

// ─── timers ──────────────────────────────────────────────────────────────────

test('submit with the timer option starts a running timer', function () {
    [$user, $project, $task] = asanaAppSetup();

    signedPost('/asana-app/submit', [
        'task' => 'AT1',
        'user' => 'AU1',
        'values' => [
            'project' => (string) $project->id,
            'task' => (string) $task->id,
            'hours' => '',
            'timer' => ['start'],
        ],
    ])->assertOk();

    $entry = $user->timeEntries()->sole();
    expect((bool) $entry->is_running)->toBeTrue()
        ->and($entry->asana_task_gid)->toBe('AT1');
});

test('form offers stop and submit stops the running timer', function () {
    [$user, $project, $task] = asanaAppSetup();

    signedPost('/asana-app/submit', [
        'task' => 'AT1',
        'user' => 'AU1',
        'values' => ['project' => (string) $project->id, 'task' => (string) $task->id, 'hours' => '', 'timer' => ['start']],
    ]);

    $fields = collect(signedGet('/asana-app/form', ['task' => 'AT1', 'user' => 'AU1'])->json('metadata.fields'))->keyBy('id');
    expect($fields['timer']['options'][0]['id'])->toBe('stop');

    signedPost('/asana-app/submit', [
        'task' => 'AT1',
        'user' => 'AU1',
        'values' => ['timer' => ['stop']],
    ])->assertOk();

    expect((bool) $user->timeEntries()->sole()->fresh()->is_running)->toBeFalse();
});

// ─── widget ──────────────────────────────────────────────────────────────────

test('widget reports totals, own time, and a running timer pill', function () {
    [$user, $project, $task] = asanaAppSetup();

    $other = User::factory()->create(['default_hourly_rate' => 100]);
    $project->users()->attach($other->id, ['hourly_rate_override' => null]);

    TimeEntry::create(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => '2026-07-01', 'hours' => 1.5, 'is_running' => false, 'is_billable' => true, 'billable_rate_snapshot' => 100, 'billable_amount' => 150, 'asana_task_gid' => 'AT1']);
    TimeEntry::create(['user_id' => $other->id, 'project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => '2026-07-02', 'hours' => 2.0, 'is_running' => true, 'timer_started_at' => now(), 'is_billable' => true, 'billable_rate_snapshot' => 100, 'billable_amount' => 200, 'asana_task_gid' => 'AT1']);

    $response = signedGet('/asana-app/widget', [
        'user' => 'AU1',
        'resource_url' => 'https://internal.filter.agency/asana-app/tasks/AT1',
    ])->assertOk();

    expect($response->json('template'))->toBe('summary_with_details_v0')
        ->and($response->json('metadata.title'))->toBe('Time logged');

    $fields = collect($response->json('metadata.fields'))->keyBy('name');
    expect($fields['Total logged']['text'])->toBe('3.5 hrs')
        ->and($fields['Your time']['text'])->toBe('1.5 hrs')
        ->and($fields['Timer']['text'])->toContain($other->name);
});

test('widget totals refresh for other viewers immediately after a submit', function () {
    [$user, $project, $task] = asanaAppSetup();
    $colleague = User::factory()->create(['asana_user_gid' => 'AU2', 'default_hourly_rate' => 100]);

    // Colleague opens the task first — widget aggregates get cached.
    $before = collect(signedGet('/asana-app/widget', [
        'user' => 'AU2',
        'resource_url' => 'https://internal.filter.agency/asana-app/tasks/AT1',
    ])->json('metadata.fields'))->keyBy('name');
    expect($before['Total logged']['text'])->toBe('0.0 hrs');

    signedPost('/asana-app/submit', [
        'task' => 'AT1',
        'user' => 'AU1',
        'values' => ['project' => (string) $project->id, 'task' => (string) $task->id, 'hours' => '2', 'timer' => []],
    ])->assertOk();

    // Same colleague, straight after: must see the new total, not a 60s-stale cache.
    $after = collect(signedGet('/asana-app/widget', [
        'user' => 'AU2',
        'resource_url' => 'https://internal.filter.agency/asana-app/tasks/AT1',
    ])->json('metadata.fields'))->keyBy('name');
    expect($after['Total logged']['text'])->toBe('2.0 hrs');
});

test('widget still renders totals for viewers without a linked account', function () {
    [$user, $project, $task] = asanaAppSetup();

    TimeEntry::create(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => '2026-07-01', 'hours' => 2.0, 'is_running' => false, 'is_billable' => true, 'billable_rate_snapshot' => 100, 'billable_amount' => 200, 'asana_task_gid' => 'AT1']);

    $fields = collect(signedGet('/asana-app/widget', [
        'user' => 'STRANGER',
        'resource_url' => 'https://internal.filter.agency/asana-app/tasks/AT1',
    ])->assertOk()->json('metadata.fields'))->keyBy('name');

    expect($fields['Total logged']['text'])->toBe('2.0 hrs')
        ->and($fields)->not->toHaveKey('Your time');
});

// ─── human-facing attachment link ────────────────────────────────────────────

test('attachment link redirects a regular user to their timesheet', function () {
    [$user] = asanaAppSetup();
    $this->actingAs($user);

    $this->get('/asana-app/tasks/AT1')->assertRedirect(route('timesheet'));
});
