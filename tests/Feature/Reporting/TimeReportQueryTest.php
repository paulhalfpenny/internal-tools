<?php

use App\Domain\Reporting\TimeReportQuery;
use App\Enums\GroupBy;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function entry(array $attrs): TimeEntry
{
    return TimeEntry::create(array_merge([
        'spent_on' => '2026-04-01',
        'hours' => 1.0,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 84.0,
        'billable_amount' => 84.0,
        'invoiced_at' => null,
    ], $attrs));
}

test('totals sums hours and billable amounts correctly', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([]);
    $task = Task::factory()->create();

    entry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 2.0, 'billable_amount' => 168.0]);
    entry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 1.5, 'billable_amount' => 126.0]);
    entry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 0.5, 'is_billable' => false, 'billable_amount' => 0.0]);

    $totals = (new TimeReportQuery(
        from: CarbonImmutable::parse('2026-04-01'),
        to: CarbonImmutable::parse('2026-04-30'),
    ))->totals();

    expect($totals->totalHours)->toBe(4.0)
        ->and($totals->billableHours)->toBe(3.5)
        ->and($totals->billableAmount)->toBe(294.0)
        ->and($totals->billablePercent)->toBe(87.5);
});

test('totals uninvoiced_amount excludes invoiced entries', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([]);
    $task = Task::factory()->create();

    entry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 1.0, 'billable_amount' => 84.0]);
    entry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 1.0, 'billable_amount' => 84.0, 'invoiced_at' => now()]);

    $totals = (new TimeReportQuery(
        from: CarbonImmutable::parse('2026-04-01'),
        to: CarbonImmutable::parse('2026-04-30'),
    ))->totals();

    expect($totals->billableAmount)->toBe(168.0)
        ->and($totals->uninvoicedAmount)->toBe(84.0); // only the non-invoiced one
});

test('totals filters by user_id', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $project = Project::factory()->create([]);
    $task = Task::factory()->create();

    entry(['user_id' => $u1->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 3.0, 'billable_amount' => 252.0]);
    entry(['user_id' => $u2->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 2.0, 'billable_amount' => 168.0]);

    $totals = (new TimeReportQuery(
        from: CarbonImmutable::parse('2026-04-01'),
        to: CarbonImmutable::parse('2026-04-30'),
        userId: $u1->id,
    ))->totals();

    expect($totals->totalHours)->toBe(3.0);
});

test('totals includes entries on the final date of the range', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([]);
    $task = Task::factory()->create();

    entry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 2.5, 'spent_on' => '2026-04-30']);

    $totals = (new TimeReportQuery(
        from: CarbonImmutable::parse('2026-04-01'),
        to: CarbonImmutable::parse('2026-04-30'),
    ))->totals();

    expect($totals->totalHours)->toBe(2.5);
});

test('groupBy Client aggregates correctly', function () {
    $c1 = Client::factory()->create(['name' => 'Acme']);
    $c2 = Client::factory()->create(['name' => 'Zeta']);
    $p1 = Project::factory()->create(['client_id' => $c1->id]);
    $p2 = Project::factory()->create(['client_id' => $c2->id]);
    $task = Task::factory()->create();
    $user = User::factory()->create();

    entry(['user_id' => $user->id, 'project_id' => $p1->id, 'task_id' => $task->id, 'hours' => 3.0, 'billable_amount' => 252.0]);
    entry(['user_id' => $user->id, 'project_id' => $p2->id, 'task_id' => $task->id, 'hours' => 1.0, 'billable_amount' => 84.0]);

    $rows = (new TimeReportQuery(
        from: CarbonImmutable::parse('2026-04-01'),
        to: CarbonImmutable::parse('2026-04-30'),
    ))->groupBy(GroupBy::Client);

    expect($rows)->toHaveCount(2);
    $acme = $rows->firstWhere('label', 'Acme');
    expect($acme->total_hours)->toBe(3.0)
        ->and($acme->billable_amount)->toBe(252.0);
});

test('grouped report derives exact totals from one aggregate query for every grouping', function () {
    $acme = Client::factory()->create(['name' => 'Acme']);
    $zeta = Client::factory()->create(['name' => 'Zeta']);
    $firstProject = Project::factory()->create(['client_id' => $acme->id]);
    $secondProject = Project::factory()->create(['client_id' => $zeta->id]);
    $firstTask = Task::factory()->create();
    $secondTask = Task::factory()->create();
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    entry(['user_id' => $firstUser->id, 'project_id' => $firstProject->id, 'task_id' => $firstTask->id, 'hours' => 2.5, 'billable_amount' => 210.0]);
    entry(['user_id' => $secondUser->id, 'project_id' => $firstProject->id, 'task_id' => $secondTask->id, 'hours' => 2.0, 'billable_amount' => 168.0, 'invoiced_at' => now()]);
    entry(['user_id' => $firstUser->id, 'project_id' => $secondProject->id, 'task_id' => $firstTask->id, 'hours' => 2.0, 'is_billable' => false, 'billable_amount' => 0.0]);

    $query = new TimeReportQuery(
        from: CarbonImmutable::parse('2026-04-01'),
        to: CarbonImmutable::parse('2026-04-30'),
    );

    foreach (GroupBy::cases() as $groupBy) {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $query->groupedReport($groupBy);

        $aggregateQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql) => str_contains(strtolower($sql), 'sum(time_entries.hours)'));

        expect($result->rows)->toHaveCount(2)
            ->and($result->totals->totalHours)->toBe(6.5)
            ->and($result->totals->billableHours)->toBe(4.5)
            ->and($result->totals->billableAmount)->toBe(378.0)
            ->and($result->totals->uninvoicedAmount)->toBe(210.0)
            ->and($aggregateQueries)->toHaveCount(1);

        DB::disableQueryLog();
    }
});
