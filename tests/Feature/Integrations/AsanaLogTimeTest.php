<?php

use App\Livewire\Integrations\AsanaLogTime;
use App\Models\AsanaProject;
use App\Models\AsanaProjectAssociation;
use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// The compact log-time form the browser extension shows in its overlay
// iframe on app.asana.com (route /asana-app/tasks/{gid}).

function embedSetup(): array
{
    $user = User::factory()->create(['default_hourly_rate' => 100]);

    $project = Project::factory()->create(['name' => 'Website Build']);
    $task = Task::factory()->create(['name' => 'Development']);
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    AsanaProject::create(['gid' => 'BOARD1', 'workspace_gid' => 'WS1', 'name' => 'Client Board', 'is_archived' => false]);
    $project->asanaProjects()->attach('BOARD1', ['asana_custom_field_gid' => null]);
    AsanaTask::create(['gid' => 'AT1', 'asana_project_gid' => 'BOARD1', 'name' => 'Fix the checkout flow', 'is_completed' => false]);

    return [$user, $project, $task];
}

test('saving logs the entry against the Asana task and remembers the choice', function () {
    [$user, $project, $task] = embedSetup();

    Livewire::actingAs($user)
        ->test(AsanaLogTime::class, ['taskGid' => 'AT1'])
        ->set('selectedTaskId', $task->id)
        ->set('hoursInput', '1:30')
        ->set('notes', 'Checkout fix')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('asana-entry-saved')
        ->assertSet('hoursInput', '');

    $entry = TimeEntry::sole();
    expect((float) $entry->hours)->toBe(1.5)
        ->and($entry->asana_task_gid)->toBe('AT1')
        ->and($entry->notes)->toBe('Checkout fix');

    $assoc = AsanaProjectAssociation::sole();
    expect($assoc->project_id)->toBe($project->id)
        ->and($assoc->task_id)->toBe($task->id);
});

test('stopping the timer banks the elapsed time and notifies the extension', function () {
    [$user, , $task] = embedSetup();

    Livewire::actingAs($user)
        ->test(AsanaLogTime::class, ['taskGid' => 'AT1'])
        ->set('selectedTaskId', $task->id)
        ->call('startTimer');

    TimeEntry::sole()->update(['timer_started_at' => now()->subMinutes(30)]);

    Livewire::actingAs($user)
        ->test(AsanaLogTime::class, ['taskGid' => 'AT1'])
        ->call('stopRunningTimer')
        ->assertDispatched('asana-entry-saved')
        ->assertSee('Timer stopped');

    $entry = TimeEntry::sole();
    expect($entry->is_running)->toBeFalse()
        ->and((float) $entry->hours)->toBeGreaterThanOrEqual(0.5)
        ->and((float) $entry->hours)->toBeLessThan(0.52);
});
