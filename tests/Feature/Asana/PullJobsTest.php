<?php

use App\Jobs\Asana\PullAsanaProjectsJob;
use App\Jobs\Asana\PullAsanaTasksJob;
use App\Models\AsanaProject;
use App\Models\AsanaSyncLog;
use App\Models\AsanaTask;
use App\Models\User;
use App\Services\Asana\AsanaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function asanaTestConnectedUser(): User
{
    return User::factory()->create([
        'asana_access_token' => 'tok',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'me',
        'asana_workspace_gid' => 'WS1',
    ]);
}

test('pulling projects does not prune when actor visibility is partial', function () {
    $firstUser = User::factory()->create([
        'asana_access_token' => 'tok-first',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'first',
        'asana_workspace_gid' => 'WS1',
    ]);
    $deniedUser = User::factory()->create([
        'asana_access_token' => 'tok-denied',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'denied',
        'asana_workspace_gid' => 'WS1',
    ]);
    AsanaProject::create(['gid' => 'p-old', 'workspace_gid' => 'WS1', 'name' => 'Old', 'is_archived' => false]);

    Http::fake(function ($request) {
        $token = $request->header('Authorization')[0] ?? '';

        if ($token === 'Bearer tok-first') {
            return Http::response([
                'data' => [
                    ['gid' => 'p1', 'name' => 'Visible project', 'archived' => false],
                ],
                'next_page' => null,
            ]);
        }

        return Http::response([
            'errors' => [
                ['message' => 'You do not have access to this workspace.'],
            ],
        ], 403);
    });

    (new PullAsanaProjectsJob('WS1', $firstUser->id, [$deniedUser->id]))->handle(app(AsanaService::class));

    expect(AsanaProject::find('p1')->name)->toBe('Visible project');
    expect(AsanaProject::find('p-old'))->not->toBeNull();
    expect(AsanaSyncLog::where('event', 'asana.pull_projects.partial_visibility')->exists())->toBeTrue();
    expect(AsanaSyncLog::where('event', 'asana.pull_projects.failed')->exists())->toBeFalse();
});

test('pulling tasks preserves names longer than 255 characters', function () {
    $user = asanaTestConnectedUser();
    $longName = str_repeat('Long Asana task name ', 16);

    expect(mb_strlen($longName))->toBeGreaterThan(255);
    expect(Schema::getColumnType('asana_tasks', 'name'))->toBe('text');

    Http::fake([
        'app.asana.com/api/1.0/projects/P1/tasks*' => Http::response([
            'data' => [
                ['gid' => 't-long', 'name' => $longName, 'completed' => false, 'parent' => null],
            ],
            'next_page' => null,
        ]),
    ]);

    (new PullAsanaTasksJob('P1', $user->id))->handle(app(AsanaService::class));

    expect(AsanaTask::find('t-long')->name)->toBe($longName);
});

test('pulling tasks falls back when the first actor cannot access the Asana project', function () {
    $deniedUser = User::factory()->create([
        'asana_access_token' => 'tok-denied',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'denied',
        'asana_workspace_gid' => 'WS1',
    ]);
    $allowedUser = User::factory()->create([
        'asana_access_token' => 'tok-allowed',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'allowed',
        'asana_workspace_gid' => 'WS1',
    ]);

    Http::fake(function ($request) {
        $token = $request->header('Authorization')[0] ?? '';

        if ($token === 'Bearer tok-denied') {
            return Http::response([
                'errors' => [
                    ['message' => 'You do not have access to this project.'],
                ],
            ], 403);
        }

        return Http::response([
            'data' => [
                ['gid' => 't1', 'name' => 'Visible task', 'completed' => false, 'parent' => null],
            ],
            'next_page' => null,
        ]);
    });

    $thrown = null;

    try {
        (new PullAsanaTasksJob('P1', $deniedUser->id, [$allowedUser->id]))->handle(app(AsanaService::class));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull();
    expect(AsanaTask::find('t1')->name)->toBe('Visible task');
    expect(AsanaSyncLog::where('event', 'asana.pull_tasks.failed')->exists())->toBeFalse();
    expect(AsanaSyncLog::where('event', 'asana.pull_tasks.completed')->first()?->context['user_id'])->toBe($allowedUser->id);
});

test('pulling tasks preserves stale data when Asana exhausts its server error retries', function () {
    $user = asanaTestConnectedUser();
    $staleTask = AsanaTask::create([
        'gid' => 't-stale',
        'asana_project_gid' => 'P1',
        'name' => 'Previously synced task',
        'search_text' => null,
        'is_completed' => false,
        'parent_gid' => null,
        'last_synced_at' => now()->subHour(),
    ]);

    Http::fake([
        'app.asana.com/api/1.0/projects/P1/tasks*' => Http::response('<html>Upstream error</html>', 500),
    ]);

    $thrown = null;

    try {
        (new PullAsanaTasksJob('P1', $user->id))->handle(app(AsanaService::class));
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    $warning = AsanaSyncLog::where('event', 'asana.pull_tasks.upstream_error')->first();

    expect($thrown)->toBeNull()
        ->and($staleTask->fresh())->not->toBeNull()
        ->and($warning?->level)->toBe('warn')
        ->and($warning?->context['asana_project_gid'])->toBe('P1')
        ->and($warning?->context['user_id'])->toBe($user->id)
        ->and($warning?->context['status'])->toBe(500)
        ->and(AsanaSyncLog::where('event', 'asana.pull_tasks.failed')->exists())->toBeFalse()
        ->and(AsanaSyncLog::where('event', 'asana.pull_tasks.completed')->exists())->toBeFalse();
});

test('pulling tasks still fails for non-server HTTP errors', function () {
    $user = asanaTestConnectedUser();

    Http::fake([
        'app.asana.com/api/1.0/projects/P1/tasks*' => Http::response(['errors' => [['message' => 'Rate limited']]], 429),
    ]);

    expect(fn () => (new PullAsanaTasksJob('P1', $user->id))->handle(app(AsanaService::class)))
        ->toThrow(RequestException::class);

    expect(AsanaSyncLog::where('event', 'asana.pull_tasks.failed')->exists())->toBeTrue()
        ->and(AsanaSyncLog::where('event', 'asana.pull_tasks.upstream_error')->exists())->toBeFalse();
});
