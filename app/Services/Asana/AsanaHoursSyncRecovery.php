<?php

namespace App\Services\Asana;

use App\Jobs\Asana\SyncAsanaTaskHoursJob;
use App\Models\AsanaPendingHourSync;

final class AsanaHoursSyncRecovery
{
    public function dispatchPending(): int
    {
        $pendingTotals = AsanaPendingHourSync::query()->get();

        foreach ($pendingTotals as $pendingTotal) {
            SyncAsanaTaskHoursJob::dispatch(
                (string) $pendingTotal->asana_task_gid,
                $pendingTotal->project_id,
            );
        }

        return $pendingTotals->count();
    }

    public function markPending(string $asanaTaskGid, int $projectId, string $reason): void
    {
        AsanaPendingHourSync::query()->upsert(
            [[
                'asana_task_gid' => $asanaTaskGid,
                'project_id' => $projectId,
                'reason' => $reason,
            ]],
            ['asana_task_gid', 'project_id'],
            ['reason'],
        );
    }

    public function resolve(string $asanaTaskGid, int $projectId): void
    {
        AsanaPendingHourSync::query()
            ->where('asana_task_gid', $asanaTaskGid)
            ->where('project_id', $projectId)
            ->delete();
    }
}
