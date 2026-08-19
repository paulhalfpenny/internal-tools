<?php

use App\Enums\BudgetType;
use App\Enums\Role;
use App\Livewire\Reports\ProjectDetail;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function projectBudgetEntry(array $attrs): TimeEntry
{
    return TimeEntry::create(array_merge([
        'spent_on' => '2026-04-15',
        'hours' => 1.0,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 100.0,
        'billable_amount' => 100.0,
    ], $attrs));
}

test('project budget page exports a CSV scoped to that project only', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $user = User::factory()->create();
    $task = Task::factory()->create();

    $thisProject = Project::factory()->create([
        'code' => 'PRJ-EXP',
        'name' => 'Export test',
        'budget_type' => BudgetType::FixedFee,
        'budget_amount' => 1000,
        'starts_on' => '2026-01-01',
    ]);
    $otherProject = Project::factory()->create();

    projectBudgetEntry(['user_id' => $user->id, 'project_id' => $thisProject->id, 'task_id' => $task->id, 'notes' => 'In scope']);
    projectBudgetEntry(['user_id' => $user->id, 'project_id' => $otherProject->id, 'task_id' => $task->id, 'notes' => 'Out of scope']);

    $component = Livewire::test(ProjectDetail::class, ['project' => $thisProject]);
    $response = $component->instance()->export();

    ob_start();
    $response->sendContent();
    $body = (string) ob_get_clean();

    expect($body)->toContain('In scope')->not->toContain('Out of scope');
    expect($response->headers->get('Content-Disposition'))->toContain('prj-exp');
});

test('project detail exports the active filters with two-decimal hours', function () {
    $admin = User::factory()->create([
        'role' => Role::Admin,
        'schedule_preferences' => ['hours_display_format' => 'hhmm'],
    ]);
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $project = Project::factory()->create(['starts_on' => '2026-06-01']);
    $taskA = Task::factory()->create();
    $taskB = Task::factory()->create();
    $taskC = Task::factory()->create();

    foreach ([[$taskA, 40.45, 'Task A'], [$taskB, 15.05, 'Task B'], [$taskC, 1.3, 'Task C']] as [$task, $hours, $notes]) {
        projectBudgetEntry([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'spent_on' => '2026-07-15',
            'hours' => $hours,
            'notes' => $notes,
        ]);
    }

    projectBudgetEntry([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $taskA->id,
        'spent_on' => '2026-06-15',
        'notes' => 'Outside month',
    ]);
    projectBudgetEntry([
        'user_id' => $otherUser->id,
        'project_id' => $project->id,
        'task_id' => $taskA->id,
        'spent_on' => '2026-07-15',
        'notes' => 'Outside user',
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(ProjectDetail::class, ['project' => $project])
        ->set('filterMonth', '2026-07')
        ->set('filterUserId', $user->id)
        ->assertSee('56.80')
        ->assertDontSee('56:48');

    $response = $component->instance()->export();
    ob_start();
    $response->sendContent();
    $body = (string) ob_get_clean();
    $rows = array_values(array_filter(explode("\r\n", trim($body))));
    array_shift($rows);
    $exportedHours = collect($rows)->sum(fn (string $row): float => (float) str_getcsv($row)[6]);

    expect($body)->toContain('Task A')
        ->toContain('Task B')
        ->toContain('Task C')
        ->toContain('1.30')
        ->not->toContain('Outside month')
        ->not->toContain('Outside user')
        ->and($exportedHours)->toBe(56.8)
        ->and($response->headers->get('Content-Disposition'))->toContain('2026-07-01-to-2026-07-31');
});
