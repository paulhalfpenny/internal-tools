<?php

use App\Domain\TimeTracking\TimeEntryService;
use App\Enums\Role;
use App\Livewire\Timesheet\DayView;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('day totals render as HH:MM when the user prefers that format', function () {
    $user = User::factory()->create([
        'role' => Role::User,
        'default_hourly_rate' => 100,
        'schedule_preferences' => ['hours_display_format' => 'hhmm'],
    ]);
    $this->actingAs($user);

    $project = Project::factory()->create(['default_hourly_rate' => 100]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => now()->toDateString(),
        'hours' => 1.5,
        'notes' => null,
    ]);

    Livewire::test(DayView::class)
        ->assertSee('1:30');
});

test('day totals render as decimal by default', function () {
    $user = User::factory()->create(['role' => Role::User, 'default_hourly_rate' => 100]);
    $this->actingAs($user);

    $project = Project::factory()->create(['default_hourly_rate' => 100]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => now()->toDateString(),
        'hours' => 1.5,
        'notes' => null,
    ]);

    Livewire::test(DayView::class)
        ->assertSee('1.5')
        ->assertDontSee('1:30');
});

test('opening edit modal prefills hoursInput as HH:MM when the user prefers that format', function () {
    $user = User::factory()->create([
        'role' => Role::User,
        'default_hourly_rate' => 100,
        'schedule_preferences' => ['hours_display_format' => 'hhmm'],
    ]);
    $this->actingAs($user);

    $project = Project::factory()->create(['default_hourly_rate' => 100]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    $entry = app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => now()->toDateString(),
        'hours' => 1.5,
        'notes' => null,
    ]);

    Livewire::test(DayView::class)
        ->call('openEditModal', $entry->id)
        ->assertSet('hoursInput', '1:30');
});

test('typing a decimal value still saves correctly when display preference is HH:MM', function () {
    $user = User::factory()->create([
        'role' => Role::User,
        'default_hourly_rate' => 100,
        'schedule_preferences' => ['hours_display_format' => 'hhmm'],
    ]);
    $this->actingAs($user);

    $project = Project::factory()->create(['default_hourly_rate' => 100]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    Livewire::test(DayView::class)
        ->call('openNewModal')
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('hoursInput', '0.25')
        ->call('save');

    $entry = $user->timeEntries()->sole();
    expect((float) $entry->hours)->toBe(0.25);
});
