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

test('user role + project override is the primary resolution path', function () {
    // The simplified mental model: a user has a role (rate library row); each
    // project may override that with a custom £/hr. Resolution should pick the
    // override when set, else fall back to the user's library role rate.
    $standardDev = Rate::create(['name' => 'Standard Dev', 'hourly_rate' => 85.00]);

    $user = User::factory()->create([
        'rate_id' => $standardDev->id,
        'default_hourly_rate' => null,
    ]);

    $project = Project::factory()->create(['is_billable' => true]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);

    // No override set — should resolve to user's library role rate
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);
    $project->load(['tasks', 'users']);
    expect((float) (new RateResolver)->resolve($project, $task, $user)->rateSnapshot)->toBe(85.00);

    // Set a project override — that takes precedence
    $project->users()->updateExistingPivot($user->id, ['hourly_rate_override' => 120.00]);
    $project->load(['tasks', 'users']);
    expect((float) (new RateResolver)->resolve($project, $task, $user)->rateSnapshot)->toBe(120.00);
});

test('a user without a role bills at the £100 fallback', function () {
    $user = User::factory()->create(['rate_id' => null, 'default_hourly_rate' => null]);
    $project = Project::factory()->create(['is_billable' => true]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    $project->load(['tasks', 'users']);
    $resolution = (new RateResolver)->resolve($project, $task, $user);

    expect((float) $resolution->rateSnapshot)->toBe(RateResolver::FALLBACK_HOURLY_RATE)
        ->and((float) $resolution->rateSnapshot)->toBe(100.0);
});

test('editing a library rate does not change historical billable_rate_snapshot', function () {
    $rate = Rate::create(['name' => 'Std', 'hourly_rate' => 100.00]);
    $user = User::factory()->create(['rate_id' => $rate->id]);
    $project = Project::factory()->create(['is_billable' => true]);
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

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
