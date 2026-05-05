<?php

use App\Domain\Billing\RateResolver;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;

// Helpers to build lightweight model stubs without hitting the database

function makeProject(
    bool $isBillable = true,
    ?float $defaultRate = null,
    array $taskPivots = [],  // [task_id => ['is_billable' => bool, 'hourly_rate_override' => ?float]]
    array $userPivots = [],  // [user_id => ['hourly_rate_override' => ?float]]
): Project {
    $project = new Project;
    $project->is_billable = $isBillable;
    $project->default_hourly_rate = $defaultRate;

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

function makeUser(int $id = 1, ?float $defaultRate = null): User
{
    $user = new User;
    $user->id = $id;
    $user->default_hourly_rate = $defaultRate;

    return $user;
}

// --- is_billable resolution ---

test('non_billable project always returns is_billable false', function () {
    $project = makeProject(false, 100.0, [1 => ['is_billable' => true, 'hourly_rate_override' => null]]);
    $task = makeTask(1);
    $user = makeUser(1, 50.0);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->isBillable)->toBeFalse()
        ->and($result->rateSnapshot)->toBeNull();
});

test('task not assigned to project returns is_billable false', function () {
    $project = makeProject(true, 84.0, []); // no tasks assigned
    $task = makeTask(99);
    $user = makeUser(1, 50.0);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->isBillable)->toBeFalse();
});

test('project_task.is_billable false returns is_billable false', function () {
    $project = makeProject(true, 84.0, [1 => ['is_billable' => false, 'hourly_rate_override' => null]]);
    $task = makeTask(1);
    $user = makeUser(1, 50.0);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->isBillable)->toBeFalse()
        ->and($result->rateSnapshot)->toBeNull();
});

test('project_task.is_billable true on hourly project returns is_billable true', function () {
    $project = makeProject(true, 84.0, [1 => ['is_billable' => true, 'hourly_rate_override' => null]]);
    $task = makeTask(1);
    $user = makeUser(1);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->isBillable)->toBeTrue();
});

test('fixed_fee project with billable task returns is_billable true', function () {
    $project = makeProject(true, null, [1 => ['is_billable' => true, 'hourly_rate_override' => null]]);
    $task = makeTask(1);
    $user = makeUser(1);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->isBillable)->toBeTrue();
});

// --- rate resolution ---

test('project_user rate override wins over all others', function () {
    $project = makeProject(
        true,
        defaultRate: 84.0,
        taskPivots: [1 => ['is_billable' => true, 'hourly_rate_override' => null]],
        userPivots: [1 => ['hourly_rate_override' => 120.0]],
    );
    $task = makeTask(1);
    $user = makeUser(1, defaultRate: 50.0);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->rateSnapshot)->toBe(120.0);
});

test('project default rate used when no user override', function () {
    $project = makeProject(
        true,
        defaultRate: 84.0,
        taskPivots: [1 => ['is_billable' => true, 'hourly_rate_override' => null]],
        userPivots: [1 => ['hourly_rate_override' => null]],
    );
    $task = makeTask(1);
    $user = makeUser(1, defaultRate: 50.0);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->rateSnapshot)->toBe(84.0);
});

test('user default rate used when project has no default and no user override', function () {
    $project = makeProject(
        true,
        defaultRate: null,
        taskPivots: [1 => ['is_billable' => true, 'hourly_rate_override' => null]],
        userPivots: [1 => ['hourly_rate_override' => null]],
    );
    $task = makeTask(1);
    $user = makeUser(1, defaultRate: 60.0);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->rateSnapshot)->toBe(60.0);
});

test('null rate when billable task has no rate at any level', function () {
    $project = makeProject(
        true,
        defaultRate: null,
        taskPivots: [1 => ['is_billable' => true, 'hourly_rate_override' => null]],
        userPivots: [],
    );
    $task = makeTask(1);
    $user = makeUser(1, defaultRate: null);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->isBillable)->toBeTrue()
        ->and($result->rateSnapshot)->toBeNull();
});

test('user not assigned to project still falls through to project then user rate', function () {
    // user not in userPivots — falls through to project default
    $project = makeProject(
        true,
        defaultRate: 75.0,
        taskPivots: [1 => ['is_billable' => true, 'hourly_rate_override' => null]],
        userPivots: [], // user not assigned
    );
    $task = makeTask(1);
    $user = makeUser(1, defaultRate: 50.0);

    $result = (new RateResolver)->resolve($project, $task, $user);

    expect($result->rateSnapshot)->toBe(75.0);
});

// --- billable_amount calculation ---

test('resolveWithHours computes billable_amount correctly', function () {
    $project = makeProject(
        true,
        defaultRate: 84.0,
        taskPivots: [1 => ['is_billable' => true, 'hourly_rate_override' => null]],
        userPivots: [],
    );
    $task = makeTask(1);
    $user = makeUser(1);

    $result = (new RateResolver)->resolveWithHours($project, $task, $user, 2.5);

    expect($result->billableAmount)->toBe(210.0); // 2.5 * 84.0
});

test('resolveWithHours returns zero amount when non-billable', function () {
    $project = makeProject(false, 84.0);
    $task = makeTask(1);
    $user = makeUser(1);

    $result = (new RateResolver)->resolveWithHours($project, $task, $user, 8.0);

    expect($result->billableAmount)->toBe(0.0);
});

test('resolveWithHours returns zero amount when billable but no rate', function () {
    $project = makeProject(
        true,
        defaultRate: null,
        taskPivots: [1 => ['is_billable' => true, 'hourly_rate_override' => null]],
        userPivots: [],
    );
    $task = makeTask(1);
    $user = makeUser(1, defaultRate: null);

    $result = (new RateResolver)->resolveWithHours($project, $task, $user, 3.0);

    expect($result->billableAmount)->toBe(0.0);
});

test('resolveWithHours rounds to 2 decimal places', function () {
    $project = makeProject(
        true,
        defaultRate: 84.0,
        taskPivots: [1 => ['is_billable' => true, 'hourly_rate_override' => null]],
        userPivots: [],
    );
    $task = makeTask(1);
    $user = makeUser(1);

    $result = (new RateResolver)->resolveWithHours($project, $task, $user, 1.25);

    expect($result->billableAmount)->toBe(105.0); // 1.25 * 84 = 105.00
});
