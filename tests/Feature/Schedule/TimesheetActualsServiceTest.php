<?php

use App\Domain\Schedule\TimesheetActualsService;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

$weekPeriods = [
    ['index' => 0, 'starts_on' => '2026-05-11', 'ends_on' => '2026-05-17'],
    ['index' => 1, 'starts_on' => '2026-05-18', 'ends_on' => '2026-05-24'],
    ['index' => 2, 'starts_on' => '2026-05-25', 'ends_on' => '2026-05-31'],
];

test('actualsByProjectForPeriods sums hours across users into the correct period bucket', function () use ($weekPeriods) {
    $service = app(TimesheetActualsService::class);
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    TimeEntry::factory()->create(['project_id' => $project->id, 'user_id' => $alice->id, 'spent_on' => '2026-05-12', 'hours' => 3.0]);
    TimeEntry::factory()->create(['project_id' => $project->id, 'user_id' => $bob->id, 'spent_on' => '2026-05-13', 'hours' => 2.5]);
    TimeEntry::factory()->create(['project_id' => $project->id, 'user_id' => $alice->id, 'spent_on' => '2026-05-20', 'hours' => 4.0]);
    TimeEntry::factory()->create(['project_id' => $other->id, 'user_id' => $alice->id, 'spent_on' => '2026-05-12', 'hours' => 9.0]);
    TimeEntry::factory()->create(['project_id' => $project->id, 'user_id' => $alice->id, 'spent_on' => '2026-06-10', 'hours' => 99.0]);

    $result = $service->actualsByProjectForPeriods([$project->id, $other->id], $weekPeriods);

    expect($result[$project->id][0])->toBe(5.5);
    expect($result[$project->id][1])->toBe(4.0);
    expect($result[$project->id][2] ?? null)->toBeNull();
    expect($result[$other->id][0])->toBe(9.0);
});

test('lifetimeActualsByProject returns total hours regardless of date', function () {
    $service = app(TimesheetActualsService::class);
    $project = Project::factory()->create();

    TimeEntry::factory()->create(['project_id' => $project->id, 'spent_on' => '2020-01-01', 'hours' => 5.0]);
    TimeEntry::factory()->create(['project_id' => $project->id, 'spent_on' => '2026-05-12', 'hours' => 3.0]);

    expect($service->lifetimeActualsByProject([$project->id]))->toBe([$project->id => 8.0]);
});

test('actualsByUserForPeriods buckets per user per period', function () use ($weekPeriods) {
    $service = app(TimesheetActualsService::class);
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    TimeEntry::factory()->create(['user_id' => $alice->id, 'spent_on' => '2026-05-12', 'hours' => 7.5]);
    TimeEntry::factory()->create(['user_id' => $alice->id, 'spent_on' => '2026-05-20', 'hours' => 2.0]);
    TimeEntry::factory()->create(['user_id' => $bob->id, 'spent_on' => '2026-05-13', 'hours' => 4.0]);

    $result = $service->actualsByUserForPeriods([$alice->id, $bob->id], $weekPeriods);

    expect($result[$alice->id][0])->toBe(7.5);
    expect($result[$alice->id][1])->toBe(2.0);
    expect($result[$bob->id][0])->toBe(4.0);
});

test('empty inputs short-circuit without querying', function () {
    $service = app(TimesheetActualsService::class);

    expect($service->actualsByProjectForPeriods([], [['index' => 0, 'starts_on' => '2026-05-11', 'ends_on' => '2026-05-17']]))->toBe([]);
    expect($service->lifetimeActualsByProject([]))->toBe([]);
    expect($service->actualsByUserForPeriods([], [['index' => 0, 'starts_on' => '2026-05-11', 'ends_on' => '2026-05-17']]))->toBe([]);
});
