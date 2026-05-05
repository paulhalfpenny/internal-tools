<?php

use App\Domain\Budgeting\ProjectBudgetCalculator;
use App\Enums\BudgetType;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function budgetTestEntry(array $attrs): TimeEntry
{
    return TimeEntry::create(array_merge([
        'spent_on' => '2026-04-01',
        'hours' => 1.0,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 100.0,
        'billable_amount' => 100.0,
        'invoiced_at' => null,
    ], $attrs));
}

test('returns null when project has no budget', function () {
    $project = Project::factory()->create([]);

    expect((new ProjectBudgetCalculator)->forProject($project))->toBeNull();
});

test('fixed-fee budget — actuals are sum of billable time', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $project = Project::factory()->create([
                'budget_type' => BudgetType::FixedFee,
        'budget_amount' => 1000.00,
        'starts_on' => '2026-01-01',
    ]);

    budgetTestEntry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 5, 'billable_amount' => 500]);
    budgetTestEntry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 1, 'is_billable' => false, 'billable_amount' => 0]);

    $status = (new ProjectBudgetCalculator)->forProject($project);

    expect($status)->not->toBeNull()
        ->and($status->budgetAmount)->toBe(1000.0)
        ->and($status->actualAmount)->toBe(500.0)
        ->and($status->actualHours)->toBe(5.0)
        ->and($status->percentUsed())->toBe(50.0)
        ->and($status->isOver())->toBeFalse();
});

test('fixed-fee budget — over-budget flagged', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $project = Project::factory()->create([
                'budget_type' => BudgetType::FixedFee,
        'budget_amount' => 1000.00,
        'starts_on' => '2026-01-01',
    ]);

    budgetTestEntry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 12, 'billable_amount' => 1200]);

    $status = (new ProjectBudgetCalculator)->forProject($project);

    expect($status->actualAmount)->toBe(1200.0)
        ->and($status->isOver())->toBeTrue()
        ->and($status->percentUsed())->toBe(120.0);
});

test('monthly CI — cumulative budget rolls over (under then over)', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $project = Project::factory()->create([
                'budget_type' => BudgetType::MonthlyCi,
        'budget_amount' => 500.00,
        'budget_starts_on' => '2026-04-01',
    ]);

    // Month 1 (April): underspend by £100
    budgetTestEntry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => '2026-04-15', 'hours' => 4, 'billable_amount' => 400]);
    // Month 2 (May): overspend by £100
    budgetTestEntry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => '2026-05-15', 'hours' => 6, 'billable_amount' => 600]);

    $status = (new ProjectBudgetCalculator)->forProject($project, CarbonImmutable::parse('2026-05-31'));

    // 2 months elapsed × £500 = £1000 budget; £400 + £600 = £1000 actual; net even.
    expect($status->budgetAmount)->toBe(1000.0)
        ->and($status->actualAmount)->toBe(1000.0)
        ->and($status->variance())->toBe(0.0)
        ->and($status->percentUsed())->toBe(100.0);
});

test('monthly CI — entries before budget start date are excluded', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $project = Project::factory()->create([
                'budget_type' => BudgetType::MonthlyCi,
        'budget_amount' => 500.00,
        'budget_starts_on' => '2026-04-01',
    ]);

    budgetTestEntry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => '2026-03-15', 'hours' => 5, 'billable_amount' => 500]);
    budgetTestEntry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => '2026-04-15', 'hours' => 2, 'billable_amount' => 200]);

    $status = (new ProjectBudgetCalculator)->forProject($project, CarbonImmutable::parse('2026-04-30'));

    expect($status->actualAmount)->toBe(200.0)
        ->and($status->actualHours)->toBe(2.0);
});

test('non-billable time entries are excluded from actuals', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $project = Project::factory()->create([
                'budget_type' => BudgetType::FixedFee,
        'budget_amount' => 1000.00,
        'starts_on' => '2026-01-01',
    ]);

    budgetTestEntry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 5, 'billable_amount' => 500]);
    budgetTestEntry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 10, 'is_billable' => false, 'billable_amount' => 0]);

    $status = (new ProjectBudgetCalculator)->forProject($project);

    expect($status->actualAmount)->toBe(500.0)
        ->and($status->actualHours)->toBe(5.0);
});

test('forProjects batches lookups and returns map keyed by id', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $a = Project::factory()->create([
                'budget_type' => BudgetType::FixedFee,
        'budget_amount' => 1000.00,
        'starts_on' => '2026-01-01',
    ]);
    $b = Project::factory()->create([
                'budget_type' => BudgetType::MonthlyCi,
        'budget_amount' => 200.00,
        'budget_starts_on' => '2026-04-01',
    ]);
    $c = Project::factory()->create([]); // no budget

    budgetTestEntry(['user_id' => $user->id, 'project_id' => $a->id, 'task_id' => $task->id, 'hours' => 3, 'billable_amount' => 300]);
    budgetTestEntry(['user_id' => $user->id, 'project_id' => $b->id, 'task_id' => $task->id, 'spent_on' => '2026-04-10', 'hours' => 1, 'billable_amount' => 100]);

    $result = (new ProjectBudgetCalculator)->forProjects(
        collect([$a, $b, $c]),
        CarbonImmutable::parse('2026-04-30'),
    );

    expect($result)->toHaveKey($a->id)
        ->and($result)->toHaveKey($b->id)
        ->and($result)->not->toHaveKey($c->id)
        ->and($result[$a->id]->actualAmount)->toBe(300.0)
        ->and($result[$b->id]->budgetAmount)->toBe(200.0)
        ->and($result[$b->id]->actualAmount)->toBe(100.0);
});

test('monthly breakdown produces one row per month with running totals', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $project = Project::factory()->create([
                'budget_type' => BudgetType::MonthlyCi,
        'budget_amount' => 500.00,
        'budget_starts_on' => '2026-04-01',
    ]);

    budgetTestEntry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => '2026-04-15', 'hours' => 4, 'billable_amount' => 400]);
    budgetTestEntry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => '2026-05-15', 'hours' => 6, 'billable_amount' => 600]);

    $rows = (new ProjectBudgetCalculator)->monthlyBreakdown($project, CarbonImmutable::parse('2026-05-31'));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->month_amount)->toBe(400.0)
        ->and($rows[0]->running_variance)->toBe(100.0)
        ->and($rows[1]->month_amount)->toBe(600.0)
        ->and($rows[1]->running_budget)->toBe(1000.0)
        ->and($rows[1]->running_variance)->toBe(0.0);
});
