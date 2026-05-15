<?php

use App\Enums\Role;
use App\Livewire\Schedule\ScheduleBoard;
use App\Models\Project;
use App\Models\ScheduleAssignment;
use App\Models\SchedulePlaceholder;
use App\Models\ScheduleTimeOff;
use App\Models\Team;
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

test('url backed state controls schedule view defaults', function () {
    $manager = User::factory()->manager()->create();
    $project = Project::factory()->create();
    $team = Team::factory()->create();

    Livewire::actingAs($manager)
        ->test(ScheduleBoard::class)
        ->assertSet('viewMode', 'team')
        ->assertSeeInOrder(['Team', 'Projects']);

    Livewire::actingAs($manager)
        ->withQueryParams([
            'view' => 'projects',
            'scale' => 'month',
            'date' => '2026-05-15',
            'heatmap' => 'capacity',
            'role' => 'Developer',
            'team' => (string) $team->id,
            'project' => (string) $project->id,
        ])
        ->test(ScheduleBoard::class)
        ->assertSet('viewMode', 'projects')
        ->assertSet('scale', 'month')
        ->assertSet('selectedDate', '2026-05-15')
        ->assertSet('heatmapMetric', 'capacity')
        ->assertSet('roleFilter', 'Developer')
        ->assertSet('teamFilter', (string) $team->id)
        ->assertSet('projectFilter', (string) $project->id);
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

test('admin must explicitly attach a non-team user before scheduling them', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create();

    Livewire::actingAs($admin)
        ->test(ScheduleBoard::class)
        ->set('assignmentProjectId', $project->id)
        ->set('assignmentAssigneeType', 'user')
        ->set('assignmentUserId', $user->id)
        ->set('assignmentStartsOn', '2026-05-11')
        ->set('assignmentEndsOn', '2026-05-15')
        ->set('assignmentHoursPerDay', '6')
        ->set('addUserToProjectTeam', false)
        ->call('saveAssignment')
        ->assertHasErrors(['addUserToProjectTeam']);

    $this->assertDatabaseMissing('schedule_assignments', [
        'project_id' => $project->id,
        'user_id' => $user->id,
    ]);
});

test('admin creates placeholders and time off', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['name' => 'Sam Producer']);

    Livewire::actingAs($admin)
        ->test(ScheduleBoard::class)
        ->set('placeholderName', 'Contract Designer')
        ->set('placeholderRoleTitle', 'Design')
        ->set('placeholderWeeklyCapacity', '32')
        ->set('placeholderWorkDays', [1, 2, 3, 4])
        ->call('savePlaceholder')
        ->assertHasNoErrors();

    $placeholder = SchedulePlaceholder::firstOrFail();
    expect($placeholder->effectiveScheduleWorkDays())->toBe([1, 2, 3, 4]);

    Livewire::actingAs($admin)
        ->test(ScheduleBoard::class)
        ->set('timeOffUserId', $user->id)
        ->set('timeOffStartsOn', '2026-05-13')
        ->set('timeOffEndsOn', '2026-05-13')
        ->set('timeOffHoursPerDay', '8')
        ->set('timeOffLabel', 'Holiday')
        ->call('saveTimeOff')
        ->assertHasNoErrors();

    $timeOff = ScheduleTimeOff::firstOrFail();
    expect($timeOff->user_id)->toBe($user->id);
    expect($timeOff->starts_on->toDateString())->toBe('2026-05-13');
    expect($timeOff->label)->toBe('Holiday');
});

test('team schedule can be filtered by role title', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['name' => 'Dev One', 'role_title' => 'Developer']);
    User::factory()->create(['name' => 'Design One', 'role_title' => 'Designer']);
    SchedulePlaceholder::factory()->create(['name' => 'Dev Placeholder', 'role_title' => 'Senior Developer']);

    Livewire::actingAs($admin)
        ->withQueryParams(['view' => 'team', 'role' => 'Developer'])
        ->test(ScheduleBoard::class)
        ->assertSee('Dev One')
        ->assertSee('Dev Placeholder')
        ->assertDontSee('Design One');
});

test('grouped schedule filter controls metric role team and project filters', function () {
    $admin = User::factory()->admin()->create();
    $team = Team::factory()->create();
    $project = Project::factory()->create();

    Livewire::actingAs($admin)
        ->test(ScheduleBoard::class)
        ->set('scheduleFilter', 'metric:capacity')
        ->assertSet('heatmapMetric', 'capacity')
        ->assertSet('roleFilter', '')
        ->set('scheduleFilter', 'role:Developer')
        ->assertSet('roleFilter', 'Developer')
        ->assertSet('teamFilter', '')
        ->assertSet('projectFilter', '')
        ->set('scheduleFilter', 'team:'.$team->id)
        ->assertSet('roleFilter', '')
        ->assertSet('teamFilter', (string) $team->id)
        ->set('scheduleFilter', 'project:'.$project->id)
        ->assertSet('teamFilter', '')
        ->assertSet('projectFilter', (string) $project->id)
        ->set('scheduleFilter', 'filter:all')
        ->assertSet('projectFilter', '');
});

test('team schedule can be filtered by assigned team', function () {
    $admin = User::factory()->admin()->create();
    $development = Team::factory()->create(['name' => 'Development']);
    $design = Team::factory()->create(['name' => 'Design']);
    $developer = User::factory()->create(['name' => 'Morgan Developer']);
    $designer = User::factory()->create(['name' => 'Oscar Designer']);
    $developer->teams()->attach($development->id);
    $designer->teams()->attach($design->id);

    Livewire::actingAs($admin)
        ->withQueryParams(['view' => 'team', 'team' => (string) $development->id])
        ->test(ScheduleBoard::class)
        ->assertSee('Morgan Developer')
        ->assertDontSee('Oscar Designer');
});

test('team schedule can be filtered by project membership or scheduled project work', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create(['name' => 'Alpha Project']);
    $otherProject = Project::factory()->create(['name' => 'Beta Project']);
    $member = User::factory()->create(['name' => 'Alpha Member']);
    $scheduledOnly = User::factory()->create(['name' => 'Alpha Scheduled']);
    $other = User::factory()->create(['name' => 'Beta Member']);
    $placeholder = SchedulePlaceholder::factory()->create(['name' => 'Alpha Placeholder']);

    $project->users()->attach($member->id, ['hourly_rate_override' => null, 'rate_id' => null]);
    $otherProject->users()->attach($other->id, ['hourly_rate_override' => null, 'rate_id' => null]);
    ScheduleAssignment::factory()->create(['project_id' => $project->id, 'user_id' => $scheduledOnly->id]);
    ScheduleAssignment::factory()->create(['project_id' => $project->id, 'schedule_placeholder_id' => $placeholder->id]);

    Livewire::actingAs($admin)
        ->withQueryParams(['view' => 'team', 'project' => (string) $project->id])
        ->test(ScheduleBoard::class)
        ->assertSee('Alpha Member')
        ->assertSee('Alpha Scheduled')
        ->assertSee('Alpha Placeholder')
        ->assertDontSee('Beta Member');
});

test('drag move places an assignment on the dropped period and preserves duration', function () {
    $admin = User::factory()->admin()->create();
    $assignment = ScheduleAssignment::factory()->create([
        'starts_on' => '2026-05-11',
        'ends_on' => '2026-05-15',
    ]);

    Livewire::actingAs($admin)
        ->test(ScheduleBoard::class)
        ->call('moveAssignmentToPeriod', $assignment->id, '2026-05-18');

    $assignment->refresh();
    expect($assignment->starts_on->toDateString())->toBe('2026-05-18');
    expect($assignment->ends_on->toDateString())->toBe('2026-05-22');
});

test('drag move from a visible middle segment moves that segment without copying the source period', function () {
    $admin = User::factory()->admin()->create();
    $assignment = ScheduleAssignment::factory()->create([
        'starts_on' => '2026-05-11',
        'ends_on' => '2026-05-29',
    ]);

    Livewire::actingAs($admin)
        ->test(ScheduleBoard::class)
        ->call('moveAssignmentToPeriod', $assignment->id, '2026-06-01', '2026-05-18');

    $ranges = ScheduleAssignment::query()
        ->where('project_id', $assignment->project_id)
        ->where('user_id', $assignment->user_id)
        ->orderBy('starts_on')
        ->get()
        ->map(fn (ScheduleAssignment $assignment) => [
            $assignment->starts_on->toDateString(),
            $assignment->ends_on->toDateString(),
        ])
        ->all();

    expect($ranges)->toBe([
        ['2026-05-11', '2026-05-17'],
        ['2026-05-25', '2026-05-29'],
        ['2026-06-01', '2026-06-07'],
    ]);
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

test('drag move to another user can reassign a whole assignment', function () {
    $admin = User::factory()->admin()->create();
    $sourceUser = User::factory()->create();
    $targetUser = User::factory()->create();
    $assignment = ScheduleAssignment::factory()->create([
        'user_id' => $sourceUser->id,
        'starts_on' => '2026-05-11',
        'ends_on' => '2026-05-15',
    ]);

    Livewire::actingAs($admin)
        ->test(ScheduleBoard::class)
        ->call('moveAssignmentToPeriod', $assignment->id, '2026-05-18', null, 'user', $targetUser->id)
        ->assertHasNoErrors();

    $assignment->refresh();
    expect($assignment->user_id)->toBe($targetUser->id);
    expect($assignment->starts_on->toDateString())->toBe('2026-05-18');
    expect($assignment->ends_on->toDateString())->toBe('2026-05-22');
});

test('split assignments for the same person and project stay on one team row', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Monthly Care Retainer']);

    ScheduleAssignment::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'starts_on' => '2026-05-11',
        'ends_on' => '2026-05-17',
    ]);
    ScheduleAssignment::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'starts_on' => '2026-05-25',
        'ends_on' => '2026-05-29',
    ]);
    ScheduleAssignment::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'starts_on' => '2026-06-01',
        'ends_on' => '2026-06-07',
    ]);

    $html = Livewire::actingAs($admin)
        ->withQueryParams(['view' => 'team', 'date' => '2026-05-15'])
        ->test(ScheduleBoard::class)
        ->html();

    expect(substr_count($html, '>Monthly Care Retainer</div>'))->toBe(1);
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

test('archived projects and inactive users are hidden from the board', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['name' => 'Active Person', 'is_active' => true]);
    User::factory()->create(['name' => 'Inactive Person', 'is_active' => false]);
    Project::factory()->create(['name' => 'Active Project', 'is_archived' => false]);
    Project::factory()->create(['name' => 'Archived Project', 'is_archived' => true]);

    Livewire::actingAs($admin)
        ->test(ScheduleBoard::class)
        ->set('viewMode', 'team')
        ->assertSee('Active Person')
        ->assertDontSee('Inactive Person')
        ->set('viewMode', 'projects')
        ->assertSee('Active Project')
        ->assertDontSee('Archived Project');
});
