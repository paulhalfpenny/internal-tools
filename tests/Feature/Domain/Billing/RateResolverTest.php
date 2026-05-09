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

function makeProject(
    bool $isBillable = true,
    array $taskPivots = [],  // [task_id => ['is_billable' => bool]]
    array $userPivots = [],  // [user_id => ['hourly_rate_override' => ?float]]
): Project {
    $project = new Project;
    $project->is_billable = $isBillable;

    $tasks = new Collection;
    foreach ($taskPivots as $taskId => $pivotData) {
        $task = new Task;
        $task->id = $taskId;
        $pivotModel = new Pivot;
        $pivotModel->forceFill($pivotData);
        $task->setRelation('pivot', $pivotModel);
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

function makeTask(int $id = 1): Task
{
    $task = new Task;
    $task->id = $id;

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
    $project = makeProject(false, [1 => ['is_billable' => true]]);
    $task = makeTask(1);
    $user = makeUser(1);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->isBillable)->toBeFalse()
        ->and($result->rateSnapshot)->toBeNull();
});

test('task not assigned to project returns is_billable false', function () {
    $project = makeProject(true, []); // no tasks assigned
    $task = makeTask(99);
    $user = makeUser(1);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->isBillable)->toBeFalse();
});

test('project_task.is_billable false returns is_billable false', function () {
    $project = makeProject(true, [1 => ['is_billable' => false]]);
    $task = makeTask(1);
    $user = makeUser(1);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->isBillable)->toBeFalse()
        ->and($result->rateSnapshot)->toBeNull();
});

test('project_task.is_billable true returns is_billable true', function () {
    $project = makeProject(true, [1 => ['is_billable' => true]]);
    $task = makeTask(1);
    $user = makeUser(1);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->isBillable)->toBeTrue();
});

// --- rate resolution ---

test('project_user override wins over user role rate', function () {
    $rate = Rate::create(['name' => 'Standard', 'hourly_rate' => 60.0]);
    $project = makeProject(
        true,
        taskPivots: [1 => ['is_billable' => true]],
        userPivots: [1 => ['hourly_rate_override' => 120.0]],
    );
    $task = makeTask(1);
    $user = makeUser(1, rateId: $rate->id);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->rateSnapshot)->toBe(120.0);
});

test("user's library role rate is used when no project override", function () {
    $rate = Rate::create(['name' => 'Senior', 'hourly_rate' => 150.0]);
    $project = makeProject(
        true,
        taskPivots: [1 => ['is_billable' => true]],
        userPivots: [1 => ['hourly_rate_override' => null]],
    );
    $task = makeTask(1);
    $user = makeUser(1, rateId: $rate->id);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->rateSnapshot)->toBe(150.0);
});

test('falls back to FALLBACK_HOURLY_RATE when user has no role and no override', function () {
    $project = makeProject(
        true,
        taskPivots: [1 => ['is_billable' => true]],
        userPivots: [1 => ['hourly_rate_override' => null]],
    );
    $task = makeTask(1);
    $user = makeUser(1, rateId: null);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->isBillable)->toBeTrue()
        ->and($result->rateSnapshot)->toBe(RateResolver::FALLBACK_HOURLY_RATE)
        ->and($result->rateSnapshot)->toBe(100.0);
});

test('falls back to FALLBACK_HOURLY_RATE for user not assigned to project', function () {
    $project = makeProject(
        true,
        taskPivots: [1 => ['is_billable' => true]],
        userPivots: [], // user not assigned
    );
    $task = makeTask(1);
    $user = makeUser(1, rateId: null);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->rateSnapshot)->toBe(100.0);
});

// --- billable_amount calculation ---

test('resolveWithHours computes billable_amount using user role rate', function () {
    $rate = Rate::create(['name' => 'Std', 'hourly_rate' => 84.0]);
    $project = makeProject(
        true,
        taskPivots: [1 => ['is_billable' => true]],
        userPivots: [1 => ['hourly_rate_override' => null]],
    );
    $task = makeTask(1);
    $user = makeUser(1, rateId: $rate->id);

    $result = (new RateResolver)->resolveWithHours($project, $task, $user, 2.5);

    expect($result->billableAmount)->toBe(210.0); // 2.5 * 84.0
});

test('resolveWithHours returns zero amount when non-billable', function () {
    $project = makeProject(false);
    $task = makeTask(1);
    $user = makeUser(1);

    $result = (new RateResolver)->resolveWithHours($project, $task, $user, 8.0);

    expect($result->billableAmount)->toBe(0.0);
});

test('resolveWithHours rounds to 2 decimal places', function () {
    $rate = Rate::create(['name' => 'Std', 'hourly_rate' => 84.0]);
    $project = makeProject(
        true,
        taskPivots: [1 => ['is_billable' => true]],
        userPivots: [],
    );
    $task = makeTask(1);
    $user = makeUser(1, rateId: $rate->id);

    $result = (new RateResolver)->resolveWithHours($project, $task, $user, 1.25);

    expect($result->billableAmount)->toBe(105.0); // 1.25 * 84 = 105.00
});
