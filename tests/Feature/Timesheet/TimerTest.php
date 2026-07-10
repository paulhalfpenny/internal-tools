<?php

use App\Domain\TimeTracking\TimeEntryService;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function makeUserWithBillableProject(): array
{
    $user = User::factory()->create(['default_hourly_rate' => 84.0]);
    $project = Project::factory()->create([
        'default_hourly_rate' => 84.0,
    ]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    return [$user, $project, $task];
}

function makeEntry(User $user, Project $project, Task $task, float $hours = 0.0): TimeEntry
{
    return TimeEntry::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => Carbon::today()->toDateString(),
        'hours' => $hours,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 84.0,
        'billable_amount' => round($hours * 84.0, 2),
    ]);
}

function putRunning(TimeEntry $entry, Carbon $startedAt): void
{
    $entry->update(['is_running' => true, 'timer_started_at' => $startedAt]);
}

test('stopTimer accumulates elapsed hours and clears running state', function () {
    [$user, $project, $task] = makeUserWithBillableProject();
    $entry = makeEntry($user, $project, $task, 1.0); // starts with 1h

    // Pretend the timer started 1.5h (5400s) ago
    putRunning($entry, Carbon::now()->subSeconds(5400));

    app(TimeEntryService::class)->stopTimer($entry->fresh());

    $entry->refresh();
    expect($entry->is_running)->toBeFalse()
        ->and($entry->timer_started_at)->toBeNull()
        ->and((float) $entry->hours)->toBeGreaterThan(2.49)
        ->and((float) $entry->hours)->toBeLessThan(2.51); // 1.0 + ~1.5
});

test('stopTimer recalculates billable_amount based on accumulated hours', function () {
    [$user, $project, $task] = makeUserWithBillableProject();
    $entry = makeEntry($user, $project, $task, 0.0);

    // Timer ran for exactly 1 hour (3600s)
    putRunning($entry, Carbon::now()->subSeconds(3600));

    app(TimeEntryService::class)->stopTimer($entry->fresh());

    $entry->refresh();
    $resolvedRate = (float) $entry->billable_rate_snapshot;
    $expectedAmount = round((float) $entry->hours * $resolvedRate, 2);

    expect((float) $entry->hours)->toBeGreaterThan(0.99)
        ->and((float) $entry->hours)->toBeLessThan(1.01)
        ->and($resolvedRate)->toBeGreaterThan(0.0)
        ->and((float) $entry->billable_amount)->toBe($expectedAmount);
});

test('starting a second timer auto-stops the first', function () {
    [$user, $project, $task] = makeUserWithBillableProject();

    $first = makeEntry($user, $project, $task);
    putRunning($first, Carbon::now()->subSeconds(3600)); // running for ~1h

    $second = makeEntry($user, $project, $task);
    app(TimeEntryService::class)->startTimer($second);

    $first->refresh();
    $second->refresh();

    expect($first->is_running)->toBeFalse()
        ->and((float) $first->hours)->toBeGreaterThan(0.99) // ~1h accumulated
        ->and($second->is_running)->toBeTrue();
});

test('stopping a timer does not affect other users timers', function () {
    [$user1, $project, $task] = makeUserWithBillableProject();
    $user2 = User::factory()->create();
    $project->users()->attach($user2->id, ['hourly_rate_override' => null]);

    $entry1 = makeEntry($user1, $project, $task);
    $entry2 = makeEntry($user2, $project, $task);

    app(TimeEntryService::class)->startTimer($entry1);
    app(TimeEntryService::class)->startTimer($entry2);

    app(TimeEntryService::class)->stopTimer($entry1->fresh());

    $entry2->refresh();
    expect($entry2->is_running)->toBeTrue(); // user2's timer unaffected
});
