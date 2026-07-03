<?php

use App\Domain\TimeTracking\TimeEntryService;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Polled by the browser extension so the Asana toolbar icon can show a
// running-timer cue.

test('guests get running false, not a login redirect', function () {
    $this->getJson('/asana-app/timer-status')
        ->assertOk()
        ->assertExactJson(['running' => false]);
});

test('reports the running timer and its Asana task', function () {
    $user = User::factory()->create(['default_hourly_rate' => 100]);
    $project = Project::factory()->create();
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    $this->actingAs($user)
        ->getJson('/asana-app/timer-status')
        ->assertOk()
        ->assertJson(['running' => false]);

    $service = app(TimeEntryService::class);
    $entry = $service->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => now()->toDateString(),
        'hours' => 0.0,
        'notes' => null,
        'asana_task_gid' => 'AT1',
    ]);
    $service->startTimer($entry);

    $this->actingAs($user)
        ->getJson('/asana-app/timer-status')
        ->assertOk()
        ->assertExactJson(['running' => true, 'gid' => 'AT1']);
});
