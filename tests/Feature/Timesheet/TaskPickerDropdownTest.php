<?php

use App\Enums\Role;
use App\Livewire\Admin\Projects\Edit as AdminProjectEdit;
use App\Livewire\Timesheet\DayView;
use App\Livewire\Timesheet\WeekView;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('saving project billability clears cached project picker task grouping', function () {
    $user = User::factory()->create(['role' => Role::User, 'default_hourly_rate' => 100]);
    $admin = User::factory()->create(['role' => Role::Admin]);
    $project = Project::factory()->nonBillable()->create(['name' => 'JDW001 Programme Activity']);
    $task = Task::factory()->create(['name' => 'Billable task', 'is_archived' => false]);

    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    $this->actingAs($user);

    $initialDayTask = Livewire::test(DayView::class)
        ->call('openNewModal')
        ->viewData('projectsForPicker')[0]['tasks'][0];
    $initialWeekTask = Livewire::test(WeekView::class)
        ->call('openAddRowModal')
        ->viewData('projectsForPicker')[0]['tasks'][0];

    expect($initialDayTask['is_billable'])->toBeFalse();
    expect($initialWeekTask['is_billable'])->toBeFalse();

    $this->actingAs($admin);

    Livewire::test(AdminProjectEdit::class, ['project' => $project])
        ->set('isBillable', '1')
        ->call('save')
        ->assertHasNoErrors();

    $this->actingAs($user);

    $dayTask = Livewire::test(DayView::class)
        ->call('openNewModal')
        ->viewData('projectsForPicker')[0]['tasks'][0];
    $weekTask = Livewire::test(WeekView::class)
        ->call('openAddRowModal')
        ->viewData('projectsForPicker')[0]['tasks'][0];

    expect($dayTask['is_billable'])->toBeTrue();
    expect($weekTask['is_billable'])->toBeTrue();
});

test('day entry picker selections stay local until the entry is submitted', function () {
    $user = User::factory()->create(['role' => Role::User]);
    $project = Project::factory()->create();
    $task = Task::factory()->create(['is_archived' => false]);

    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    $html = html_entity_decode(
        Livewire::actingAs($user)
            ->test(DayView::class)
            ->call('openNewModal')
            ->html(),
        ENT_QUOTES | ENT_HTML5,
    );

    expect($html)
        ->toContain("\$wire.set('selectedProjectId', id, false)")
        ->toContain("\$wire.set('selectedTaskId', id, false)")
        ->toContain("\$wire.set('selectedAsanaTaskGid', gid, false)")
        ->not->toContain('\$wire.selectedProjectId = id')
        ->not->toContain('\$wire.selectedTaskId = id')
        ->not->toContain('\$wire.selectedAsanaTaskGid = gid');
});
