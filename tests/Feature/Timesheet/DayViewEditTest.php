<?php

use App\Domain\TimeTracking\TimeEntryService;
use App\Enums\Role;
use App\Livewire\Timesheet\DayView;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('opening edit modal renders hours as h:mm not decimal', function () {
    $user = User::factory()->create(['role' => Role::User, 'default_hourly_rate' => 100]);
    $this->actingAs($user);

    $project = Project::factory()->create(['default_hourly_rate' => 100]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    $entry = app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => now()->toDateString(),
        'hours' => 0.25,
        'notes' => null,
    ]);

    Livewire::test(DayView::class)
        ->call('openEditModal', $entry->id)
        ->assertSet('hoursInput', '0:15')
        ->assertSet('selectedProjectId', $project->id)
        ->assertSet('selectedTaskId', $task->id);
});

test('day view lists entries even when spent_on is stored as a full datetime string', function () {
    // Regression: SQLite stores the date column as TEXT so Eloquent's `date`
    // cast writes 'YYYY-MM-DD HH:MM:SS'. Equality matching on the date string
    // would silently miss those rows. whereDate() must keep working.
    $user = User::factory()->create(['role' => Role::User, 'default_hourly_rate' => 100]);
    $this->actingAs($user);

    $project = Project::factory()->create(['default_hourly_rate' => 100]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    $entry = app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => '2026-05-04',
        'hours' => 1.0,
        'notes' => 'should appear in day view',
    ]);

    // Forcefully store the timestamp form to simulate the SQLite-on-DATE behaviour
    \DB::table('time_entries')->where('id', $entry->id)->update(['spent_on' => '2026-05-04 00:00:00']);

    Livewire::test(DayView::class)
        ->set('selectedDate', '2026-05-04')
        ->assertSee('should appear in day view');
});

test('typing 0:15 saves as 0.25 hours and re-opens as 0:15', function () {
    $user = User::factory()->create(['role' => Role::User, 'default_hourly_rate' => 100]);
    $this->actingAs($user);

    $project = Project::factory()->create(['default_hourly_rate' => 100]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    Livewire::test(DayView::class)
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('hoursInput', '0:15')
        ->set('entryDate', now()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    $entry = TimeEntry::firstOrFail();
    expect((float) $entry->hours)->toBe(0.25);

    Livewire::test(DayView::class)
        ->call('openEditModal', $entry->id)
        ->assertSet('hoursInput', '0:15');
});
