<?php

namespace App\Services\Asana;

use App\Jobs\Asana\PullAsanaTasksJob;
use App\Models\AsanaProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

final class AsanaTaskRefreshDispatcher
{
    public function dispatchForProject(Project $project): int
    {
        $boardGids = $project->asanaProjects()->pluck('asana_projects.gid');

        return $this->dispatchForBoardGids($boardGids);
    }

    /**
     * @param  iterable<int, string>  $boardGids
     */
    public function dispatchForBoardGids(iterable $boardGids): int
    {
        $boards = AsanaProject::query()
            ->whereIn('gid', collect($boardGids)->filter()->unique()->values()->all())
            ->get(['gid', 'workspace_gid']);

        if ($boards->isEmpty()) {
            return 0;
        }

        $connectedUsers = User::query()
            ->whereNotNull('asana_access_token')
            ->whereNotNull('asana_user_gid')
            ->whereNotNull('asana_workspace_gid')
            ->where('is_active', true)
            ->get(['id', 'asana_workspace_gid']);

        if ($connectedUsers->isEmpty()) {
            return 0;
        }

        $dispatched = 0;
        foreach ($boards as $board) {
            $actor = $this->actorForWorkspace($connectedUsers, $board->workspace_gid);
            if ($actor === null) {
                continue;
            }

            PullAsanaTasksJob::dispatch($board->gid, $actor->id);
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * @param  Collection<int, User>  $connectedUsers
     */
    private function actorForWorkspace(Collection $connectedUsers, string $workspaceGid): ?User
    {
        return $connectedUsers->firstWhere('asana_workspace_gid', $workspaceGid);
    }
}
