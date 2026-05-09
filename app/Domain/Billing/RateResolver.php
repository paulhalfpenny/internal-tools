<?php

namespace App\Domain\Billing;

use App\Models\Project;
use App\Models\Rate;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Resolves billability and rate for a (project, task, user) combination.
 *
 * Resolution order for billable_rate_snapshot (first match wins):
 *   1. project_user.hourly_rate_override — the per-project custom rate
 *   2. user.rate_id → rates library — the user's default role rate
 *   3. FALLBACK_HOURLY_RATE — last-resort default if neither is set
 *
 * Resolution for is_billable:
 *   1. If project.is_billable = false → false (rate is null, amount is £0)
 *   2. Else project_task.is_billable for this (project, task)
 *
 * Rates and billability are frozen at save time. Changing project/user/task
 * settings after an entry is saved does NOT change historical entries.
 */
final class RateResolver
{
    public const FALLBACK_HOURLY_RATE = 100.0;

    public function resolve(Project $project, Task $task, User $user): RateResolution
    {
        $isBillable = $this->resolveBillable($project, $task);
        $rate = $isBillable ? $this->resolveRate($project, $user) : null;

        return new RateResolution(
            isBillable: $isBillable,
            rateSnapshot: $rate,
        );
    }

    public function resolveWithHours(Project $project, Task $task, User $user, float $hours): RateResolution
    {
        $isBillable = $this->resolveBillable($project, $task);
        $rate = $isBillable ? $this->resolveRate($project, $user) : null;
        $amount = ($isBillable && $rate !== null) ? round($hours * $rate, 2) : 0.0;

        return new RateResolution(
            isBillable: $isBillable,
            rateSnapshot: $rate,
            billableAmount: $amount,
        );
    }

    private function resolveBillable(Project $project, Task $task): bool
    {
        if (! $project->is_billable) {
            return false;
        }

        // Look up the project_task pivot row
        $assignedTask = $project->tasks->firstWhere('id', $task->id);
        if ($assignedTask === null) {
            return false;
        }

        /** @var Pivot $pivot */
        $pivot = $assignedTask->getRelation('pivot');

        return (bool) $pivot->getAttribute('is_billable');
    }

    private function resolveRate(Project $project, User $user): float
    {
        // 1. Per-project custom override for this user
        $assignedUser = $project->users->firstWhere('id', $user->id);
        if ($assignedUser !== null) {
            /** @var Pivot $projectUser */
            $projectUser = $assignedUser->getRelation('pivot');
            $override = $projectUser->getAttribute('hourly_rate_override');
            if ($override !== null) {
                return (float) $override;
            }
        }

        // 2. User's default role rate (rate library)
        if ($user->rate_id !== null) {
            $rate = Rate::find($user->rate_id);
            if ($rate !== null) {
                return (float) $rate->hourly_rate;
            }
        }

        // 3. Last-resort fallback
        return self::FALLBACK_HOURLY_RATE;
    }
}
