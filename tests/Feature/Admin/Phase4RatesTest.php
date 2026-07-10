<?php

use App\Domain\TimeTracking\TimeEntryService;
use App\Models\Project;
use App\Models\Rate;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('editing a library rate does not change historical billable_rate_snapshot', function () {
    $rate = Rate::create(['name' => 'Std', 'hourly_rate' => 100.00]);
    $user = User::factory()->create(['rate_id' => $rate->id]);
    $project = Project::factory()->create(['is_billable' => true]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    $project = $project->fresh(['tasks', 'users']);

    $entry = app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => now()->toDateString(),
        'hours' => 2.0,
        'notes' => null,
    ]);

    expect((float) $entry->billable_rate_snapshot)->toBe(100.00);
    expect((float) $entry->billable_amount)->toBe(200.00);

    $rate->update(['hourly_rate' => 250.00]);
    $entry->refresh();

    // Snapshot is frozen — rate change does not retroactively re-rate the entry
    expect((float) $entry->billable_rate_snapshot)->toBe(100.00);
    expect((float) $entry->billable_amount)->toBe(200.00);
});
