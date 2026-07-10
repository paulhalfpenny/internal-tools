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

test('manager cannot view a user who is not their direct report', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $stranger = User::factory()->create(); // no reports_to_user_id

    $this->actingAs($manager)
        ->get(route('team.timesheet', $stranger))
        ->assertForbidden();
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
