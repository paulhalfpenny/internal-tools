<?php

use App\Domain\TimeTracking\TimeEntryService;
use App\Livewire\Timesheet\DayView;
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

test('saving an entry keeps the entry UI open and remembers the Asana board association', function () {
    [$user, $project, $task] = deepLinkSetup();
    $this->actingAs($user);

    $component = Livewire::test(DayView::class)
        ->call('openNewModal')
        ->set('showCalendarPanel', true)
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('selectedAsanaTaskGid', 'AT1')
        ->set('hoursInput', '0.5')
        ->call('save')
        ->assertSet('showModal', true)
        ->assertSet('showCalendarPanel', true)
        ->assertSet('hoursInput', '')
        ->assertSet('editingEntryId', null);

    $html = $component->html();

    expect($html)
        ->toContain('openNewEntry()')
        ->toContain('closeNewEntry()')
        ->toContain('x-cloak x-show="showEntryModal"')
        ->toContain('@click="showCalendarPanel = false"')
        ->toContain('@mousedown.self="closeEntry()"')
        ->toContain('@keydown.escape.window="closeEntry()"')
        ->not->toContain('<template x-if="showEntryModal">')
        ->not->toContain('wire:click="closeModal"')
        ->not->toContain('wire:click="closeCalendarPanel"');

    $assoc = AsanaProjectAssociation::sole();
    expect($assoc->asana_project_gid)->toBe('BOARD1')
        ->and($assoc->project_id)->toBe($project->id)
        ->and($assoc->task_id)->toBe($task->id);

    $component
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->call('startTimerFromModal')
        ->assertSet('showModal', false)
        ->assertSet('showCalendarPanel', false);

    expect(TimeEntry::count())->toBe(2);

    Livewire::withQueryParams(['log_asana' => 'AT1'])
        ->test(DayView::class)
        ->assertSet('selectedTaskId', $task->id);
});

test('the local close path clears Livewire modal state after saving an edit', function () {
    [$user, $project, $task] = deepLinkSetup();
    $this->actingAs($user);

    $entry = app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => now()->toDateString(),
        'hours' => 0.5,
        'notes' => 'Original note',
    ]);

    $html = html_entity_decode(
        Livewire::test(DayView::class)
            ->call('openEditModal', $entry->id)
            ->call('save')
            ->assertSet('showModal', true)
            ->assertSet('editingEntryId', null)
            ->html(),
        ENT_QUOTES | ENT_HTML5,
    );

    preg_match('/closeNewEntry\(\) \{(?<body>.*?)\n        \},\n        closeEntry\(\)/s', $html, $matches);

    expect($matches['body'] ?? '')
        ->toContain("\$wire.set('showModal', false, false)");
});
