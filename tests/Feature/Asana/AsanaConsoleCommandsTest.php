<?php

use App\Jobs\Asana\PullAsanaProjectsJob;
use App\Jobs\Asana\PullAsanaTasksJob;
use App\Models\AsanaSyncLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('asana:refresh-projects dispatches one workspace pull per connected workspace', function () {
    Bus::fake([PullAsanaProjectsJob::class]);

    User::factory()->create([
        'asana_access_token' => 'tok-a',
        'asana_user_gid' => 'u1',
        'asana_workspace_gid' => 'WS1',
    ]);
    User::factory()->create([
        'asana_access_token' => 'tok-b',
        'asana_user_gid' => 'u2',
        'asana_workspace_gid' => 'WS2',
    ]);
    User::factory()->create([
        'asana_access_token' => 'tok-c',
        'asana_user_gid' => 'u3',
        'asana_workspace_gid' => 'WS1', // shared workspace, should still only fire once for WS1
    ]);

    $this->artisan('asana:refresh-projects')->assertExitCode(0);

    Bus::assertDispatchedTimes(PullAsanaProjectsJob::class, 2);
});

test('asana:refresh-projects no-ops when no users connected', function () {
    Bus::fake([PullAsanaProjectsJob::class]);

    $this->artisan('asana:refresh-projects')->assertExitCode(0);

    Bus::assertNothingDispatched();
});

test('asana:refresh-tasks dispatches a pull for each linked, non-archived project', function () {
    Bus::fake([PullAsanaTasksJob::class]);

    User::factory()->create([
        'asana_access_token' => 'tok-a',
        'asana_user_gid' => 'u1',
        'asana_workspace_gid' => 'WS1',
    ]);
    User::factory()->create([
        'asana_access_token' => 'tok-b',
        'asana_user_gid' => 'u2',
        'asana_workspace_gid' => 'WS2',
    ]);

    Project::factory()->create([
        'asana_project_gid' => 'AP1',
        'asana_workspace_gid' => 'WS1',
    ]);
    Project::factory()->create([
        'asana_project_gid' => 'AP2',
        'asana_workspace_gid' => 'WS2',
    ]);
    Project::factory()->create([
        'asana_project_gid' => 'AP-archived',
        'asana_workspace_gid' => 'WS1',
        'is_archived' => true,
    ]);
    Project::factory()->create([
        'asana_project_gid' => 'AP-no-actor',
        'asana_workspace_gid' => 'WS-OTHER', // no connected user in this workspace
    ]);

    $this->artisan('asana:refresh-tasks')->assertExitCode(0);

    Bus::assertDispatchedTimes(PullAsanaTasksJob::class, 2);
    Bus::assertDispatched(PullAsanaTasksJob::class, fn ($j) => $j->asanaProjectGid === 'AP1');
    Bus::assertDispatched(PullAsanaTasksJob::class, fn ($j) => $j->asanaProjectGid === 'AP2');
    Bus::assertNotDispatched(PullAsanaTasksJob::class, fn ($j) => $j->asanaProjectGid === 'AP-archived');
    Bus::assertNotDispatched(PullAsanaTasksJob::class, fn ($j) => $j->asanaProjectGid === 'AP-no-actor');
});

test('asana:refresh-tasks no-ops when no users connected', function () {
    Bus::fake([PullAsanaTasksJob::class]);

    Project::factory()->create([
        'asana_project_gid' => 'AP1',
        'asana_workspace_gid' => 'WS1',
    ]);

    $this->artisan('asana:refresh-tasks')->assertExitCode(0);

    Bus::assertNothingDispatched();
});

test('asana:prune-logs deletes entries older than the retention window', function () {
    $this->travelTo(now()->subDays(40));
    AsanaSyncLog::create(['level' => 'info', 'event' => 'old']);
    $this->travelTo(now()->addDays(35));
    AsanaSyncLog::create(['level' => 'info', 'event' => 'recent']);
    $this->travelBack();

    $this->artisan('asana:prune-logs')->assertExitCode(0);

    expect(AsanaSyncLog::where('event', 'old')->exists())->toBeFalse();
    expect(AsanaSyncLog::where('event', 'recent')->exists())->toBeTrue();
});

test('asana:prune-logs respects custom days option', function () {
    $this->travelTo(now()->subDays(2));
    AsanaSyncLog::create(['level' => 'info', 'event' => 'two-days-ago']);
    $this->travelBack();

    $this->artisan('asana:prune-logs', ['--days' => 1])->assertExitCode(0);

    expect(AsanaSyncLog::where('event', 'two-days-ago')->exists())->toBeFalse();
});
