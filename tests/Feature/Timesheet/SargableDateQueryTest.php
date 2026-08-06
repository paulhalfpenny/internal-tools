<?php

use App\Livewire\Schedule\ScheduleBoard;
use App\Livewire\Timesheet\DayView;
use App\Models\Project;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleTimeOff;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('DayView uses direct DATE equality and keeps selected-day boundaries exact', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->users()->attach($user);

    TimeEntry::factory()->create(['user_id' => $user->id, 'project_id' => $project->id, 'spent_on' => '2026-05-12', 'notes' => 'Selected day']);
    TimeEntry::factory()->create(['user_id' => $user->id, 'project_id' => $project->id, 'spent_on' => '2026-05-11', 'notes' => 'Previous day']);
    TimeEntry::factory()->create(['user_id' => $user->id, 'project_id' => $project->id, 'spent_on' => '2026-05-13', 'notes' => 'Next day']);

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::actingAs($user)
        ->test(DayView::class)
        ->set('selectedDate', '2026-05-12');

    $timeEntrySql = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql) => str_contains($sql, 'from "time_entries"'))
        ->map(strtolower(...));

    expect($timeEntrySql->join(' '))->toContain('"spent_on" = ?')
        ->and($timeEntrySql->join(' '))->not->toContain('strftime');

    DB::disableQueryLog();
});

test('ScheduleBoard uses direct DATE ranges while retaining overlap boundaries', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $assignment = ScheduleAssignment::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'starts_on' => '2026-05-11',
        'ends_on' => '2026-05-11',
        'notes' => 'Boundary assignment',
    ]);
    $timeOff = ScheduleTimeOff::factory()->create([
        'user_id' => $user->id,
        'starts_on' => '2026-05-17',
        'ends_on' => '2026-05-17',
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $html = Livewire::actingAs($admin)
        ->test(ScheduleBoard::class)
        ->set('selectedDate', '2026-05-11')
        ->html();

    $scheduleSql = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql) => str_contains($sql, 'from "schedule_'))
        ->map(strtolower(...));

    expect($html)->toContain('Boundary assignment')
        ->and($html)->toContain('Time off')
        ->and($scheduleSql->join(' '))->toContain('"ends_on" >= ?')
        ->and($scheduleSql->join(' '))->toContain('"starts_on" <= ?')
        ->and($scheduleSql->join(' '))->not->toContain('strftime');

    DB::disableQueryLog();
});
