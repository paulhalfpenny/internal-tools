<?php

use App\Jobs\Asana\PullAsanaProjectsJob;
use App\Jobs\Asana\PullAsanaTasksJob;
use App\Models\AsanaProject;
use App\Models\AsanaSyncLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function linkBoardToProject(Project $project, string $boardGid, string $workspaceGid): void
{
    AsanaProject::firstOrCreate(
        ['gid' => $boardGid],
        ['workspace_gid' => $workspaceGid, 'name' => 'Asana '.$boardGid, 'is_archived' => false],
    );
    $project->asanaProjects()->attach($boardGid, ['asana_custom_field_gid' => null]);
}

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

    $p1 = Project::factory()->create();
    linkBoardToProject($p1, 'AP1', 'WS1');
    $p2 = Project::factory()->create();
    linkBoardToProject($p2, 'AP2', 'WS2');
    $pArchived = Project::factory()->create(['is_archived' => true]);
    linkBoardToProject($pArchived, 'AP-archived', 'WS1');
    $pNoActor = Project::factory()->create();
    linkBoardToProject($pNoActor, 'AP-no-actor', 'WS-OTHER');

    $this->artisan('asana:refresh-tasks')->assertExitCode(0);

    Bus::assertDispatchedTimes(PullAsanaTasksJob::class, 2);
    Bus::assertDispatched(PullAsanaTasksJob::class, fn ($j) => $j->asanaProjectGid === 'AP1');
    Bus::assertDispatched(PullAsanaTasksJob::class, fn ($j) => $j->asanaProjectGid === 'AP2');
    Bus::assertNotDispatched(PullAsanaTasksJob::class, fn ($j) => $j->asanaProjectGid === 'AP-archived');
    Bus::assertNotDispatched(PullAsanaTasksJob::class, fn ($j) => $j->asanaProjectGid === 'AP-no-actor');
});

test('asana:refresh-tasks no-ops when no users connected', function () {
    Bus::fake([PullAsanaTasksJob::class]);

    $p = Project::factory()->create();
    linkBoardToProject($p, 'AP1', 'WS1');

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
