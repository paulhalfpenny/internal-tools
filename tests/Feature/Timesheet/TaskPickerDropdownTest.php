<?php

use App\Enums\Role;
use App\Livewire\Admin\Projects\Edit as AdminProjectEdit;
use App\Livewire\Admin\Tasks\Index as AdminTasks;
use App\Livewire\Timesheet\DayView;
use App\Livewire\Timesheet\WeekView;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function taskPickerDropdownSetup(): User
{
    $user = User::factory()->create(['role' => Role::User, 'default_hourly_rate' => 100]);
    $project = Project::factory()->create(['name' => 'Internal']);
    $task = Task::factory()->create(['name' => 'Holiday', 'is_default_billable' => false]);

    $project->tasks()->attach($task->id, ['is_billable' => false, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    return $user;
}

function taskPickerNonBillableProjectSetup(): User
{
    $user = User::factory()->create(['role' => Role::User, 'default_hourly_rate' => 100]);
    $project = Project::factory()->nonBillable()->create(['name' => 'Internal']);
    $task = Task::factory()->create(['name' => 'Admin']);

    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    return $user;
}

function taskPickerArchivedTaskSetup(): array
{
    $user = User::factory()->create(['role' => Role::User, 'default_hourly_rate' => 100]);
    $project = Project::factory()->create(['name' => 'JDW001 Programme Activity']);
    $activeTask = Task::factory()->create(['name' => 'Implementation', 'is_archived' => false]);
    $archivedTask = Task::factory()->create(['name' => 'Meeting', 'is_archived' => true]);

    $project->tasks()->attach($activeTask->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->tasks()->attach($archivedTask->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    return [$user, $activeTask, $archivedTask];
}

function taskPickerMethodBody(string $html): string
{
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
    preg_match('/pickTask\(id\) \{(?P<body>.*?)\n\s*\},\n\s*pickAsanaTask/s', $html, $matches);

    expect($matches)->not->toBeEmpty();

    return $matches['body'];
}

function assertTaskPickerClosesBeforeLivewireSync(string $html, string $wireSyncStatement): void
{
    $methodBody = taskPickerMethodBody($html);

    expect($methodBody)->toContain('this.closePickers();');

    $closePosition = strpos($methodBody, 'this.closePickers();');
    $wirePosition = strpos($methodBody, $wireSyncStatement);

    expect($wirePosition)->not->toBeFalse();
    expect($closePosition)->toBeLessThan($wirePosition);
}

function assertPickersSearchFromMainInputs(string $html): void
{
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);

    expect($html)
        ->toContain("taskSearch: ''")
        ->toContain(':value="projectOpen ? projectSearch : selectedProjectLabel"')
        ->toContain('@input="searchProjects($event.target.value)"')
        ->toContain('@click.stop="toggleProjectPicker()"')
        ->toContain(':value="asanaTaskOpen ? asanaTaskSearch : selectedAsanaTaskLabel"')
        ->toContain('@input="searchAsanaTasks($event.target.value)"')
        ->toContain('@click.stop="toggleAsanaTaskPicker()"')
        ->toContain('@click.stop="openAsanaTaskPicker()"')
        ->toContain('window.asanaTaskFilter?.filterAsanaTasksForProject')
        ->toContain(':value="taskOpen ? taskSearch : selectedTaskLabel"')
        ->toContain('@input="searchTasks($event.target.value)"')
        ->toContain('@click.stop="toggleTaskPicker()"')
        ->not->toContain('x-model="projectSearch"')
        ->not->toContain('x-model="asanaTaskSearch"')
        ->not->toContain('x-model="taskSearch"')
        ->not->toContain('border-b border-gray-100 flex justify-end')
        ->toContain('filteredBillableTasks')
        ->toContain('filteredNonBillableTasks')
        ->toContain('No tasks match.');
}

test('day view closes the task dropdown before syncing the selected task', function () {
    $user = taskPickerDropdownSetup();
    $this->actingAs($user);

    $html = Livewire::test(DayView::class)
        ->call('openNewModal')
        ->html();

    assertTaskPickerClosesBeforeLivewireSync($html, '$wire.selectedTaskId = id;');
});

test('day view dropdowns search from their main inputs', function () {
    $user = taskPickerDropdownSetup();
    $this->actingAs($user);

    $html = Livewire::test(DayView::class)
        ->call('openNewModal')
        ->html();

    assertPickersSearchFromMainInputs($html);
});

test('week view closes the task dropdown before syncing the selected task', function () {
    $user = taskPickerDropdownSetup();
    $this->actingAs($user);

    $html = Livewire::test(WeekView::class)
        ->call('openAddRowModal')
        ->html();

    assertTaskPickerClosesBeforeLivewireSync($html, "\$wire.set('newRowTaskId', id);");
});

test('week view dropdowns search from their main inputs', function () {
    $user = taskPickerDropdownSetup();
    $this->actingAs($user);

    $html = Livewire::test(WeekView::class)
        ->call('openAddRowModal')
        ->html();

    assertPickersSearchFromMainInputs($html);
});

test('day view picker treats tasks on non-billable projects as non-billable', function () {
    $user = taskPickerNonBillableProjectSetup();
    $this->actingAs($user);

    $component = Livewire::test(DayView::class)
        ->call('openNewModal');

    $projects = $component->viewData('projectsForPicker');

    expect($projects[0]['tasks'][0]['is_billable'])->toBeFalse();
});

test('day view picker excludes archived project tasks', function () {
    [$user, $activeTask, $archivedTask] = taskPickerArchivedTaskSetup();
    $this->actingAs($user);

    $component = Livewire::test(DayView::class)
        ->call('openNewModal');

    $projects = $component->viewData('projectsForPicker');
    $taskNames = collect($projects[0]['tasks'])->pluck('name')->all();

    expect($taskNames)
        ->toContain($activeTask->name)
        ->not->toContain($archivedTask->name);
});

test('week view picker excludes archived project tasks', function () {
    [$user, $activeTask, $archivedTask] = taskPickerArchivedTaskSetup();
    $this->actingAs($user);

    $component = Livewire::test(WeekView::class)
        ->call('openAddRowModal');

    $projects = $component->viewData('projectsForPicker');
    $taskNames = collect($projects[0]['tasks'])->pluck('name')->all();

    expect($taskNames)
        ->toContain($activeTask->name)
        ->not->toContain($archivedTask->name);
});

test('archiving a task clears cached project picker tasks', function () {
    $user = User::factory()->create(['role' => Role::User, 'default_hourly_rate' => 100]);
    $admin = User::factory()->create(['role' => Role::Admin]);
    $project = Project::factory()->create(['name' => 'JDW001 Programme Activity']);
    $task = Task::factory()->create(['name' => 'Meeting', 'is_archived' => false]);

    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    $this->actingAs($user);

    $initialDayTaskNames = collect(Livewire::test(DayView::class)
        ->call('openNewModal')
        ->viewData('projectsForPicker')[0]['tasks'])->pluck('name')->all();
    $initialWeekTaskNames = collect(Livewire::test(WeekView::class)
        ->call('openAddRowModal')
        ->viewData('projectsForPicker')[0]['tasks'])->pluck('name')->all();

    expect($initialDayTaskNames)->toContain($task->name);
    expect($initialWeekTaskNames)->toContain($task->name);

    $this->actingAs($admin);

    Livewire::test(AdminTasks::class)
        ->call('toggleArchive', $task->id);

    $this->actingAs($user);

    $dayTaskNames = collect(Livewire::test(DayView::class)
        ->call('openNewModal')
        ->viewData('projectsForPicker')[0]['tasks'])->pluck('name')->all();
    $weekTaskNames = collect(Livewire::test(WeekView::class)
        ->call('openAddRowModal')
        ->viewData('projectsForPicker')[0]['tasks'])->pluck('name')->all();

    expect($dayTaskNames)->not->toContain($task->name);
    expect($weekTaskNames)->not->toContain($task->name);
});

test('saving project task assignments clears cached project picker tasks', function () {
    $user = User::factory()->create(['role' => Role::User, 'default_hourly_rate' => 100]);
    $admin = User::factory()->create(['role' => Role::Admin]);
    $project = Project::factory()->create(['name' => 'JDW001 Programme Activity']);
    $task = Task::factory()->create(['name' => 'Meeting', 'is_archived' => false]);

    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    $this->actingAs($user);

    $initialDayTaskNames = collect(Livewire::test(DayView::class)
        ->call('openNewModal')
        ->viewData('projectsForPicker')[0]['tasks'])->pluck('name')->all();
    $initialWeekTaskNames = collect(Livewire::test(WeekView::class)
        ->call('openAddRowModal')
        ->viewData('projectsForPicker')[0]['tasks'])->pluck('name')->all();

    expect($initialDayTaskNames)->toContain($task->name);
    expect($initialWeekTaskNames)->toContain($task->name);

    $this->actingAs($admin);

    Livewire::test(AdminProjectEdit::class, ['project' => $project])
        ->set('taskAssignments', [])
        ->call('save')
        ->assertHasNoErrors();

    $this->actingAs($user);

    $dayTaskNames = collect(Livewire::test(DayView::class)
        ->call('openNewModal')
        ->viewData('projectsForPicker')[0]['tasks'])->pluck('name')->all();
    $weekTaskNames = collect(Livewire::test(WeekView::class)
        ->call('openAddRowModal')
        ->viewData('projectsForPicker')[0]['tasks'])->pluck('name')->all();

    expect($dayTaskNames)->not->toContain($task->name);
    expect($weekTaskNames)->not->toContain($task->name);
});

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

test('week view picker treats tasks on non-billable projects as non-billable', function () {
    $user = taskPickerNonBillableProjectSetup();
    $this->actingAs($user);

    $component = Livewire::test(WeekView::class)
        ->call('openAddRowModal');

    $projects = $component->viewData('projectsForPicker');

    expect($projects[0]['tasks'][0]['is_billable'])->toBeFalse();
});
