<?php

use App\Domain\TimeTracking\TimeEntryService;
use App\Enums\Role;
use App\Jobs\Asana\PullAsanaTasksJob;
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

test('non-admin user can queue an Asana task refresh for the selected linked project', function () {
    [$user, $project] = asanaTestDayViewSetup();
    Bus::fake([SyncAsanaTaskHoursJob::class, PullAsanaTasksJob::class]);

    $this->actingAs($user);

    Livewire::test(DayView::class)
        ->set('selectedProjectId', $project->id)
        ->call('refreshSelectedProjectAsanaTasks')
        ->assertHasNoErrors();

    Bus::assertDispatched(PullAsanaTasksJob::class, fn ($job) => $job->asanaProjectGid === 'AP1' && $job->userId !== $user->id);
});

test('editing a calendar entry keeps Asana task optional on required linked projects', function () {
    [$user, $project, $task] = asanaTestDayViewSetup();
    $this->actingAs($user);

    Livewire::test(DayView::class)
        ->call('pullFromCalendarEvent', 'Daily standup', 0.5)
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('entryDate', now()->toDateString())
        ->set('selectedAsanaTaskGid', '')
        ->call('save')
        ->assertHasNoErrors();

    $entry = TimeEntry::firstOrFail();

    Livewire::test(DayView::class)
        ->call('openEditModal', $entry->id)
        ->set('hoursInput', '0:45')
        ->call('save')
        ->assertHasNoErrors();

    expect($entry->fresh()->hours)->toBe('0.75')
        ->and($entry->fresh()->asana_task_gid)->toBeNull();
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

test('editing an entry shows when its Asana task moved to a different project', function () {
    [$user, $project, $task] = asanaTestDayViewSetup();
    $this->actingAs($user);

    $entry = app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => now()->toDateString(),
        'hours' => 1.0,
        'notes' => null,
        'asana_task_gid' => 'AT1',
    ]);

    AsanaProject::create(['gid' => 'AP2', 'workspace_gid' => 'WS1', 'name' => 'Asana AP2', 'is_archived' => false]);
    AsanaTask::findOrFail('AT1')->update(['asana_project_gid' => 'AP2']);

    $component = Livewire::test(DayView::class)
        ->call('openEditModal', $entry->id)
        ->set('hoursInput', '2.0')
        ->call('save')
        ->assertHasErrors(['selectedAsanaTaskGid'])
        ->assertSet('showModal', true);

    expect($entry->fresh()->hours)->toBe('1.00');

    $document = new DOMDocument;
    $previousLibxmlErrorHandling = libxml_use_internal_errors(true);
    $document->loadHTML($component->html());
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlErrorHandling);

    $ignoredErrorAncestors = (new DOMXPath($document))->query(
        "//p[contains(normalize-space(.), 'That Asana task is no longer in this project.')]/ancestor::*[@*[name()='wire:ignore']]"
    );

    expect($ignoredErrorAncestors)->not->toBeFalse()
        ->and($ignoredErrorAncestors->length)->toBe(0);
});

test('save succeeds without an Asana task when the project marks Asana tasks as optional', function () {
    [$user, $project, $task] = asanaTestDayViewSetup();
    $project->forceFill(['asana_task_required' => false])->save();
    $this->actingAs($user);

    Livewire::test(DayView::class)
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('hoursInput', '1.0')
        ->set('entryDate', now()->toDateString())
        ->set('selectedAsanaTaskGid', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(TimeEntry::count())->toBe(1);
    expect(TimeEntry::first()->asana_task_gid)->toBeNull();
});

test('save still validates a provided Asana task gid even when optional', function () {
    [$user, $project, $task] = asanaTestDayViewSetup();
    $project->forceFill(['asana_task_required' => false])->save();
    AsanaTask::create(['gid' => 'OUTSIDER', 'asana_project_gid' => 'OUTSIDE', 'name' => 'Foreign', 'is_completed' => false]);
    $this->actingAs($user);

    Livewire::test(DayView::class)
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('hoursInput', '1.0')
        ->set('entryDate', now()->toDateString())
        ->set('selectedAsanaTaskGid', 'OUTSIDER')
        ->call('save')
        ->assertHasErrors(['selectedAsanaTaskGid']);
});
