<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Asana\AsanaTaskHoursAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('sums hours across all users for a given asana task gid', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create();
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    foreach ([
        ['user' => $alice, 'gid' => 'asana-1', 'hours' => 1.50],
        ['user' => $bob,   'gid' => 'asana-1', 'hours' => 2.25],
        ['user' => $alice, 'gid' => 'asana-2', 'hours' => 9.99], // different task
        ['user' => $alice, 'gid' => null,      'hours' => 5.00], // unlinked
    ] as $row) {
        TimeEntry::create([
            'user_id' => $row['user']->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'spent_on' => '2026-05-05',
            'hours' => $row['hours'],
            'is_running' => false,
            'is_billable' => true,
            'billable_rate_snapshot' => 100,
            'billable_amount' => 100 * $row['hours'],
            'asana_task_gid' => $row['gid'],
        ]);
    }

    expect((new AsanaTaskHoursAggregator)->totalHours('asana-1'))
        ->toBe(3.75);
});
