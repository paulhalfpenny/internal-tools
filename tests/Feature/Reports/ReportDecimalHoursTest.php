<?php

use App\Enums\Role;
use App\Livewire\Reports\ClientDetail;
use App\Livewire\Reports\ClientsReport;
use App\Livewire\Reports\ProjectDetail;
use App\Livewire\Reports\ProjectsReport;
use App\Livewire\Reports\TasksReport;
use App\Livewire\Reports\TeamOverviewReport;
use App\Livewire\Reports\TeamReport;
use App\Livewire\Reports\TimeReport;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('all reports use fixed decimal hours regardless of the viewer preference', function () {
    $admin = User::factory()->create([
        'role' => Role::Admin,
        'schedule_preferences' => ['hours_display_format' => 'hhmm'],
    ]);
    $member = User::factory()->create();
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id, 'starts_on' => '2026-07-01']);
    $task = Task::factory()->create();

    TimeEntry::factory()->create([
        'user_id' => $member->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => '2026-07-15',
        'hours' => 1.3,
        'is_billable' => true,
        'billable_amount' => 130.0,
    ]);

    $this->actingAs($admin);

    foreach ([
        [TimeReport::class, []],
        [TasksReport::class, []],
        [ClientsReport::class, []],
        [ClientDetail::class, ['client' => $client]],
        [TeamOverviewReport::class, []],
        [TeamReport::class, ['user' => $member]],
        [ProjectsReport::class, []],
    ] as [$component, $parameters]) {
        Livewire::test($component, $parameters)
            ->set('preset', 'custom')
            ->set('from', '2026-07-01')
            ->set('to', '2026-07-31')
            ->assertSee('1.30')
            ->assertDontSee('1:18');
    }

    Livewire::test(ProjectDetail::class, ['project' => $project])
        ->set('filterMonth', '2026-07')
        ->assertSee('1.30')
        ->assertDontSee('1:18');
});
