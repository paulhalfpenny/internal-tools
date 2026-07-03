<?php

use App\Livewire\Timesheet\DayView;
use App\Models\AsanaProject;
use App\Models\AsanaProjectAssociation;
use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// The Asana widget card deep-links to /timesheet?log_asana={gid}. Opening
// that link must present the entry modal prefilled for the linked project
// and Asana task, since the in-Asana form is only reachable before the
// widget occupies the app's slot on the task.

function deepLinkSetup(): array
{
    $user = User::factory()->create(['default_hourly_rate' => 100]);

    $project = Project::factory()->create(['name' => 'Website Build', 'asana_task_required' => false]);
    $task = Task::factory()->create(['name' => 'Development']);
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    AsanaProject::create(['gid' => 'BOARD1', 'workspace_gid' => 'WS1', 'name' => 'Client Board', 'is_archived' => false]);
    $project->asanaProjects()->attach('BOARD1', ['asana_custom_field_gid' => null]);
    AsanaTask::create(['gid' => 'AT1', 'asana_project_gid' => 'BOARD1', 'name' => 'Fix the checkout flow', 'is_completed' => false]);

    return [$user, $project, $task];
}

test('log_asana query param opens the entry modal prefilled', function () {
    [$user, $project, $task] = deepLinkSetup();
    AsanaProjectAssociation::create([
        'user_id' => $user->id,
        'asana_project_gid' => 'BOARD1',
        'project_id' => $project->id,
        'task_id' => $task->id,
        'last_used_at' => now(),
    ]);
    $this->actingAs($user);

    Livewire::withQueryParams(['log_asana' => 'AT1'])
        ->test(DayView::class)
        ->assertSet('showModal', true)
        ->assertSet('selectedProjectId', $project->id)
        ->assertSet('selectedTaskId', $task->id)
        ->assertSet('selectedAsanaTaskGid', 'AT1');
});

test('log_asana prefills project without an association via the board mapping', function () {
    [$user, $project] = deepLinkSetup();
    $this->actingAs($user);

    Livewire::withQueryParams(['log_asana' => 'AT1'])
        ->test(DayView::class)
        ->assertSet('showModal', true)
        ->assertSet('selectedProjectId', $project->id)
        ->assertSet('selectedAsanaTaskGid', 'AT1');
});

test('an unknown log_asana gid opens nothing', function () {
    [$user] = deepLinkSetup();
    $this->actingAs($user);

    Livewire::withQueryParams(['log_asana' => 'NOPE'])
        ->test(DayView::class)
        ->assertSet('showModal', false);
});
