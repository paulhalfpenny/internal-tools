<?php

namespace App\Services\Asana;

use App\Models\TimeEntry;

final class AsanaTaskHoursAggregator
{
    /**
     * Sum every Internal Tools time entry currently associated with the given
     * Asana task gid, regardless of user. This is the value pushed to Asana's
     * cumulative custom field.
     */
    public function totalHours(string $asanaTaskGid): float
    {
        return (float) TimeEntry::query()
            ->where('asana_task_gid', $asanaTaskGid)
            ->sum('hours');
    }
}
