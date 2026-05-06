<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimesheetCompletionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function logHours(User $user, string $date, float $hours): void
{
    $projectId = Project::query()->value('id') ?? Project::factory()->create()->id;
    $taskId = Task::query()->value('id') ?? Task::factory()->create()->id;

    TimeEntry::create([
        'user_id' => $user->id,
        'project_id' => $projectId,
        'task_id' => $taskId,
        'spent_on' => $date,
        'hours' => $hours,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 80,
        'billable_amount' => 80 * $hours,
    ]);
}

test('weekly target falls back to 40h when capacity is null or zero', function () {
    $service = app(TimesheetCompletionService::class);

    $user = User::factory()->create(['weekly_capacity_hours' => 0]);

    expect($service->weeklyTarget($user))->toBe(40.0);
});

test('weekly target honours the per-user override', function () {
    $service = app(TimesheetCompletionService::class);

    $user = User::factory()->create(['weekly_capacity_hours' => 30]);

    expect($service->weeklyTarget($user))->toBe(30.0);
});

test('weekly hours sum entries between Mon and Sun', function () {
    $service = app(TimesheetCompletionService::class);
    $user = User::factory()->create();
    $monday = CarbonImmutable::parse('2026-05-04');

    logHours($user, '2026-05-04', 8);
    logHours($user, '2026-05-07', 6.5);
    logHours($user, '2026-05-10', 1);
    logHours($user, '2026-04-30', 4);

    expect($service->weeklyHoursLogged($user, $monday))->toBe(15.5);
});

test('mid-week threshold flags users below 60% of target by Wednesday', function () {
    $service = app(TimesheetCompletionService::class);
    $monday = CarbonImmutable::parse('2026-05-04');

    $behind = User::factory()->create(['name' => 'Behind', 'weekly_capacity_hours' => 40]);
    $onTrack = User::factory()->create(['name' => 'OnTrack', 'weekly_capacity_hours' => 40]);
    $custom = User::factory()->create(['name' => 'Custom', 'weekly_capacity_hours' => 20]);

    logHours($behind, '2026-05-04', 5);
    logHours($behind, '2026-05-05', 5);
    logHours($behind, '2026-05-06', 5);
    logHours($onTrack, '2026-05-04', 8);
    logHours($onTrack, '2026-05-05', 8);
    logHours($onTrack, '2026-05-06', 8);
    logHours($custom, '2026-05-04', 7);
    logHours($custom, '2026-05-05', 7);

    $rows = $service->usersBelowMidWeekThreshold($monday);

    expect($rows->pluck('user.name')->all())->toEqual(['Behind']);
    $behindRow = $rows->first();
    expect($behindRow['hours'])->toBe(15.0);
    expect($behindRow['target'])->toBe(40.0);
    expect($behindRow['threshold'])->toBe(24.0);
});

test('mid-week threshold ignores entries logged on Thu, Fri, Sat, Sun', function () {
    $service = app(TimesheetCompletionService::class);
    $monday = CarbonImmutable::parse('2026-05-04');

    $user = User::factory()->create();
    logHours($user, '2026-05-04', 5);
    logHours($user, '2026-05-07', 30);

    $rows = $service->usersBelowMidWeekThreshold($monday);

    expect($rows->pluck('user.id')->all())->toContain($user->id);
});

test('mid-week threshold skips paused users', function () {
    $service = app(TimesheetCompletionService::class);
    $monday = CarbonImmutable::parse('2026-05-04');

    User::factory()->create([
        'name' => 'OnHoliday',
        'notifications_paused_until' => CarbonImmutable::parse('2026-05-10'),
    ]);

    expect($service->usersBelowMidWeekThreshold($monday))->toBeEmpty();
});

test('mid-week threshold skips inactive users', function () {
    $service = app(TimesheetCompletionService::class);
    $monday = CarbonImmutable::parse('2026-05-04');

    User::factory()->create(['is_active' => false]);

    expect($service->usersBelowMidWeekThreshold($monday))->toBeEmpty();
});

test('weekly overdue flags users who logged less than their target', function () {
    $service = app(TimesheetCompletionService::class);
    $monday = CarbonImmutable::parse('2026-05-04');

    $missed = User::factory()->create(['name' => 'Missed']);
    $hit = User::factory()->create(['name' => 'Hit']);

    foreach (range(0, 4) as $i) {
        logHours($missed, $monday->addDays($i)->toDateString(), 7);
        logHours($hit, $monday->addDays($i)->toDateString(), 8);
    }

    $rows = $service->usersWithIncompleteWeek($monday);

    expect($rows->pluck('user.name')->all())->toEqual(['Missed']);
    expect($rows->first()['hours'])->toBe(35.0);
    expect($rows->first()['target'])->toBe(40.0);
});

test('monthly target scales by working days', function () {
    $service = app(TimesheetCompletionService::class);

    $user = User::factory()->create(['weekly_capacity_hours' => 40]);

    expect($service->monthlyTarget($user, CarbonImmutable::parse('2026-05-01')))
        ->toBe(168.0);
});

test('monthly overdue flags users below pro-rata target', function () {
    $service = app(TimesheetCompletionService::class);

    $under = User::factory()->create();
    $over = User::factory()->create();

    foreach (range(0, 19) as $i) {
        $date = CarbonImmutable::parse('2026-04-01')->addDays($i)->toDateString();
        logHours($under, $date, 4);
        logHours($over, $date, 9);
    }

    $rows = $service->usersWithIncompleteMonth(CarbonImmutable::parse('2026-04-01'));

    expect($rows->pluck('user.id')->all())->toContain($under->id);
    expect($rows->pluck('user.id')->all())->not->toContain($over->id);
});
