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
 * Resolution order for billable_rate_snapshot (first match wins). At each
 * tier, a populated rate_id (rates library row) takes precedence over the
 * legacy decimal column on the same record:
 *   1. project_user.rate_id  →  project_user.hourly_rate_override
 *   2. project.rate_id       →  project.default_hourly_rate
 *   3. user.rate_id          →  user.default_hourly_rate
 *   4. null → non-billable
 *
 * Resolution for is_billable:
 *   1. If project.is_billable = false → false
 *   2. Else project_task.is_billable for this (project, task)
 *
 * Rates and billability are frozen at save time. Changing project/user/task
 * settings after an entry is saved does NOT change historical entries.
 */
final class RateResolver
{
    public function resolve(Project $project, Task $task, User $user): RateResolution
    {
        $isBillable = $this->resolveBillable($project, $task);
        $rate = $isBillable ? $this->resolveRate($project, $user) : null;
        $amount = ($isBillable && $rate !== null) ? 0.0 : 0.0; // hours applied by caller

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

    private function resolveRate(Project $project, User $user): ?float
    {
        // 1. project_user — library rate first, then legacy override
        $assignedUser = $project->users->firstWhere('id', $user->id);
        if ($assignedUser !== null) {
            /** @var Pivot $projectUser */
            $projectUser = $assignedUser->getRelation('pivot');

            $rate = $this->resolveLibraryOrLegacy(
                $projectUser->getAttribute('rate_id'),
                $projectUser->getAttribute('hourly_rate_override'),
            );
            if ($rate !== null) {
                return $rate;
            }
        }

        // 2. project — library rate first, then legacy default
        $rate = $this->resolveLibraryOrLegacy($project->rate_id, $project->default_hourly_rate);
        if ($rate !== null) {
            return $rate;
        }

        // 3. user — library rate first, then legacy default
        $rate = $this->resolveLibraryOrLegacy($user->rate_id, $user->default_hourly_rate);
        if ($rate !== null) {
            return $rate;
        }

        // 4. no rate → non-billable
        return null;
    }

    private function resolveLibraryOrLegacy(mixed $rateId, mixed $legacyDecimal): ?float
    {
        if ($rateId !== null) {
            $rate = Rate::find($rateId);
            if ($rate !== null) {
                return (float) $rate->hourly_rate;
            }
        }

        if ($legacyDecimal !== null) {
            return (float) $legacyDecimal;
        }

        return null;
    }
}
