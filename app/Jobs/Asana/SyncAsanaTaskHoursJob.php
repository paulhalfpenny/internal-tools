<?php

namespace App\Jobs\Asana;

use App\Models\AsanaSyncLog;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Asana\AsanaService;
use App\Services\Asana\AsanaTaskHoursAggregator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncAsanaTaskHoursJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function __construct(
        public readonly string $asanaTaskGid,
        public readonly int $projectId,
    ) {}

    public function handle(AsanaService $service, AsanaTaskHoursAggregator $aggregator): void
    {
        $project = Project::find($this->projectId);
        if ($project === null
            || ! $project->asanaLinked()
            || $project->asana_workspace_gid === null
            || $project->asana_project_gid === null) {
            $this->markEntriesError('Project is no longer linked to Asana.');

            return;
        }

        $actor = $this->pickActor();
        if ($actor === null) {
            AsanaSyncLog::warn('asana.sync_hours.no_actor', [
                'asana_task_gid' => $this->asanaTaskGid,
                'project_id' => $this->projectId,
            ], $project);

            return;
        }

        $svc = $service->forUser($actor);

        $fieldGid = $project->asana_custom_field_gid;
        if ($fieldGid === null) {
            try {
                $fieldGid = $svc->ensureHoursCustomField($project->asana_project_gid, $project->asana_workspace_gid);
                $project->forceFill(['asana_custom_field_gid' => $fieldGid])->save();
            } catch (Throwable $e) {
                $this->logFailure($e, $project, 'ensure_field');
                throw $e;
            }
        }

        $total = $aggregator->totalHours($this->asanaTaskGid);

        try {
            $svc->setTaskHours($this->asanaTaskGid, $fieldGid, $total);
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                $this->markEntriesError('Asana task not found ('.$this->asanaTaskGid.').');
                AsanaSyncLog::error('asana.sync_hours.task_not_found', [
                    'asana_task_gid' => $this->asanaTaskGid,
                    'project_id' => $this->projectId,
                ], $project);

                return;
            }

            if ($e->response->status() === 429) {
                $retryAfter = (int) ($e->response->header('Retry-After') ?: 30);
                $this->release($retryAfter);

                return;
            }

            $this->logFailure($e, $project, 'set_hours');
            throw $e;
        } catch (Throwable $e) {
            $this->logFailure($e, $project, 'set_hours');
            throw $e;
        }

        TimeEntry::query()
            ->where('asana_task_gid', $this->asanaTaskGid)
            ->update([
                'asana_synced_at' => now(),
                'asana_sync_error' => null,
            ]);

        AsanaSyncLog::info('asana.sync_hours.pushed', [
            'asana_task_gid' => $this->asanaTaskGid,
            'hours' => $total,
            'project_id' => $this->projectId,
        ], $project);
    }

    public function failed(Throwable $exception): void
    {
        $this->markEntriesError(substr($exception->getMessage(), 0, 500));
    }

    private function pickActor(): ?User
    {
        return User::query()
            ->whereNotNull('asana_access_token')
            ->whereNotNull('asana_user_gid')
            ->where('is_active', true)
            ->orderByRaw('CASE WHEN role = "admin" THEN 0 WHEN role = "manager" THEN 1 ELSE 2 END')
            ->first();
    }

    private function markEntriesError(string $message): void
    {
        TimeEntry::query()
            ->where('asana_task_gid', $this->asanaTaskGid)
            ->update(['asana_sync_error' => $message]);
    }

    private function logFailure(Throwable $e, Project $project, string $stage): void
    {
        AsanaSyncLog::error('asana.sync_hours.failed', [
            'stage' => $stage,
            'asana_task_gid' => $this->asanaTaskGid,
            'project_id' => $this->projectId,
            'error' => $e->getMessage(),
            'attempt' => $this->attempts(),
        ], $project);
    }
}
