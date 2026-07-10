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

function deepLinkSetup(): array
{
    $user = User::factory()->create([
        'default_hourly_rate' => 100,
        'asana_user_gid' => 'AU1',
        'asana_access_token' => 'token-1',
    ]);

    $project = Project::factory()->create(['name' => 'Website Build', 'asana_task_required' => false]);
    $task = Task::factory()->create(['name' => 'Development']);
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    AsanaProject::create(['gid' => 'BOARD1', 'workspace_gid' => 'WS1', 'name' => 'Client Board', 'is_archived' => false]);
    $project->asanaProjects()->attach('BOARD1', ['asana_custom_field_gid' => null]);
    AsanaTask::create(['gid' => 'AT1', 'asana_project_gid' => 'BOARD1', 'name' => 'Fix the checkout flow', 'is_completed' => false]);

    return [$user, $project, $task];
}

test('saving an entry with an Asana task remembers the board association', function () {
    [$user, $project, $task] = deepLinkSetup();
    $this->actingAs($user);

    Livewire::test(DayView::class)
        ->call('openNewModal')
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('selectedAsanaTaskGid', 'AT1')
        ->set('hoursInput', '0.5')
        ->call('save');

    $assoc = AsanaProjectAssociation::sole();
    expect($assoc->asana_project_gid)->toBe('BOARD1')
        ->and($assoc->project_id)->toBe($project->id)
        ->and($assoc->task_id)->toBe($task->id);

    Livewire::withQueryParams(['log_asana' => 'AT1'])
        ->test(DayView::class)
        ->assertSet('selectedTaskId', $task->id);
});
