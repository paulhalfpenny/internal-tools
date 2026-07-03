<?php

use App\Domain\Mcp\InternalMcpActions;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// MCP project writes must bust the per-user timesheet picker caches
// (projects_picker_{id} / projects_picker_eloquent_{id}, 10-minute TTL).
// The admin Livewire screens already do; before this, MCP writes left users
// with stale pickers (e.g. a project whose tasks appeared empty) for up to
// 10 minutes, surviving hard refreshes.

function warmPickerCaches(User $user): void
{
    Cache::put("projects_picker_{$user->id}", ['stale'], now()->addMinutes(10));
    Cache::put("projects_picker_eloquent_{$user->id}", ['stale'], now()->addMinutes(10));
}

function expectPickerCachesCleared(User $user): void
{
    expect(Cache::has("projects_picker_{$user->id}"))->toBeFalse()
        ->and(Cache::has("projects_picker_eloquent_{$user->id}"))->toBeFalse();
}

function mcpPickerCacheSetup(): array
{
    $admin = User::factory()->create(['role' => Role::Admin]);
    $member = User::factory()->create();
    $client = Client::factory()->create();
    $task = Task::factory()->create();

    $project = Project::factory()->create(['client_id' => $client->id]);
    $project->users()->attach($member->id, ['hourly_rate_override' => null, 'rate_id' => null]);

    return [$admin, $member, $client, $task, $project];
}

test('mcp updateProject busts picker caches for assigned users', function () {
    [$admin, $member, , $task, $project] = mcpPickerCacheSetup();
    warmPickerCaches($member);

    app(InternalMcpActions::class)->updateProject($admin, $project, ['task_ids' => [$task->id]]);

    expectPickerCachesCleared($member);
});

test('mcp updateProject busts picker caches for users removed from the project', function () {
    [$admin, $member, , , $project] = mcpPickerCacheSetup();
    $newMember = User::factory()->create();
    warmPickerCaches($member);
    warmPickerCaches($newMember);

    app(InternalMcpActions::class)->updateProject($admin, $project, ['user_ids' => [$newMember->id]]);

    expectPickerCachesCleared($member);
    expectPickerCachesCleared($newMember);
});

test('mcp createProject busts picker caches for its initial members', function () {
    [$admin, $member, $client, $task] = mcpPickerCacheSetup();
    warmPickerCaches($member);

    app(InternalMcpActions::class)->createProject($admin, [
        'client_id' => $client->id,
        'code' => 'MCPNEW1',
        'name' => 'MCP-created project',
        'task_ids' => [$task->id],
        'user_ids' => [$member->id],
    ]);

    expectPickerCachesCleared($member);
});

test('mcp archiveProject busts picker caches for assigned users', function () {
    [$admin, $member, , , $project] = mcpPickerCacheSetup();
    warmPickerCaches($member);

    app(InternalMcpActions::class)->archiveProject($admin, $project, true);

    expectPickerCachesCleared($member);
});

test('mcp assignProjectMember busts picker caches for that member', function () {
    [$admin, , , , $project] = mcpPickerCacheSetup();
    $newMember = User::factory()->create();
    warmPickerCaches($newMember);

    app(InternalMcpActions::class)->assignProjectMember($admin, $project, $newMember);

    expectPickerCachesCleared($newMember);
});

test('mcp unassignProjectMember busts picker caches for that member', function () {
    [$admin, $member, , , $project] = mcpPickerCacheSetup();
    warmPickerCaches($member);

    app(InternalMcpActions::class)->unassignProjectMember($admin, $project, $member);

    expectPickerCachesCleared($member);
});
