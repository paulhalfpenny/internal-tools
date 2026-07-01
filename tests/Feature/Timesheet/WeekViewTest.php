<?php

use App\Domain\TimeTracking\TimeEntryService;
use App\Enums\Role;
use App\Jobs\Asana\PullAsanaTasksJob;
use App\Livewire\Timesheet\WeekView;
use App\Models\AsanaProject;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
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

function weekViewRowKey(Project $project, Task $task, ?string $asanaGid = null): string
{
    return 'p'.$project->id.'_t'.$task->id.'_a'.($asanaGid ?? 'none');
}

test('week view groups existing entries into rows by (project, task)', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    // Same project+task on Mon and Wed (same week)
    $monday = now()->startOfWeek()->toDateString();
    $wednesday = now()->startOfWeek()->addDays(2)->toDateString();
    app(TimeEntryService::class)->create($user, ['project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => $monday, 'hours' => 1.0, 'notes' => null]);
    app(TimeEntryService::class)->create($user, ['project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => $wednesday, 'hours' => 2.0, 'notes' => null]);

    $rowKey = weekViewRowKey($project, $task);
    Livewire::test(WeekView::class)
        ->assertSet("cellValues.{$rowKey}.0", '1.0')
        ->assertSet("cellValues.{$rowKey}.2", '2.0')
        ->assertSet("cellValues.{$rowKey}.1", '');
});

test('week view sums same-day entries that only differ by notes', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    $monday = now()->startOfWeek()->toDateString();
    app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $monday,
        'hours' => 1.0,
        'notes' => 'First note',
    ]);
    app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $monday,
        'hours' => 0.5,
        'notes' => 'Second note',
    ]);

    $rowKey = weekViewRowKey($project, $task);
    $component = Livewire::test(WeekView::class)
        ->assertSet("cellValues.{$rowKey}.0", '1.5');

    expect($component->viewData('dayTotals')[0])->toBe(1.5)
        ->and($component->viewData('weekTotal'))->toBe(1.5);
});

test('saving an unchanged summed week cell keeps the existing total', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    $monday = now()->startOfWeek()->toDateString();
    app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $monday,
        'hours' => 1.0,
        'notes' => 'First note',
    ]);
    app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $monday,
        'hours' => 0.5,
        'notes' => 'Second note',
    ]);

    $rowKey = weekViewRowKey($project, $task);

    Livewire::test(WeekView::class)
        ->assertSet("cellValues.{$rowKey}.0", '1.5')
        ->call('save');

    expect((float) TimeEntry::whereDate('spent_on', $monday)->sum('hours'))->toBe(1.5);
});

test('week view totals include elapsed time for a running timer', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00'));

    try {
        [$user, $project, $task] = weekViewSetup();
        $this->actingAs($user);

        $entry = app(TimeEntryService::class)->create($user, [
            'project_id' => $project->id,
            'task_id' => $task->id,
            'spent_on' => '2026-07-01',
            'hours' => 0.25,
            'notes' => null,
        ]);
        $entry->update([
            'is_running' => true,
            'timer_started_at' => Carbon::parse('2026-07-01 09:15:00'),
        ]);

        $rowKey = weekViewRowKey($project, $task);
        $component = Livewire::test(WeekView::class);

        $dayTotals = $component->viewData('dayTotals');

        $component->assertSet("cellValues.{$rowKey}.2", '0.25');
        expect($dayTotals[2])->toBe(1.0)
            ->and($component->viewData('weekTotal'))->toBe(1.0);
    } finally {
        Carbon::setTestNow();
    }
});

test('save creates new entries from filled cells', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    $rowKey = weekViewRowKey($project, $task);

    Livewire::test(WeekView::class)
        ->set('extraRows', [$rowKey])
        ->set("cellValues.{$rowKey}", ['2:00', '', '3:00', '', '', '', ''])
        ->call('save');

    $monday = now()->startOfWeek()->toDateString();
    $wednesday = now()->startOfWeek()->addDays(2)->toDateString();
    expect(TimeEntry::whereDate('spent_on', $monday)->where('user_id', $user->id)->first()->hours)->toBe('2.00');
    expect(TimeEntry::whereDate('spent_on', $wednesday)->where('user_id', $user->id)->first()->hours)->toBe('3.00');

    Livewire::test(WeekView::class)
        ->assertSet("cellValues.{$rowKey}.0", '2.0')
        ->assertSet("cellValues.{$rowKey}.2", '3.0');
});

test('week view keys editable rows and cells by row identity', function () {
    [$user, $project, $meetingTask] = weekViewSetup();
    $this->actingAs($user);

    $adminTask = Task::factory()->create(['name' => 'Admin']);
    $project->tasks()->attach($adminTask->id, ['is_billable' => true, 'hourly_rate_override' => null]);

    $wednesday = now()->startOfWeek()->addDays(2)->toDateString();
    app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $meetingTask->id,
        'spent_on' => $wednesday,
        'hours' => 0.25,
        'notes' => null,
    ]);
    app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $adminTask->id,
        'spent_on' => $wednesday,
        'hours' => 0.5,
        'notes' => null,
    ]);

    $meetingRowKey = weekViewRowKey($project, $meetingTask);
    $adminRowKey = weekViewRowKey($project, $adminTask);

    $html = Livewire::test(WeekView::class)->html();

    expect($html)
        ->toContain('wire:key="week-row-'.$meetingRowKey.'"')
        ->toContain('wire:key="week-row-'.$adminRowKey.'"')
        ->toContain('wire:key="week-cell-'.$meetingRowKey.'-2"')
        ->toContain('wire:key="week-cell-'.$adminRowKey.'-2"')
        ->toContain('wire:model.live.blur="cellValues.'.$meetingRowKey.'.2"')
        ->toContain('wire:model.live.blur="cellValues.'.$adminRowKey.'.2"');
});

test('save deletes entries when their cell is cleared', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    $monday = now()->startOfWeek()->toDateString();
    app(TimeEntryService::class)->create($user, ['project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => $monday, 'hours' => 1.0, 'notes' => null]);

    expect(TimeEntry::count())->toBe(1);

    $rowKey = weekViewRowKey($project, $task);
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
        ->assertSet('extraRows.0', weekViewRowKey($project, $task));
});

test('week view can queue an Asana task refresh for the selected linked project', function () {
    [$user, $project] = weekViewSetup();
    User::factory()->create([
        'role' => Role::Admin,
        'asana_access_token' => 'tok',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'admin-gid',
        'asana_workspace_gid' => 'WS1',
    ]);
    AsanaProject::create(['gid' => 'AP1', 'workspace_gid' => 'WS1', 'name' => 'Asana AP1', 'is_archived' => false]);
    $project->asanaProjects()->attach('AP1', ['asana_custom_field_gid' => null]);
    Bus::fake([PullAsanaTasksJob::class]);

    $this->actingAs($user);

    Livewire::test(WeekView::class)
        ->set('newRowProjectId', $project->id)
        ->call('refreshNewRowAsanaTasks')
        ->assertHasNoErrors();

    Bus::assertDispatched(PullAsanaTasksJob::class, fn ($job) => $job->asanaProjectGid === 'AP1' && $job->userId !== $user->id);
});

test('copy rows from most recent week adds blank rows without creating entries', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    $sourceWeekStart = Carbon::parse('2026-06-16')->startOfWeek();
    app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $sourceWeekStart->toDateString(),
        'hours' => 1.0,
        'notes' => null,
    ]);
    app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $sourceWeekStart->copy()->addDays(2)->toDateString(),
        'hours' => 2.0,
        'notes' => null,
    ]);

    $rowKey = weekViewRowKey($project, $task);

    Livewire::test(WeekView::class)
        ->set('selectedDate', '2026-06-30')
        ->assertSee('Copy rows from most recent week')
        ->call('copyRowsFromMostRecentWeek')
        ->assertSet('extraRows.0', $rowKey)
        ->assertSet("cellValues.{$rowKey}", ['', '', '', '', '', '', ''])
        ->assertSee('Copied 1 row from week of');

    expect(TimeEntry::count())->toBe(2);
});

test('removeRow deletes the row plus any of its entries this week', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    $monday = now()->startOfWeek()->toDateString();
    app(TimeEntryService::class)->create($user, ['project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => $monday, 'hours' => 1.0, 'notes' => null]);

    $rowKey = weekViewRowKey($project, $task);
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

    $rowKey = weekViewRowKey($project, $task);

    Livewire::actingAs($manager)
        ->test(WeekView::class, ['user' => $report])
        ->assertSet('isReadOnly', true)
        ->set("cellValues.{$rowKey}.0", '99:00')
        ->call('save');

    // Saved entry remains 5.0 — write was blocked
    expect((float) TimeEntry::first()->hours)->toBe(5.00);
});
