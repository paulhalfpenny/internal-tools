<?php

use App\Jobs\Asana\SyncAsanaTaskHoursJob;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function asanaTestObserverEntry(string $gid, Project $project, User $user, Task $task): TimeEntry
{
    return TimeEntry::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => '2026-05-05',
        'hours' => 1.0,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 100,
        'billable_amount' => 100,
        'asana_task_gid' => $gid,
    ]);
}

test('saving entry with asana_task_gid dispatches sync job', function () {
    Bus::fake([SyncAsanaTaskHoursJob::class]);

    $project = Project::factory()->create(['asana_project_gid' => 'P1', 'asana_workspace_gid' => 'WS1']);
    $task = Task::factory()->create();
    $user = User::factory()->create();

    asanaTestObserverEntry('T1', $project, $user, $task);

    Bus::assertDispatched(SyncAsanaTaskHoursJob::class, fn (SyncAsanaTaskHoursJob $j) => $j->asanaTaskGid === 'T1');
});

test('changing asana_task_gid dispatches for both old and new', function () {
    $project = Project::factory()->create(['asana_project_gid' => 'P1', 'asana_workspace_gid' => 'WS1']);
    $task = Task::factory()->create();
    $user = User::factory()->create();

    $entry = asanaTestObserverEntry('T-old', $project, $user, $task);

    Bus::fake([SyncAsanaTaskHoursJob::class]);

    $entry->update(['asana_task_gid' => 'T-new']);

    Bus::assertDispatched(SyncAsanaTaskHoursJob::class, fn ($j) => $j->asanaTaskGid === 'T-old');
    Bus::assertDispatched(SyncAsanaTaskHoursJob::class, fn ($j) => $j->asanaTaskGid === 'T-new');
});

test('deleting entry dispatches sync to recompute total', function () {
    $project = Project::factory()->create(['asana_project_gid' => 'P1', 'asana_workspace_gid' => 'WS1']);
    $task = Task::factory()->create();
    $user = User::factory()->create();
    $entry = asanaTestObserverEntry('Tdel', $project, $user, $task);

    Bus::fake([SyncAsanaTaskHoursJob::class]);

    $entry->delete();

    Bus::assertDispatched(SyncAsanaTaskHoursJob::class, fn ($j) => $j->asanaTaskGid === 'Tdel');
});

test('saving entry with no asana task does not dispatch', function () {
    Bus::fake([SyncAsanaTaskHoursJob::class]);

    $project = Project::factory()->create();
    $task = Task::factory()->create();
    $user = User::factory()->create();

    TimeEntry::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => '2026-05-05',
        'hours' => 1,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 100,
        'billable_amount' => 100,
        'asana_task_gid' => null,
    ]);

    Bus::assertNotDispatched(SyncAsanaTaskHoursJob::class);
});
