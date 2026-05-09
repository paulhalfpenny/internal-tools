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
use Illuminate\Support\LazyCollection;

uses(RefreshDatabase::class);

// ─── helpers ────────────────────────────────────────────────────────────────

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

// ─── totals ─────────────────────────────────────────────────────────────────

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

test('totals returns empty DTO when no entries in range', function () {
    $totals = (new TimeReportQuery(
        from: CarbonImmutable::parse('2025-01-01'),
        to: CarbonImmutable::parse('2025-01-31'),
    ))->totals();

    expect($totals->totalHours)->toBe(0.0)
        ->and($totals->billableAmount)->toBe(0.0);
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

test('totals excludes entries outside the date range', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([]);
    $task = Task::factory()->create();

    entry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 5.0, 'spent_on' => '2026-03-31']); // outside
    entry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 2.0, 'spent_on' => '2026-04-01']); // inside

    $totals = (new TimeReportQuery(
        from: CarbonImmutable::parse('2026-04-01'),
        to: CarbonImmutable::parse('2026-04-30'),
    ))->totals();

    expect($totals->totalHours)->toBe(2.0);
});

// ─── groupBy ────────────────────────────────────────────────────────────────

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

test('groupBy Project returns rows ordered by client then project', function () {
    $client = Client::factory()->create(['name' => 'Client A']);
    $p1 = Project::factory()->create(['client_id' => $client->id, 'name' => 'Alpha']);
    $p2 = Project::factory()->create(['client_id' => $client->id, 'name' => 'Beta']);
    $task = Task::factory()->create();
    $user = User::factory()->create();

    entry(['user_id' => $user->id, 'project_id' => $p2->id, 'task_id' => $task->id, 'hours' => 2.0, 'billable_amount' => 168.0]);
    entry(['user_id' => $user->id, 'project_id' => $p1->id, 'task_id' => $task->id, 'hours' => 1.0, 'billable_amount' => 84.0]);

    $rows = (new TimeReportQuery(
        from: CarbonImmutable::parse('2026-04-01'),
        to: CarbonImmutable::parse('2026-04-30'),
    ))->groupBy(GroupBy::Project);

    expect($rows->first()->label)->toBe('Alpha'); // Alpha before Beta
});

test('groupBy Task includes colour field', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([]);
    $task = Task::factory()->create(['name' => 'Development', 'colour' => '#10B981']);

    entry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 4.0, 'billable_amount' => 336.0]);

    $rows = (new TimeReportQuery(
        from: CarbonImmutable::parse('2026-04-01'),
        to: CarbonImmutable::parse('2026-04-30'),
    ))->groupBy(GroupBy::Task);

    expect($rows->first()->colour)->toBe('#10B981')
        ->and($rows->first()->total_hours)->toBe(4.0);
});

test('groupBy User aggregates per user', function () {
    $u1 = User::factory()->create(['name' => 'Alice']);
    $u2 = User::factory()->create(['name' => 'Bob']);
    $project = Project::factory()->create([]);
    $task = Task::factory()->create();

    entry(['user_id' => $u1->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 5.0, 'billable_amount' => 420.0]);
    entry(['user_id' => $u2->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 3.0, 'billable_amount' => 252.0]);

    $rows = (new TimeReportQuery(
        from: CarbonImmutable::parse('2026-04-01'),
        to: CarbonImmutable::parse('2026-04-30'),
    ))->groupBy(GroupBy::User);

    expect($rows)->toHaveCount(2);
    $alice = $rows->firstWhere('label', 'Alice');
    expect($alice->total_hours)->toBe(5.0);
});

test('billableOnly filter excludes non-billable entries from totals', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([]);
    $task = Task::factory()->create();

    entry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 2.0, 'is_billable' => true, 'billable_amount' => 168.0]);
    entry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 3.0, 'is_billable' => false, 'billable_amount' => 0.0]);

    $totals = (new TimeReportQuery(
        from: CarbonImmutable::parse('2026-04-01'),
        to: CarbonImmutable::parse('2026-04-30'),
        billableOnly: true,
    ))->totals();

    expect($totals->totalHours)->toBe(2.0);
});

// ─── entries stream ──────────────────────────────────────────────────────────

test('entries returns a LazyCollection of TimeEntry models', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([]);
    $task = Task::factory()->create();

    entry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 1.0]);
    entry(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'hours' => 2.0]);

    $entries = (new TimeReportQuery(
        from: CarbonImmutable::parse('2026-04-01'),
        to: CarbonImmutable::parse('2026-04-30'),
    ))->entries();

    expect($entries)->toBeInstanceOf(LazyCollection::class);
    expect($entries->count())->toBe(2);
    expect($entries->first())->toBeInstanceOf(TimeEntry::class);
});
