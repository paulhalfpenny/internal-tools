<?php

namespace App\Domain\TimeTracking;

use App\Models\AsanaProjectAssociation;
use App\Models\User;

final class AsanaProjectAssociationService
{
    /**
     * The project/task this user last logged against for this Asana board.
     *
     * @return array{project_id: int, task_id: int}|null
     */
    public function lookup(User $user, string $asanaProjectGid): ?array
    {
        if ($asanaProjectGid === '') {
            return null;
        }

        $assoc = AsanaProjectAssociation::query()
            ->where('user_id', $user->id)
            ->where('asana_project_gid', $asanaProjectGid)
            ->first();

        if ($assoc === null) {
            return null;
        }

        return [
            'project_id' => $assoc->project_id,
            'task_id' => $assoc->task_id,
        ];
    }

    public function remember(User $user, string $asanaProjectGid, int $projectId, int $taskId): void
    {
        if ($asanaProjectGid === '') {
            return;
        }

        AsanaProjectAssociation::updateOrCreate(
            ['user_id' => $user->id, 'asana_project_gid' => $asanaProjectGid],
            ['project_id' => $projectId, 'task_id' => $taskId, 'last_used_at' => now()],
        );
    }
}
