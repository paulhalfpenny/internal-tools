<?php

namespace App\Jobs\Asana;

use App\Models\AsanaSyncLog;
use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Asana\AsanaService;
use App\Services\Asana\AsanaTaskHoursAggregator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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
        if ($project === null) {
            $this->markEntriesError('Project is no longer linked to Asana.');

            return;
        }

        $asanaTask = AsanaTask::find($this->asanaTaskGid);
        if ($asanaTask === null) {
            $this->markEntriesError('Asana task '.$this->asanaTaskGid.' is no longer cached locally.');

            return;
        }

        $boardGid = $asanaTask->asana_project_gid;
        $linkedBoard = $project->asanaProjects()->where('gid', $boardGid)->first();

        if ($linkedBoard === null) {
            // The board has been unlinked from this project since the entry was logged.
            // Mark the entries with a soft error so the sync isn't retried, but don't fail the job.
            $this->markEntriesError('Project is no longer linked to the Asana board for this task.');
            AsanaSyncLog::warn('asana.sync_hours.board_unlinked', [
                'asana_task_gid' => $this->asanaTaskGid,
                'project_id' => $this->projectId,
                'board_gid' => $boardGid,
            ], $project);

            return;
        }

        $workspaceGid = $linkedBoard->workspace_gid;

        $actor = $this->pickActor($workspaceGid);
        if ($actor === null) {
            AsanaSyncLog::warn('asana.sync_hours.no_actor', [
                'asana_task_gid' => $this->asanaTaskGid,
                'project_id' => $this->projectId,
            ], $project);

            return;
        }

        $svc = $service->forUser($actor);

        /** @var Pivot $pivot */
        $pivot = $linkedBoard->getRelation('pivot');
        $fieldGid = $pivot->getAttribute('asana_custom_field_gid');
        if ($fieldGid === null) {
            try {
                $fieldGid = $svc->ensureHoursCustomField($boardGid, $workspaceGid);
                DB::table('project_asana_links')
                    ->where('project_id', $this->projectId)
                    ->where('asana_project_gid', $boardGid)
                    ->update(['asana_custom_field_gid' => $fieldGid, 'updated_at' => now()]);
            } catch (Throwable $e) {
                $this->logFailure($e, $project, 'ensure_field');
                throw $e;
            }
        }

        // Snapshot the latest updated_at among matching entries BEFORE the API call so we
        // only mark synced rows that haven't been edited since the SUM was computed.
        $snapshot = TimeEntry::query()
            ->where('asana_task_gid', $this->asanaTaskGid)
            ->where('project_id', $this->projectId)
            ->max('updated_at');

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
            ->where('project_id', $this->projectId)
            ->when($snapshot !== null, fn ($q) => $q->where('updated_at', '<=', $snapshot))
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

    private function pickActor(string $workspaceGid): ?User
    {
        return User::query()
            ->whereNotNull('asana_access_token')
            ->whereNotNull('asana_user_gid')
            ->where('asana_workspace_gid', $workspaceGid)
            ->where('is_active', true)
            ->orderByRaw('CASE WHEN role = "admin" THEN 0 WHEN role = "manager" THEN 1 ELSE 2 END')
            ->first();
    }

    private function markEntriesError(string $message): void
    {
        TimeEntry::query()
            ->where('asana_task_gid', $this->asanaTaskGid)
            ->where('project_id', $this->projectId)
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
