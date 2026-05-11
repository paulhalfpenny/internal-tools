<?php

namespace App\Jobs\Asana;

use App\Models\AsanaProject;
use App\Models\AsanaSyncLog;
use App\Models\User;
use App\Services\Asana\AsanaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PullAsanaProjectsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly string $workspaceGid,
        public readonly int $userId,
    ) {}

    public function handle(AsanaService $service): void
    {
        $user = User::find($this->userId);
        if ($user === null || ! $user->asanaConnected()) {
            return;
        }

        try {
            $projects = $service->forUser($user)->getProjects($this->workspaceGid);
        } catch (Throwable $e) {
            AsanaSyncLog::error('asana.pull_projects.failed', [
                'workspace_gid' => $this->workspaceGid,
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ], $user);

            throw $e;
        }

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
        if ($seenGids !== []) {
            AsanaProject::query()
                ->where('workspace_gid', $this->workspaceGid)
                ->whereNotIn('gid', $seenGids)
                ->delete();
        }

        AsanaSyncLog::info('asana.pull_projects.completed', [
            'workspace_gid' => $this->workspaceGid,
            'count' => count($projects),
        ]);
    }
}
