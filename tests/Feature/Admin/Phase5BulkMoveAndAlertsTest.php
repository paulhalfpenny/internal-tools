<?php

use App\Console\Commands\CheckBudgetAlerts;
use App\Enums\BudgetType;
use App\Enums\Role;
use App\Livewire\Admin\TimeEntries\BulkMove;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimeEntryAudit;
use App\Models\User;
use App\Notifications\BudgetThresholdReached;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function bulkMoveEntry(array $attrs): TimeEntry
{
    return TimeEntry::create(array_merge([
        'spent_on' => '2026-04-15',
        'hours' => 1.0,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 100.0,
        'billable_amount' => 100.0,
    ], $attrs));
}

test('bulk move re-assigns selected entries and writes audit rows', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $user = User::factory()->create();
    $task = Task::factory()->create();
    $taskB = Task::factory()->create();

    $sourceProject = Project::factory()->create(['default_hourly_rate' => 100]);
    $destProject = Project::factory()->create(['default_hourly_rate' => 100]);
    $sourceProject->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);
    $destProject->tasks()->attach($taskB->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);
    $destProject->users()->attach($user->id, ['hourly_rate_override' => null, 'rate_id' => null]);

    $entry1 = bulkMoveEntry(['user_id' => $user->id, 'project_id' => $sourceProject->id, 'task_id' => $task->id]);
    $entry2 = bulkMoveEntry(['user_id' => $user->id, 'project_id' => $sourceProject->id, 'task_id' => $task->id]);
    $entryUntouched = bulkMoveEntry(['user_id' => $user->id, 'project_id' => $sourceProject->id, 'task_id' => $task->id]);

    Livewire::test(BulkMove::class)
        ->set('filterFrom', '2026-04-01')
        ->set('filterTo', '2026-04-30')
        ->set('selected', [$entry1->id, $entry2->id])
        ->set('destinationProjectId', $destProject->id)
        ->set('destinationTaskId', $taskB->id)
        ->call('move');

    expect($entry1->fresh()->project_id)->toBe($destProject->id);
    expect($entry1->fresh()->task_id)->toBe($taskB->id);
    expect($entry2->fresh()->project_id)->toBe($destProject->id);
    expect($entryUntouched->fresh()->project_id)->toBe($sourceProject->id);

    expect(TimeEntryAudit::where('time_entry_id', $entry1->id)->where('field', 'project_id')->exists())->toBeTrue();
    expect(TimeEntryAudit::where('time_entry_id', $entry1->id)->where('field', 'task_id')->exists())->toBeTrue();
});

test('bulk move refuses if destination task is not on destination project', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $user = User::factory()->create();
    $task = Task::factory()->create();
    $strayTask = Task::factory()->create();

    $sourceProject = Project::factory()->create();
    $destProject = Project::factory()->create();
    $sourceProject->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);
    // Note: $strayTask is NOT attached to $destProject

    $entry = bulkMoveEntry(['user_id' => $user->id, 'project_id' => $sourceProject->id, 'task_id' => $task->id]);

    Livewire::test(BulkMove::class)
        ->set('filterFrom', '2026-04-01')
        ->set('filterTo', '2026-04-30')
        ->set('selected', [$entry->id])
        ->set('destinationProjectId', $destProject->id)
        ->set('destinationTaskId', $strayTask->id)
        ->call('move')
        ->assertSet('confirmation', 'That task is not assigned to the destination project.');

    expect($entry->fresh()->project_id)->toBe($sourceProject->id);
    expect(TimeEntryAudit::count())->toBe(0);
});

test('budget alerts command sends 80 percent alert once and de-duplicates on re-run', function () {
    Notification::fake();

    $admin = User::factory()->create(['role' => Role::Admin]);
    $manager = User::factory()->create();
    $project = Project::factory()->create([
        'manager_user_id' => $manager->id,
        'budget_type' => BudgetType::FixedFee,
        'budget_amount' => 1000.00,
        'starts_on' => now()->subMonth()->toDateString(),
    ]);
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);

    bulkMoveEntry([
        'spent_on' => now()->toDateString(),
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'hours' => 8,
        'billable_amount' => 850,
    ]);

    $this->artisan(CheckBudgetAlerts::class)->assertSuccessful();

    Notification::assertSentTo([$admin, $manager], BudgetThresholdReached::class);

    Notification::fake(); // reset
    $this->artisan(CheckBudgetAlerts::class)->assertSuccessful();
    Notification::assertNothingSent();
});

test('budget alerts command sends 100 percent alert when project goes over budget', function () {
    Notification::fake();

    $admin = User::factory()->create(['role' => Role::Admin]);
    $project = Project::factory()->create([
        'budget_type' => BudgetType::FixedFee,
        'budget_amount' => 500.00,
        'starts_on' => now()->subMonth()->toDateString(),
    ]);
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);

    bulkMoveEntry([
        'spent_on' => now()->toDateString(),
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'hours' => 6,
        'billable_amount' => 600,
    ]);

    $this->artisan(CheckBudgetAlerts::class)->assertSuccessful();

    // Both 80% and 100% thresholds cross at once on first run
    Notification::assertSentToTimes($admin, BudgetThresholdReached::class, 2);
});

test('budget alerts dry-run does not send and does not record threshold', function () {
    Notification::fake();

    $admin = User::factory()->create(['role' => Role::Admin]);
    $project = Project::factory()->create([
        'budget_type' => BudgetType::FixedFee,
        'budget_amount' => 1000.00,
        'starts_on' => now()->subMonth()->toDateString(),
    ]);
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);

    bulkMoveEntry([
        'spent_on' => now()->toDateString(),
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'hours' => 8,
        'billable_amount' => 850,
    ]);

    $this->artisan('app:check-budget-alerts', ['--dry-run' => true])->assertSuccessful();
    Notification::assertNothingSent();

    // Subsequent real run still sends
    $this->artisan(CheckBudgetAlerts::class)->assertSuccessful();
    Notification::assertSentTo($admin, BudgetThresholdReached::class);
});
