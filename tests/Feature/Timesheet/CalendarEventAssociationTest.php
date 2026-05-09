<?php

use App\Domain\TimeTracking\CalendarEventAssociationService;
use App\Enums\Role;
use App\Livewire\Timesheet\DayView;
use App\Models\CalendarEventAssociation;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function calendarTestSetup(): array
{
    $user = User::factory()->create([
        'role' => Role::User,
        'default_hourly_rate' => 100,
    ]);

    $project = Project::factory()->create(['default_hourly_rate' => 100]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    return [$user, $project, $task];
}

test('saving an entry pulled from a calendar event remembers the association', function () {
    [$user, $project, $task] = calendarTestSetup();
    $this->actingAs($user);

    Livewire::test(DayView::class)
        ->call('pullFromCalendarEvent', 'Sprint planning', 0.5)
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('entryDate', now()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    $assoc = CalendarEventAssociation::where('user_id', $user->id)
        ->where('event_title', 'Sprint planning')
        ->first();

    expect($assoc)->not->toBeNull()
        ->and($assoc->project_id)->toBe($project->id)
        ->and($assoc->task_id)->toBe($task->id)
        ->and($assoc->last_used_at)->not->toBeNull();
});

test('pulling a calendar event with a known title auto-fills project and task', function () {
    [$user, $project, $task] = calendarTestSetup();
    $this->actingAs($user);

    app(CalendarEventAssociationService::class)
        ->remember($user, 'Recurring standup', $project->id, $task->id);

    Livewire::test(DayView::class)
        ->call('pullFromCalendarEvent', 'Recurring standup', 0.25)
        ->assertSet('selectedProjectId', $project->id)
        ->assertSet('selectedTaskId', $task->id)
        ->assertSet('notes', 'Recurring standup');
});

test('saving an entry without pulling from calendar does not create an association', function () {
    [$user, $project, $task] = calendarTestSetup();
    $this->actingAs($user);

    Livewire::test(DayView::class)
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('hoursInput', '1.0')
        ->set('notes', 'manual entry')
        ->set('entryDate', now()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    expect(CalendarEventAssociation::count())->toBe(0);
});

test('subsequent pulls of the same event title update last_used_at and tracked project/task', function () {
    [$user, $project, $task] = calendarTestSetup();
    $secondTask = Task::factory()->create();
    $project->tasks()->attach($secondTask->id, ['is_billable' => true, 'hourly_rate_override' => null]);

    $service = app(CalendarEventAssociationService::class);
    $service->remember($user, 'Demo prep', $project->id, $task->id);

    $service->remember($user, 'Demo prep', $project->id, $secondTask->id);

    $row = CalendarEventAssociation::where('event_title', 'Demo prep')->sole();
    expect($row->task_id)->toBe($secondTask->id);
});
