<?php

namespace App\Jobs\Asana;

use App\Models\AsanaSyncLog;
use App\Models\AsanaTask;
use App\Models\User;
use App\Services\Asana\AsanaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Throwable;

class PullAsanaTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly string $asanaProjectGid,
        public readonly int $userId,
        /** @var array<int, int> */
        public readonly array $fallbackUserIds = [],
    ) {}

    public function handle(AsanaService $service): void
    {
        $result = $this->pullTasksWithAvailableActor($service);
        if ($result === null) {
            return;
        }

        /** @var User $user */
        $user = $result['user'];
        $tasks = $result['tasks'];

        $now = now();
        $seenGids = [];
        foreach ($tasks as $task) {
            $seenGids[] = $task['gid'];
            AsanaTask::updateOrCreate(
                ['gid' => $task['gid']],
                [
                    'asana_project_gid' => $this->asanaProjectGid,
                    'name' => $task['name'],
                    'search_text' => $task['search_text'],
                    'is_completed' => $task['completed'],
                    'parent_gid' => $task['parent_gid'],
                    'last_synced_at' => $now,
                ],
            );
        }

        // Only prune when we actually saw tasks — an empty response (transient API blip,
        // revoked permission, etc.) must not wipe the cache.
        if ($seenGids !== []) {
            AsanaTask::query()
                ->where('asana_project_gid', $this->asanaProjectGid)
                ->whereNotIn('gid', $seenGids)
                ->delete();
        }

        AsanaSyncLog::info('asana.pull_tasks.completed', [
            'asana_project_gid' => $this->asanaProjectGid,
            'user_id' => $user->id,
            'count' => count($tasks),
        ]);
    }

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

    private function logFailure(User $user, Throwable $e): void
    {
        AsanaSyncLog::error('asana.pull_tasks.failed', [
            'asana_project_gid' => $this->asanaProjectGid,
            'user_id' => $user->id,
            'error' => $e->getMessage(),
        ], $user);
    }
}
