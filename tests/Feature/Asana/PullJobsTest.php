<?php

use App\Jobs\Asana\PullAsanaProjectsJob;
use App\Jobs\Asana\PullAsanaTasksJob;
use App\Models\AsanaProject;
use App\Models\AsanaSyncLog;
use App\Models\AsanaTask;
use App\Models\User;
use App\Services\Asana\AsanaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
