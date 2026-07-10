<?php

use App\Enums\Role;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function apiSetup(): array
{
    $user = User::factory()->create(['role' => Role::User, 'is_active' => true]);
    $project = Project::factory()->create();
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);
    $token = PersonalAccessToken::generate($user, 'test')['token'];

    return [$user, $project, $task, $token];
}

test('API rejects revoked tokens', function () {
    [, , , $token] = apiSetup();
    $model = PersonalAccessToken::findActiveByPlaintext($token);
    $model->revoke();

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->getJson('/api/me')
        ->assertStatus(401);
});

test('POST /api/time-entries creates an entry', function () {
    [$user, $project, $task, $token] = apiSetup();

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/time-entries', [
            'project_id' => $project->id,
            'task_id' => $task->id,
            'spent_on' => '2026-05-04',
            'hours' => '1:30',
            'notes' => '[#17654] Test ticket — https://filter.freshdesk.com/a/tickets/17654',
        ])
        ->assertCreated();

    $entry = TimeEntry::first();
    expect((float) $entry->hours)->toBe(1.5);
    expect($entry->user_id)->toBe($user->id);
    expect($entry->notes)->toContain('17654');
});

test('POST /api/time-entries rejects projects the user is not assigned to', function () {
    [, , , $token] = apiSetup();
    $otherProject = Project::factory()->create();
    $task = Task::factory()->create();
    $otherProject->tasks()->attach($task->id, ['is_billable' => true]);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/time-entries', [
            'project_id' => $otherProject->id,
            'task_id' => $task->id,
            'spent_on' => '2026-05-04',
            'hours' => '1:00',
        ])
        ->assertStatus(403);
});

test('POST /api/timers/stop stops the running timer', function () {
    [$user, $project, $task, $token] = apiSetup();
    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/timers/start', [
            'project_id' => $project->id,
            'task_id' => $task->id,
            'spent_on' => '2026-05-04',
        ])
        ->assertCreated();

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/timers/stop')
        ->assertOk()
        ->assertJsonPath('is_running', false);

    expect(TimeEntry::where('user_id', $user->id)->where('is_running', true)->count())->toBe(0);
});
