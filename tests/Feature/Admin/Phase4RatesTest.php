<?php

use App\Domain\Billing\RateResolver;
use App\Domain\TimeTracking\TimeEntryService;
use App\Enums\Role;
use App\Livewire\Admin\Rates\Library;
use App\Models\Project;
use App\Models\Rate;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('library page can create and edit rates', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    Livewire::test(Library::class)
        ->set('name', 'Senior Strategy')
        ->set('hourlyRate', '150.00')
        ->call('create');

    expect(Rate::where('name', 'Senior Strategy')->where('hourly_rate', 150.00)->exists())->toBeTrue();
});

test('rate resolver picks library rate over legacy decimal at the project tier', function () {
    $rate = Rate::create(['name' => 'Std Dev', 'hourly_rate' => 85.00]);
    $project = Project::factory()->create([
        'is_billable' => true,
        'default_hourly_rate' => 999.00,  // legacy still set
        'rate_id' => $rate->id,            // library rate wins
    ]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);

    $user = User::factory()->create(['default_hourly_rate' => 50.00]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null, 'rate_id' => null]);

    $project->load(['tasks', 'users']);
    $resolution = (new RateResolver)->resolve($project, $task, $user);

    expect((float) $resolution->rateSnapshot)->toBe(85.00);
});

test('rate resolver falls back to legacy decimal when no rate_id is set', function () {
    $project = Project::factory()->create([
        'is_billable' => true,
        'default_hourly_rate' => 110.00,
        'rate_id' => null,
    ]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);

    $user = User::factory()->create(['default_hourly_rate' => 50.00]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null, 'rate_id' => null]);

    $project->load(['tasks', 'users']);
    $resolution = (new RateResolver)->resolve($project, $task, $user);

    expect((float) $resolution->rateSnapshot)->toBe(110.00);
});

test('project_user library rate beats project library rate', function () {
    $cheap = Rate::create(['name' => 'Cheap', 'hourly_rate' => 50.00]);
    $expensive = Rate::create(['name' => 'Expensive', 'hourly_rate' => 200.00]);

    $project = Project::factory()->create([
        'is_billable' => true,
        'default_hourly_rate' => null,
        'rate_id' => $cheap->id,
    ]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);

    $user = User::factory()->create(['default_hourly_rate' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null, 'rate_id' => $expensive->id]);

    $project->load(['tasks', 'users']);
    $resolution = (new RateResolver)->resolve($project, $task, $user);

    expect((float) $resolution->rateSnapshot)->toBe(200.00);
});

test('user role + project override is the primary resolution path', function () {
    // The simplified mental model: a user has a role (rate library row); each
    // project may override that with a custom £/hr. Resolution should pick the
    // override when set, else fall back to the user's library role rate.
    $standardDev = Rate::create(['name' => 'Standard Dev', 'hourly_rate' => 85.00]);

    $user = User::factory()->create([
        'rate_id' => $standardDev->id,
        'default_hourly_rate' => null,
    ]);

    $project = Project::factory()->create([
        'is_billable' => true,
        'rate_id' => null,
        'default_hourly_rate' => null,
    ]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);

    // No override set — should resolve to user's library role rate
    $project->users()->attach($user->id, ['hourly_rate_override' => null, 'rate_id' => null]);
    $project->load(['tasks', 'users']);
    expect((float) (new RateResolver)->resolve($project, $task, $user)->rateSnapshot)->toBe(85.00);

    // Set a project override — that takes precedence
    $project->users()->updateExistingPivot($user->id, ['hourly_rate_override' => 120.00]);
    $project->load(['tasks', 'users']);
    expect((float) (new RateResolver)->resolve($project, $task, $user)->rateSnapshot)->toBe(120.00);
});

test('editing a library rate does not change historical billable_rate_snapshot', function () {
    $rate = Rate::create(['name' => 'Std', 'hourly_rate' => 100.00]);
    $project = Project::factory()->create(['is_billable' => true, 'default_hourly_rate' => null, 'rate_id' => $rate->id]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['hourly_rate_override' => null, 'rate_id' => null]);

    // Reload project so the resolver sees the attached task and user pivot rows
    $project = $project->fresh(['tasks', 'users']);

    $entry = app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => now()->toDateString(),
        'hours' => 2.0,
        'notes' => null,
    ]);

    expect((float) $entry->billable_rate_snapshot)->toBe(100.00);
    expect((float) $entry->billable_amount)->toBe(200.00);

    $rate->update(['hourly_rate' => 250.00]);
    $entry->refresh();

    // Snapshot is frozen — rate change does not retroactively re-rate the entry
    expect((float) $entry->billable_rate_snapshot)->toBe(100.00);
    expect((float) $entry->billable_amount)->toBe(200.00);
});
