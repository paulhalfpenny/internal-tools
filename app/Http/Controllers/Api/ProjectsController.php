<?php

namespace App\Http\Controllers\Api;

use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectsController
{
    /**
     * Returns the active projects the current user is assigned to, with their
     * tasks (id, name, billability, colour) and — if the project is linked to
     * Asana — the cached Asana tasks for it. Mirrors the shape consumed by
     * the day-view picker so the Freshdesk widget can reuse the logic.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $projects = Project::with(['client', 'tasks', 'asanaProjects'])
            ->where('is_archived', false)
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
            ->orderBy('name')
            ->get();

        $linkedAsanaProjectGids = $projects
            ->flatMap(fn (Project $p) => $p->asanaProjects->pluck('gid'))
            ->unique()
            ->values()
            ->all();
        $asanaTasksByProject = AsanaTask::query()
            ->whereIn('asana_project_gid', $linkedAsanaProjectGids)
            ->where('is_completed', false)
            ->orderBy('name')
            ->get(['gid', 'asana_project_gid', 'name'])
            ->groupBy('asana_project_gid');

        return response()->json([
            'projects' => $projects->map(function (Project $p) use ($asanaTasksByProject) {
                $boardGids = $p->asanaProjects->pluck('gid')->values()->all();

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'client_name' => $p->client->name,
                    'asana_project_gids' => $boardGids,
                    'asana_task_required' => (bool) $p->asana_task_required,
                    'tasks' => $p->tasks->map(function (Task $t) {
                        /** @var Pivot $pivot */
                        $pivot = $t->getRelation('pivot');

                        return [
                            'id' => $t->id,
                            'name' => $t->name,
                            'colour' => $t->colour,
                            'is_billable' => (bool) $pivot->getAttribute('is_billable'),
                        ];
                    })->values()->all(),
                    'asana_tasks' => collect($boardGids)
                        ->flatMap(fn (string $gid) => $asanaTasksByProject->get($gid, collect()))
                        ->map(fn (AsanaTask $t) => ['gid' => $t->gid, 'name' => $t->name])
                        ->values()
                        ->all(),
                ];
            })->values()->all(),
        ]);
    }
}
