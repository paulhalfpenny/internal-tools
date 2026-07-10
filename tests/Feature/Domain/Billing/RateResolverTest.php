<?php

use App\Domain\Billing\RateResolver;
use App\Models\Project;
use App\Models\Rate;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Helpers to build lightweight model stubs

/**
 * @param  array<int, int>  $assignedTaskIds  — list of task ids that are assigned to the project
 * @param  array<int, bool>  $taskBillabilityById
 * @param  array<int, array{hourly_rate_override: ?float}>  $userPivots
 */
function makeProject(
    bool $isBillable = true,
    array $assignedTaskIds = [],
    array $taskBillabilityById = [],
    array $userPivots = [],
): Project {
    $project = new Project;
    $project->is_billable = $isBillable;

    $tasks = new Collection;
    foreach ($assignedTaskIds as $taskId) {
        $task = new Task;
        $task->id = $taskId;
        $pivot = new Pivot;
        $pivot->forceFill(['is_billable' => $taskBillabilityById[$taskId] ?? true]);
        $task->setRelation('pivot', $pivot);
        $tasks->push($task);
    }
    $project->setRelation('tasks', $tasks);

    $users = new Collection;
    foreach ($userPivots as $userId => $pivotData) {
        $user = new User;
        $user->id = $userId;
        $pivotModel = new Pivot;
        $pivotModel->forceFill($pivotData);
        $user->setRelation('pivot', $pivotModel);
        $users->push($user);
    }
    $project->setRelation('users', $users);

    return $project;
}

function makeTask(int $id = 1, bool $isDefaultBillable = true): Task
{
    $task = new Task;
    $task->id = $id;
    $task->is_default_billable = $isDefaultBillable;

    return $task;
}

function makeUser(int $id = 1, ?int $rateId = null): User
{
    $user = new User;
    $user->id = $id;
    $user->rate_id = $rateId;

    return $user;
}

// --- is_billable resolution ---

test('non_billable project always returns is_billable false', function () {
    $project = makeProject(false, [1]);
    $task = makeTask(1, isDefaultBillable: true);
    $user = makeUser(1);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->isBillable)->toBeFalse()
        ->and($result->rateSnapshot)->toBeNull();
});

test('project task pivot can make a globally non-billable task billable', function () {
    $project = makeProject(true, [1], [1 => true]);
    $task = makeTask(1, isDefaultBillable: false);
    $user = makeUser(1);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->isBillable)->toBeTrue();
});

// --- rate resolution ---

test('project_user override wins over user role rate', function () {
    $rate = Rate::create(['name' => 'Standard', 'hourly_rate' => 60.0]);
    $project = makeProject(
        true,
        assignedTaskIds: [1],
        userPivots: [1 => ['hourly_rate_override' => 120.0]],
    );
    $task = makeTask(1);
    $user = makeUser(1, rateId: $rate->id);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->rateSnapshot)->toBe(120.0);
});

test('falls back to FALLBACK_HOURLY_RATE when user has no role and no override', function () {
    $project = makeProject(
        true,
        assignedTaskIds: [1],
        userPivots: [1 => ['hourly_rate_override' => null]],
    );
    $task = makeTask(1);
    $user = makeUser(1, rateId: null);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->isBillable)->toBeTrue()
        ->and($result->rateSnapshot)->toBe(RateResolver::FALLBACK_HOURLY_RATE)
        ->and($result->rateSnapshot)->toBe(100.0);
});

// --- billable_amount calculation ---

test('resolveWithHours computes billable_amount using user role rate', function () {
    $rate = Rate::create(['name' => 'Std', 'hourly_rate' => 84.0]);
    $project = makeProject(
        true,
        assignedTaskIds: [1],
        userPivots: [1 => ['hourly_rate_override' => null]],
    );
    $task = makeTask(1);
    $user = makeUser(1, rateId: $rate->id);

    $result = (new RateResolver)->resolveWithHours($project, $task, $user, 2.5);

    expect($result->billableAmount)->toBe(210.0); // 2.5 * 84.0
});
