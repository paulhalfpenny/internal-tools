<?php

use App\Domain\Budgeting\ProjectBudgetCalculator;
use App\Enums\BudgetType;
use App\Enums\Role;
use App\Livewire\Reports\ProjectBudget;
use App\Livewire\Reports\ProjectsReport;
use App\Models\AsanaProject;
use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function budgetReportEntry(array $attrs): TimeEntry
{
    return TimeEntry::create(array_merge([
        'spent_on' => '2026-04-15',
        'hours' => 1.0,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 100.0,
        'billable_amount' => 100.0,
        'invoiced_at' => null,
    ], $attrs));
}

test('projects report exposes budget status on rows', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $user = User::factory()->create();
    $task = Task::factory()->create();

    $project = Project::factory()->create([
        'budget_type' => BudgetType::FixedFee,
        'budget_amount' => 1000.00,
        'starts_on' => '2026-04-01',
    ]);

    budgetReportEntry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 5, 'billable_amount' => 500]);

    $this->actingAs($admin);

    $component = Livewire::test(ProjectsReport::class)
        ->set('preset', 'this_month')
        ->set('from', '2026-04-01')
        ->set('to', '2026-04-30');

    $rows = $component->instance()->rows(app(ProjectBudgetCalculator::class));

    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    expect($row->budget_status)->not->toBeNull()
        ->and($row->budget_status->budgetAmount)->toBe(1000.0)
        ->and($row->budget_status->actualAmount)->toBe(500.0);
});

test('drill-down page renders for a budgeted project', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $user = User::factory()->create();
    $task = Task::factory()->create();

    $project = Project::factory()->create([
        'budget_type' => BudgetType::MonthlyCi,
        'budget_amount' => 500.00,
        'budget_starts_on' => '2026-04-01',
    ]);

    budgetReportEntry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 4, 'billable_amount' => 400]);

    $this->actingAs($admin);

    $response = $this->get(route('reports.projects.budget', $project));
    $response->assertOk();
    $response->assertSee('CI Retainer');
});

test('budget page lists time entries for this project only, with who/what/when', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $alice = User::factory()->create(['name' => 'Alice Example']);
    $task = Task::factory()->create(['name' => 'Discovery']);

    $thisProject = Project::factory()->create([
        'budget_type' => BudgetType::MonthlyCi,
        'budget_amount' => 500.00,
        'budget_starts_on' => '2026-04-01',
    ]);
    $otherProject = Project::factory()->create([
        'budget_type' => BudgetType::MonthlyCi,
        'budget_amount' => 500.00,
        'budget_starts_on' => '2026-04-01',
    ]);

    budgetReportEntry(['user_id' => $alice->id, 'project_id' => $thisProject->id, 'task_id' => $task->id, 'hours' => 2.5, 'spent_on' => '2026-04-10', 'notes' => 'Kickoff call']);
    budgetReportEntry(['user_id' => $alice->id, 'project_id' => $otherProject->id, 'task_id' => $task->id, 'hours' => 9.0, 'notes' => 'Should not appear']);

    $this->actingAs($admin);

    $component = Livewire::test(ProjectBudget::class, ['project' => $thisProject]);

    $component->assertSee('Alice Example')
        ->assertSee('Discovery')
        ->assertSee('Kickoff call')
        ->assertSee('2.5')
        ->assertDontSee('Should not appear');
});

test('budget page shows the linked Asana task for entries that have one', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $user = User::factory()->create();
    $task = Task::factory()->create();

    $project = Project::factory()->create([
        'budget_type' => BudgetType::MonthlyCi,
        'budget_amount' => 500.00,
        'budget_starts_on' => '2026-04-01',
    ]);

    AsanaProject::create(['gid' => 'AP1', 'workspace_gid' => 'WS1', 'name' => 'Asana AP1', 'is_archived' => false]);
    AsanaTask::create(['gid' => 'AT1', 'asana_project_gid' => 'AP1', 'name' => 'Fix the header bug', 'is_completed' => false]);

    budgetReportEntry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 1.0, 'asana_task_gid' => 'AT1']);
    budgetReportEntry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 2.0, 'asana_task_gid' => null]);

    $this->actingAs($admin);

    Livewire::test(ProjectBudget::class, ['project' => $project])
        ->assertSee('Fix the header bug');
});

test('budget page paginates time entries', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $user = User::factory()->create();
    $task = Task::factory()->create();

    $project = Project::factory()->create([
        'budget_type' => BudgetType::MonthlyCi,
        'budget_amount' => 500.00,
        'budget_starts_on' => '2026-04-01',
    ]);

    foreach (range(1, 30) as $i) {
        $day = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        budgetReportEntry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 1.0, 'spent_on' => "2026-04-{$day}"]);
    }

    $this->actingAs($admin);

    $component = Livewire::test(ProjectBudget::class, ['project' => $project]);

    $entries = $component->viewData('entries');
    expect($entries->total())->toBe(30)
        ->and($entries->items())->toHaveCount(25);
});
