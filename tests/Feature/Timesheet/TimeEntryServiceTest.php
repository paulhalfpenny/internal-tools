<?php

use App\Domain\TimeTracking\TimeEntryService;
use App\Models\Project;
use App\Models\Rate;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupProjectWithTaskAndUser(float $userRate = 84.0): array
{
    $rate = Rate::create(['name' => 'Std '.$userRate, 'hourly_rate' => $userRate]);
    $user = User::factory()->create(['rate_id' => $rate->id]);
    $project = Project::factory()->create();
    $task = Task::factory()->create(['is_default_billable' => true]);

    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    return [$user, $project, $task, $rate];
}

test('creates a time entry with correct denormalised billing fields', function () {
    [$user, $project, $task] = setupProjectWithTaskAndUser(userRate: 84.0);

    $service = app(TimeEntryService::class);
    $entry = $service->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => today()->toDateString(),
        'hours' => 2.0,
        'notes' => null,
    ]);

    expect($entry->is_billable)->toBeTrue()
        ->and((float) $entry->billable_rate_snapshot)->toBe(84.0)
        ->and((float) $entry->billable_amount)->toBe(168.0);
});

test('float inputs do not reach brick math when decimal attributes are cast', function () {
    [$user, $project, $task] = setupProjectWithTaskAndUser(userRate: 84.0);

    $deprecations = [];
    $previousErrorHandler = set_error_handler(
        function (int $level, string $message, string $file, int $line) use (&$deprecations, &$previousErrorHandler): bool {
            if ($level === E_USER_DEPRECATED && str_contains($message, 'Passing floats to BigNumber::of()')) {
                $deprecations[] = $message;

                return true;
            }

            return $previousErrorHandler ? ($previousErrorHandler)($level, $message, $file, $line) : false;
        },
    );

    try {
        $service = app(TimeEntryService::class);
        $entry = $service->create($user, [
            'project_id' => $project->id,
            'task_id' => $task->id,
            'spent_on' => today()->toDateString(),
            'hours' => 1.25,
            'notes' => null,
        ]);

        $entry = $service->update($entry, ['hours' => 2.5]);
        $entry->toArray();
    } finally {
        restore_error_handler();
    }

    expect($deprecations)->toBeEmpty()
        ->and($entry->hours)->toBe('2.50')
        ->and($entry->billable_rate_snapshot)->toBe('84.00')
        ->and($entry->billable_amount)->toBe('210.00');
});

test('billable_amount is frozen after the user role rate changes', function () {
    [$user, $project, $task, $rate] = setupProjectWithTaskAndUser(userRate: 84.0);

    $service = app(TimeEntryService::class);
    $entry = $service->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => today()->toDateString(),
        'hours' => 1.0,
        'notes' => null,
    ]);

    $rate->update(['hourly_rate' => 120.0]);

    $entry->refresh();
    expect((float) $entry->billable_rate_snapshot)->toBe(84.0)
        ->and((float) $entry->billable_amount)->toBe(84.0);
});

test('update recalculates billing fields at update time', function () {
    [$user, $project, $task] = setupProjectWithTaskAndUser(userRate: 84.0);

    $service = app(TimeEntryService::class);
    $entry = $service->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => today()->toDateString(),
        'hours' => 1.0,
        'notes' => null,
    ]);

    $service->update($entry, ['hours' => 3.0]);

    $entry->refresh();
    expect((float) $entry->hours)->toBe(3.0)
        ->and((float) $entry->billable_amount)->toBe(252.0);
});

test('non-billable project produces zero billing amount', function () {
    $rate = Rate::create(['name' => 'Std', 'hourly_rate' => 84.0]);
    $user = User::factory()->create(['rate_id' => $rate->id]);
    $project = Project::factory()->nonBillable()->create();
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => false, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    $entry = app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => today()->toDateString(),
        'hours' => 4.0,
        'notes' => null,
    ]);

    expect($entry->is_billable)->toBeFalse()
        ->and($entry->billable_rate_snapshot)->toBeNull()
        ->and((float) $entry->billable_amount)->toBe(0.0);
});
