<?php

use App\Enums\Role;
use App\Livewire\Timesheet\DayView;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('manager can view a direct report timesheet', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $report = User::factory()->create(['reports_to_user_id' => $manager->id]);

    $this->actingAs($manager)
        ->get(route('team.timesheet', $report))
        ->assertOk()
        ->assertSee($report->name)
        ->assertSee('read-only');
});

test('manager cannot view a user who is not their direct report', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $stranger = User::factory()->create(); // no reports_to_user_id

    $this->actingAs($manager)
        ->get(route('team.timesheet', $stranger))
        ->assertForbidden();
});

test('admin can view any user timesheet via the team route', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $other = User::factory()->create();

    $this->actingAs($admin)
        ->get(route('team.timesheet', $other))
        ->assertOk();
});

test('write actions are blocked when manager views a direct report', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $report = User::factory()->create(['reports_to_user_id' => $manager->id, 'default_hourly_rate' => 100]);

    $project = Project::factory()->create();
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($report->id, ['hourly_rate_override' => null]);

    Livewire::actingAs($manager)
        ->test(DayView::class, ['user' => $report])
        ->assertSet('isReadOnly', true)
        ->call('openNewModal')
        ->assertSet('showModal', false)
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('hoursInput', '1.0')
        ->set('entryDate', now()->toDateString())
        ->call('save')
        ->assertSet('showModal', false);

    expect(TimeEntry::count())->toBe(0);
});

test('manager sees their direct reports in the Team Timesheets dropdown', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $a = User::factory()->create(['name' => 'Alice', 'reports_to_user_id' => $manager->id, 'is_active' => true]);
    $b = User::factory()->create(['name' => 'Bob', 'reports_to_user_id' => $manager->id, 'is_active' => true]);
    User::factory()->create(['name' => 'Stranger']);

    $this->actingAs($manager)
        ->get(route('timesheet'))
        ->assertOk()
        ->assertSee('Team Timesheets')
        ->assertSee('Alice')
        ->assertSee('Bob')
        ->assertDontSee('Stranger');
});

test('user with no direct reports does not see the Team Timesheets dropdown', function () {
    $solo = User::factory()->create(['role' => Role::User]);

    $this->actingAs($solo)
        ->get(route('timesheet'))
        ->assertOk()
        ->assertDontSee('Team Timesheets');
});
