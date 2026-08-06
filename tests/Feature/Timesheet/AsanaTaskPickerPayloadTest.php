<?php

use App\Enums\Role;
use App\Livewire\Timesheet\DayView;
use App\Livewire\Timesheet\WeekView;
use App\Models\AsanaProject;
use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function timesheetAsanaPickerSetup(): array
{
    $user = User::factory()->create(['role' => Role::User]);
    $project = Project::factory()->create();
    $task = Task::factory()->create();
    $project->users()->attach($user);
    $project->tasks()->attach($task, ['is_billable' => true, 'hourly_rate_override' => null]);

    AsanaProject::create(['gid' => 'PICKER-BOARD', 'workspace_gid' => 'WORKSPACE', 'name' => 'Picker board', 'is_archived' => false]);
    $project->asanaProjects()->attach('PICKER-BOARD', ['asana_custom_field_gid' => null]);
    AsanaTask::create([
        'gid' => 'VISIBLE-TASK',
        'asana_project_gid' => 'PICKER-BOARD',
        'name' => 'Visible task',
        'search_text' => 'visible task',
        'is_completed' => false,
    ]);
    AsanaTask::create([
        'gid' => 'COMPLETED-TASK',
        'asana_project_gid' => 'PICKER-BOARD',
        'name' => 'Completed task',
        'search_text' => 'completed task',
        'is_completed' => true,
    ]);
    AsanaTask::create([
        'gid' => 'OUTSIDE-TASK',
        'asana_project_gid' => 'OUTSIDE-BOARD',
        'name' => 'Outside task',
        'search_text' => 'outside task',
        'is_completed' => false,
    ]);

    return [$user, $project];
}

test('DayView excludes all Asana task lists from its render payload and loads selected project tasks on demand', function () {
    [$user, $project] = timesheetAsanaPickerSetup();

    $component = Livewire::actingAs($user)->test(DayView::class);

    expect($component->html())->not->toContain('VISIBLE-TASK');

    expect($component->instance()->loadAsanaTasksForProject($project->id))->toBe([
        ['gid' => 'VISIBLE-TASK', 'name' => 'Visible task', 'search_text' => 'visible task', 'board_name' => 'Picker board'],
    ]);
});

test('WeekView excludes all Asana task lists from its render payload and loads selected project tasks on demand', function () {
    [$user, $project] = timesheetAsanaPickerSetup();

    $component = Livewire::actingAs($user)->test(WeekView::class);

    expect($component->html())->not->toContain('VISIBLE-TASK');

    expect($component->instance()->loadAsanaTasksForProject($project->id))->toBe([
        ['gid' => 'VISIBLE-TASK', 'name' => 'Visible task', 'search_text' => 'visible task', 'board_name' => 'Picker board'],
    ]);
});
