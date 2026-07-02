<?php

namespace App\Jobs\Asana;

use App\Models\AsanaProject;
use App\Models\AsanaSyncLog;
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

class PullAsanaProjectsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly string $workspaceGid,
        public readonly int $userId,
        /** @var array<int, int> */
        public readonly array $fallbackUserIds = [],
    ) {}

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

        // Only prune when we actually saw projects — an empty response (transient API blip,
        // revoked permission, etc.) must not wipe the cache.
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
        AsanaSyncLog::error('asana.pull_projects.failed', [
            'workspace_gid' => $this->workspaceGid,
            'user_id' => $user->id,
            'error' => $e->getMessage(),
        ], $user);
    }
}
