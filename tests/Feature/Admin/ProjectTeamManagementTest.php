<?php

use App\Enums\Role;
use App\Livewire\Admin\Projects\Edit;
use App\Livewire\Timesheet\DayView;
use App\Livewire\Timesheet\WeekView;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('confirmAddUsers ignores users already assigned to the project', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);
    $existing = User::factory()->create();
    $project->users()->attach($existing->id, ['hourly_rate_override' => 50.00, 'rate_id' => null]);

    Livewire::test(Edit::class, ['project' => $project])
        ->call('openAddUserModal')
        ->set('pendingNewUserDropdown', $existing->id)
        ->call('confirmAddUsers');

    // Pivot row should still have the original 50.00 override, not be re-attached with null.
    $pivot = $project->fresh()->users()->where('users.id', $existing->id)->first()->pivot;
    expect((float) $pivot->hourly_rate_override)->toBe(50.00);
});

test('confirmAddUsers refreshes the new users cached project pickers', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $user = User::factory()->create(['role' => Role::User]);
    $project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);

    $this->actingAs($user);

    $initialDayProjects = Livewire::test(DayView::class)
        ->call('openNewModal')
        ->viewData('projectsForPicker');
    $initialWeekProjects = Livewire::test(WeekView::class)
        ->call('openAddRowModal')
        ->viewData('projectsForPicker');

    expect(collect($initialDayProjects)->pluck('id'))->not->toContain($project->id)
        ->and(collect($initialWeekProjects)->pluck('id'))->not->toContain($project->id);

    $this->actingAs($admin);

    Livewire::test(Edit::class, ['project' => $project])
        ->call('openAddUserModal')
        ->set('pendingNewUserDropdown', $user->id)
        ->call('confirmAddUsers');

    $this->actingAs($user);

    $dayProjects = Livewire::test(DayView::class)
        ->call('openNewModal')
        ->viewData('projectsForPicker');
    $weekProjects = Livewire::test(WeekView::class)
        ->call('openAddRowModal')
        ->viewData('projectsForPicker');

    expect(collect($dayProjects)->pluck('id'))->toContain($project->id)
        ->and(collect($weekProjects)->pluck('id'))->toContain($project->id);
});
