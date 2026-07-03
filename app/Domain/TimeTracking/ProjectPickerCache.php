<?php

namespace App\Domain\TimeTracking;

use Illuminate\Support\Facades\Cache;

/**
 * The timesheet views cache each user's project/task picker payload for 10
 * minutes (projects_picker_{id} for DayView, projects_picker_eloquent_{id}
 * for WeekView). Every write path that changes what a picker should show
 * (project task/user assignments, archiving, task changes) must forget the
 * affected users' keys, or their pickers serve stale data — surviving hard
 * refreshes — until the TTL expires.
 */
final class ProjectPickerCache
{
    /**
     * @param  iterable<int, int|string>  $userIds
     */
    public static function forgetForUsers(iterable $userIds): void
    {
        collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->each(function (int $userId): void {
                Cache::forget("projects_picker_{$userId}");
                Cache::forget("projects_picker_eloquent_{$userId}");
            });
    }
}
