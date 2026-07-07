<?php

use App\Enums\Role;
use App\Livewire\Admin\Timesheets\Index as AdminTimesheetsIndex;
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

test('admin index page renders with employee rows', function () {
    [$admin, $employee] = adminTimesheetSetup();
    $this->actingAs($admin);

    $this->get(route('admin.timesheets'))
        ->assertOk()
        ->assertSee($employee->name)
        ->assertSee($employee->email);
});

test('admin can sort timesheet rows by hours this week', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-08 09:00:00'));

    try {
        $admin = User::factory()->create(['role' => Role::Admin, 'name' => 'Zoe Admin']);
        $project = Project::factory()->create();
        $task = Task::factory()->create();

        $highHours = User::factory()->create(['name' => 'Ada Eight', 'email' => 'ada@example.com']);
        $midHours = User::factory()->create(['name' => 'Bea Four', 'email' => 'bea@example.com']);
        $noHours = User::factory()->create(['name' => 'Cara Zero', 'email' => 'cara@example.com']);

        foreach ([[$highHours, 8.0], [$midHours, 4.0]] as [$user, $hours]) {
            TimeEntry::create([
                'user_id' => $user->id,
                'project_id' => $project->id,
                'task_id' => $task->id,
                'spent_on' => Carbon::parse('2026-07-08')->toDateString(),
                'hours' => $hours,
                'is_running' => false,
                'is_billable' => true,
                'billable_rate_snapshot' => 80,
                'billable_amount' => $hours * 80,
            ]);
        }

        $this->actingAs($admin);

        Livewire::test(AdminTimesheetsIndex::class)
            ->assertSeeInOrder(['Ada Eight', 'Bea Four', 'Cara Zero'])
            ->call('sortByHoursThisWeek')
            ->assertSet('sortField', 'week_hours')
            ->assertSet('sortDirection', 'asc')
            ->assertSeeInOrder(['Cara Zero', 'Bea Four', 'Ada Eight'])
            ->call('sortByHoursThisWeek')
            ->assertSet('sortDirection', 'desc')
            ->assertSeeInOrder(['Ada Eight', 'Bea Four', 'Cara Zero']);
    } finally {
        Carbon::setTestNow();
    }
});

test('admin can sort timesheet rows by name', function () {
    $admin = User::factory()->create(['role' => Role::Admin, 'name' => 'Zoe Admin']);

    User::factory()->create(['name' => 'Cara Zero', 'email' => 'cara@example.com']);
    User::factory()->create(['name' => 'Ada Eight', 'email' => 'ada@example.com']);
    User::factory()->create(['name' => 'Bea Four', 'email' => 'bea@example.com']);

    $this->actingAs($admin);

    Livewire::test(AdminTimesheetsIndex::class)
        ->assertSeeInOrder(['Ada Eight', 'Bea Four', 'Cara Zero'])
        ->call('sortBy', 'name')
        ->assertSet('sortField', 'name')
        ->assertSet('sortDirection', 'desc')
        ->assertSeeInOrder(['Cara Zero', 'Bea Four', 'Ada Eight'])
        ->call('sortBy', 'name')
        ->assertSet('sortDirection', 'asc')
        ->assertSeeInOrder(['Ada Eight', 'Bea Four', 'Cara Zero']);
});

test('admin can sort timesheet rows by last entry', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-08 09:00:00'));

    try {
        $admin = User::factory()->create(['role' => Role::Admin, 'name' => 'Zoe Admin']);
        $project = Project::factory()->create();
        $task = Task::factory()->create();

        $oldEntry = User::factory()->create(['name' => 'Ada Old', 'email' => 'ada@example.com']);
        $recentEntry = User::factory()->create(['name' => 'Bea Recent', 'email' => 'bea@example.com']);
        $noEntry = User::factory()->create(['name' => 'Cara Missing', 'email' => 'cara@example.com']);

        foreach ([[$oldEntry, '2026-07-01'], [$recentEntry, '2026-07-08']] as [$user, $spentOn]) {
            TimeEntry::create([
                'user_id' => $user->id,
                'project_id' => $project->id,
                'task_id' => $task->id,
                'spent_on' => $spentOn,
                'hours' => 1.0,
                'is_running' => false,
                'is_billable' => true,
                'billable_rate_snapshot' => 80,
                'billable_amount' => 80,
            ]);
        }

        $this->actingAs($admin);

        Livewire::test(AdminTimesheetsIndex::class)
            ->assertSeeInOrder(['Ada Old', 'Bea Recent', 'Cara Missing'])
            ->call('sortBy', 'last_entry')
            ->assertSet('sortField', 'last_entry')
            ->assertSet('sortDirection', 'asc')
            ->assertSeeInOrder(['Cara Missing', 'Ada Old', 'Bea Recent'])
            ->call('sortBy', 'last_entry')
            ->assertSet('sortDirection', 'desc')
            ->assertSeeInOrder(['Bea Recent', 'Ada Old', 'Cara Missing']);
    } finally {
        Carbon::setTestNow();
    }
});

test('admin can mount DayView for another user and load that users entries', function () {
    [$admin, $employee, $project, $task] = adminTimesheetSetup();

    TimeEntry::create([
        'user_id' => $employee->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => Carbon::today()->toDateString(),
        'hours' => 2.5,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 80,
        'billable_amount' => 200,
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(DayView::class, ['user' => $employee]);

    $component->assertSet('isImpersonating', true)
        ->assertSet('viewedUserId', $employee->id);
});

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

test('admin can edit an impersonated users entry', function () {
    [$admin, $employee, $project, $task] = adminTimesheetSetup();
    $entry = TimeEntry::create([
        'user_id' => $employee->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => Carbon::today()->toDateString(),
        'hours' => 1.0,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 80,
        'billable_amount' => 80,
    ]);

    $this->actingAs($admin);

    Livewire::test(DayView::class, ['user' => $employee])
        ->call('openEditModal', $entry->id)
        ->set('hoursInput', '3:30')
        ->call('save');

    expect((float) $entry->fresh()->hours)->toBe(3.5);
});

test('admin can delete an impersonated users entry', function () {
    [$admin, $employee, $project, $task] = adminTimesheetSetup();
    $entry = TimeEntry::create([
        'user_id' => $employee->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => Carbon::today()->toDateString(),
        'hours' => 1.0,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 80,
        'billable_amount' => 80,
    ]);

    $this->actingAs($admin);

    Livewire::test(DayView::class, ['user' => $employee])
        ->call('deleteEntry', $entry->id);

    expect(TimeEntry::find($entry->id))->toBeNull();
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

test('manager (non-admin) gets 403', function () {
    [, $employee] = adminTimesheetSetup();
    $manager = User::factory()->create(['role' => Role::Manager]);

    $this->actingAs($manager);

    $this->get(route('admin.timesheets'))->assertForbidden();
});

test('admin opening their own timesheet via admin route does not impersonate', function () {
    [$admin] = adminTimesheetSetup();
    $this->actingAs($admin);

    Livewire::test(DayView::class, ['user' => $admin])
        ->assertSet('isImpersonating', false)
        ->assertSet('viewedUserId', null);
});
