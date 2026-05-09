<?php

use App\Domain\TimeTracking\TimeEntryService;
use App\Enums\Role;
use App\Livewire\Timesheet\WeekView;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function weekViewSetup(): array
{
    $user = User::factory()->create(['role' => Role::User, 'default_hourly_rate' => 100]);
    $project = Project::factory()->create();
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    return [$user, $project, $task];
}

test('week view groups existing entries into rows by (project, task)', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    // Same project+task on Mon and Wed (same week)
    $monday = now()->startOfWeek()->toDateString();
    $wednesday = now()->startOfWeek()->addDays(2)->toDateString();
    app(TimeEntryService::class)->create($user, ['project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => $monday, 'hours' => 1.0, 'notes' => null]);
    app(TimeEntryService::class)->create($user, ['project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => $wednesday, 'hours' => 2.0, 'notes' => null]);

    $rowKey = $project->id.':'.$task->id.':';
    Livewire::test(WeekView::class)
        ->assertSet("cellValues.{$rowKey}.0", '1:00')
        ->assertSet("cellValues.{$rowKey}.2", '2:00')
        ->assertSet("cellValues.{$rowKey}.1", '');
});

test('save creates new entries from filled cells', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    $rowKey = $project->id.':'.$task->id.':';

    Livewire::test(WeekView::class)
        ->set('extraRows', [$rowKey])
        ->set("cellValues.{$rowKey}", ['2:00', '', '3:00', '', '', '', ''])
        ->call('save');

    $monday = now()->startOfWeek()->toDateString();
    $wednesday = now()->startOfWeek()->addDays(2)->toDateString();
    expect(TimeEntry::whereDate('spent_on', $monday)->where('user_id', $user->id)->first()->hours)->toBe('2.00');
    expect(TimeEntry::whereDate('spent_on', $wednesday)->where('user_id', $user->id)->first()->hours)->toBe('3.00');
});

test('save deletes entries when their cell is cleared', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    $monday = now()->startOfWeek()->toDateString();
    app(TimeEntryService::class)->create($user, ['project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => $monday, 'hours' => 1.0, 'notes' => null]);

    expect(TimeEntry::count())->toBe(1);

    $rowKey = $project->id.':'.$task->id.':';
    Livewire::test(WeekView::class)
        ->set("cellValues.{$rowKey}", ['', '', '', '', '', '', ''])
        ->call('save');

    expect(TimeEntry::count())->toBe(0);
});

test('addRow flow appends an empty row to the timesheet', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    Livewire::test(WeekView::class)
        ->call('openAddRowModal')
        ->assertSet('showAddRowModal', true)
        ->set('newRowProjectId', $project->id)
        ->set('newRowTaskId', $task->id)
        ->call('addRow')
        ->assertSet('showAddRowModal', false)
        ->assertSet('extraRows.0', $project->id.':'.$task->id.':');
});

test('removeRow deletes the row plus any of its entries this week', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    $monday = now()->startOfWeek()->toDateString();
    app(TimeEntryService::class)->create($user, ['project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => $monday, 'hours' => 1.0, 'notes' => null]);

    $rowKey = $project->id.':'.$task->id.':';
    Livewire::test(WeekView::class)
        ->call('removeRow', $rowKey);

    expect(TimeEntry::count())->toBe(0);
});

test('manager viewing direct report week is read-only and cannot save', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    [$report, $project, $task] = weekViewSetup();
    $report->update(['reports_to_user_id' => $manager->id]);

    $monday = now()->startOfWeek()->toDateString();
    app(TimeEntryService::class)->create($report, ['project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => $monday, 'hours' => 5.0, 'notes' => null]);

    $rowKey = $project->id.':'.$task->id.':';

    Livewire::actingAs($manager)
        ->test(WeekView::class, ['user' => $report])
        ->assertSet('isReadOnly', true)
        ->set("cellValues.{$rowKey}.0", '99:00')
        ->call('save');

    // Saved entry remains 5.0 — write was blocked
    expect((float) TimeEntry::first()->hours)->toBe(5.00);
});
