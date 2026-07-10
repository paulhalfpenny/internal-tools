<?php

use App\Enums\Role;
use App\Livewire\Timesheet\DayView;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function adminTimesheetSetup(): array
{
    $admin = User::factory()->create(['role' => Role::Admin, 'default_hourly_rate' => 100]);
    $employee = User::factory()->create(['role' => Role::User, 'default_hourly_rate' => 80]);
    $project = Project::factory()->create(['default_hourly_rate' => 80]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($employee->id, ['hourly_rate_override' => null]);

    return [$admin, $employee, $project, $task];
}

test('admin save creates entry under impersonated user, not admin', function () {
    [$admin, $employee, $project, $task] = adminTimesheetSetup();
    $this->actingAs($admin);

    Livewire::test(DayView::class, ['user' => $employee])
        ->call('openNewModal')
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('hoursInput', '2:00')
        ->call('save');

    expect(TimeEntry::where('user_id', $employee->id)->count())->toBe(1)
        ->and(TimeEntry::where('user_id', $admin->id)->count())->toBe(0);
});

test('admin startTimer is a no-op while impersonating', function () {
    [$admin, $employee, $project, $task] = adminTimesheetSetup();
    $entry = TimeEntry::create([
        'user_id' => $employee->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => Carbon::today()->toDateString(),
        'hours' => 0,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 80,
        'billable_amount' => 0,
    ]);

    $this->actingAs($admin);

    Livewire::test(DayView::class, ['user' => $employee])
        ->call('startTimer', $entry->id);

    expect((bool) $entry->fresh()->is_running)->toBeFalse();
});

test('non-admin gets 403 hitting an admin timesheet route', function () {
    [, $employee] = adminTimesheetSetup();
    $regular = User::factory()->create(['role' => Role::User]);

    $this->actingAs($regular);

    $this->get(route('admin.timesheets'))->assertForbidden();
    $this->get(route('admin.timesheets.user', $employee))->assertForbidden();
});
