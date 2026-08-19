<?php

use App\Domain\Schedule\TimesheetActualsService;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

test('lifetime actuals are cached and invalidated by every schedule-relevant time entry mutation', function () {
    Cache::flush();

    $service = app(TimesheetActualsService::class);
    $firstProject = Project::factory()->create();
    $secondProject = Project::factory()->create();
    $user = User::factory()->create();
    $entry = TimeEntry::factory()->create(['project_id' => $firstProject->id, 'user_id' => $user->id, 'hours' => 2.5]);

    expect($service->lifetimeActualsByProject([$firstProject->id, $secondProject->id]))->toBe([
        $firstProject->id => 2.5,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($service->lifetimeActualsByProject([$secondProject->id, $firstProject->id]))->toBe([
        $firstProject->id => 2.5,
    ]);

    $aggregateQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql) => str_contains(strtolower($sql), 'sum(hours)'));

    expect($aggregateQueries)->toHaveCount(0);
    DB::disableQueryLog();

    $imported = TimeEntry::factory()->create(['project_id' => $secondProject->id, 'user_id' => $user->id, 'hours' => 1.25]);
    expect($service->lifetimeActualsByProject([$firstProject->id, $secondProject->id]))->toBe([
        $firstProject->id => 2.5,
        $secondProject->id => 1.25,
    ]);

    $entry->update(['hours' => 3.5]);
    expect($service->lifetimeActualsByProject([$firstProject->id, $secondProject->id]))->toBe([
        $firstProject->id => 3.5,
        $secondProject->id => 1.25,
    ]);

    $entry->update(['project_id' => $secondProject->id]);
    expect($service->lifetimeActualsByProject([$firstProject->id, $secondProject->id]))->toBe([
        $secondProject->id => 4.75,
    ]);

    $imported->delete();
    expect($service->lifetimeActualsByProject([$firstProject->id, $secondProject->id]))->toBe([
        $secondProject->id => 3.5,
    ]);
});

test('lifetime actuals cache a large project list within the database key constraint', function () {
    DB::statement('CREATE TABLE schedule_actuals_cache ("key" varchar(255) NOT NULL CHECK (length("key") <= 255), value TEXT NOT NULL, expiration INTEGER NOT NULL, PRIMARY KEY ("key"))');

    config()->set('cache.stores.schedule_actuals', [
        'driver' => 'database',
        'connection' => null,
        'table' => 'schedule_actuals_cache',
        'lock_connection' => null,
        'lock_table' => null,
    ]);
    Cache::setDefaultDriver('schedule_actuals');
    Cache::forgetDriver('schedule_actuals');

    expect(app(TimesheetActualsService::class)->lifetimeActualsByProject(range(1, 111)))->toBe([]);
});
