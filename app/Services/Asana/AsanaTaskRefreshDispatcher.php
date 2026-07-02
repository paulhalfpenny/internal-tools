<?php

namespace App\Services\Asana;

use App\Enums\Role;
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
            ->orderByRaw('case when role = ? then 0 else 1 end', [Role::Admin->value])
            ->orderBy('id')
            ->get(['id', 'role', 'asana_workspace_gid']);

        if ($connectedUsers->isEmpty()) {
            return 0;
        }

        $dispatched = 0;
        foreach ($boards as $board) {
            $actors = $this->actorsForWorkspace($connectedUsers, $board->workspace_gid);
            if ($actors->isEmpty()) {
                continue;
            }

            /** @var User $actor */
            $actor = $actors->first();
            $fallbackUserIds = $actors->skip(1)->pluck('id')->values()->all();

            PullAsanaTasksJob::dispatch($board->gid, $actor->id, $fallbackUserIds);
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * @param  Collection<int, User>  $connectedUsers
     * @return Collection<int, User>
     */
    private function actorsForWorkspace(Collection $connectedUsers, string $workspaceGid): Collection
    {
        return $connectedUsers
            ->where('asana_workspace_gid', $workspaceGid)
            ->values();
    }
}
