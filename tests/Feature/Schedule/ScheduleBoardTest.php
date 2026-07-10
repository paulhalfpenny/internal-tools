<?php

use App\Enums\Role;
use App\Livewire\Schedule\ScheduleBoard;
use App\Models\Project;
use App\Models\ScheduleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('schedule route is visible to managers and admins only', function () {
    $user = User::factory()->create(['role' => Role::User]);
    $manager = User::factory()->manager()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($user)->get(route('schedule'))->assertForbidden();
    $this->actingAs($manager)->get(route('schedule'))->assertOk();
    $this->actingAs($admin)->get(route('schedule'))->assertOk();
});

test('manager can view but not mutate schedule data', function () {
    $manager = User::factory()->manager()->create();

    Livewire::actingAs($manager)
        ->test(ScheduleBoard::class)
        ->call('openAssignmentModal')
        ->assertForbidden();
});

test('admin creates a user assignment and can attach the user to the project team', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['name' => 'Ava Designer']);
    $project = Project::factory()->create(['name' => 'Website Refresh']);

    Livewire::actingAs($admin)
        ->test(ScheduleBoard::class)
        ->set('assignmentProjectId', $project->id)
        ->set('assignmentAssigneeType', 'user')
        ->set('assignmentUserId', $user->id)
        ->set('assignmentStartsOn', '2026-05-11')
        ->set('assignmentEndsOn', '2026-05-15')
        ->set('assignmentHoursPerDay', '6')
        ->set('addUserToProjectTeam', true)
        ->call('saveAssignment')
        ->assertHasNoErrors();

    $assignment = ScheduleAssignment::firstOrFail();
    expect($assignment->project_id)->toBe($project->id);
    expect($assignment->user_id)->toBe($user->id);
    expect($assignment->starts_on->toDateString())->toBe('2026-05-11');
    expect($assignment->ends_on->toDateString())->toBe('2026-05-15');

    expect(DB::table('project_user')
        ->where('project_id', $project->id)
        ->where('user_id', $user->id)
        ->exists())->toBeTrue();
});

test('drag move to another user reassigns the moved segment', function () {
    $admin = User::factory()->admin()->create();
    $sourceUser = User::factory()->create();
    $targetUser = User::factory()->create();
    $project = Project::factory()->create();
    $assignment = ScheduleAssignment::factory()->create([
        'project_id' => $project->id,
        'user_id' => $sourceUser->id,
        'starts_on' => '2026-05-11',
        'ends_on' => '2026-05-29',
    ]);

    Livewire::actingAs($admin)
        ->test(ScheduleBoard::class)
        ->call('moveAssignmentToPeriod', $assignment->id, '2026-06-01', '2026-05-18', 'user', $targetUser->id)
        ->assertHasNoErrors();

    $sourceRanges = ScheduleAssignment::query()
        ->where('project_id', $project->id)
        ->where('user_id', $sourceUser->id)
        ->orderBy('starts_on')
        ->get()
        ->map(fn (ScheduleAssignment $assignment) => [
            $assignment->starts_on->toDateString(),
            $assignment->ends_on->toDateString(),
        ])
        ->all();
    $targetAssignment = ScheduleAssignment::query()
        ->where('project_id', $project->id)
        ->where('user_id', $targetUser->id)
        ->firstOrFail();

    expect($sourceRanges)->toBe([
        ['2026-05-11', '2026-05-17'],
        ['2026-05-25', '2026-05-29'],
    ]);
    expect($targetAssignment->starts_on->toDateString())->toBe('2026-06-01');
    expect($targetAssignment->ends_on->toDateString())->toBe('2026-06-07');
    expect(DB::table('project_user')
        ->where('project_id', $project->id)
        ->where('user_id', $targetUser->id)
        ->exists())->toBeTrue();
});

test('shift timeline moves future assignments for one project', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $past = ScheduleAssignment::factory()->create([
        'project_id' => $project->id,
        'starts_on' => '2026-05-01',
        'ends_on' => '2026-05-05',
    ]);
    $future = ScheduleAssignment::factory()->create([
        'project_id' => $project->id,
        'starts_on' => '2026-05-15',
        'ends_on' => '2026-05-19',
    ]);
    $other = ScheduleAssignment::factory()->create([
        'project_id' => $otherProject->id,
        'starts_on' => '2026-05-15',
        'ends_on' => '2026-05-19',
    ]);

    Livewire::actingAs($admin)
        ->test(ScheduleBoard::class)
        ->set('shiftProjectId', $project->id)
        ->set('shiftFromDate', '2026-05-15')
        ->set('shiftNewStartDate', '2026-05-22')
        ->call('shiftTimeline')
        ->assertHasNoErrors();

    expect($past->fresh()->starts_on->toDateString())->toBe('2026-05-01');
    expect($future->fresh()->starts_on->toDateString())->toBe('2026-05-22');
    expect($future->fresh()->ends_on->toDateString())->toBe('2026-05-26');
    expect($other->fresh()->starts_on->toDateString())->toBe('2026-05-15');
});
