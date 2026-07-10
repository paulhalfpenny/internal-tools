<?php

use App\Domain\Schedule\ScheduleAvailabilityService;
use App\Models\Project;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleTimeOff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('availability subtracts scheduled work and time off', function () {
    $service = app(ScheduleAvailabilityService::class);
    $project = Project::factory()->create();
    $user = User::factory()->create([
        'weekly_capacity_hours' => 40,
        'schedule_work_days' => [1, 2, 3, 4, 5],
    ]);

    $assignment = ScheduleAssignment::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'starts_on' => '2026-05-11',
        'ends_on' => '2026-05-15',
        'hours_per_day' => 6,
    ]);

    $timeOff = ScheduleTimeOff::factory()->create([
        'user_id' => $user->id,
        'starts_on' => '2026-05-13',
        'ends_on' => '2026-05-13',
        'hours_per_day' => 8,
    ]);

    $summary = $service->summaryForPeriod($user, collect([$assignment->load(['user', 'placeholder'])]), collect([$timeOff->load('user')]), '2026-05-11', '2026-05-17');

    expect($summary['capacity'])->toBe(40.0);
    expect($summary['scheduled'])->toBe(30.0);
    expect($summary['time_off'])->toBe(8.0);
    expect($summary['committed'])->toBe(38.0);
    expect($summary['availability'])->toBe(2.0);
});

test('assignments scheduled on non working days remain visible as over capacity', function () {
    $service = app(ScheduleAvailabilityService::class);
    $project = Project::factory()->create();
    $user = User::factory()->create([
        'weekly_capacity_hours' => 40,
        'schedule_work_days' => [1, 2, 3, 4, 5],
    ]);

    $assignment = ScheduleAssignment::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'starts_on' => '2026-05-16',
        'ends_on' => '2026-05-16',
        'hours_per_day' => 6,
    ]);

    $summary = $service->summaryForPeriod($user, collect([$assignment->load(['user', 'placeholder'])]), collect(), '2026-05-16', '2026-05-16');

    expect($summary['capacity'])->toBe(0.0);
    expect($summary['scheduled'])->toBe(6.0);
    expect($summary['availability'])->toBe(-6.0);
});
