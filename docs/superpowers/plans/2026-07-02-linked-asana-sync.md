# Linked Asana Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure scheduled Asana sync refreshes the union of Asana projects visible to connected Internal Tools users, while task sync remains linked-only and uses any same-workspace connected actor that can access each linked board.

**Architecture:** Project refresh runs once per Asana workspace but receives every connected Internal Tools actor for that workspace, preferring admins first. The project job merges project lists across those actors and only prunes cached projects after a complete successful visibility pass; task refresh continues to dispatch only for `project_asana_links` attached to non-archived Internal Tools projects, with fallback actors for permission-restricted boards.

**Tech Stack:** Laravel console commands, queued jobs, Eloquent models, Laravel HTTP client fakes, Pest feature tests, Asana REST API.

## Global Constraints

- Linked-only task sync: task jobs must only dispatch for Asana boards in `project_asana_links` attached to non-archived Internal Tools projects.
- Asana permissions are authoritative: never bypass or imply access when the API returns `403`.
- Do not prune cached Asana projects or tasks after a partial refresh, permission denial, missing actor, or API failure.
- Actor selection order is Internal Tools admins first, then ascending `users.id`.
- Reuse existing `AsanaService`, `AsanaSyncLog`, queue job, and Pest test patterns.
- Do not add new third-party dependencies.

---

## File Structure

- Modify: `app/Console/Commands/AsanaRefreshProjectsCommand.php`
  - Responsibility: choose the primary and fallback actors for each workspace-level project refresh.
- Modify: `app/Jobs/Asana/PullAsanaProjectsJob.php`
  - Responsibility: collect and merge all projects visible to the candidate actors for one workspace, then upsert/prune safely.
- Modify: `app/Services/Asana/AsanaTaskRefreshDispatcher.php`
  - Responsibility: keep task refresh linked-only and pass all same-workspace task actors to each linked-board pull.
- Modify: `app/Jobs/Asana/PullAsanaTasksJob.php`
  - Responsibility: try fallback actors on project-level `403`, log one permission warning if nobody can access the linked board, and avoid retry noise.
- Modify: `tests/Feature/Asana/AsanaConsoleCommandsTest.php`
  - Responsibility: verify project and task refresh dispatch the expected actor sets and that task refresh ignores unlinked cached boards.
- Modify: `tests/Feature/Asana/PullJobsTest.php`
  - Responsibility: verify project union, safe project pruning, and task fallback behavior.

## References

- Asana project listing endpoint: `https://developers.asana.com/reference/getprojectsforworkspace`
- Asana project task endpoint: `https://developers.asana.com/reference/gettasksforproject`
- Asana filtered task endpoint: `https://developers.asana.com/reference/gettasks`

---

### Task 1: Dispatch Project Refresh With All Workspace Actors

**Files:**
- Modify: `app/Console/Commands/AsanaRefreshProjectsCommand.php`
- Modify: `app/Jobs/Asana/PullAsanaProjectsJob.php`
- Test: `tests/Feature/Asana/AsanaConsoleCommandsTest.php`

**Interfaces:**
- Consumes: `User` records with `asana_access_token`, `asana_user_gid`, `asana_workspace_gid`, `role`, and `is_active`.
- Produces: `PullAsanaProjectsJob::__construct(string $workspaceGid, int $userId, array $fallbackUserIds = [])`.

- [ ] **Step 1: Write the failing dispatch test**

Add this test to `tests/Feature/Asana/AsanaConsoleCommandsTest.php` after `asana:refresh-projects dispatches one workspace pull per connected workspace`:

```php
test('asana:refresh-projects dispatches all same-workspace actors with admins first', function () {
    Bus::fake([PullAsanaProjectsJob::class]);

    $regularUser = User::factory()->create([
        'asana_access_token' => 'tok-user',
        'asana_user_gid' => 'u1',
        'asana_workspace_gid' => 'WS1',
    ]);
    $admin = User::factory()->admin()->create([
        'asana_access_token' => 'tok-admin',
        'asana_user_gid' => 'admin',
        'asana_workspace_gid' => 'WS1',
    ]);
    User::factory()->create([
        'asana_access_token' => 'tok-other',
        'asana_user_gid' => 'u2',
        'asana_workspace_gid' => 'WS2',
    ]);

    $this->artisan('asana:refresh-projects')->assertExitCode(0);

    Bus::assertDispatched(PullAsanaProjectsJob::class, fn (PullAsanaProjectsJob $job) => $job->workspaceGid === 'WS1'
        && $job->userId === $admin->id
        && property_exists($job, 'fallbackUserIds')
        && $job->fallbackUserIds === [$regularUser->id]
    );
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
php artisan test tests/Feature/Asana/AsanaConsoleCommandsTest.php --filter="dispatches all same-workspace actors"
```

Expected: FAIL because `PullAsanaProjectsJob` does not yet expose `fallbackUserIds` and `asana:refresh-projects` dispatches only one actor per workspace.

- [ ] **Step 3: Add fallback actor support to the project job constructor**

In `app/Jobs/Asana/PullAsanaProjectsJob.php`, replace the constructor with:

```php
public function __construct(
    public readonly string $workspaceGid,
    public readonly int $userId,
    /** @var array<int, int> */
    public readonly array $fallbackUserIds = [],
) {}
```

Add these imports:

```php
use Illuminate\Support\Collection;
```

Add this helper near the bottom of the class:

```php
/**
 * @return array<int, int>
 */
private function candidateUserIds(): array
{
    return Collection::make([$this->userId, ...$this->fallbackUserIds])
        ->filter(fn ($userId): bool => is_int($userId) || ctype_digit((string) $userId))
        ->map(fn ($userId): int => (int) $userId)
        ->unique()
        ->values()
        ->all();
}
```

- [ ] **Step 4: Dispatch admin-first actor lists from the command**

In `app/Console/Commands/AsanaRefreshProjectsCommand.php`, add:

```php
use App\Enums\Role;
```

Replace the connected-user query and dispatch loop with:

```php
$connectedUsers = User::query()
    ->whereNotNull('asana_access_token')
    ->whereNotNull('asana_user_gid')
    ->whereNotNull('asana_workspace_gid')
    ->where('is_active', true)
    ->orderByRaw('case when role = ? then 0 else 1 end', [Role::Admin->value])
    ->orderBy('id')
    ->get(['id', 'role', 'asana_workspace_gid']);

if ($connectedUsers->isEmpty()) {
    $this->info('No connected Asana users; nothing to refresh.');

    return self::SUCCESS;
}

$workspaceGids = $connectedUsers->pluck('asana_workspace_gid')->unique()->filter();

foreach ($workspaceGids as $workspaceGid) {
    $actors = $connectedUsers
        ->where('asana_workspace_gid', $workspaceGid)
        ->values();

    /** @var User $actor */
    $actor = $actors->first();
    $fallbackUserIds = $actors->skip(1)->pluck('id')->values()->all();

    PullAsanaProjectsJob::dispatch($workspaceGid, $actor->id, $fallbackUserIds);
}

$this->info(sprintf('Dispatched %d workspace project pull(s).', $workspaceGids->count()));

return self::SUCCESS;
```

- [ ] **Step 5: Run the dispatch tests**

Run:

```bash
php artisan test tests/Feature/Asana/AsanaConsoleCommandsTest.php --filter="asana:refresh-projects"
```

Expected: PASS for the no-op, one-workspace-per-connected-workspace, and admin-first actor-list tests.

- [ ] **Step 6: Commit Task 1**

Run:

```bash
git add app/Console/Commands/AsanaRefreshProjectsCommand.php app/Jobs/Asana/PullAsanaProjectsJob.php tests/Feature/Asana/AsanaConsoleCommandsTest.php
git commit -m "Improve Asana project refresh actor selection"
```

---

### Task 2: Merge Project Visibility Across Actors Without Unsafe Pruning

**Files:**
- Modify: `app/Jobs/Asana/PullAsanaProjectsJob.php`
- Test: `tests/Feature/Asana/PullJobsTest.php`

**Interfaces:**
- Consumes: `PullAsanaProjectsJob::candidateUserIds(): array<int, int>` from Task 1.
- Produces: `PullAsanaProjectsJob::collectProjectsWithAvailableActors(AsanaService $service): ?array`.

- [ ] **Step 1: Write the failing union test**

Add this test to `tests/Feature/Asana/PullJobsTest.php` after `pulling projects upserts and removes stale rows`:

```php
test('pulling projects merges visibility across fallback actors', function () {
    $firstUser = User::factory()->create([
        'asana_access_token' => 'tok-first',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'first',
        'asana_workspace_gid' => 'WS1',
    ]);
    $secondUser = User::factory()->create([
        'asana_access_token' => 'tok-second',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'second',
        'asana_workspace_gid' => 'WS1',
    ]);
    AsanaProject::create(['gid' => 'p-old', 'workspace_gid' => 'WS1', 'name' => 'Old', 'is_archived' => false]);

    Http::fake(function ($request) {
        $token = $request->header('Authorization')[0] ?? '';

        if ($token === 'Bearer tok-first') {
            return Http::response([
                'data' => [
                    ['gid' => 'p1', 'name' => 'First visible project', 'archived' => false],
                ],
                'next_page' => null,
            ]);
        }

        return Http::response([
            'data' => [
                ['gid' => 'p2', 'name' => 'Second visible project', 'archived' => false],
            ],
            'next_page' => null,
        ]);
    });

    (new PullAsanaProjectsJob('WS1', $firstUser->id, [$secondUser->id]))->handle(app(AsanaService::class));

    expect(AsanaProject::find('p1')->name)->toBe('First visible project');
    expect(AsanaProject::find('p2')->name)->toBe('Second visible project');
    expect(AsanaProject::find('p-old'))->toBeNull();
    expect(AsanaSyncLog::where('event', 'asana.pull_projects.completed')->first()?->context['user_ids'])
        ->toBe([$firstUser->id, $secondUser->id]);
});
```

- [ ] **Step 2: Write the failing safe-prune test**

Add this test immediately after the union test:

```php
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
```

- [ ] **Step 3: Run the project job tests to verify they fail**

Run:

```bash
php artisan test tests/Feature/Asana/PullJobsTest.php --filter="pulling projects"
```

Expected: FAIL because the job still fetches only the primary actor's project list and prunes after that single list.

- [ ] **Step 4: Replace the project job handle flow with collect-then-write**

In `app/Jobs/Asana/PullAsanaProjectsJob.php`, replace `handle()` with:

```php
public function handle(AsanaService $service): void
{
    $result = $this->collectProjectsWithAvailableActors($service);
    if ($result === null) {
        return;
    }

    $projects = $result['projects'];
    $successfulUserIds = $result['user_ids'];
    $canPrune = $result['can_prune'];

    $now = now();
    $seenGids = [];
    foreach ($projects as $project) {
        $seenGids[] = $project['gid'];
        AsanaProject::updateOrCreate(
            ['gid' => $project['gid']],
            [
                'workspace_gid' => $this->workspaceGid,
                'name' => $project['name'],
                'is_archived' => $project['archived'],
                'last_synced_at' => $now,
            ],
        );
    }

    if ($canPrune && $seenGids !== []) {
        AsanaProject::query()
            ->where('workspace_gid', $this->workspaceGid)
            ->whereNotIn('gid', $seenGids)
            ->delete();
    }

    AsanaSyncLog::info('asana.pull_projects.completed', [
        'workspace_gid' => $this->workspaceGid,
        'user_ids' => $successfulUserIds,
        'count' => count($projects),
        'pruned' => $canPrune,
    ]);
}
```

- [ ] **Step 5: Add the project collection helper**

Add this method below `handle()`:

```php
/**
 * @return array{projects: list<array{gid: string, name: string, archived: bool}>, user_ids: array<int, int>, can_prune: bool}|null
 */
private function collectProjectsWithAvailableActors(AsanaService $service): ?array
{
    $projectsByGid = [];
    $successfulUserIds = [];
    $permissionDeniedUserIds = [];
    $lastPermissionError = null;

    foreach ($this->candidateUserIds() as $userId) {
        $user = User::find($userId);
        if ($user === null || ! $user->asanaConnected()) {
            continue;
        }

        try {
            foreach ($service->forUser($user)->getProjects($this->workspaceGid) as $project) {
                $projectsByGid[$project['gid']] = $project;
            }

            $successfulUserIds[] = $user->id;
        } catch (RequestException $e) {
            if ($e->response->status() === 403) {
                $permissionDeniedUserIds[] = $user->id;
                $lastPermissionError = $e->getMessage();

                continue;
            }

            $this->logFailure($user, $e);

            throw $e;
        } catch (Throwable $e) {
            $this->logFailure($user, $e);

            throw $e;
        }
    }

    if ($permissionDeniedUserIds !== []) {
        AsanaSyncLog::warn('asana.pull_projects.partial_visibility', [
            'workspace_gid' => $this->workspaceGid,
            'user_ids' => $permissionDeniedUserIds,
            'error' => $lastPermissionError,
        ]);
    }

    if ($successfulUserIds === []) {
        return null;
    }

    return [
        'projects' => array_values($projectsByGid),
        'user_ids' => $successfulUserIds,
        'can_prune' => $permissionDeniedUserIds === [],
    ];
}
```

Add this helper below `candidateUserIds()`:

```php
private function logFailure(User $user, Throwable $e): void
{
    AsanaSyncLog::error('asana.pull_projects.failed', [
        'workspace_gid' => $this->workspaceGid,
        'user_id' => $user->id,
        'error' => $e->getMessage(),
    ], $user);
}
```

Add this import at the top of the file:

```php
use Illuminate\Http\Client\RequestException;
```

- [ ] **Step 6: Run the project job tests**

Run:

```bash
php artisan test tests/Feature/Asana/PullJobsTest.php --filter="pulling projects"
```

Expected: PASS. The existing single-user pruning test should still pass because `can_prune` is true when the only actor succeeds.

- [ ] **Step 7: Commit Task 2**

Run:

```bash
git add app/Jobs/Asana/PullAsanaProjectsJob.php tests/Feature/Asana/PullJobsTest.php
git commit -m "Merge Asana project visibility across actors"
```

---

### Task 3: Keep Task Refresh Linked-Only With Actor Fallback

**Files:**
- Modify: `app/Services/Asana/AsanaTaskRefreshDispatcher.php`
- Modify: `app/Jobs/Asana/PullAsanaTasksJob.php`
- Test: `tests/Feature/Asana/AsanaConsoleCommandsTest.php`
- Test: `tests/Feature/Asana/PullJobsTest.php`

**Interfaces:**
- Consumes: linked board gids from `project_asana_links`.
- Produces: `PullAsanaTasksJob::__construct(string $asanaProjectGid, int $userId, array $fallbackUserIds = [])`.

- [ ] **Step 1: Write the linked-only regression test**

Add this test to `tests/Feature/Asana/AsanaConsoleCommandsTest.php` after `asana:refresh-tasks dispatches a pull for each linked, non-archived project`:

```php
test('asana:refresh-tasks ignores cached Asana projects that are not linked', function () {
    Bus::fake([PullAsanaTasksJob::class]);

    User::factory()->create([
        'asana_access_token' => 'tok-a',
        'asana_user_gid' => 'u1',
        'asana_workspace_gid' => 'WS1',
    ]);

    AsanaProject::create([
        'gid' => 'AP-unlinked',
        'workspace_gid' => 'WS1',
        'name' => 'Unlinked board',
        'is_archived' => false,
    ]);

    $project = Project::factory()->create();
    linkBoardToProject($project, 'AP-linked', 'WS1');

    $this->artisan('asana:refresh-tasks')->assertExitCode(0);

    Bus::assertDispatchedTimes(PullAsanaTasksJob::class, 1);
    Bus::assertDispatched(PullAsanaTasksJob::class, fn (PullAsanaTasksJob $job) => $job->asanaProjectGid === 'AP-linked');
    Bus::assertNotDispatched(PullAsanaTasksJob::class, fn (PullAsanaTasksJob $job) => $job->asanaProjectGid === 'AP-unlinked');
});
```

- [ ] **Step 2: Write the task actor-list dispatch test**

Add this test to `tests/Feature/Asana/AsanaConsoleCommandsTest.php`:

```php
test('asana:refresh-tasks dispatches all same-workspace actors with admins first', function () {
    Bus::fake([PullAsanaTasksJob::class]);

    $regularUser = User::factory()->create([
        'asana_access_token' => 'tok-user',
        'asana_user_gid' => 'u1',
        'asana_workspace_gid' => 'WS1',
    ]);
    $admin = User::factory()->admin()->create([
        'asana_access_token' => 'tok-admin',
        'asana_user_gid' => 'admin',
        'asana_workspace_gid' => 'WS1',
    ]);
    User::factory()->create([
        'asana_access_token' => 'tok-other',
        'asana_user_gid' => 'u2',
        'asana_workspace_gid' => 'WS2',
    ]);

    $project = Project::factory()->create();
    linkBoardToProject($project, 'AP1', 'WS1');

    $this->artisan('asana:refresh-tasks')->assertExitCode(0);

    Bus::assertDispatched(PullAsanaTasksJob::class, fn (PullAsanaTasksJob $job) => $job->asanaProjectGid === 'AP1'
        && $job->userId === $admin->id
        && property_exists($job, 'fallbackUserIds')
        && $job->fallbackUserIds === [$regularUser->id]
    );
});
```

- [ ] **Step 3: Write the task fallback job test**

Add this test to `tests/Feature/Asana/PullJobsTest.php` after `pulling tasks caches searchable Asana custom field values`:

```php
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
```

- [ ] **Step 4: Run task tests to verify failure before implementation**

Run:

```bash
php artisan test tests/Feature/Asana/AsanaConsoleCommandsTest.php --filter="asana:refresh-tasks"
php artisan test tests/Feature/Asana/PullJobsTest.php --filter="falls back when the first actor cannot access"
```

Expected from a clean baseline: FAIL because task jobs do not yet accept fallback actors and `403` is still logged as `asana.pull_tasks.failed`. If the current workspace already contains the task-fallback patch, these tests may already pass; keep the existing implementation and continue to Step 7.

- [ ] **Step 5: Pass fallback actors from the task dispatcher**

In `app/Services/Asana/AsanaTaskRefreshDispatcher.php`, add:

```php
use App\Enums\Role;
```

Build connected users with admin-first ordering:

```php
$connectedUsers = User::query()
    ->whereNotNull('asana_access_token')
    ->whereNotNull('asana_user_gid')
    ->whereNotNull('asana_workspace_gid')
    ->where('is_active', true)
    ->orderByRaw('case when role = ? then 0 else 1 end', [Role::Admin->value])
    ->orderBy('id')
    ->get(['id', 'role', 'asana_workspace_gid']);
```

Replace each single actor dispatch with:

```php
$actors = $this->actorsForWorkspace($connectedUsers, $board->workspace_gid);
if ($actors->isEmpty()) {
    continue;
}

/** @var User $actor */
$actor = $actors->first();
$fallbackUserIds = $actors->skip(1)->pluck('id')->values()->all();

PullAsanaTasksJob::dispatch($board->gid, $actor->id, $fallbackUserIds);
$dispatched++;
```

Use this helper:

```php
/**
 * @param  Collection<int, User>  $connectedUsers
 * @return Collection<int, User>
 */
private function actorsForWorkspace(Collection $connectedUsers, string $workspaceGid): Collection
{
    return $connectedUsers
        ->where('asana_workspace_gid', $workspaceGid)
        ->values();
}
```

- [ ] **Step 6: Add fallback behavior to the task job**

In `app/Jobs/Asana/PullAsanaTasksJob.php`, use the same `fallbackUserIds` constructor and `candidateUserIds()` helper shape from `PullAsanaProjectsJob`.

Replace the start of `handle()` with:

```php
$result = $this->pullTasksWithAvailableActor($service);
if ($result === null) {
    return;
}

/** @var User $user */
$user = $result['user'];
$tasks = $result['tasks'];
```

Add this method:

```php
/**
 * @return array{user: User, tasks: list<array{gid: string, name: string, search_text: string|null, completed: bool, parent_gid: string|null}>}|null
 */
private function pullTasksWithAvailableActor(AsanaService $service): ?array
{
    $permissionDeniedUserIds = [];
    $lastPermissionError = null;

    foreach ($this->candidateUserIds() as $userId) {
        $user = User::find($userId);
        if ($user === null || ! $user->asanaConnected()) {
            continue;
        }

        try {
            return [
                'user' => $user,
                'tasks' => $service->forUser($user)->getTasks($this->asanaProjectGid),
            ];
        } catch (RequestException $e) {
            if ($e->response->status() === 403) {
                $permissionDeniedUserIds[] = $user->id;
                $lastPermissionError = $e->getMessage();

                continue;
            }

            $this->logFailure($user, $e);

            throw $e;
        } catch (Throwable $e) {
            $this->logFailure($user, $e);

            throw $e;
        }
    }

    if ($permissionDeniedUserIds !== []) {
        AsanaSyncLog::warn('asana.pull_tasks.permission_denied', [
            'asana_project_gid' => $this->asanaProjectGid,
            'user_ids' => $permissionDeniedUserIds,
            'error' => $lastPermissionError,
        ]);
    }

    return null;
}
```

Ensure the completed log records the successful actor:

```php
AsanaSyncLog::info('asana.pull_tasks.completed', [
    'asana_project_gid' => $this->asanaProjectGid,
    'user_id' => $user->id,
    'count' => count($tasks),
]);
```

- [ ] **Step 7: Run task refresh tests**

Run:

```bash
php artisan test tests/Feature/Asana/AsanaConsoleCommandsTest.php --filter="asana:refresh-tasks"
php artisan test tests/Feature/Asana/PullJobsTest.php --filter="pulling tasks"
```

Expected: PASS. The linked-only regression test should confirm unlinked cached boards are ignored.

- [ ] **Step 8: Commit Task 3**

Run:

```bash
git add app/Services/Asana/AsanaTaskRefreshDispatcher.php app/Jobs/Asana/PullAsanaTasksJob.php tests/Feature/Asana/AsanaConsoleCommandsTest.php tests/Feature/Asana/PullJobsTest.php
git commit -m "Keep Asana task refresh linked-only with actor fallback"
```

---

### Task 4: Final Verification and Deployment Readiness

**Files:**
- Verify: `app/Console/Commands/AsanaRefreshProjectsCommand.php`
- Verify: `app/Jobs/Asana/PullAsanaProjectsJob.php`
- Verify: `app/Services/Asana/AsanaTaskRefreshDispatcher.php`
- Verify: `app/Jobs/Asana/PullAsanaTasksJob.php`
- Verify: `tests/Feature/Asana/AsanaConsoleCommandsTest.php`
- Verify: `tests/Feature/Asana/PullJobsTest.php`

**Interfaces:**
- Consumes: completed Tasks 1-3.
- Produces: a branch ready to commit, push, and deploy when requested.

- [ ] **Step 1: Run the focused Asana suite**

Run:

```bash
php artisan test tests/Feature/Asana/AsanaConsoleCommandsTest.php tests/Feature/Asana/PullJobsTest.php
```

Expected: PASS. These tests cover workspace actor dispatch, project union, safe pruning, linked-only task dispatch, and task actor fallback.

- [ ] **Step 2: Format only changed PHP files**

Run:

```bash
vendor/bin/pint --dirty
```

Expected: PASS with no syntax or formatting failures.

- [ ] **Step 3: Run the full application test suite**

Run:

```bash
php artisan test
```

Expected: PASS. Existing timesheet, admin, MCP, schedule, reporting, and Asana tests should remain green.

- [ ] **Step 4: Review the final diff for scope**

Run:

```bash
git diff -- app/Console/Commands/AsanaRefreshProjectsCommand.php app/Jobs/Asana/PullAsanaProjectsJob.php app/Services/Asana/AsanaTaskRefreshDispatcher.php app/Jobs/Asana/PullAsanaTasksJob.php tests/Feature/Asana/AsanaConsoleCommandsTest.php tests/Feature/Asana/PullJobsTest.php
```

Expected: Diff only contains the project union, safe pruning, linked-only task dispatch, task fallback, and associated test changes.

- [ ] **Step 5: Make the final implementation commit**

If Tasks 1-3 were not committed separately, run:

```bash
git add app/Console/Commands/AsanaRefreshProjectsCommand.php app/Jobs/Asana/PullAsanaProjectsJob.php app/Services/Asana/AsanaTaskRefreshDispatcher.php app/Jobs/Asana/PullAsanaTasksJob.php tests/Feature/Asana/AsanaConsoleCommandsTest.php tests/Feature/Asana/PullJobsTest.php
git commit -m "Improve linked Asana sync coverage"
```

Expected: A commit containing only the Asana sync implementation and tests. Push and deploy only after explicit approval.

---

## Self-Review

- Spec coverage: The plan covers union project discovery across connected actors, linked-only task refresh, actor fallback for restricted boards, safe pruning, permission logging, and verification.
- Placeholder scan: No task uses incomplete-work marker language or vague error-handling instructions.
- Type consistency: Both jobs use `fallbackUserIds`, `candidateUserIds()`, and integer user ids consistently. The task dispatcher and project command both order actors with `Role::Admin->value` then `id`.
- Scope check: The plan is one coherent implementation because project discovery and linked task refresh share the same Asana actor-selection problem.
