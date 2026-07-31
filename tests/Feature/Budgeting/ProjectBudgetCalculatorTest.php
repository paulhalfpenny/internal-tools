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

test('monthly spend reports billable per-month and cumulative totals without a budget', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $project = Project::factory()->create([
        'budget_type' => null,
        'starts_on' => null,
    ]);

    budgetTestEntry([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => '2026-04-15',
        'hours' => 4,
        'billable_amount' => 400,
    ]);
    budgetTestEntry([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => '2026-05-15',
        'hours' => 6,
        'billable_amount' => 600,
    ]);
    budgetTestEntry([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => '2026-05-20',
        'hours' => 2,
        'is_billable' => false,
        'billable_amount' => 0,
    ]);

    $rows = (new ProjectBudgetCalculator)->monthlySpend($project, CarbonImmutable::parse('2026-05-31'));

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('month_amount')->all())->toBe([400.0, 600.0])
        ->and($rows->pluck('month_hours')->all())->toBe([4.0, 6.0])
        ->and($rows->pluck('running_amount')->all())->toBe([400.0, 1000.0])
        ->and($rows->pluck('running_hours')->all())->toBe([4.0, 10.0]);
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
