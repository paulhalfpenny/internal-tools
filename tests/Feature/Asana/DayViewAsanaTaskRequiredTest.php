<?php

use App\Enums\Role;
use App\Jobs\Asana\SyncAsanaTaskHoursJob;
use App\Livewire\Timesheet\DayView;
use App\Models\AsanaProject;
use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => Bus::fake([SyncAsanaTaskHoursJob::class]));

function asanaTestDayViewSetup(bool $adminConnected = true, bool $projectLinked = true): array
{
    $user = User::factory()->create([
        'role' => Role::User,
        'default_hourly_rate' => 100,
    ]);

    if ($adminConnected) {
        User::factory()->create([
            'role' => Role::Admin,
            'asana_access_token' => 'tok',
            'asana_token_expires_at' => now()->addHour(),
            'asana_user_gid' => 'admin-gid',
            'asana_workspace_gid' => 'WS1',
        ]);
    }

    $project = Project::factory()->create([
        'default_hourly_rate' => 100,
    ]);

    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    if ($projectLinked) {
        AsanaProject::create(['gid' => 'AP1', 'workspace_gid' => 'WS1', 'name' => 'Asana AP1', 'is_archived' => false]);
        $project->asanaProjects()->attach('AP1', ['asana_custom_field_gid' => null]);
        AsanaTask::create(['gid' => 'AT1', 'asana_project_gid' => 'AP1', 'name' => 'Real Asana Task', 'is_completed' => false]);
    }

    return [$user, $project, $task];
}

test('save fails on linked project when no Asana task picked', function () {
    [$user, $project, $task] = asanaTestDayViewSetup();
    $this->actingAs($user);

    Livewire::test(DayView::class)
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('hoursInput', '1.0')
        ->set('entryDate', now()->toDateString())
        ->set('selectedAsanaTaskGid', '')
        ->call('save')
        ->assertHasErrors(['selectedAsanaTaskGid']);

    expect(TimeEntry::count())->toBe(0);
});

test('non-admin user can save time on linked project as long as an admin has connected', function () {
    [$user, $project, $task] = asanaTestDayViewSetup();
    $this->actingAs($user);

    expect($user->asanaConnected())->toBeFalse();

    Livewire::test(DayView::class)
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('hoursInput', '1.0')
        ->set('entryDate', now()->toDateString())
        ->set('selectedAsanaTaskGid', 'AT1')
        ->call('save')
        ->assertHasNoErrors();

    $entry = TimeEntry::firstOrFail();
    expect($entry->asana_task_gid)->toBe('AT1');
});

test('save blocked on linked project when no admin has connected Asana', function () {
    [$user, $project, $task] = asanaTestDayViewSetup(adminConnected: false);
    $this->actingAs($user);

    Livewire::test(DayView::class)
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('hoursInput', '1.0')
        ->set('entryDate', now()->toDateString())
        ->set('selectedAsanaTaskGid', 'AT1')
        ->call('save')
        ->assertHasErrors(['selectedAsanaTaskGid']);
});

test('save still works on unlinked projects with no Asana task', function () {
    [$user, $project, $task] = asanaTestDayViewSetup(adminConnected: false, projectLinked: false);
    $this->actingAs($user);

    Livewire::test(DayView::class)
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('hoursInput', '1.5')
        ->set('entryDate', now()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    expect(TimeEntry::count())->toBe(1);
});

test('save rejects an Asana task gid that belongs to a different project', function () {
    [$user, $project, $task] = asanaTestDayViewSetup();
    AsanaTask::create(['gid' => 'OTHER', 'asana_project_gid' => 'OTHER_PROJ', 'name' => 'Foreign', 'is_completed' => false]);
    $this->actingAs($user);

    Livewire::test(DayView::class)
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('hoursInput', '1.0')
        ->set('entryDate', now()->toDateString())
        ->set('selectedAsanaTaskGid', 'OTHER')
        ->call('save')
        ->assertHasErrors(['selectedAsanaTaskGid']);
});
