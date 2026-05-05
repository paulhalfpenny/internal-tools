<?php

use App\Domain\TimeTracking\TimeEntryService;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// ─── helpers ────────────────────────────────────────────────────────────────

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

/** Put an entry into running state with a known start time, bypassing startTimer. */
function putRunning(TimeEntry $entry, Carbon $startedAt): void
{
    $entry->update(['is_running' => true, 'timer_started_at' => $startedAt]);
}

// ─── startTimer ─────────────────────────────────────────────────────────────

test('startTimer sets is_running and records timer_started_at', function () {
    [$user, $project, $task] = makeUserWithBillableProject();
    $entry = makeEntry($user, $project, $task);

    app(TimeEntryService::class)->startTimer($entry);

    $entry->refresh();
    expect($entry->is_running)->toBeTrue()
        ->and($entry->timer_started_at)->not->toBeNull();
});

test('calling startTimer on an already-running entry is idempotent', function () {
    [$user, $project, $task] = makeUserWithBillableProject();
    $entry = makeEntry($user, $project, $task);

    $startedAt = Carbon::now()->subMinutes(5);
    putRunning($entry, $startedAt);

    // Call startTimer again on the running entry
    app(TimeEntryService::class)->startTimer($entry->fresh());

    $entry->refresh();
    // Still running; timer_started_at should not have been reset forward
    expect($entry->is_running)->toBeTrue()
        ->and($entry->timer_started_at->timestamp)->toBe($startedAt->timestamp);
});

// ─── stopTimer ──────────────────────────────────────────────────────────────

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
    // hours ≈ 1.0, rate = 84.0 → billable_amount ≈ 84.0
    expect((float) $entry->hours)->toBeGreaterThan(0.99)
        ->and((float) $entry->billable_amount)->toBeGreaterThan(83.0);
});

test('stopTimer is a no-op if the entry is not running', function () {
    [$user, $project, $task] = makeUserWithBillableProject();
    $entry = makeEntry($user, $project, $task, 2.0);

    app(TimeEntryService::class)->stopTimer($entry);

    $entry->refresh();
    expect($entry->is_running)->toBeFalse()
        ->and((float) $entry->hours)->toBe(2.0); // unchanged
});

test('timer state persists across simulated page reloads', function () {
    [$user, $project, $task] = makeUserWithBillableProject();
    $entry = makeEntry($user, $project, $task);

    app(TimeEntryService::class)->startTimer($entry);

    // Simulate page reload: re-fetch from DB
    $reloaded = TimeEntry::find($entry->id);

    expect($reloaded?->is_running)->toBeTrue()
        ->and($reloaded?->timer_started_at)->not->toBeNull();
});

// ─── at-most-one-running-timer enforcement ──────────────────────────────────

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

test('both entries have correct hours after auto-stop', function () {
    [$user, $project, $task] = makeUserWithBillableProject();

    $first = makeEntry($user, $project, $task, 0.5); // pre-existing 0.5h
    putRunning($first, Carbon::now()->subSeconds(5400)); // ~1.5h elapsed

    $second = makeEntry($user, $project, $task);
    app(TimeEntryService::class)->startTimer($second);

    $first->refresh();

    expect((float) $first->hours)->toBeGreaterThan(1.99) // 0.5 + ~1.5
        ->and($first->is_running)->toBeFalse();
});

test('at most one running timer per user at any time', function () {
    [$user, $project, $task] = makeUserWithBillableProject();
    $service = app(TimeEntryService::class);

    $a = makeEntry($user, $project, $task);
    $b = makeEntry($user, $project, $task);
    $c = makeEntry($user, $project, $task);

    $service->startTimer($a);
    $service->startTimer($b);
    $service->startTimer($c);

    $running = TimeEntry::where('user_id', $user->id)->where('is_running', true)->count();
    expect($running)->toBe(1);
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
