<?php

namespace App\Jobs\Asana;

use App\Models\AsanaSyncLog;
use App\Models\AsanaTask;
use App\Models\User;
use App\Services\Asana\AsanaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PullAsanaTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly string $asanaProjectGid,
        public readonly int $userId,
    ) {}

    public function handle(AsanaService $service): void
    {
        $user = User::find($this->userId);
        if ($user === null || ! $user->asanaConnected()) {
            return;
        }

        try {
            $tasks = $service->forUser($user)->getTasks($this->asanaProjectGid);
        } catch (Throwable $e) {
            AsanaSyncLog::error('asana.pull_tasks.failed', [
                'asana_project_gid' => $this->asanaProjectGid,
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ], $user);

            throw $e;
        }

        $now = now();
        $seenGids = [];
        foreach ($tasks as $task) {
            $seenGids[] = $task['gid'];
            AsanaTask::updateOrCreate(
                ['gid' => $task['gid']],
                [
                    'asana_project_gid' => $this->asanaProjectGid,
                    'name' => $task['name'],
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
            'count' => count($tasks),
        ]);
    }
}
