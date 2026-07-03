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

test('the embed page renders the form fixed to the Asana task', function () {
    [$user, $project, $task] = embedSetup();
    AsanaProjectAssociation::create([
        'user_id' => $user->id,
        'asana_project_gid' => 'BOARD1',
        'project_id' => $project->id,
        'task_id' => $task->id,
        'last_used_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/asana-app/tasks/AT1')
        ->assertOk()
        ->assertSee('Fix the checkout flow')
        ->assertDontSee('Track Time'); // chrome-free embed layout, no app nav

    Livewire::actingAs($user)
        ->test(AsanaLogTime::class, ['taskGid' => 'AT1'])
        ->assertSet('status', 'ok')
        ->assertSet('selectedProjectId', $project->id)
        ->assertSet('selectedTaskId', $task->id);
});

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

test('hours are required to log time', function () {
    [$user, , $task] = embedSetup();

    Livewire::actingAs($user)
        ->test(AsanaLogTime::class, ['taskGid' => 'AT1'])
        ->set('selectedTaskId', $task->id)
        ->call('save')
        ->assertHasErrors(['hoursInput']);

    expect(TimeEntry::count())->toBe(0);
});

test('start timer creates a running entry without requiring hours', function () {
    [$user, , $task] = embedSetup();

    Livewire::actingAs($user)
        ->test(AsanaLogTime::class, ['taskGid' => 'AT1'])
        ->set('selectedTaskId', $task->id)
        ->call('startTimer')
        ->assertHasNoErrors()
        ->assertDispatched('asana-entry-saved')
        ->assertSet('timerStarted', true);

    $entry = TimeEntry::sole();
    expect($entry->is_running)->toBeTrue()
        ->and($entry->asana_task_gid)->toBe('AT1');
});

test('switching project clears a task that does not belong to it', function () {
    [$user, $project, $task] = embedSetup();

    $other = Project::factory()->create(['name' => 'Other Build']);
    $otherTask = Task::factory()->create(['name' => 'Design']);
    $other->tasks()->attach($otherTask->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $other->users()->attach($user->id, ['hourly_rate_override' => null]);
    $other->asanaProjects()->attach('BOARD1', ['asana_custom_field_gid' => null]);

    Livewire::actingAs($user)
        ->test(AsanaLogTime::class, ['taskGid' => 'AT1'])
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('selectedProjectId', $other->id)
        ->assertSet('selectedTaskId', null);
});

test('an unsynced Asana task shows the missing notice', function () {
    [$user] = embedSetup();

    Livewire::actingAs($user)
        ->test(AsanaLogTime::class, ['taskGid' => 'NOPE'])
        ->assertSet('status', 'missing')
        ->assertSee('synced to Internal Tools');
});

test('a board with no linked projects shows the unmapped notice', function () {
    $user = User::factory()->create(['default_hourly_rate' => 100]);
    AsanaProject::create(['gid' => 'BOARD9', 'workspace_gid' => 'WS1', 'name' => 'Orphan Board', 'is_archived' => false]);
    AsanaTask::create(['gid' => 'AT9', 'asana_project_gid' => 'BOARD9', 'name' => 'Stray task', 'is_completed' => false]);

    Livewire::actingAs($user)
        ->test(AsanaLogTime::class, ['taskGid' => 'AT9'])
        ->assertSet('status', 'unmapped')
        ->assertSee('linked to any of your projects');
});
