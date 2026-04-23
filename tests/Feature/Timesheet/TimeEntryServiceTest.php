<?php

use App\Domain\TimeTracking\TimeEntryService;
use App\Enums\BillingType;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Helper: wire up a project with a task (billable) and user assigned

function setupProjectWithTaskAndUser(
    float $projectRate = 84.0,
    float $hours = 2.0,
): array {
    $user = User::factory()->create(['default_hourly_rate' => 50.0]);
    $project = Project::factory()->create([
        'billing_type' => BillingType::Hourly,
        'default_hourly_rate' => $projectRate,
    ]);
    $task = Task::factory()->create(['is_default_billable' => true]);

    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    return [$user, $project, $task, $hours];
}

test('creates a time entry with correct denormalised billing fields', function () {
    [$user, $project, $task] = setupProjectWithTaskAndUser(projectRate: 84.0, hours: 2.0);

    $service = app(TimeEntryService::class);
    $entry = $service->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => today()->toDateString(),
        'hours' => 2.0,
        'notes' => null,
    ]);

    expect($entry->is_billable)->toBeTrue()
        ->and((float) $entry->billable_rate_snapshot)->toBe(84.0)
        ->and((float) $entry->billable_amount)->toBe(168.0);
});

test('billable_amount is frozen after project rate changes', function () {
    [$user, $project, $task] = setupProjectWithTaskAndUser(projectRate: 84.0);

    $service = app(TimeEntryService::class);
    $entry = $service->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => today()->toDateString(),
        'hours' => 1.0,
        'notes' => null,
    ]);

    // Mutate the project rate after entry is saved
    $project->update(['default_hourly_rate' => 120.0]);

    // The saved entry should be unchanged
    $entry->refresh();
    expect((float) $entry->billable_rate_snapshot)->toBe(84.0)
        ->and((float) $entry->billable_amount)->toBe(84.0);
});

test('update recalculates billing fields at update time', function () {
    [$user, $project, $task] = setupProjectWithTaskAndUser(projectRate: 84.0);

    $service = app(TimeEntryService::class);
    $entry = $service->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => today()->toDateString(),
        'hours' => 1.0,
        'notes' => null,
    ]);

    // Update hours only — billing recalculates using current (84.0) rate
    $service->update($entry, ['hours' => 3.0]);

    $entry->refresh();
    expect((float) $entry->hours)->toBe(3.0)
        ->and((float) $entry->billable_amount)->toBe(252.0);
});

test('delete removes the entry from the database', function () {
    [$user, $project, $task] = setupProjectWithTaskAndUser();

    $service = app(TimeEntryService::class);
    $entry = $service->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => today()->toDateString(),
        'hours' => 1.0,
        'notes' => null,
    ]);

    $id = $entry->id;
    $service->delete($entry);

    expect(TimeEntry::find($id))->toBeNull();
});

test('non-billable project produces zero billing amount', function () {
    $user = User::factory()->create(['default_hourly_rate' => 50.0]);
    $project = Project::factory()->nonBillable()->create();
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => false, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    $entry = app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => today()->toDateString(),
        'hours' => 4.0,
        'notes' => null,
    ]);

    expect($entry->is_billable)->toBeFalse()
        ->and($entry->billable_rate_snapshot)->toBeNull()
        ->and((float) $entry->billable_amount)->toBe(0.0);
});

test('project-user rate override is used in billing calculation', function () {
    $user = User::factory()->create(['default_hourly_rate' => 50.0]);
    $project = Project::factory()->create([
        'billing_type' => BillingType::Hourly,
        'default_hourly_rate' => 84.0,
    ]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => 120.0]);

    $entry = app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => today()->toDateString(),
        'hours' => 1.0,
        'notes' => null,
    ]);

    expect((float) $entry->billable_rate_snapshot)->toBe(120.0)
        ->and((float) $entry->billable_amount)->toBe(120.0);
});
