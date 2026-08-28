<?php

namespace App\Jobs\Asana;

use App\Models\AsanaProject;
use App\Models\AsanaSyncLog;
use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Asana\AsanaHoursSyncRecovery;
use App\Services\Asana\AsanaService;
use App\Services\Asana\AsanaSyncActorAlert;
use App\Services\Asana\AsanaTaskHoursAggregator;
use App\Services\Asana\AsanaTokenManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use LogicException;
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

    public function handle(
        AsanaService $service,
        AsanaTaskHoursAggregator $aggregator,
        AsanaSyncActorAlert $actorAlert,
        AsanaTokenManager $tokens,
        AsanaHoursSyncRecovery $hoursRecovery,
    ): void {
        $project = Project::find($this->projectId);
        if ($project === null) {
            $hoursRecovery->resolve($this->asanaTaskGid, $this->projectId);
            $this->markEntriesError('Project is no longer linked to Asana.');

            return;
        }

        $asanaTask = AsanaTask::find($this->asanaTaskGid);
        if ($asanaTask === null) {
            $hoursRecovery->resolve($this->asanaTaskGid, $this->projectId);
            $this->markEntriesError('Asana task '.$this->asanaTaskGid.' is no longer cached locally.');

            return;
        }

        $boardGid = $asanaTask->asana_project_gid;
        $linkedBoard = $project->asanaProjects()->where('gid', $boardGid)->first();

        if ($linkedBoard === null) {
            $hoursRecovery->resolve($this->asanaTaskGid, $this->projectId);
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

        $actor = User::asanaSyncActor();
        $actorUnavailableReason = $this->actorUnavailableReason($actor, $workspaceGid, $tokens);
        if ($actorUnavailableReason !== null) {
            $this->markEntriesError(
                'Hours are pending because the designated Asana sync account is unavailable.',
                TimeEntry::ASANA_SYNC_ERROR_ACTOR_UNAVAILABLE,
            );

            AsanaSyncLog::warn('asana.sync_hours.actor_unavailable', [
                'asana_task_gid' => $this->asanaTaskGid,
                'project_id' => $this->projectId,
                'workspace_gid' => $workspaceGid,
                'designated_user_id' => $actor?->id,
                'designated_asana_user_gid' => $actor?->asana_user_gid,
                'reason' => $actorUnavailableReason,
            ], $project);
            $hoursRecovery->markPending(
                $this->asanaTaskGid,
                $this->projectId,
                $actorUnavailableReason,
            );
            $actorAlert->reportUnavailable($actor, $actorUnavailableReason);

            return;
        }

        if ($actor === null) {
            throw new LogicException('Asana sync actor resolution returned an invalid state.');
        }

        $hoursRecovery->resolve($this->asanaTaskGid, $this->projectId);

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
            } catch (RequestException $e) {
                if ($e->response->status() === 403) {
                    $this->handlePermissionDenied(
                        $e,
                        $project,
                        $linkedBoard,
                        $asanaTask,
                        $actor,
                        null,
                        'ensure_field',
                    );

                    return;
                }

                $this->logFailure($e, $project, 'ensure_field');
                throw $e;
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
            if ($e->response->status() === 403) {
                $this->handlePermissionDenied(
                    $e,
                    $project,
                    $linkedBoard,
                    $asanaTask,
                    $actor,
                    $fieldGid,
                    'set_hours',
                );

                return;
            }

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
                'asana_sync_error_code' => null,
            ]);

        AsanaSyncLog::info('asana.sync_hours.pushed', [
            'asana_task_gid' => $this->asanaTaskGid,
            'hours' => $total,
            'project_id' => $this->projectId,
            'actor_user_id' => $actor->id,
            'actor_asana_user_gid' => $actor->asana_user_gid,
        ], $project);
    }

    public function failed(Throwable $exception): void
    {
        $this->markEntriesError(substr($exception->getMessage(), 0, 500));
    }

    private function actorUnavailableReason(
        ?User $actor,
        string $workspaceGid,
        AsanaTokenManager $tokens,
    ): ?string {
        if ($actor === null) {
            return 'actor_not_designated';
        }

        if ($actor->asana_access_token === null) {
            return 'actor_no_token';
        }

        if ($actor->asana_user_gid === null) {
            return 'actor_no_identity';
        }

        if ($actor->asana_workspace_gid !== $workspaceGid) {
            return 'actor_workspace_mismatch';
        }

        if ($tokens->getValidToken($actor) === null) {
            return 'actor_token_unavailable';
        }

        return null;
    }

    private function markEntriesError(string $message, ?string $code = null): void
    {
        TimeEntry::query()
            ->where('asana_task_gid', $this->asanaTaskGid)
            ->where('project_id', $this->projectId)
            ->update([
                'asana_sync_error' => $message,
                'asana_sync_error_code' => $code,
            ]);
    }

    private function handlePermissionDenied(
        RequestException $exception,
        Project $project,
        AsanaProject $linkedBoard,
        AsanaTask $asanaTask,
        User $actor,
        ?string $fieldGid,
        string $stage,
    ): void {
        $this->markEntriesError(sprintf(
            'Asana sync account cannot update hours on Asana board "%s". Grant it project-admin and custom-field edit access, then retry.',
            $linkedBoard->name,
        ));

        AsanaSyncLog::error('asana.sync_hours.permission_denied', [
            'stage' => $stage,
            'asana_task_gid' => $this->asanaTaskGid,
            'asana_task_name' => $asanaTask->name,
            'board_gid' => $linkedBoard->gid,
            'board_name' => $linkedBoard->name,
            'custom_field_gid' => $fieldGid,
            'project_id' => $this->projectId,
            'actor_user_id' => $actor->id,
            'actor_asana_user_gid' => $actor->asana_user_gid,
            'error' => $exception->getMessage(),
        ], $project);
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
