<?php

use App\Enums\BudgetType;
use App\Enums\Role;
use App\Livewire\Reports\ProjectBudget;
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

    $component = Livewire::test(ProjectBudget::class, ['project' => $thisProject]);
    $response = $component->instance()->export();

    ob_start();
    $response->sendContent();
    $body = (string) ob_get_clean();

    expect($body)->toContain('In scope')->not->toContain('Out of scope');
    expect($response->headers->get('Content-Disposition'))->toContain('prj-exp');
});
