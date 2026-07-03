<?php

namespace App\Domain\TimeTracking;

use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Shared gatekeeper for "can this user log time on this project/task
 * (optionally against this Asana task)?" — used by the MCP tools and the
 * Asana app-components endpoints so the rules can't drift apart.
 */
final class ProjectTaskUsability
{
    public static function ensure(User $user, int $projectId, int $taskId, ?string $asanaTaskGid): void
    {
        $project = Project::with(['users', 'tasks'])->findOrFail($projectId);

        if (! $project->users->contains('id', $user->id)) {
            throw new AuthorizationException('The time entry user is not assigned to this project.');
        }

        if (! $project->tasks->contains('id', $taskId)) {
            throw ValidationException::withMessages(['task_id' => 'The selected task is not assigned to this project.']);
        }

        $linkedBoardGids = $project->asanaProjects()->pluck('gid')->all();
        if ($linkedBoardGids === []) {
            return;
        }

        $hasGid = $asanaTaskGid !== null && $asanaTaskGid !== '';
        if ($project->asana_task_required && ! $hasGid) {
            throw ValidationException::withMessages(['asana_task_gid' => 'An Asana task is required for this project.']);
        }

        if ($hasGid && ! AsanaTask::where('gid', $asanaTaskGid)->whereIn('asana_project_gid', $linkedBoardGids)->exists()) {
            throw ValidationException::withMessages(['asana_task_gid' => 'The selected Asana task does not belong to this project.']);
        }
    }
}
