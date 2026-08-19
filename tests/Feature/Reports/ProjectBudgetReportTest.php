<?php

use App\Domain\Budgeting\ProjectBudgetCalculator;
use App\Enums\BudgetType;
use App\Enums\Role;
use App\Livewire\Reports\ProjectDetail;
use App\Livewire\Reports\ProjectsReport;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
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

test('projects report exposes monthly CI budget usage for the selected period', function () {
    CarbonImmutable::setTestNow('2026-07-22');

    $admin = User::factory()->create(['role' => Role::Admin]);
    $user = User::factory()->create();
    $task = Task::factory()->create();

    $ciProject = Project::factory()->create([
        'budget_type' => BudgetType::MonthlyCi,
        'budget_amount' => 500.00,
        'budget_starts_on' => '2026-04-01',
    ]);
    $fixedFeeProject = Project::factory()->create([
        'budget_type' => BudgetType::FixedFee,
        'budget_amount' => 1000.00,
        'starts_on' => '2026-04-01',
    ]);

    budgetReportEntry([
        'user_id' => $user->id,
        'project_id' => $ciProject->id,
        'task_id' => $task->id,
        'spent_on' => '2026-04-15',
        'billable_amount' => 400,
    ]);
    budgetReportEntry([
        'user_id' => $user->id,
        'project_id' => $ciProject->id,
        'task_id' => $task->id,
        'spent_on' => '2026-05-15',
        'billable_amount' => 350,
    ]);
    budgetReportEntry([
        'user_id' => $user->id,
        'project_id' => $fixedFeeProject->id,
        'task_id' => $task->id,
        'spent_on' => '2026-04-15',
        'billable_amount' => 500,
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(ProjectsReport::class)
        ->set('preset', 'custom')
        ->set('from', '2026-04-01')
        ->set('to', '2026-05-31');

    $rows = $component->instance()->rows(app(ProjectBudgetCalculator::class))->keyBy('id');

    expect((array) $rows[$ciProject->id])
        ->toHaveKey('period_percent_used', 75.0)
        ->and((array) $rows[$fixedFeeProject->id])
        ->toHaveKey('period_percent_used', null);

    $component
        ->assertSeeHtml('colspan="3">This period</th>')
        ->assertSee('75.0%');
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

    $component = Livewire::test(ProjectDetail::class, ['project' => $thisProject]);

    $component->assertSee('Alice Example')
        ->assertSee('Discovery')
        ->assertSee('Kickoff call')
        ->assertSee('2.50')
        ->assertDontSee('Should not appear');
});

test('neutral project detail route renders for an unbudgeted project', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $project = Project::factory()->create(['budget_type' => null]);

    $this->actingAs($admin)
        ->get(route('reports.projects.detail', $project))
        ->assertOk();
});

test('legacy budget route redirects to neutral project detail', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $project = Project::factory()->create();

    $this->actingAs($admin)
        ->get(route('reports.projects.budget', $project))
        ->assertRedirect(route('reports.projects.detail', $project));
});

test('unbudgeted project detail shows spend analysis and only its time entries', function () {
    CarbonImmutable::setTestNow('2026-05-31');

    $admin = User::factory()->create(['role' => Role::Admin]);
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $project = Project::factory()->create([
        'budget_type' => null,
        'starts_on' => '2026-04-01',
    ]);
    $otherProject = Project::factory()->create();

    budgetReportEntry([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => '2026-04-15',
        'hours' => 4,
        'billable_amount' => 400,
        'notes' => 'April project entry',
    ]);
    budgetReportEntry([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => '2026-05-15',
        'hours' => 6,
        'billable_amount' => 600,
        'notes' => 'May project entry',
    ]);
    budgetReportEntry([
        'user_id' => $user->id,
        'project_id' => $otherProject->id,
        'task_id' => $task->id,
        'notes' => 'Other project entry',
    ]);

    $this->actingAs($admin);

    Livewire::test(ProjectDetail::class, ['project' => $project])
        ->assertSee('This month spent')
        ->assertSee('Cumulative spent')
        ->assertSee('Spend by month')
        ->assertSee('April 2026')
        ->assertSee('May 2026')
        ->assertSee('April project entry')
        ->assertSee('May project entry')
        ->assertDontSee('Other project entry')
        ->assertDontSee('Cumulative budget')
        ->assertDontSee('Variance')
        ->assertDontSee('Set a budget');

    CarbonImmutable::setTestNow();
});

test('projects report links budgeted and unbudgeted projects to their detail page', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $budgeted = Project::factory()->create([
        'budget_type' => BudgetType::FixedFee,
        'budget_amount' => 1000,
    ]);
    $unbudgeted = Project::factory()->create(['budget_type' => null]);

    budgetReportEntry([
        'user_id' => $user->id,
        'project_id' => $budgeted->id,
        'task_id' => $task->id,
        'spent_on' => '2026-04-15',
    ]);
    budgetReportEntry([
        'user_id' => $user->id,
        'project_id' => $unbudgeted->id,
        'task_id' => $task->id,
        'spent_on' => '2026-04-15',
    ]);

    $this->actingAs($admin);

    Livewire::test(ProjectsReport::class)
        ->set('preset', 'custom')
        ->set('from', '2026-04-01')
        ->set('to', '2026-04-30')
        ->assertSeeHtml('href="'.route('reports.projects.detail', $budgeted).'"')
        ->assertSeeHtml('href="'.route('reports.projects.detail', $unbudgeted).'"');
});
